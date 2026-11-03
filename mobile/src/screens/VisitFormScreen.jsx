import { useState, useCallback } from 'react'
import { View, Text, TouchableOpacity, StyleSheet, Alert, Image, ActivityIndicator, TextInput } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import * as Location from 'expo-location'
import * as ImagePicker from 'expo-image-picker'
import api from '../api'
import { useSync } from '../sync'
import { useAuth } from '../auth'
import { can, serverMessage, isOnlineRejection } from '../rbac'
import { theme } from '../theme'
import { Screen, Field, Btn, ChipRow, Card, InfoRow, OfflineReadStatus } from '../components'
import { FIELD_EVIDENCE_LIMITS, photoToSubmission } from '../image'
import { fetchOfflineRead } from '../offline'
import { useOfflineDraft } from '../useOfflineDraft'

const VISIT_STATUSES = ['Visited - Delivered', 'Visited - Payment Follow-up', 'Premises Locked', 'Business Closed', 'Subject Not Found', 'Refused Service', 'Follow-up Scheduled']
const DELIVERY_STATUSES = ['Out for Delivery', 'Delivered', 'Returned', 'Undelivered', 'Not Mailable']

export default function VisitFormScreen({ route, navigation }) {
  const { assignment, bill } = route.params || {}
  const { user, canUseOfflineCache } = useAuth()
  const { queueBundleAndFlush } = useSync()
  const [status, setStatus] = useState('Visited - Delivered')
  const [delivery, setDelivery] = useState('Delivered')
  const [recipientName, setRecipientName] = useState('')
  const [recipientContact, setRecipientContact] = useState('')
  const [notes, setNotes] = useState('')
  const [gps, setGps] = useState(null)
  const [locating, setLocating] = useState(false)
  const [photos, setPhotos] = useState([])
  const [claimPhoto, setClaimPhoto] = useState(null)
  const [busy, setBusy] = useState(false)
  const [claim, setClaim] = useState(false)
  const [claimReceipt, setClaimReceipt] = useState('')
  const [claimAmount, setClaimAmount] = useState('')
  const [claimDate, setClaimDate] = useState('')
  const [claimNotes, setClaimNotes] = useState('')
  const [history, setHistory] = useState(null)
  const [historyError, setHistoryError] = useState('')
  const [historyStatus, setHistoryStatus] = useState(null)
  const [historySyncedAt, setHistorySyncedAt] = useState(null)
  const [historyCacheError, setHistoryCacheError] = useState(null)
  const draftValue = {
    status, delivery, recipientName, recipientContact, notes, gps, photos, claim,
    claimReceipt, claimAmount, claimDate, claimNotes, claimPhoto,
  }
  const { ready: draftReady, error: draftError, clear: clearDraft } = useOfflineDraft(
    `visit:${assignment?.id}:${bill?.id}`,
    user?.id,
    draftValue,
    (draft) => {
      for (const [key, setter] of Object.entries({
        status: setStatus, delivery: setDelivery, recipientName: setRecipientName,
        recipientContact: setRecipientContact, notes: setNotes, gps: setGps, photos: setPhotos,
        claim: setClaim, claimReceipt: setClaimReceipt, claimAmount: setClaimAmount,
        claimDate: setClaimDate, claimNotes: setClaimNotes, claimPhoto: setClaimPhoto,
      })) {
        if (draft[key] !== undefined) setter(draft[key])
      }
    },
    Boolean(recipientName || recipientContact || notes || gps || photos.length || claim
      || claimReceipt || claimAmount || claimDate || claimNotes || claimPhoto
      || status !== 'Visited - Delivered' || delivery !== 'Delivered'),
  )

  const loadHistory = useCallback(async () => {
    try {
      const result = await fetchOfflineRead({
        module: 'visits',
        cacheKey: `${user?.role}:bill:${bill?.id}:50`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get('/enforcement-visits', { params: { bill_id: bill?.id, per_page: 50 } }),
        select: (response) => response.data?.data?.data || response.data?.data || [],
        cacheSelect: (visits) => Array.isArray(visits) ? visits.map((visit) => ({
          id: visit.id,
          visit_reference: visit.visit_reference,
          visit_date: visit.visit_date,
          gps_coordinate: visit.gps_coordinate,
          gps_accuracy: visit.gps_accuracy,
          visit_status: visit.visit_status,
          delivery_status: visit.delivery_status,
          remarks: visit.remarks,
          recipient_name: visit.recipient_name,
          snapshot_case_status: visit.snapshot_case_status,
          next_action: visit.next_action,
          next_followup_date: visit.next_followup_date,
        })) : [],
      })
      setHistory(result.status === 'expired' ? [] : result.data)
      setHistoryStatus(result.status)
      setHistorySyncedAt(result.syncedAt)
      setHistoryCacheError(result.cacheError || null)
      setHistoryError(result.status === 'expired' ? 'Offline visit history has expired. Connect to refresh it.' : '')
    } catch (error) {
      setHistory([])
      setHistoryError(serverMessage(error, 'Unable to load visit history.'))
    }
  }, [bill?.id, canUseOfflineCache, user])

  useFocusEffect(useCallback(() => { loadHistory() }, [loadHistory]))

  // A visit must always be recorded against a specific assignment & bill.
  if (!bill || !assignment) {
    return (
      <Screen>
        {draftError ? <Text style={{ color: theme.colors.danger, paddingHorizontal: 16 }}>{draftError}</Text> : null}
        {!draftReady ? <Text style={{ color: theme.colors.textMuted, paddingHorizontal: 16 }}>Restoring encrypted visit draft…</Text> : null}
        <Card style={{ marginTop: 14 }}>
          <Text style={s.billNo}>Field visits</Text>
          <Text style={{ color: theme.colors.textMuted, fontSize: 13, marginTop: 6 }}>
            Select an assignment first — a visit is always recorded against one of your assigned cases.
          </Text>
          <View style={{ marginTop: 14 }}>
            <Btn label="📋 Choose an assignment" onPress={() => navigation.navigate('Tasks')} />
          </View>
        </Card>
      </Screen>
    )
  }

  const canComplete = can(user, 'tasks.complete') || can(user, 'enforcement.record_visit')
  const canEscalate = can(user, 'tasks.escalate')
  const canClaim = can(user, 'payments.claim')

  const getGps = async () => {
    setLocating(true)
    try {
      const { status: perm, canAskAgain } = await Location.requestForegroundPermissionsAsync()
      if (perm !== 'granted') {
        return Alert.alert(
          'Location access needed',
          canAskAgain
            ? 'Grant location permission to capture the visit GPS stamp.'
            : 'Location permission was denied. Enable it in your device settings, then try again.'
        )
      }
      const loc = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High })
      setGps({
        latitude: loc.coords.latitude,
        longitude: loc.coords.longitude,
        accuracy: loc.coords.accuracy != null ? Math.round(loc.coords.accuracy) : null,
        at: new Date().toISOString(),
      })
    } catch (e) {
      Alert.alert('GPS error', 'Could not get a location fix. Move to open sky and try again.')
    } finally { setLocating(false) }
  }

  const pickPhotos = async () => {
    const remaining = FIELD_EVIDENCE_LIMITS.visitPhotos - photos.length
    if (remaining <= 0) return Alert.alert('Photo limit', `A visit can include at most ${FIELD_EVIDENCE_LIMITS.visitPhotos} photographs.`)
    const { status: perm } = await ImagePicker.requestMediaLibraryPermissionsAsync()
    if (perm !== 'granted') return Alert.alert('Permission', 'Gallery access required to attach proof documents.')
    const result = await ImagePicker.launchImageLibraryAsync({ mediaTypes: 'images', allowsMultipleSelection: true, selectionLimit: remaining, quality: 0.6 })
    if (!result.canceled) {
      try {
        const selected = await Promise.all(result.assets.slice(0, remaining).map(async (asset) => {
          const capturedAt = asset.exif?.DateTimeOriginal || new Date().toISOString()
          const dataUri = await photoToSubmission({ uri: asset.uri, fromCamera: false })
          return { uri: dataUri, dataUri, fromCamera: false, capturedAt }
        }))
        setPhotos((current) => [...current, ...selected].slice(0, FIELD_EVIDENCE_LIMITS.visitPhotos))
      } catch (error) {
        Alert.alert('Photo not added', error.message)
      }
    }
  }

  const takePhoto = async () => {
    if (photos.length >= FIELD_EVIDENCE_LIMITS.visitPhotos) return Alert.alert('Photo limit', `A visit can include at most ${FIELD_EVIDENCE_LIMITS.visitPhotos} photographs.`)
    const { status: perm, canAskAgain } = await ImagePicker.requestCameraPermissionsAsync()
    if (perm !== 'granted') {
      return Alert.alert(
        'Camera access needed',
        canAskAgain
          ? 'Grant camera permission to capture proof documents.'
          : 'Camera permission was denied. Enable it in your device settings, then try again.'
      )
    }
    const result = await ImagePicker.launchCameraAsync({ quality: 0.6 })
    if (!result.canceled) {
      const a = result.assets[0]
      try {
        const capturedAt = new Date().toISOString()
        const dataUri = await photoToSubmission({ uri: a.uri, fromCamera: true })
        setPhotos((current) => [...current, { uri: dataUri, dataUri, fromCamera: true, capturedAt }])
      } catch (error) {
        Alert.alert('Photo not added', error.message)
      }
    }
  }

  const takeClaimPhoto = async () => {
    const { status: perm, canAskAgain } = await ImagePicker.requestCameraPermissionsAsync()
    if (perm !== 'granted') {
      return Alert.alert(
        'Camera access needed',
        canAskAgain
          ? 'Grant camera permission to capture receipt evidence.'
          : 'Camera permission was denied. Enable it in your device settings, then try again.'
      )
    }
    const result = await ImagePicker.launchCameraAsync({ quality: 0.6 })
    if (!result.canceled) {
      try {
        const dataUri = await photoToSubmission({ uri: result.assets[0].uri, fromCamera: true })
        setClaimPhoto({ uri: dataUri, dataUri, fromCamera: true, capturedAt: new Date().toISOString() })
      } catch (error) {
        Alert.alert('Receipt photo not added', error.message)
      }
    }
  }

  const submit = async () => {
    if (!draftReady) return Alert.alert('Please wait', 'The encrypted offline draft is still loading.')
    if (!gps) return Alert.alert('GPS required', 'Capture your GPS location before saving.')
    if (claim && (!claimReceipt.trim() || !(Number(claimAmount) > 0) || !claimPhoto)) return Alert.alert('Claim incomplete', 'Provide a receipt number, positive amount and receipt photo for the on-the-spot payment claim.')
    setBusy(true)
    try {
      const preparedPhotos = await Promise.all(photos.map((photo) => photoToSubmission(photo)))
      const receiptPhoto = claim ? await photoToSubmission(claimPhoto) : undefined
      const payload = {
        assignment_id: assignment.id,
        property_bill_id: bill.id,
        status,
        delivery_status: delivery,
        recipient_name: recipientName.trim() || undefined,
        recipient_contact: recipientContact.trim() || undefined,
        notes: notes || undefined,
        gps_lat: gps.latitude,
        gps_lng: gps.longitude,
        gps_accuracy: gps.accuracy,
        gps_captured_at: gps.at,
        photo_type: delivery === 'Delivered' ? 'BILL_DELIVERY' : 'PROPERTY_FULL_VIEW',
        proof_photo: preparedPhotos[0],
        claim_receipt_number: claim ? claimReceipt.trim() : undefined,
        claim_amount: claim ? Number(claimAmount) : undefined,
        claim_payment_date: claim && claimDate ? claimDate : undefined,
        claim_receipt_photo: receiptPhoto,
        claim_remarks: claim && claimNotes.trim() ? claimNotes.trim() : undefined,
      }
      const children = preparedPhotos.slice(1).map((dataUri, index) => ({
        kind: 'evidence',
        parentField: 'visit_id',
        payload: {
          photo_type: delivery === 'Delivered' ? 'BILL_DELIVERY' : 'PROPERTY_FULL_VIEW',
          data_uri: dataUri,
          bill_id: bill.id,
          task_id: assignment.id,
          property_id: bill.property_id || undefined,
          gps_coordinate: `${gps.latitude.toFixed(6)},${gps.longitude.toFixed(6)}`,
          captured_at: photos[index + 1]?.capturedAt,
        },
      }))
      const result = await queueBundleAndFlush('visit', payload, children)
      if (result.status !== 'failed') await clearDraft()
      const synced = result.allSynced
      Alert.alert(
        result.status === 'failed' ? 'Visit needs correction' : synced ? 'Visit submitted' : 'Visit saved for synchronization',
        result.status === 'failed'
          ? `${result.lastError || 'The server rejected this submission.'} The encrypted form draft remains available for correction.`
          : synced
          ? 'The visit and evidence were confirmed by RETD. Any payment claim remains pending server verification.'
          : 'The visit and evidence are retained in encrypted offline storage and will retry when connected. A payment claim is not verified or paid.',
        [
          { text: 'Done', onPress: () => navigation.goBack() },
          { text: 'Record another visit', onPress: () => {
            setStatus('Visited - Delivered'); setDelivery('Delivered'); setRecipientName(''); setRecipientContact('')
            setNotes(''); setGps(null); setPhotos([]); setClaim(false); setClaimReceipt(''); setClaimAmount('')
            setClaimDate(''); setClaimNotes(''); setClaimPhoto(null)
          } },
        ],
      )
    } catch (error) {
      Alert.alert('Visit not saved', isOnlineRejection(error)
        ? serverMessage(error, 'The server rejected the visit. The encrypted draft remains available.')
        : error?.message || 'The visit remains in the encrypted draft and was not discarded.')
    } finally { setBusy(false) }
  }

  const escalate = async () => {
    setBusy(true)
    try {
      await api.post(`/enforcement-assignments/${assignment.id}/action`, { action: 'escalate', visit_date: new Date().toISOString().slice(0, 10), notes: notes || undefined })
      Alert.alert('Escalated', 'Case escalated for manager review.', [{ text: 'OK', onPress: () => navigation.goBack() }])
    } catch (e) {
      if (isOnlineRejection(e)) {
        Alert.alert('Not escalated', serverMessage(e, 'The server rejected the escalation.'))
      } else {
        Alert.alert('Escalation not confirmed', 'Escalations require an online server decision. No offline escalation was queued; reconnect and check the task status before retrying.')
      }
    } finally { setBusy(false) }
  }

  return (
    <Screen>
      {draftError ? <Text style={{ color: theme.colors.danger, paddingHorizontal: 16 }}>{draftError}</Text> : null}
      {!draftReady ? <Text style={{ color: theme.colors.textMuted, paddingHorizontal: 16 }}>Restoring encrypted visit draft…</Text> : null}
      <Card style={{ marginTop: 14 }}>
        <View style={s.billHeader}>
          <View style={{ flex: 1 }}>
            <Text style={s.billNo}>{bill.bill_number}</Text>
            <Text style={s.addr}>{bill.property_address}</Text>
          </View>
          {bill.outstanding_balance != null && (
            <View style={s.dueBadge}>
              <Text style={s.dueLabel}>Outstanding</Text>
              <Text style={s.dueValue}>US$ {Number(bill.outstanding_balance || 0).toLocaleString()}</Text>
            </View>
          )}
        </View>
        <View style={{ marginTop: 8, borderTopWidth: 1, borderTopColor: theme.colors.border, paddingTop: 4 }}>
          {bill.property_id ? <InfoRow label="Property ID" value={bill.property_id} /> : null}
          {bill.tin ? <InfoRow label="TIN" value={bill.tin} /> : null}
          {bill.taxpayer_name ? <InfoRow label="Taxpayer" value={bill.taxpayer_name} /> : null}
          {bill.property_classification ? <InfoRow label="Classification" value={bill.property_classification} /> : null}
          {bill.property_type ? <InfoRow label="Type" value={bill.property_type} /> : null}
          {bill.tax_period ? <InfoRow label="Tax period" value={bill.tax_period} /> : null}
          {bill.assessed_value != null ? <InfoRow label="Assessed value" value={`US$ ${Number(bill.assessed_value).toLocaleString()}`} /> : null}
          {bill.total_tax_due != null ? <InfoRow label="Total tax due" value={`US$ ${Number(bill.total_tax_due).toLocaleString()}`} /> : null}
          {bill.case_status ? <InfoRow label="Case status" value={bill.case_status} /> : null}
          {bill.payment_status ? <InfoRow label="Payment status" value={bill.payment_status} /> : null}
          {assignment.status && <InfoRow label="Task status" value={assignment.status} />}
          {assignment.assigned_to && <InfoRow label="Assigned staff" value={assignment.assignedTo?.full_name || `#${assignment.assigned_to}`} />}
        </View>
      </Card>

      <Text style={s.sectionLabel}>1 · VISIT STATUS</Text>
      <ChipRow options={VISIT_STATUSES} value={status} onChange={setStatus} />

      <Text style={s.sectionLabel}>2 · DELIVERY</Text>
      <ChipRow options={DELIVERY_STATUSES} value={delivery} onChange={setDelivery} />

      <Text style={s.sectionLabel}>3 · GPS LOCATION</Text>
      <View style={{ paddingHorizontal: 16 }}>
        <Btn tone={gps ? 'success' : 'navy'} label={gps ? `✓ ${gps.latitude.toFixed(5)}, ${gps.longitude.toFixed(5)}${gps.accuracy ? ` (±${gps.accuracy}m)` : ''}` : '📡 Capture GPS'} onPress={getGps} loading={locating} />
      </View>

      <Text style={s.sectionLabel}>4 · RECIPIENT</Text>
      <Field label="Recipient name">
        <TextInput style={[s.input, { marginHorizontal: 0 }]} placeholder="Who received the bill…" value={recipientName} onChangeText={setRecipientName} placeholderTextColor={theme.colors.textLight} />
      </Field>
      <Field label="Recipient contact">
        <TextInput style={[s.input, { marginHorizontal: 0 }]} placeholder="Phone / email…" value={recipientContact} onChangeText={setRecipientContact} placeholderTextColor={theme.colors.textLight} />
      </Field>

      <Text style={s.sectionLabel}>5 · PHOTO EVIDENCE</Text>
      <View style={{ flexDirection: 'row', gap: 10, paddingHorizontal: 16, marginBottom: 10 }}>
        <Btn tone="outline" label="📷 Take Photo" onPress={takePhoto} style={{ flex: 1 }} />
        <Btn tone="ghost" label="🖼 Gallery" onPress={pickPhotos} style={{ flex: 1 }} />
      </View>
      {photos.length > 0 && (
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, paddingHorizontal: 16, marginBottom: 8 }}>
          {photos.map((shot, i) => (
            <TouchableOpacity key={i} onPress={() => setPhotos(photos.filter((_, ix) => ix !== i))} hitSlop={8}>
              <Image source={{ uri: shot.uri }} style={{ width: 76, height: 76, borderRadius: 10, borderWidth: 1, borderColor: theme.colors.success }} />
            </TouchableOpacity>
          ))}
        </View>
      )}

      {canClaim && (
        <>
          <TouchableOpacity style={{ paddingHorizontal: 16, marginBottom: 10, minHeight: 44, justifyContent: 'center' }} onPress={() => setClaim(!claim)}>
            <Text style={{ color: claim ? theme.colors.success : theme.colors.primary, fontWeight: '800', fontSize: 14 }}>
              {claim ? '✓ On-the-spot payment claim enabled — tap to remove' : '💰 Add on-the-spot payment claim'}
            </Text>
          </TouchableOpacity>
          {claim && (
            <>
              <View style={{ paddingHorizontal: 16, marginBottom: 10 }}>
                <Btn
                  tone={claimPhoto ? 'success' : 'outline'}
                  label={claimPhoto ? '✓ Receipt evidence captured' : '📷 Capture required receipt evidence'}
                  onPress={takeClaimPhoto}
                  disabled={busy}
                />
                {claimPhoto ? (
                  <Image source={{ uri: claimPhoto.uri }} style={{ width: 96, height: 96, borderRadius: 10, marginTop: 8 }} />
                ) : null}
              </View>
              <Field label="Receipt number">
                <TextInput style={[s.input, { marginHorizontal: 0 }]} placeholder="Receipt / voucher #…" value={claimReceipt} onChangeText={setClaimReceipt} placeholderTextColor={theme.colors.textLight} />
              </Field>
              <Field label="Amount paid (US$)">
                <TextInput style={[s.input, { marginHorizontal: 0 }]} placeholder="0.00" keyboardType="decimal-pad" value={claimAmount} onChangeText={setClaimAmount} placeholderTextColor={theme.colors.textLight} />
              </Field>
              <Field label="Payment date (YYYY-MM-DD)">
                <TextInput style={[s.input, { marginHorizontal: 0 }]} placeholder={new Date().toISOString().slice(0, 10)} value={claimDate} onChangeText={setClaimDate} placeholderTextColor={theme.colors.textLight} />
              </Field>
              <Field label="Notes on the claim">
                <TextInput style={[s.input, { marginHorizontal: 0 }]} placeholder="Optional…" value={claimNotes} onChangeText={setClaimNotes} placeholderTextColor={theme.colors.textLight} />
              </Field>
            </>
          )}
        </>
      )}

      <Text style={s.sectionLabel}>6 · NOTES</Text>
      <Field label="">
        <TextInput
          style={[s.input, { textAlignVertical: 'top', minHeight: 84 }]}
          multiline
          placeholder="Optional remarks on the visit…"
          value={notes} onChangeText={setNotes} placeholderTextColor={theme.colors.textLight}
        />
      </Field>

      <View style={{ paddingHorizontal: 16, gap: 10, marginTop: 8 }}>
        {canComplete && <Btn label="✓ Save Visit" onPress={submit} loading={busy} disabled={!draftReady} />}
        {canEscalate && <Btn tone="danger" label="⚠ Escalate to Manager" onPress={escalate} disabled={busy} />}
        {canClaim && (
          <TouchableOpacity style={{ marginTop: 4, minHeight: 44, justifyContent: 'center' }} onPress={() => navigation.navigate('SubmitReceipt', { bill })}>
            <Text style={{ color: theme.colors.primary, textAlign: 'center', fontWeight: '700', fontSize: 14 }}>💰 Submit Payment Receipt</Text>
          </TouchableOpacity>
        )}
      </View>

      <OfflineReadStatus status={historyStatus} syncedAt={historySyncedAt} cacheError={historyCacheError} />
      {historyError ? <Text style={{ color: theme.colors.danger, marginHorizontal: 16 }}>{historyError}</Text> : null}
      {history === null ? (
        <ActivityIndicator color={theme.colors.primary} style={{ marginTop: 24 }} />
      ) : history.length > 0 ? (
        <>
          <Text style={s.sectionLabel}>7 · ENGAGEMENT HISTORY</Text>
          {history.map((v) => (
            <Card key={v.id}>
              <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 }}>
                <Text style={s.histRef}>{v.visit_reference}</Text>
                <Text style={s.histDate}>{String(v.visit_date || '').slice(0, 10)}</Text>
              </View>
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginBottom: 6 }}>
                <Text style={s.histPill}>{v.visit_status}</Text>
                {v.delivery_status ? <Text style={s.histPill}>{v.delivery_status}</Text> : null}
              </View>
              {v.remarks ? <Text style={s.histNote}>{v.remarks}</Text> : null}
              {v.recipient_name ? <Text style={s.histMeta}>Recipient: {v.recipient_name}</Text> : null}
              {v.snapshot_outstanding != null ? <Text style={s.histMeta}>Outstanding at visit: US$ {Number(v.snapshot_outstanding).toLocaleString()}</Text> : null}
              {v.snapshot_payment_status ? <Text style={s.histMeta}>Payment: {v.snapshot_payment_status}</Text> : null}
              {v.snapshot_case_status ? <Text style={s.histMeta}>Case: {v.snapshot_case_status}</Text> : null}
              {v.next_action ? <Text style={s.histMeta}>Next: {v.next_action}{v.next_followup_date ? ` · ${String(v.next_followup_date).slice(0, 10)}` : ''}</Text> : null}
              {v.gps_coordinate ? <Text style={s.histMeta}>📍 {v.gps_coordinate}</Text> : null}
            </Card>
          ))}
        </>
      ) : null}
    </Screen>
  )
}

const s = StyleSheet.create({
  billHeader: { flexDirection: 'row', alignItems: 'center' },
  billNo: { fontSize: 18, fontWeight: '900', color: theme.colors.navy },
  addr: { fontSize: 13, color: theme.colors.textMuted, marginTop: 3, flexShrink: 1 },
  dueBadge: { backgroundColor: theme.colors.warningBg, borderRadius: 10, paddingHorizontal: 12, paddingVertical: 8, marginLeft: 12, alignItems: 'center' },
  dueLabel: { fontSize: 10, color: theme.colors.warning, fontWeight: '700' },
  dueValue: { fontSize: 14, color: theme.colors.warning, fontWeight: '900' },
  sectionLabel: { fontSize: 12, fontWeight: '800', color: theme.colors.navy, letterSpacing: 0.8, marginLeft: 16, marginTop: 20, marginBottom: 10, textTransform: 'uppercase' },
  input: { backgroundColor: '#fff', borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12, fontSize: 15, borderWidth: 1.5, borderColor: theme.colors.border, marginHorizontal: 16, color: theme.colors.text },
  histRef: { fontSize: 13, fontWeight: '800', color: theme.colors.navy },
  histDate: { fontSize: 12, color: theme.colors.textLight },
  histPill: { backgroundColor: theme.colors.bg, borderRadius: 8, paddingHorizontal: 8, paddingVertical: 3, fontSize: 11, color: theme.colors.textMuted, fontWeight: '700', overflow: 'hidden' },
  histNote: { fontSize: 13, color: theme.colors.text, marginTop: 4, lineHeight: 18 },
  histMeta: { fontSize: 11, color: theme.colors.textLight, marginTop: 3 },
})