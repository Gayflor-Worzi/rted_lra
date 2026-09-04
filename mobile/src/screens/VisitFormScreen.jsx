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
import { Screen, Field, Btn, ChipRow, Card, InfoRow } from '../components'

const VISIT_STATUSES = ['Visited - Delivered', 'Visited - Payment Follow-up', 'Premises Locked', 'Business Closed', 'Subject Not Found', 'Refused Service', 'Follow-up Scheduled']
const DELIVERY_STATUSES = ['Delivered', 'Undelivered', 'Returned to Office', 'Not Mailable']

export default function VisitFormScreen({ route, navigation }) {
  const { assignment, bill } = route.params || {}
  const { user } = useAuth()
  const { queueAndFlush } = useSync()
  const [status, setStatus] = useState('Visited - Delivered')
  const [delivery, setDelivery] = useState('Delivered')
  const [recipientName, setRecipientName] = useState('')
  const [recipientContact, setRecipientContact] = useState('')
  const [notes, setNotes] = useState('')
  const [gps, setGps] = useState(null)
  const [locating, setLocating] = useState(false)
  const [photos, setPhotos] = useState([])
  const [busy, setBusy] = useState(false)
  const [claim, setClaim] = useState(false)
  const [claimReceipt, setClaimReceipt] = useState('')
  const [claimAmount, setClaimAmount] = useState('')
  const [claimDate, setClaimDate] = useState('')
  const [claimNotes, setClaimNotes] = useState('')
  const [history, setHistory] = useState(null)

  const loadHistory = useCallback(async () => {
    try {
      const r = await api.get('/enforcement-visits', { params: { bill_id: bill?.id, per_page: 50 } })
      setHistory(r.data?.data || [])
    } catch { setHistory([]) }
  }, [bill?.id])

  useFocusEffect(useCallback(() => { loadHistory() }, [loadHistory]))

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
    const { status: perm } = await ImagePicker.requestMediaLibraryPermissionsAsync()
    if (perm !== 'granted') return Alert.alert('Permission', 'Gallery access required to attach proof documents.')
    const result = await ImagePicker.launchImageLibraryAsync({ mediaTypes: 'images', allowsMultipleSelection: true, quality: 0.7 })
    if (!result.canceled) setPhotos([...photos, ...result.assets.map((a) => a.uri)])
  }

  const takePhoto = async () => {
    const { status: perm, canAskAgain } = await ImagePicker.requestCameraPermissionsAsync()
    if (perm !== 'granted') {
      return Alert.alert(
        'Camera access needed',
        canAskAgain
          ? 'Grant camera permission to capture proof documents.'
          : 'Camera permission was denied. Enable it in your device settings, then try again.'
      )
    }
    const result = await ImagePicker.launchCameraAsync({ quality: 0.7 })
    if (!result.canceled) setPhotos([...photos, result.assets[0].uri])
  }

  const submit = async () => {
    if (!gps) return Alert.alert('GPS required', 'Capture your GPS location before saving.')
    if (claim && (!claimReceipt.trim() || !(Number(claimAmount) > 0))) return Alert.alert('Claim incomplete', 'Provide a receipt number and amount for the on-the-spot payment claim.')
    setBusy(true)
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
      proof_photo: photos[0] || undefined,
      claim_receipt_number: claim ? claimReceipt.trim() : undefined,
      claim_amount: claim ? Number(claimAmount) : undefined,
      claim_payment_date: claim && claimDate ? claimDate : undefined,
      claim_remarks: claim && claimNotes.trim() ? claimNotes.trim() : undefined,
    }
    const actionPayload = {
      assignment_id: assignment.id,
      action: 'completed',
      visit_date: new Date().toISOString().slice(0, 10),
      notes: notes || undefined,
    }
    const queued = []
    const fail = (e, what) => {
      setBusy(false)
      if (isOnlineRejection(e)) {
        Alert.alert(`${what} not saved`, serverMessage(e, 'The server rejected the record.'))
        return true
      }
      return false
    }
    try {
      await api.post('/enforcement-visits', payload)
    } catch (e) {
      if (fail(e, 'Visit')) return
      await queueAndFlush('visit', payload)
      queued.push('visit')
    }
    try {
      await api.post(`/enforcement-assignments/${assignment.id}/action`, actionPayload)
    } catch (e) {
      if (fail(e, 'Task update')) return
      await queueAndFlush('action', actionPayload)
      queued.push('action')
    }
    Alert.alert(
      queued.length ? 'Saved offline' : 'Visit saved',
      queued.length
        ? 'Visit queued locally — it will sync automatically when you are back online.'
        : 'The visit has been recorded against the task. Previous visits stay on the record.',
      [
        { text: 'Done', onPress: () => navigation.goBack() },
        { text: 'Record another visit', onPress: () => { setStatus('Visited - Delivered'); setDelivery('Delivered'); setRecipientName(''); setRecipientContact(''); setNotes(''); setGps(null); setPhotos([]); setClaim(false); setClaimReceipt(''); setClaimAmount(''); setClaimDate(''); setClaimNotes('') } },
      ]
    )
    setBusy(false)
  }

  const escalate = async () => {
    setBusy(true)
    const payload = { assignment_id: assignment.id, action: 'escalate', visit_date: new Date().toISOString().slice(0, 10), notes: notes || undefined }
    try {
      await api.post(`/enforcement-assignments/${assignment.id}/action`, { action: 'escalate', visit_date: payload.visit_date, notes: payload.notes })
      Alert.alert('Escalated', 'Case escalated for manager review.', [{ text: 'OK', onPress: () => navigation.goBack() }])
    } catch (e) {
      if (isOnlineRejection(e)) {
        Alert.alert('Not escalated', serverMessage(e, 'The server rejected the escalation.'))
      } else {
        await queueAndFlush('action', payload)
        Alert.alert('Queued offline', 'Escalation will sync when online.', [{ text: 'OK', onPress: () => navigation.goBack() }])
      }
    } finally { setBusy(false) }
  }

  return (
    <Screen>
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
          {photos.map((uri, i) => (
            <TouchableOpacity key={i} onPress={() => setPhotos(photos.filter((_, ix) => ix !== i))} hitSlop={8}>
              <Image source={{ uri }} style={{ width: 76, height: 76, borderRadius: 10, borderWidth: 1, borderColor: theme.colors.success }} />
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
        {canComplete && <Btn label="✓ Save Visit" onPress={submit} loading={busy} />}
        {canEscalate && <Btn tone="danger" label="⚠ Escalate to Manager" onPress={escalate} disabled={busy} />}
        {canClaim && (
          <TouchableOpacity style={{ marginTop: 4, minHeight: 44, justifyContent: 'center' }} onPress={() => navigation.navigate('SubmitReceipt', { bill })}>
            <Text style={{ color: theme.colors.primary, textAlign: 'center', fontWeight: '700', fontSize: 14 }}>💰 Submit Payment Receipt</Text>
          </TouchableOpacity>
        )}
      </View>

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