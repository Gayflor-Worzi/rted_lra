import { useState, useCallback } from 'react'
import { View, Text, TouchableOpacity, StyleSheet, Alert, ActivityIndicator, RefreshControl, Image, Modal } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import api from '../api'
import { useAuth } from '../auth'
import { can, serverMessage, isOnlineRejection } from '../rbac'
import { theme } from '../theme'
import { Screen, Card, InfoRow, Badge, Field, Input, Btn, ChipRow, Empty } from '../components'

const fd = (v) => (v ? String(v).slice(0, 10) : '—')

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
  const { user } = useAuth()
  const { discoveryId } = route.params || {}
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const [refreshing, setRefreshing] = useState(false)
  const [busy, setBusy] = useState('')
  const [panel, setPanel] = useState(null)
  const [path, setPath] = useState('account')
  const [input1, setInput1] = useState('')
  const [input2, setInput2] = useState('')
  const [remarks, setRemarks] = useState('')
  const [photoUris, setPhotoUris] = useState({})
  const [activePhoto, setActivePhoto] = useState(null)

  const loadPhotos = useCallback(async (photos) => {
    if (!photos || photos.length === 0) return
    const want = photos.map((p) => p.id).filter((id) => !(id in photoUris))
    if (want.length === 0) return
    const next = { ...photoUris }
    await Promise.all(want.map(async (id) => {
      try {
        const r = await api.get(`/evidence/photos/${id}/download`, { responseType: 'blob' })
        next[id] = await blobToDataUri(r.data)
      } catch { next[id] = null }
    }))
    setPhotoUris(next)
  }, [photoUris])

  const load = useCallback(async () => {
    try {
      const r = await api.get(`/discoveries/${discoveryId}`)
      const data = r.data.data
      setD(data)
      loadPhotos(data?.photos || [])
      setErr('')
    } catch (e) { setErr(serverMessage(e, 'Could not load the discovery record.')) }
  }, [discoveryId, loadPhotos])

  useFocusEffect(useCallback(() => { load() }, [load]))
  const refresh = async () => { setRefreshing(true); await load(); setRefreshing(false) }

  if (err && !d) {
    return (
      <Screen>
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
  const creator = d.discovered_by === user?.id

  const runAction = async (kind, endpoint, payload) => {
    setBusy(kind)
    try {
      await api.post(`/discoveries/${discoveryId}/${endpoint}`, payload)
      setPanel(null); setRemarks(''); setInput1(''); setInput2('')
      Alert.alert('Done', 'Discovery updated.')
      load()
    } catch (e) {
      if (isOnlineRejection(e)) Alert.alert('Action failed', serverMessage(e, 'The server rejected the action.'))
      else Alert.alert('Offline', 'Skipped — connect to the network and retry.')
    } finally { setBusy('') }
  }

  const openPanel = (key) => {
    setPanel(key); setPath('account'); setInput1(''); setInput2(''); setRemarks('')
  }

  const actions = []
  if (creator && status === 'DISCOVERED' && can(user, 'discovery.create')) actions.push({ key: 'submit', label: 'Submit for Review', endpoint: 'submit', payload: {}, panel: null })
  if (creator && ['rejected', 'returned'].includes(d.ac_decision) && can(user, 'discovery.create')) actions.push({ key: 'resubmit', label: 'Resubmit after Correction', endpoint: 'resubmit', payload: {}, panel: null })
  if (can(user, 'discovery.review') && !creator && ['SUBMITTED', 'RESUBMITTED'].includes(status)) actions.push({ key: 'review', label: 'Begin Manager Review', endpoint: 'review', panel: 'remarks' })
  if (can(user, 'discovery.classify') && !creator && ['UNDER_MANAGER_REVIEW', 'SUBMITTED'].includes(status)) actions.push({ key: 'classify', label: 'Classify & Route', endpoint: 'classify', panel: 'classify' })
  if (can(user, 'discovery.route_to_account') && !creator && status === 'CLASSIFIED' && d.decision_path === 'account') actions.push({ key: 'route_account', label: 'Route to Account & Record', endpoint: 'route-to-account', payload: {}, panel: null })
  if (can(user, 'discovery.route_to_valuation') && !creator && status === 'CLASSIFIED' && d.decision_path === 'valuation') actions.push({ key: 'route_val', label: 'Route to Valuation', endpoint: 'route-to-valuation', payload: {}, panel: null })
  if ((can(user, 'discovery.route_to_account') || can(user, 'discovery.litas_processing')) && status === 'SENT_TO_ACCOUNT') actions.push({ key: 'litas', label: 'Record LITAS Result', endpoint: 'account-processing', panel: 'litas' })
  if (can(user, 'discovery.approve') && !creator && status === 'PENDING_AC_APPROVAL') actions.push({ key: 'approve', label: 'Approve (AC)', endpoint: 'approve', panel: 'remarks' })
  if (can(user, 'discovery.reject') && !creator && ['PENDING_AC_APPROVAL', 'VALUATION_MANAGER_REVIEW'].includes(status)) actions.push({ key: 'reject', label: 'Reject', endpoint: 'reject', panel: 'remarks', danger: true })
  if (can(user, 'discovery.reopen') && ['AC_REJECTED', 'RETURNED_FOR_CORRECTION'].includes(status)) actions.push({ key: 'reopen', label: 'Reopen for Correction', endpoint: 'reopen', payload: {}, panel: null })

  return (
    <Screen scroll refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={theme.colors.primary} />}>
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 }}>
          <Text style={s.ref}>{d.discovery_reference}</Text>
          <Badge status={status} label={status} />
        </View>
        {d.decision_path ? <InfoRow label="Decision path" value={d.decision_path} /> : null}
        <InfoRow label="Discovery date" value={fd(d.discovery_date)} />
        <InfoRow label="Recorded by" value={d.discoverer?.full_name || '—'} />
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

      <Text style={s.sectionLabel}>SYSTEM REFERENCES</Text>
      <Card>
        <InfoRow label="Property ID (LITAS)" value={d.property_id || '—'} bold />
        <InfoRow label="Document # (LITAS)" value={d.document_number || '—'} bold />
        {d.processed_by ? <InfoRow label="Processed by" value={d.processed_by} /> : null}
        {d.processed_at ? <InfoRow label="Processed at" value={fd(d.processed_at)} /> : null}
      </Card>

      <Text style={s.sectionLabel}>GPS</Text>
      <Card>
        <InfoRow label="Coordinates" value={coordOf(d)} />
        {d.gps_accuracy != null ? <InfoRow label="Accuracy" value={`±${d.gps_accuracy}m`} /> : null}
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

      {d.photos && d.photos.length > 0 && (
        <>
          <Text style={s.sectionLabel}>EVIDENCE PHOTOS ({d.photos.length})</Text>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10, paddingHorizontal: 16 }}>
            {d.photos.map((p) => (
              <TouchableOpacity key={p.id} style={s.thumbWrap} onPress={() => setActivePhoto(p)}>
                {photoUris[p.id]
                  ? <Image source={{ uri: photoUris[p.id] }} style={s.thumb} />
                  : <View style={[s.thumb, { alignItems: 'center', justifyContent: 'center' }]}><ActivityIndicator color={theme.colors.primary} /></View>}
                <Text style={s.thumbCap} numberOfLines={1}>{p.photo_reference}</Text>
              </TouchableOpacity>
            ))}
          </View>
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

      {actions.length > 0 && (
        <>
          <Text style={s.sectionLabel}>ACTIONS</Text>
          <View style={{ paddingHorizontal: 16, gap: 10 }}>
            {actions.map((a) => (
              <Btn
                key={a.key}
                tone={a.danger ? 'danger' : 'primary'}
                label={a.label}
                disabled={busy !== ''}
                onPress={() => (a.panel ? openPanel(a.key) : runAction(a.key, a.endpoint, a.payload))}
              />
            ))}
          </View>
        </>
      )}

      {panel === 'review' || panel === 'approve' || panel === 'reject' ? (
        <Card style={{ marginTop: 14 }}>
          <Text style={s.panelTitle}>Remarks (required for the record)</Text>
          <Field label="Remarks">
            <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 72, textAlignVertical: 'top' }} placeholder="Reason / instructions…" />
          </Field>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
            <Btn label="Confirm" style={{ flex: 1 }} onPress={() => runAction(panel, panel, { remarks: remarks.trim() || undefined })} loading={busy === panel} />
          </View>
        </Card>
      ) : null}

      {panel === 'classify' && (
        <Card style={{ marginTop: 14 }}>
          <Text style={s.panelTitle}>Classification & routing path</Text>
          <Text style={s.panelSub}>Choose where the property should go next.</Text>
          <View style={{ marginBottom: 10, paddingHorizontal: 16 }}>
            <ChipRow options={['account', 'valuation']} value={path} onChange={setPath} />
          </View>
          <Field label="Classification decision">
            <Input value={input1} onChangeText={setInput1} placeholder="e.g. Two-storey commercial property" />
          </Field>
          <Field label="Manager remarks">
            <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 60, textAlignVertical: 'top' }} placeholder="Optional…" />
          </Field>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
            <Btn label="Classify" style={{ flex: 1 }} onPress={() => runAction('classify', 'classify', { decision_path: path, classification_decision: input1.trim() || undefined, manager_remarks: remarks.trim() || undefined })} loading={busy === 'classify'} />
          </View>
        </Card>
      )}

      {panel === 'litas' && (
        <Card style={{ marginTop: 14 }}>
          <Text style={s.panelTitle}>LITAS processing result</Text>
          <Text style={s.panelSub}>Confirm the property was created in the source system and record its identifiers.</Text>
          <Field label="Property ID (from LITAS)">
            <Input value={input1} onChangeText={setInput1} placeholder="LITAS property id" />
          </Field>
          <Field label="Document # (from LITAS)">
            <Input value={input2} onChangeText={setInput2} placeholder="LITAS document number" />
          </Field>
          <Field label="Remarks">
            <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 60, textAlignVertical: 'top' }} placeholder="Optional…" />
          </Field>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setPanel(null)} disabled={busy !== ''} />
            <Btn label="Record" style={{ flex: 1 }} onPress={() => runAction('litas', 'account-processing', { property_id: input1.trim() || undefined, document_number: input2.trim() || undefined, remarks: remarks.trim() || undefined })} loading={busy === 'litas'} />
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
  thumbWrap: { width: 96, marginBottom: 8 },
  thumb: { width: 96, height: 96, borderRadius: 10, backgroundColor: theme.colors.surface || '#eef1f5' },
  thumbCap: { fontSize: 10, color: theme.colors.textMuted, marginTop: 4, width: 96 },
  lightbox: { flex: 1, backgroundColor: 'rgba(0,0,0,0.93)', alignItems: 'center', justifyContent: 'center', padding: 24 },
  lightboxImg: { width: '100%', height: '70%' },
  lightboxCap: { color: '#fff', fontSize: 13, marginTop: 14, textAlign: 'center' },
  lightboxClose: { position: 'absolute', top: 60, right: 20, padding: 10 },
})