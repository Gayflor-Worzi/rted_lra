import { useState, useCallback } from 'react'
import { View, Text, TouchableOpacity, StyleSheet, Alert, ActivityIndicator, RefreshControl, Image, Modal } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import api from '../api'
import { useAuth } from '../auth'
import { fetchOfflineRead } from '../offline'
import { can, serverMessage, isOnlineRejection } from '../rbac'
import { useOfflineDraft } from '../useOfflineDraft'
import { theme } from '../theme'
import { Screen, Card, InfoRow, Badge, Field, Input, Btn, ChipRow, Empty, OfflineReadStatus } from '../components'

const fd = (v) => (v ? String(v).slice(0, 10) : '—')
const fm = (v) => (v === null || v === undefined ? '—' : 'US$ ' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))

const coordOf = (d) => {
  if (d?.gps_lat && d?.gps_lng) return `${Number(d.gps_lat).toFixed(5)}, ${Number(d.gps_lng).toFixed(5)}`
  if (typeof d?.gps_coordinate === 'string' && d.gps_coordinate.includes(',')) {
    const [lat, lng] = d.gps_coordinate.split(',')
    return `${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)}`
  }
  return d?.gps_coordinate || '—'
}

const blobToDataUri = (blob) =>
  new Promise((resolve, reject) => {
    if (blob?.data) {
      const base64 = typeof blob.data === 'string' ? blob.data : (blob.data.base64 || blob.data)
      if (base64) return resolve(`data:${blob.type || 'image/jpeg'};base64,${base64}`)
    }
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.onerror = reject
    reader.readAsDataURL(blob)
  })

export default function DiscoveryDetailScreen({ route, navigation }) {
  const { user, canUseOfflineCache } = useAuth()
  const { discoveryId } = route.params || {}
  const [d, setD] = useState(null)
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
  const [input3, setInput3] = useState('')
  const [input4, setInput4] = useState('')
  const [remarks, setRemarks] = useState('')
  const [photoUris, setPhotoUris] = useState({})
  const [activePhoto, setActivePhoto] = useState(null)
  const { ready: actionDraftReady, error: actionDraftError, clear: clearActionDraft } = useOfflineDraft(
    `discovery-action:${discoveryId}`,
    user?.id,
    { panel, decision, input1, input2, input3, input4, remarks },
    (draft) => {
      setPanel(draft.panel ?? null)
      setDecision(draft.decision ?? '')
      setInput1(draft.input1 ?? '')
      setInput2(draft.input2 ?? '')
      setInput3(draft.input3 ?? '')
      setInput4(draft.input4 ?? '')
      setRemarks(draft.remarks ?? '')
    },
    !!panel && Boolean(decision || input1 || input2 || input3 || input4 || remarks),
  )

  const loadPhotos = useCallback(async (photos) => {
    if (!photos || photos.length === 0) return
    const want = photos.map((p) => p.id).filter((id) => !(id in photoUris))
    if (want.length === 0) return
    const next = { ...photoUris }
    await Promise.all(want.map(async (id) => {
      try {
        // Property photographs live on the discovery's evidence route.
        const url = `/discoveries/${discoveryId}/evidence/${id}/file`
        const r = await api.get(url, { responseType: 'blob' })
        next[id] = await blobToDataUri(r.data)
      } catch { next[id] = null }
    }))
    setPhotoUris(next)
  }, [photoUris, discoveryId])

  const load = useCallback(async () => {
    try {
      const result = await fetchOfflineRead({
        module: 'discoveries',
        cacheKey: `${user?.role}:detail:${discoveryId}`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get(`/discoveries/${discoveryId}`),
        select: (response) => response.data?.data,
        cacheCondition: (record) => String(record?.discovered_by) === String(user?.id),
      })
      if (result.status === 'expired') {
        setD(null)
        setReadStatus(result.status)
        setSyncedAt(result.syncedAt)
        setErr('Offline discovery data has expired. Connect to refresh.')
        return
      }
      const data = result.data
      setD(data)
      setReadStatus(result.status)
      setSyncedAt(result.syncedAt)
      if (result.status !== 'cached') {
        const evidencePhotos = (data?.evidence || []).filter((e) => e.evidence_type === 'PROPERTY_PHOTO')
        loadPhotos(evidencePhotos)
      }
      setErr('')
    } catch (e) {
      setReadStatus(null)
      setErr(serverMessage(e, 'Could not load the discovery record.'))
    }
  }, [discoveryId, loadPhotos, user, canUseOfflineCache])

  useFocusEffect(useCallback(() => { load() }, [load]))
  const refresh = async () => { setRefreshing(true); await load(); setRefreshing(false) }

  if (err && !d) {
    return (
      <Screen>
        <OfflineReadStatus status={readStatus} syncedAt={syncedAt} />
        <Card style={{ marginTop: 20 }}>
          <Text style={{ color: theme.colors.danger, fontWeight: '700', marginBottom: 8 }}>⚠ Unable to load discovery</Text>
          <Text style={{ color: theme.colors.textMuted, fontSize: 13, marginBottom: 12 }}>{err}</Text>
          <Btn label="⟳ Retry" onPress={refresh} loading={refreshing} />
        </Card>
      </Screen>
    )
  }
  if (!d) {
    return <Screen><ActivityIndicator color={theme.colors.primary} style={{ marginTop: 60 }} /></Screen>
  }

  const status = d.status

  // Property photographs and documentary evidence are counted from the rows
  // themselves. The `evidence_photos` table belongs to valuations and visits,
  // not to a discovery, which is what made this screen report zero
  // photographs while three PROPERTY_PHOTO rows existed.
  const allEvidence = d.evidence || []
  const propertyPhotos = allEvidence
    .filter((e) => e.evidence_type === 'PROPERTY_PHOTO')
    .sort((a, b) => (a.sequence_no ?? 0) - (b.sequence_no ?? 0))
  const supportingEvidence = allEvidence.filter((e) => e.evidence_type !== 'PROPERTY_PHOTO')

  const runAction = async (kind, endpoint, payload) => {
    if (!actionDraftReady) return Alert.alert('Please wait', 'The encrypted action draft is still loading.')
    if (readStatus === 'cached' || readStatus === 'expired') {
      return Alert.alert('Connect to submit', 'This saved action form can be edited offline, but RETD must authorize the decision online. Your encrypted draft is preserved.')
    }
    setBusy(kind)
    try {
      const r = await api.post(`/discoveries/${discoveryId}/${endpoint}`, payload)
      setPanel(null); setDecision(''); setInput1(''); setInput2(''); setInput3(''); setInput4(''); setRemarks('')
      const billed = r?.data?.data?.bill
      let message = billed
        ? `Discovery updated and bill ${billed.document_number} logged for you (${billed.tax_period ?? '—'}).`
        : 'Discovery updated.'
      try {
        await clearActionDraft()
      } catch (error) {
        message += ` The action succeeded, but its local draft could not be cleared: ${error.message}`
      }
      Alert.alert('Done', message)
      load()
    } catch (e) {
      if (isOnlineRejection(e)) Alert.alert('Action failed', serverMessage(e, 'The server rejected the action.'))
      else Alert.alert('Offline', 'Skipped — connect to the network and retry.')
    } finally { setBusy('') }
  }

  const loadOfficers = async () => {
    if (officers) return
    try { setOfficers((await api.get('/lookup/supervisors')).data?.data || []) }
    catch { setOfficers([]) }
  }

  const openPanel = (key, initialDecision = '') => {
    if (!actionDraftReady || readStatus === 'cached' || readStatus === 'expired') return
    setPanel(key); setDecision(initialDecision); setInput1(''); setInput2(''); setInput3(''); setRemarks('')
    // Registration prefills the tax from the approved assessment: that figure is
    // authoritative and the API rejects a contradicting entry.
    setInput4(key === 'registration' && d?.valuation?.annual_tax != null ? String(d.valuation.annual_tax) : '')
    if (key === 'acct-review' || key === 'val-review') loadOfficers()
  }

  const actions = []
  if (status === 'Draft' && can(user, 'discovery.create')) actions.push({ key: 'submit', label: 'Submit for Classification & Routing', endpoint: 'submit', payload: {}, panel: null })
  if (status === 'Returned for Correction' && can(user, 'discovery.create')) actions.push({ key: 'resubmit', label: 'Resubmit after Correction', endpoint: 'resubmit', payload: {}, panel: null })
  if (['Rejected', 'Returned for Correction'].includes(status) && can(user, 'discovery.reopen')) actions.push({ key: 'reopen', label: 'Reopen for Correction', endpoint: 'reopen', payload: {}, panel: null })
  if (can(user, 'discovery.aarm_review') && ['Awaiting Account Manager Review', 'Approved'].includes(status)) {
    if (can(user, 'discovery.aarm_approve')) actions.push({ key: 'acct-review', decision: 'approve', label: 'Approve & assign A&R officer', endpoint: 'acct-review', panel: 'acct-review' })
    if (can(user, 'discovery.refer_to_valuation')) actions.push({ key: 'acct-review', decision: 'refer_to_valuation', label: 'Refer to Valuation', endpoint: 'acct-review', panel: 'acct-review' })
    actions.push({ key: 'acct-review', decision: 'return', label: 'Return for correction', endpoint: 'acct-review', panel: 'acct-review' })
    actions.push({ key: 'acct-review', decision: 'reject', label: 'Reject', endpoint: 'acct-review', panel: 'acct-review', danger: true })
  }
  if (can(user, 'discovery.route_to_valuation') && ['Awaiting Valuation', 'Referred to Valuation'].includes(status)) actions.push({ key: 'val-review', label: 'Valuation Manager Decision', endpoint: 'val-review', panel: 'val-review' })
  if (status === 'Assigned to Account & Record' && (can(user, 'discovery.property_registration') || can(user, 'discovery.aarm_review'))) actions.push({ key: 'registration', label: 'Record Processing Result', endpoint: 'account-processing', panel: 'registration' })
  if (status === 'Registered' && (can(user, 'discovery.property_registration') || can(user, 'discovery.review') || can(user, 'discovery.aarm_review'))) actions.push({ key: 'complete', label: 'Complete Discovery', endpoint: 'complete', payload: {}, panel: null })

  const acctOptions = [...(can(user, 'discovery.aarm_approve') ? ['approve'] : []), ...(can(user, 'discovery.refer_to_valuation') ? ['refer_to_valuation'] : []), 'return', 'reject']

  const officerPick = () => (
    <View style={{ paddingHorizontal: 16, marginBottom: 14 }}>
      <Text style={s.panelSub}>
        {officers === null ? 'Loading active staff…'
          : officers.length === 0 ? 'No staff found to assign. Contact an administrator.'
          : 'Select the officer to assign (eligibility is validated by the server).'}
      </Text>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        {officers === null ? <ActivityIndicator color={theme.colors.primary} /> : officers.map((o) => {
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
  )

  return (
    <Screen scroll refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={theme.colors.primary} />}>
      <OfflineReadStatus status={readStatus} syncedAt={syncedAt} />
      {actionDraftError ? <Text style={{ color: theme.colors.danger, marginHorizontal: 16 }}>{actionDraftError}</Text> : null}
      {!actionDraftReady ? <Text style={{ color: theme.colors.textMuted, marginHorizontal: 16 }}>Restoring encrypted action draft…</Text> : null}
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 }}>
          <Text style={s.ref}>{d.discovery_reference}</Text>
          <Badge status={status} label={status} />
        </View>
        {d.decision_path ? <InfoRow label="Decision path" value={d.decision_path} /> : null}
        <InfoRow label="Discovery date" value={fd(d.discovery_date)} />
        <InfoRow label="Recorded by" value={d.discovered_by || '—'} />
        <InfoRow label="Created" value={d.created_at ? new Date(d.created_at).toLocaleDateString() : '—'} />
      </Card>

      <Text style={s.sectionLabel}>PROPERTY</Text>
      <Card>
        <InfoRow label="Reference / address" value={d.property_address || '—'} bold />
        <InfoRow label="Street" value={d.street || '—'} />
        <InfoRow label="House number" value={d.house_number || '—'} />
        <InfoRow label="City / Town" value={d.city_town || '—'} />
        <InfoRow label="Community" value={d.community || '—'} />
        <InfoRow label="District" value={d.district || '—'} />
        <InfoRow label="County" value={d.county || '—'} />
      </Card>
      <Card>
        <InfoRow label="Classification" value={d.property_classification || '—'} />
        <InfoRow label="Property type" value={d.property_type || '—'} />
        <InfoRow label="Occupancy / use" value={d.occupancy_use || '—'} />
        {d.description ? <InfoRow label="Description" value={d.description} /> : null}
      </Card>

      <Text style={s.sectionLabel}>OWNER / OCCUPANT</Text>
      <Card>
        <InfoRow label="Owner name" value={d.owner_name || '—'} />
        <InfoRow label="Owner contact" value={d.owner_contact || '—'} />
        <InfoRow label="TIN" value={d.tin || '—'} />
      </Card>

      <Text style={s.sectionLabel}>VALUE DECLARATION</Text>
      <Card>
        <InfoRow label="Declaration" value={d.value_declaration_label || d.value_declaration_type || 'Not declared'} bold />
        <InfoRow label="Declared value" value={fm(d.declared_value)} />
        {d.declared_assessed_value != null ? <InfoRow label="Declared / assessed" value={fm(d.declared_assessed_value)} /> : null}
        {d.documentary_value != null ? <InfoRow label="Documentary value" value={fm(d.documentary_value)} /> : null}
        {d.official_assessed_value != null
          ? <InfoRow label="Official assessed value" value={fm(d.official_assessed_value)} bold />
          : <InfoRow label="Official assessed value" value="Not yet assessed" />}
      </Card>

      <Text style={s.sectionLabel}>SYSTEM REFERENCES</Text>
      <Card>
        <InfoRow label="Property ID (source)" value={d.property_id || '—'} bold />
        <InfoRow label="Document # (source)" value={d.document_number || '—'} bold />
        {d.processed_by ? <InfoRow label="Processed by" value={d.processed_by} /> : null}
        {d.processed_at ? <InfoRow label="Processed at" value={fd(d.processed_at)} /> : null}
      </Card>

      <Text style={s.sectionLabel}>GPS</Text>
      <Card>
        <InfoRow label="Coordinates" value={coordOf(d)} />
        {d.gps_accuracy != null ? <InfoRow label="Accuracy" value={`±${d.gps_accuracy}m`} /> : null}
        {/* A field-collected coordinate is a candidate until it is certified. */}
        <View style={{ marginTop: 6 }}>
          <Badge
            status={d.gps_verified === 'yes' ? 'Registered' : 'Returned for Correction'}
            label={d.gps_verified === 'yes' ? 'Official Property GPS' : 'Official GPS Not Yet Certified'}
          />
        </View>
      </Card>

      {d.remarks ? (
        <>
          <Text style={s.sectionLabel}>REMARKS</Text>
          <Card><Text style={{ color: theme.colors.text, fontSize: 13, lineHeight: 19 }}>{d.remarks}</Text></Card>
        </>
      ) : null}

      {(d.manager_remarks || d.ac_remarks || d.classification_decision) && (
        <>
          <Text style={s.sectionLabel}>REVIEWS & DECISIONS</Text>
          <Card>
            {d.manager_remarks ? <InfoRow label="Manager remarks" value={d.manager_remarks} /> : null}
            {d.classification_decision ? <InfoRow label="Classification decision" value={d.classification_decision} /> : null}
            {d.ac_decision ? <InfoRow label="AC decision" value={d.ac_decision} bold /> : null}
            {d.ac_remarks ? <InfoRow label="AC remarks" value={d.ac_remarks} /> : null}
          </Card>
        </>
      )}

      {propertyPhotos.length > 0 && (
        <>
          <Text style={s.sectionLabel}>PROPERTY PHOTOS ({propertyPhotos.length})</Text>
          <View style={{ paddingHorizontal: 16 }}>
            {/* The property photograph is the subject, so it leads. */}
            <TouchableOpacity
              onPress={() => setActivePhoto(propertyPhotos[0])}
              accessibilityRole="image"
              accessibilityLabel={`Property photo ${propertyPhotos[0].sequence_no ?? ''}`}
            >
              {photoUris[propertyPhotos[0].id]
                ? <Image
                    source={{ uri: photoUris[propertyPhotos[0].id] }}
                    style={s.heroPhoto}
                    resizeMode="cover"
                  />
                : <View style={[s.heroPhoto, { alignItems: 'center', justifyContent: 'center' }]}>
                    {readStatus === 'cached'
                      ? <Text style={{ color: theme.colors.textMuted }}>Photo requires a connection.</Text>
                      : <ActivityIndicator color={theme.colors.primary} />}
                  </View>}
            </TouchableOpacity>
            {propertyPhotos[0].gps_latitude != null && (
              <Text style={s.photoMeta}>
                📍 {Number(propertyPhotos[0].gps_latitude).toFixed(6)}, {Number(propertyPhotos[0].gps_longitude).toFixed(6)}
              </Text>
            )}
          </View>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10, paddingHorizontal: 16, marginTop: 10 }}>
            {propertyPhotos.map((p) => (
              <TouchableOpacity key={p.id} style={s.thumbWrap} onPress={() => setActivePhoto(p)}>
                {photoUris[p.id]
                  ? <Image source={{ uri: photoUris[p.id] }} style={s.thumb} />
                  : <View style={[s.thumb, { alignItems: 'center', justifyContent: 'center' }]}>
                      {readStatus === 'cached'
                        ? <Text style={{ color: theme.colors.textMuted, fontSize: 10, textAlign: 'center' }}>Online only</Text>
                        : <ActivityIndicator color={theme.colors.primary} />}
                    </View>}
                <Text style={s.thumbCap} numberOfLines={1}>Photo {p.sequence_no ?? '—'}</Text>
              </TouchableOpacity>
            ))}
          </View>
        </>
      )}

      {supportingEvidence.length > 0 && (
        <>
          <Text style={s.sectionLabel}>SUPPORTING EVIDENCE ({supportingEvidence.length})</Text>
          <Card>
            {supportingEvidence.map((e) => (
              <InfoRow
                key={e.id}
                label={e.evidence_type === 'OWNERSHIP_DOCUMENT' ? 'Ownership / deed' : e.evidence_type}
                value={`${e.original_name || '—'}${e.captured_at ? ` · ${fd(e.captured_at)}` : ''}`}
              />
            ))}
          </Card>
        </>
      )}

      <Text style={s.sectionLabel}>HISTORY</Text>
      {d.history && d.history.length > 0 ? (
        d.history.map((h, i) => (
          <Card key={i}>
            <Text style={s.engType}>{String(h.action || 'updated').replace(/_/g, ' ')}</Text>
            <Text style={s.engMeta}>{h.performed_by || '—'} · {h.created_at ? new Date(h.created_at).toLocaleString() : ''}</Text>
          </Card>
        ))
      ) : (
        <Card><Text style={{ color: theme.colors.textMuted, fontSize: 13 }}>No history recorded.</Text></Card>
      )}

      {actions.length > 0 && readStatus !== 'cached' && readStatus !== 'expired' && (
        <>
          <Text style={s.sectionLabel}>ACTIONS</Text>
          <View style={{ paddingHorizontal: 16, gap: 10 }}>
            {actions.map((a) => (
              <Btn
                key={`${a.key}-${a.decision || 'x'}`}
                tone={a.danger ? 'danger' : 'primary'}
                label={a.label}
                disabled={busy !== ''}
                onPress={() => (a.panel ? openPanel(a.key, a.decision) : runAction(a.key, a.endpoint, a.payload))}
              />
            ))}
          </View>
        </>
      )}

  {readStatus !== 'expired' && panel === 'acct-review' && (
    <Card style={{ marginTop: 14 }}>
      <Text style={s.panelTitle}>Account &amp; Record Manager review</Text>
      <Text style={s.panelSub}>Approve and assign an officer, return for correction, refer to valuation, or reject.</Text>
      <View style={{ marginBottom: 10, paddingHorizontal: 16 }}>
        <ChipRow options={acctOptions} value={decision} onChange={setDecision}
          colors={{ approve: theme.colors.success, refer_to_valuation: theme.colors.primary, return: '#D97706', reject: theme.colors.danger }} />
      </View>
      {decision === 'approve' && officerPick()}
      <Field label="Remarks">
        <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 64, textAlignVertical: 'top' }} placeholder="Decision note…" />
      </Field>
      <View style={{ flexDirection: 'row', gap: 8 }}>
        <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
        <Btn label="Confirm" style={{ flex: 1 }} loading={busy === 'acct-review'}
          disabled={!decision || (decision === 'approve' && !input1)}
          onPress={() => runAction('acct-review', 'acct-review', { decision, officer_id: input1 ? Number(input1) : undefined, remarks: remarks.trim() || undefined })} />
      </View>
    </Card>
  )}

  {readStatus !== 'expired' && panel === 'val-review' && (
    <Card style={{ marginTop: 14 }}>
      <Text style={s.panelTitle}>Valuation Manager decision</Text>
      <Text style={s.panelSub}>Assign to a Valuation Officer, conduct it yourself, or return for correction.</Text>
      <View style={{ marginBottom: 10, paddingHorizontal: 16 }}>
        {/* "Conduct personally" is shown only where the caller can begin and submit
            the assessment (valuation.submit) — routing permission alone would hand
            them work the API refuses. The server enforces the same rule. */}
        <ChipRow options={can(user, 'valuation.submit') ? ['assign', 'self', 'return'] : ['assign', 'return']} value={decision} onChange={setDecision}
          colors={{ assign: theme.colors.primary, self: '#2563EB', return: '#D97706' }} />
      </View>
      {decision === 'assign' && officerPick()}
      <Field label="Remarks">
        <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 64, textAlignVertical: 'top' }} placeholder="Note to the valuation officer / discovering officer…" />
      </Field>
      <View style={{ flexDirection: 'row', gap: 8 }}>
        <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
        <Btn label="Confirm" style={{ flex: 1 }} loading={busy === 'val-review'}
          disabled={!decision || (decision === 'assign' && !input1)}
          onPress={() => runAction('val-review', 'val-review', { decision, officer_id: decision === 'assign' ? Number(input1) : undefined, remarks: remarks.trim() || undefined })} />
      </View>
    </Card>
  )}

  {readStatus !== 'expired' && panel === 'registration' && (
    <Card style={{ marginTop: 14 }}>
      <Text style={s.panelTitle}>Property processing result</Text>
      <Text style={s.panelSub}>Confirming completes your Account &amp; Record Processing task and updates the property bill record (TIN {d?.tin || '—'} · {d?.owner_name || 'N/A'}). An existing bill for this TIN and tax period is updated; otherwise a new one is created.</Text>
      <Field label="Property ID (from source)">
        <Input value={input1} onChangeText={setInput1} placeholder="Property ID (source)" />
      </Field>
      <Field label="Document # (from source)">
        <Input value={input2} onChangeText={setInput2} placeholder="Document # (source)" />
      </Field>
      <Field label="Tax period (e.g. 2026)">
        <Input value={input3} onChangeText={setInput3} placeholder="Bill-Log tax period" />
      </Field>
      <Field label="Tax amount (US$, from source)">
        <Input value={input4} onChangeText={setInput4} placeholder="e.g. 1250.00" keyboardType="decimal-pad" />
      </Field>
      <Field label="Remarks">
        <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 60, textAlignVertical: 'top' }} placeholder="Optional…" />
      </Field>
      <View style={{ flexDirection: 'row', gap: 8 }}>
        <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
        <Btn label="Confirm processing" style={{ flex: 1 }} loading={busy === 'registration'}
          disabled={busy !== '' || !input1.trim() || !input2.trim() || !input3.trim() || !input4.trim() || !(Number(input4) >= 0)}
          onPress={() => runAction('registration', 'account-processing', { property_id: input1.trim(), document_number: input2.trim(), property_tax_amount: Number(input4), tax_period: input3.trim(), remarks: remarks.trim() || undefined })} />
      </View>
    </Card>
  )}

      <Modal visible={!!activePhoto} transparent animationType="fade" onRequestClose={() => setActivePhoto(null)}>
        <View style={s.lightbox}>
          {activePhoto && photoUris[activePhoto.id]
            ? <Image source={{ uri: photoUris[activePhoto.id] }} style={s.lightboxImg} resizeMode="contain" />
            : <ActivityIndicator color="#fff" />}
          {activePhoto && <Text style={s.lightboxCap}>{activePhoto.photo_reference} · {activePhoto.photo_type?.replace(/_/g, ' ')}</Text>}
          <TouchableOpacity style={s.lightboxClose} onPress={() => setActivePhoto(null)}>
            <Text style={{ color: '#fff', fontWeight: '800', fontSize: 15 }}>✕ Close</Text>
          </TouchableOpacity>
        </View>
      </Modal>
    </Screen>
  )
}

const s = StyleSheet.create({
  ref: { fontSize: 16, fontWeight: '800', color: theme.colors.navy, flexShrink: 1 },
  sectionLabel: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, letterSpacing: 0.8, marginLeft: 16, marginTop: 20, marginBottom: 10 },
  engType: { fontSize: 13, fontWeight: '700', color: theme.colors.navy },
  engMeta: { fontSize: 11, color: theme.colors.textLight, marginTop: 3 },
  panelTitle: { fontSize: 14, fontWeight: '800', color: theme.colors.navy, marginBottom: 4 },
  panelSub: { fontSize: 12, color: theme.colors.textMuted, marginBottom: 12 },
  officerChip: { borderRadius: 20, paddingHorizontal: 14, paddingVertical: 9, minHeight: 36, alignItems: 'center', justifyContent: 'center', borderWidth: 1.5, borderColor: theme.colors.border, backgroundColor: '#fff' },
  officerChipText: { fontSize: 12, fontWeight: '700', color: theme.colors.navy },
  thumbWrap: { width: 96, marginBottom: 8 },
  thumb: { width: 96, height: 96, borderRadius: 10, backgroundColor: theme.colors.surface || '#eef1f5' },
  thumbCap: { fontSize: 10, color: theme.colors.textMuted, marginTop: 4, width: 96 },
  heroPhoto: { width: '100%', height: 220, borderRadius: 12, backgroundColor: theme.colors.surface || '#eef1f5' },
  photoMeta: { fontSize: 11, color: theme.colors.textMuted, marginTop: 6 },
  lightbox: { flex: 1, backgroundColor: 'rgba(0,0,0,0.93)', alignItems: 'center', justifyContent: 'center', padding: 24 },
  lightboxImg: { width: '100%', height: '70%' },
  lightboxCap: { color: '#fff', fontSize: 13, marginTop: 14, textAlign: 'center' },
  lightboxClose: { position: 'absolute', top: 60, right: 20, padding: 10 },
})