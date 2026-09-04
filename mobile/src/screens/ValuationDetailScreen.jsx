import { useState, useCallback } from 'react'
import { View, Text, StyleSheet, Alert, ActivityIndicator, RefreshControl } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import api from '../api'
import { useAuth } from '../auth'
import { can, serverMessage, isOnlineRejection } from '../rbac'
import { theme } from '../theme'
import { Screen, Card, InfoRow, Badge, Field, Input, Btn, ChipRow, Empty } from '../components'

const fm = (v) => (v === null || v === undefined ? '—' : 'US$ ' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
const fd = (v) => (v ? String(v).slice(0, 10) : '—')

export default function ValuationDetailScreen({ route }) {
  const { user } = useAuth()
  const { valuationId } = route.params || {}
  const [v, setV] = useState(null)
  const [err, setErr] = useState('')
  const [refreshing, setRefreshing] = useState(false)
  const [busy, setBusy] = useState('')
  const [panel, setPanel] = useState(null)
  const [decision, setDecision] = useState('forward_ac')
  const [remarks, setRemarks] = useState('')

  const load = useCallback(async () => {
    try {
      const r = await api.get(`/valuations/${valuationId}`)
      setV(r.data.data)
      setErr('')
    } catch (e) { setErr(serverMessage(e, 'Could not load the valuation record.')) }
  }, [valuationId])

  useFocusEffect(useCallback(() => { load() }, [load]))
  const refresh = async () => { setRefreshing(true); await load(); setRefreshing(false) }

  const runAction = async (endpoint, payload) => {
    setBusy(endpoint)
    try {
      await api.post(`/valuations/${valuationId}/${endpoint}`, payload)
      setPanel(null); setRemarks('')
      Alert.alert('Done', 'Valuation updated.')
      load()
    } catch (e) {
      if (isOnlineRejection(e)) Alert.alert('Action failed', serverMessage(e, 'The server rejected the action.'))
      else Alert.alert('Offline', 'Connect to the network and retry.')
    } finally { setBusy('') }
  }

  if (err && !v) {
    return (
      <Screen>
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

  const isOfficer = v.valuation_officer_id === user?.id
  const canSubmit = can(user, 'valuation.submit') && (['Draft', 'Returned'].includes(v.status))
  const canManagerReview = (can(user, 'valuation.review') || can(user, 'valuation.approve')) && ['Submitted', 'Manager Review', 'AC Approval'].includes(v.status) && !isOfficer
  const canACDecide = can(user, 'valuation.approve') && v.status === 'AC Approval' && !isOfficer

  const submitPayload = () => {
    const assessed = Number(v.reassessed_value ?? v.assessed_value) || 0
    const rate = Number(v.applicable_tax_rate) || 0
    const annual = rate > 0 ? round2(assessed * (rate / 100)) : Number(v.annual_tax) || 0
    const other = Number(v.other_amounts) || 0
    return {
      assessed_value: assessed,
      annual_tax: annual,
      applicable_tax_rate: rate || undefined,
      other_amounts: other || undefined,
      remarks: (v.remarks || '').trim() || undefined,
    }
  }
  const round2 = (n) => Math.round(n * 100) / 100

  const actions = []
  if (canSubmit) actions.push({ key: 'submit', label: 'Submit for Manager Review', endpoint: 'submit', panel: null })
  if (canManagerReview) actions.push({ key: 'review', label: 'Manager Review', endpoint: 'review', panel: 'review' })
  if (canACDecide) actions.push({ key: 'decide', label: 'AC Decision', endpoint: 'decide', panel: 'decide' })

  const rows = v.descriptions || []

  return (
    <Screen scroll refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={theme.colors.primary} />}>
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 }}>
          <Text style={s.ref}>{v.valuation_reference}</Text>
          <Badge status={v.status} />
        </View>
        <InfoRow label="Type" value={String(v.valuation_type || '').replace('_', ' ')} />
        <InfoRow label="Prepared by" value={v.officer?.full_name || v.valuation_officer || '—'} />
        <InfoRow label="Submitted" value={fd(v.submitted_at)} />
        {v.litas_processing_status ? <InfoRow label="LITAS processing" value={v.litas_processing_status} /> : null}
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
              {[r.level ? `Level ${r.level}` : null, r.area_sqft ? `${r.area_sqft} sq ft` : null, r.tar ? `TAR ${r.tar}` : null, r.quantity ? `Qty ${r.quantity}` : null, r.building_age ? `${r.building_age} yrs` : null, r.depreciation_pct != null ? `${r.depreciation_pct}% depr` : null].filter(Boolean).join(' · ')}
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
        <InfoRow label="Declared value" value={fm(v.declared_value)} />
        <InfoRow label="Assessed value" value={fm(v.assessed_value)} bold />
        <InfoRow label="Applicable tax rate" value={v.applicable_tax_rate != null ? `${v.applicable_tax_rate}%` : '—'} />
        <InfoRow label="Annual tax" value={fm(v.annual_tax)} bold />
        <InfoRow label="Other amounts" value={fm(v.other_amounts)} />
        <InfoRow label="Total tax payable" value={fm(v.total_tax_payable)} bold />
      </Card>

      {v.gps_coordinate && (
        <>
          <Text style={s.sectionLabel}>GPS</Text>
          <Card><InfoRow label="Coordinates" value={v.gps_coordinate} />{v.gps_accuracy != null ? <InfoRow label="Accuracy" value={`±${v.gps_accuracy}m`} /> : null}</Card>
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

      {actions.length > 0 && (
        <>
          <Text style={s.sectionLabel}>ACTIONS</Text>
          <View style={{ paddingHorizontal: 16, gap: 10 }}>
            {actions.map((a) => (
              <Btn
                key={a.key}
                label={a.label}
                disabled={busy !== ''}
                onPress={() => (a.panel ? (setPanel(a.key), setDecision(a.key === 'review' ? 'forward_ac' : 'approve'), setRemarks('')) : runAction(a.endpoint, a.panel === null && a.key === 'submit' ? submitPayload() : {}))}
              />
            ))}
          </View>
        </>
      )}

      {panel === 'review' && (
        <Card style={{ marginTop: 14 }}>
          <Text style={s.panelTitle}>Manager review</Text>
          <View style={{ marginBottom: 10, paddingHorizontal: 16 }}>
            <ChipRow options={['forward_ac', 'return']} value={decision} onChange={setDecision} />
          </View>
          <Field label="Remarks">
            <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 64, textAlignVertical: 'top' }} placeholder="Comment for the officer / AC…" />
          </Field>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
            <Btn label={decision === 'forward_ac' ? 'Forward to AC' : 'Return to Officer'} style={{ flex: 1 }} loading={busy === 'review'} onPress={() => runAction('review', { decision, remarks: remarks.trim() || undefined })} />
          </View>
        </Card>
      )}

      {panel === 'decide' && (
        <Card style={{ marginTop: 14 }}>
          <Text style={s.panelTitle}>Assistant Commissioner decision</Text>
          <View style={{ marginBottom: 10, paddingHorizontal: 16 }}>
            <ChipRow options={['approve', 'reject']} value={decision} onChange={setDecision} />
          </View>
          <Field label="Remarks">
            <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 64, textAlignVertical: 'top' }} placeholder="Reason for decision…" />
          </Field>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
            <Btn tone={decision === 'reject' ? 'danger' : 'success'} label={decision === 'approve' ? 'Approve' : 'Reject'} style={{ flex: 1 }} loading={busy === 'decide'} onPress={() => runAction('decide', { decision, remarks: remarks.trim() || undefined })} />
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
})