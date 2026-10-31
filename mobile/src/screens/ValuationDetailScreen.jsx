import { useState, useCallback } from 'react'
import { View, Text, TouchableOpacity, StyleSheet, Alert, ActivityIndicator, RefreshControl } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import api from '../api'
import { useAuth } from '../auth'
import { fetchOfflineRead } from '../offline'
import { can, serverMessage, isOnlineRejection } from '../rbac'
import { theme } from '../theme'
import { isLandRow } from '../valuationRows'
import { Screen, Card, InfoRow, Badge, Field, Input, Btn, ChipRow, Empty, OfflineReadStatus } from '../components'

const fm = (v) => (v === null || v === undefined ? '—' : 'US$ ' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
const fd = (v) => (v ? String(v).slice(0, 10) : '—')

export default function ValuationDetailScreen({ route }) {
  const { user, canUseOfflineCache } = useAuth()
  const { valuationId } = route.params || {}
  const [v, setV] = useState(null)
  const [err, setErr] = useState('')
  const [readStatus, setReadStatus] = useState(null)
  const [syncedAt, setSyncedAt] = useState(null)
  const [refreshing, setRefreshing] = useState(false)
  const [busy, setBusy] = useState('')
  const [panel, setPanel] = useState(null)
  const [decision, setDecision] = useState('')
  const [officers, setOfficers] = useState(null)
  const [input1, setInput1] = useState('')
  const [input2, setInput2] = useState('')
  const [remarks, setRemarks] = useState('')

  const load = useCallback(async () => {
    try {
      const result = await fetchOfflineRead({
        module: 'valuations',
        cacheKey: `${user?.role}:detail:${valuationId}`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get(`/valuations/${valuationId}`),
        select: (response) => response.data?.data,
        cacheCondition: (record) => String(record?.valuation_officer_id) === String(user?.id),
      })
      if (result.status === 'expired') {
        setV(null)
        setErr('Offline valuation data has expired. Connect to refresh.')
      } else {
        setV(result.data)
        setErr('')
      }
      setReadStatus(result.status)
      setSyncedAt(result.syncedAt)
    } catch (e) { setErr(serverMessage(e, 'Could not load the valuation record.')) }
  }, [valuationId, user, canUseOfflineCache])

  useFocusEffect(useCallback(() => { load() }, [load]))
  const refresh = async () => { setRefreshing(true); await load(); setRefreshing(false) }

  const runAction = async (endpoint, payload) => {
    if (readStatus === 'cached' || readStatus === 'expired') return
    setBusy(endpoint)
    try {
      await api.post(`/valuations/${valuationId}/${endpoint}`, payload)
      setPanel(null); setDecision(''); setInput1(''); setInput2(''); setRemarks('')
      Alert.alert('Done', 'Valuation updated.')
      load()
    } catch (e) {
      if (isOnlineRejection(e)) Alert.alert('Action failed', serverMessage(e, 'The server rejected the action.'))
      else Alert.alert('Offline', 'Connect to the network and retry.')
    } finally { setBusy('') }
  }

  const loadOfficers = async () => {
    if (officers) return
    try { setOfficers((await api.get('/lookup/supervisors')).data?.data || []) }
    catch { setOfficers([]) }
  }

  const openPanel = (key) => {
    if (readStatus === 'cached' || readStatus === 'expired') return
    setPanel(key)
    setDecision(key === 'review' ? 'confirm_ac' : (key === 'submit' || key === 'processing' ? '' : 'approve'))
    setInput1(''); setInput2(''); setRemarks('')
    if (key === 'reassign' || key === 'account-review') loadOfficers()
  }

  if (err && !v) {
    return (
      <Screen>
        <OfflineReadStatus status={readStatus} syncedAt={syncedAt} />
        <Card style={{ marginTop: 20 }}>
          <Text style={{ color: theme.colors.danger, fontWeight: '700', marginBottom: 8 }}>⚠ Unable to load valuation</Text>
          <Text style={{ color: theme.colors.textMuted, fontSize: 13, marginBottom: 12 }}>{err}</Text>
          <Btn label="⟳ Retry" onPress={refresh} loading={refreshing} />
        </Card>
      </Screen>
    )
  }
  if (!v) {
    return <Screen><ActivityIndicator color={theme.colors.primary} style={{ marginTop: 60 }} /></Screen>
  }

  const status = v.status
  const isOfficer = v.valuation_officer_id === user?.id
  const canBegin = can(user, 'valuation.submit') && status === 'Assigned'
  const canSubmit = can(user, 'valuation.submit') && ['Assigned', 'Assessment in Progress', 'Returned for Correction'].includes(status)
  const canReassign = (can(user, 'valuation.review') || can(user, 'valuation.forward_ac') || can(user, 'valuation.approve')) && ['Assigned', 'Returned for Correction', 'AC Returned'].includes(status)
  const canManagerReview = (can(user, 'valuation.review') || can(user, 'valuation.approve')) && ['Submitted to Manager', 'Under Manager Review'].includes(status) && !isOfficer
  const canACDecide = can(user, 'valuation.approve') && ['Submitted to AC', 'Awaiting AC Approval'].includes(status) && !isOfficer
  const canAccountReview = can(user, 'valuation.account_review') && status === 'AC Approved'
  const canProcess = can(user, 'valuation.property_registration') && status === 'Sent to Account & Record'
  const canComplete = (can(user, 'valuation.complete') || can(user, 'valuation.account_review')) && status === 'Registered'

  const actions = []
  if (canBegin) actions.push({ key: 'begin', label: 'Start Assessment', endpoint: 'begin', panel: null })
  if (canSubmit) actions.push({ key: 'submit', label: 'Submit Assessment to Manager', endpoint: 'submit', panel: 'submit' })
  if (canReassign) actions.push({ key: 'reassign', label: 'Assign / Reassign Officer', endpoint: 'assign', panel: 'reassign' })
  if (canManagerReview) actions.push({ key: 'review', label: 'Review Assessment', endpoint: 'review', panel: 'review' })
  if (canACDecide) actions.push({ key: 'decide', label: 'AC Decision', endpoint: 'decide', panel: 'decide' })
  if (canAccountReview) actions.push({ key: 'account-review', label: 'Account & Record Final Review', endpoint: 'account-review', panel: 'account-review' })
  if (canProcess && !v.discovery_id) actions.push({ key: 'processing', label: 'Record Processing Result', endpoint: 'processing', panel: 'processing' })
  if (canComplete) actions.push({ key: 'complete', label: 'Complete Valuation', endpoint: 'complete', panel: null })

  const rows = v.descriptions || []

  return (
    <Screen scroll refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={theme.colors.primary} />}>
      <OfflineReadStatus status={readStatus} syncedAt={syncedAt} />
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 }}>
          <Text style={s.ref}>{v.valuation_reference}</Text>
          <Badge status={v.status} />
        </View>
        <InfoRow label="Type" value={String(v.valuation_type || '').replace('_', ' ')} />
        <InfoRow label="Prepared by" value={v.officer?.full_name || v.valuation_officer || '—'} />
        <InfoRow label="Submitted" value={fd(v.submitted_at)} />
        {v.registration_status ? <InfoRow label="Registration status" value={v.registration_status} /> : null}
      </Card>

      <Text style={s.sectionLabel}>PROPERTY / TAXPAYER</Text>
      <Card>
        <InfoRow label="Document #" value={v.document_number || '—'} bold />
        <InfoRow label="Property ID" value={v.property_id || '—'} bold />
        <InfoRow label="Bill ID" value={v.bill_id || '—'} />
        <InfoRow label="TIN" value={v.tin || '—'} />
        <InfoRow label="Owner" value={v.owner_name || '—'} />
        <InfoRow label="Owner contact" value={v.owner_contact || '—'} />
        <InfoRow label="Address" value={v.property_address || '—'} />
        <InfoRow label="Classification" value={v.property_classification || '—'} />
        <InfoRow label="Land dimensions" value={v.land_dimensions || '—'} />
        <InfoRow label="Building specs" value={v.building_specs || '—'} />
        <InfoRow label="Year constructed" value={v.construction_year || '—'} />
        <InfoRow label="Condition" value={v.condition || '—'} />
        <InfoRow label="Assessment date" value={fd(v.assessment_date)} />
      </Card>

      <Text style={s.sectionLabel}>DESCRIPTIONS & VALUATION SHEET</Text>
      <Card>
        {rows.length === 0 ? <Text style={{ color: theme.colors.textMuted, fontSize: 13 }}>No description rows recorded.</Text> : rows.map((r, i) => (
          <View key={i} style={i > 0 ? s.rowSep : null}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
              <Text style={s.rowNo}>No. {i + 1}</Text>
              <Text style={s.rowVal}>{fm(r.value)}</Text>
            </View>
            <Text style={s.rowDesc}>{r.description || '—'}</Text>
            <Text style={s.rowMeta}>
              {[r.level ? `Level ${r.level}` : null, r.area_sqft ? `${r.area_sqft} sq ft` : null, r.tar ? (isLandRow(r) ? `Value / lot ${r.tar}` : `TAR ${r.tar}`) : null, r.quantity ? `Qty / Lots ${r.quantity}` : null, r.building_age ? `${r.building_age} yrs` : null, r.depreciation_pct != null ? `${r.depreciation_pct}% depr` : null].filter(Boolean).join(' · ')}
            </Text>
          </View>
        ))}
        <View style={s.totalRow}>
          <Text style={s.totalLabel}>Total value</Text>
          <Text style={s.totalValue}>{fm(v.total_property_value)}</Text>
        </View>
      </Card>

      <Text style={s.sectionLabel}>MONEY & TAX</Text>
      <Card>
        {/* Each figure keeps its own source: a declared value is never shown as
            an assessment, and a reassessment is labelled as one. */}
        <InfoRow label="Declared value" value={fm(v.declared_value)} />
        {v.reassessed_value != null ? <InfoRow label="Reassessed value" value={fm(v.reassessed_value)} bold /> : null}
        <InfoRow
          label={v.valuation_type === 'reassessment' ? 'Reassessed (assessed)' : 'Assessed value'}
          value={v.assessed_value != null ? fm(v.assessed_value) : 'Not yet assessed'}
          bold
        />
        <InfoRow label="Applicable tax rate" value={v.applicable_tax_rate != null ? `${v.applicable_tax_rate}%` : '—'} />
        <InfoRow label="Annual tax" value={fm(v.annual_tax)} bold />
        <InfoRow label="Other amounts" value={fm(v.other_amounts)} />
        <InfoRow label="Total tax payable" value={fm(v.total_tax_payable)} bold />
      </Card>

      {v.gps_coordinate && (
        <>
          <Text style={s.sectionLabel}>GPS</Text>
          <Card>
            <InfoRow label="Coordinates" value={v.gps_coordinate} />
            {v.gps_accuracy != null ? <InfoRow label="Accuracy" value={`±${v.gps_accuracy}m`} /> : null}
            <View style={{ marginTop: 6 }}>
              <Badge
                status={v.gps_verified === 'yes' ? 'Registered' : 'Returned for Correction'}
                label={v.gps_verified === 'yes' ? 'Official Property GPS' : 'Official GPS Not Yet Certified'}
              />
            </View>
          </Card>
        </>
      )}

      {v.remarks && (
        <>
          <Text style={s.sectionLabel}>REMARKS</Text>
          <Card><Text style={{ color: theme.colors.text, fontSize: 13, lineHeight: 19 }}>{v.remarks}</Text></Card>
        </>
      )}

      {(v.manager_remarks || v.ac_remarks) && (
        <>
          <Text style={s.sectionLabel}>REVIEW NOTES</Text>
          <Card>
            {v.manager_remarks ? <InfoRow label="Manager" value={v.manager_remarks} /> : null}
            {v.ac_remarks ? <InfoRow label="Assistant Commissioner" value={v.ac_remarks} /> : null}
          </Card>
        </>
      )}

      {v.reviews && v.reviews.length > 0 && (
        <>
          <Text style={s.sectionLabel}>REVIEW TRAIL</Text>
          {v.reviews.map((r, i) => (
            <Card key={i}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                <Text style={s.rowNo}>{String(r.stage || '').toUpperCase()} · {r.decision}</Text>
                <Badge status={r.decision} label={r.decision} />
              </View>
              <Text style={s.rowMeta}>{r.reviewer?.full_name || r.performed_by || '—'} · {r.created_at ? new Date(r.created_at).toLocaleString() : ''}</Text>
            </Card>
          ))}
        </>
      )}

      {actions.length > 0 && readStatus !== 'cached' && readStatus !== 'expired' && (
        <>
          <Text style={s.sectionLabel}>ACTIONS</Text>
          <View style={{ paddingHorizontal: 16, gap: 10 }}>
            {actions.map((a) => (
              <Btn
                key={a.key}
                label={a.label}
                disabled={busy !== ''}
                onPress={() => (a.panel ? openPanel(a.key) : runAction(a.endpoint, {}))}
              />
            ))}
          </View>
        </>
      )}

      {readStatus !== 'cached' && readStatus !== 'expired' && panel === 'submit' && (
        <Card style={{ marginTop: 14 }}>
          <Text style={s.panelTitle}>Submit assessment to manager</Text>
          <Text style={s.panelSub}>Record the assessed value and recommended annual tax for the property.</Text>
          <Field label="Assessed value (US$)">
            <Input value={input1} onChangeText={setInput1} keyboardType="decimal-pad" placeholder="0.00" />
          </Field>
          <Field label="Recommended annual tax (US$)">
            <Input value={input2} onChangeText={setInput2} keyboardType="decimal-pad" placeholder="0.00" />
          </Field>
          <Field label="Remarks">
            <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 64, textAlignVertical: 'top' }} placeholder="Note to the manager…" />
          </Field>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
            <Btn label="Submit" style={{ flex: 1 }} loading={busy === 'submit'}
              disabled={!(Number(input1) > 0) || !(Number(input2) > 0)}
              onPress={() => runAction('submit', { assessed_value: Number(input1), annual_tax: Number(input2), remarks: remarks.trim() || undefined })} />
          </View>
        </Card>
      )}

      {readStatus !== 'cached' && readStatus !== 'expired' && panel === 'reassign' && (
        <Card style={{ marginTop: 14 }}>
          <Text style={s.panelTitle}>Assign / reassign officer</Text>
          <Text style={s.panelSub}>Choose an eligible Valuation Officer (eligibility is validated by the server).</Text>
          <View style={{ paddingHorizontal: 16, marginBottom: 14 }}>
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
              {officers === null ? <ActivityIndicator color={theme.colors.primary} />
                : officers.map((o) => {
                  const active = String(o.id) === input1
                  return (
                    <TouchableOpacity key={o.id} hitSlop={3} onPress={() => setInput1(active ? '' : String(o.id))}
                      style={[s.officerChip, active && { backgroundColor: theme.colors.primary, borderColor: theme.colors.primary }]}>
                      <Text style={[s.officerChipText, active && { color: '#fff' }]}>{o.full_name || `#${o.id}`}</Text>
                    </TouchableOpacity>
                  )
                })}
            </View>
          </View>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
            <Btn label="Assign" style={{ flex: 1 }} loading={busy === 'assign'} disabled={!input1}
              onPress={() => runAction('assign', { officer_id: Number(input1) })} />
          </View>
        </Card>
      )}

      {readStatus !== 'cached' && readStatus !== 'expired' && panel === 'review' && (
        <Card style={{ marginTop: 14 }}>
          <Text style={s.panelTitle}>Manager review</Text>
          <View style={{ marginBottom: 10, paddingHorizontal: 16 }}>
            <ChipRow options={['confirm_ac', 'return']} value={decision} onChange={setDecision}
              colors={{ confirm_ac: theme.colors.success, return: '#D97706' }} />
          </View>
          <Field label="Remarks">
            <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 64, textAlignVertical: 'top' }} placeholder="Comment for the officer / AC…" />
          </Field>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
            <Btn label={decision === 'confirm_ac' ? 'Confirm & Forward to AC' : 'Return to Officer'} style={{ flex: 1 }} loading={busy === 'review'} onPress={() => runAction('review', { decision, remarks: remarks.trim() || undefined })} />
          </View>
        </Card>
      )}

      {readStatus !== 'cached' && readStatus !== 'expired' && panel === 'decide' && (
        <Card style={{ marginTop: 14 }}>
          <Text style={s.panelTitle}>Assistant Commissioner decision</Text>
          <View style={{ marginBottom: 10, paddingHorizontal: 16 }}>
            <ChipRow options={['approve', 'reject', 'return']} value={decision} onChange={setDecision}
              colors={{ approve: theme.colors.success, reject: theme.colors.danger, return: '#D97706' }} />
          </View>
          <Field label="Remarks">
            <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 64, textAlignVertical: 'top' }} placeholder="Reason for decision…" />
          </Field>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
            <Btn tone={decision === 'reject' ? 'danger' : decision === 'approve' ? 'success' : 'ghost'} label={decision === 'approve' ? 'Approve' : decision === 'reject' ? 'Reject' : 'Return'} style={{ flex: 1 }} loading={busy === 'decide'} onPress={() => runAction('decide', { decision, remarks: remarks.trim() || undefined })} />
          </View>
        </Card>
      )}

      {readStatus !== 'cached' && readStatus !== 'expired' && panel === 'account-review' && (
        <Card style={{ marginTop: 14 }}>
          <Text style={s.panelTitle}>Account &amp; Record final review</Text>
          <Text style={s.panelSub}>Approve and assign an officer to process the registration, or refer back to Valuation.</Text>
          <View style={{ marginBottom: 10, paddingHorizontal: 16 }}>
            <ChipRow options={['approve', 'return']} value={decision} onChange={setDecision}
              colors={{ approve: theme.colors.success, return: '#D97706' }} />
          </View>
          <View style={{ paddingHorizontal: 16, marginBottom: 14 }}>
            {decision === 'approve' && (
              <>
                <Text style={s.panelSub}>Select the Account & Record Officer to assign.</Text>
                <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
                  {officers === null ? <ActivityIndicator color={theme.colors.primary} />
                    : officers.map((o) => {
                      const active = String(o.id) === input1
                      return (
                        <TouchableOpacity key={o.id} hitSlop={3} onPress={() => setInput1(active ? '' : String(o.id))}
                          style={[s.officerChip, active && { backgroundColor: theme.colors.primary, borderColor: theme.colors.primary }]}>
                          <Text style={[s.officerChipText, active && { color: '#fff' }]}>{o.full_name || `#${o.id}`}</Text>
                        </TouchableOpacity>
                      )
                    })}
                </View>
              </>
            )}
          </View>
          <Field label="Remarks">
            <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 64, textAlignVertical: 'top' }} placeholder="Decision note…" />
          </Field>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
            <Btn label="Confirm" style={{ flex: 1 }} loading={busy === 'account-review'}
              disabled={!decision || (decision === 'approve' && !input1)}
              onPress={() => runAction('account-review', { decision, officer_id: decision === 'approve' ? Number(input1) : undefined, remarks: remarks.trim() || undefined })} />
          </View>
        </Card>
      )}

      {readStatus !== 'cached' && readStatus !== 'expired' && panel === 'processing' && (
        <Card style={{ marginTop: 14 }}>
          <Text style={s.panelTitle}>Property processing result</Text>
          <Text style={s.panelSub}>Record the identifiers assigned by the source system. A valuation that comes from a discovery is processed on the discovery itself so the property bill record is written there.</Text>
          <Field label="Property ID (from source)">
            <Input value={input1} onChangeText={setInput1} placeholder="Property ID (source)" />
          </Field>
          <Field label="Document # (from source)">
            <Input value={input2} onChangeText={setInput2} placeholder="Document # (source)" />
          </Field>
          <Field label="Remarks">
            <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 64, textAlignVertical: 'top' }} placeholder="Optional…" />
          </Field>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
            <Btn label="Record" style={{ flex: 1 }} loading={busy === 'processing'}
              onPress={() => runAction('processing', { property_id: input1.trim() || undefined, document_number: input2.trim() || undefined, remarks: remarks.trim() || undefined })} />
          </View>
        </Card>
      )}
    </Screen>
  )
}

const s = StyleSheet.create({
  ref: { fontSize: 16, fontWeight: '800', color: theme.colors.navy, flexShrink: 1 },
  sectionLabel: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, letterSpacing: 0.8, marginLeft: 16, marginTop: 20, marginBottom: 10 },
  rowSep: { borderTopWidth: 1, borderTopColor: theme.colors.border, paddingTop: 10, marginTop: 10 },
  rowNo: { fontSize: 13, fontWeight: '800', color: theme.colors.navy },
  rowVal: { fontSize: 13, fontWeight: '800', color: theme.colors.navy },
  rowDesc: { fontSize: 13, color: theme.colors.text, marginTop: 4 },
  rowMeta: { fontSize: 11, color: theme.colors.textLight, marginTop: 3 },
  totalRow: { flexDirection: 'row', justifyContent: 'space-between', borderTopWidth: 1, borderTopColor: theme.colors.primary, marginTop: 12, paddingTop: 12 },
  totalLabel: { fontSize: 14, fontWeight: '800', color: theme.colors.navy },
  totalValue: { fontSize: 15, fontWeight: '900', color: theme.colors.primary },
  panelTitle: { fontSize: 14, fontWeight: '800', color: theme.colors.navy, marginBottom: 12 },
  officerChip: { borderRadius: 20, paddingHorizontal: 14, paddingVertical: 9, minHeight: 36, alignItems: 'center', justifyContent: 'center', borderWidth: 1.5, borderColor: theme.colors.border, backgroundColor: '#fff' },
  officerChipText: { fontSize: 12, fontWeight: '700', color: theme.colors.navy },
})