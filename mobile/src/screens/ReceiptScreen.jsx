import { useState } from 'react'
import { View, Text, StyleSheet, Alert, Image } from 'react-native'
import * as ImagePicker from 'expo-image-picker'
import { useSync } from '../sync'
import { useAuth } from '../auth'
import { can } from '../rbac'
import { theme } from '../theme'
import { Screen, Field, Btn, Input, Card, Empty } from '../components'
import { photoToSubmission } from '../image'
import { useOfflineDraft } from '../useOfflineDraft'

export default function ReceiptScreen({ route, navigation }) {
  const { bill } = route.params || {}
  const { user } = useAuth()
  const { queueAndFlush } = useSync()
  const [billNo, setBillNo] = useState(bill?.bill_number || '')
  const [amount, setAmount] = useState('')
  const [period, setPeriod] = useState('')
  const [receiptNo, setReceiptNo] = useState('')
  const [propertyId, setPropertyId] = useState(bill?.property_id || '')
  const [tin, setTin] = useState(bill?.tin || '')
  const [payDate, setPayDate] = useState(new Date().toISOString().slice(0, 10))
  const [photo, setPhoto] = useState(null)
  const [busy, setBusy] = useState(false)
  const { ready: draftReady, error: draftError, clear: clearDraft } = useOfflineDraft(
    `receipt:${bill?.id ?? bill?.bill_number ?? 'new'}`,
    user?.id,
    { billNo, amount, period, receiptNo, propertyId, tin, payDate, photo },
    (draft) => {
      setBillNo(draft.billNo ?? bill?.bill_number ?? '')
      setAmount(draft.amount ?? '')
      setPeriod(draft.period ?? '')
      setReceiptNo(draft.receiptNo ?? '')
      setPropertyId(draft.propertyId ?? bill?.property_id ?? '')
      setTin(draft.tin ?? bill?.tin ?? '')
      setPayDate(draft.payDate ?? new Date().toISOString().slice(0, 10))
      setPhoto(draft.photo ?? null)
    },
  )

  if (!can(user, 'payments.claim')) {
    return (
      <Screen>
        <Empty icon="🚫" title="No access" sub="Your role does not include the payments.claim permission." />
      </Screen>
    )
  }

  const takePhoto = async () => {
    const { status: perm, canAskAgain } = await ImagePicker.requestCameraPermissionsAsync()
    if (perm !== 'granted') {
      return Alert.alert(
        'Camera access needed',
        canAskAgain
          ? 'Grant camera permission to photograph the receipt.'
          : 'Camera permission was denied. Enable it in your device settings, then try again.'
      )
    }
    const result = await ImagePicker.launchCameraAsync({ quality: 0.6 })
    if (!result.canceled) {
      const a = result.assets[0]
      try {
        const dataUri = await photoToSubmission({ uri: a.uri, fromCamera: true })
        setPhoto({ uri: dataUri, dataUri, fromCamera: true })
      } catch (error) {
        Alert.alert('Photo not added', error.message)
      }
    }
  }

  const uploadPhoto = async () => {
    const { status } = await ImagePicker.requestMediaLibraryPermissionsAsync()
    if (status !== 'granted') return Alert.alert('Permission', 'Gallery access required to attach the receipt photo.')
    const result = await ImagePicker.launchImageLibraryAsync({ mediaTypes: 'images', quality: 0.6 })
    if (!result.canceled) {
      const a = result.assets[0]
      try {
        const dataUri = await photoToSubmission({ uri: a.uri, fromCamera: false })
        setPhoto({ uri: dataUri, dataUri, fromCamera: false })
      } catch (error) {
        Alert.alert('Photo not added', error.message)
      }
    }
  }

  const submit = async () => {
    if (!billNo.trim()) return Alert.alert('Required', 'Enter the bill number.')
    if (!propertyId.trim()) return Alert.alert('Required', 'Enter the property ID.')
    if (!tin.trim()) return Alert.alert('Required', 'Enter the taxpayer TIN.')
    if (!amount) return Alert.alert('Required', 'Enter the amount paid.')
    if (!receiptNo.trim()) return Alert.alert('Required', 'Enter the receipt number.')
    if (!photo) return Alert.alert('Required', 'Capture the receipt photo.')
    setBusy(true)
    try {
      const payload = {
        billing_number: billNo.trim(),
        bill_id: bill?.id ?? undefined,
        property_id: propertyId.trim(),
        tin: tin.trim(),
        amount: parseFloat(amount),
        period: period.trim() || undefined,
        payment_date: payDate || undefined,
        receipt_number: receiptNo.trim(),
        receipt_photo: await photoToSubmission(photo),
        captured_at: new Date().toISOString(),
      }
      const result = await queueAndFlush('receipt', payload)
      let cleanupWarning = ''
      if (result.status !== 'failed') {
        try {
          await clearDraft()
        } catch (error) {
          cleanupWarning = ` The submission was saved, but the editable draft remains: ${error.message}`
        }
      }
      if (result.status === 'synced') {
        Alert.alert(
          'Submitted to server',
          `Receipt claim ${result.serverEntityId ? `#${result.serverEntityId} ` : ''}was received and is awaiting account and record verification. It has not been marked paid.${cleanupWarning}`,
          [{ text: 'OK', onPress: () => navigation.goBack() }],
        )
      } else {
        const syncFailed = result.status === 'failed'
        Alert.alert(
          syncFailed ? 'Sync failed — retry required' : 'Pending Sync',
          syncFailed
            ? `${result.lastError || 'The server rejected this receipt.'} The receipt, evidence and editable encrypted form draft remain on this device; review the error in Sync, correct any details, and retry. It has not been marked paid.${cleanupWarning}`
            : `The receipt and evidence are saved encrypted on this device and will be submitted when connectivity returns. It has not been marked paid.${cleanupWarning}`,
          [{ text: 'OK', onPress: () => navigation.goBack() }],
        )
      }
    } catch (error) {
      Alert.alert('Receipt not saved', error?.message || 'The receipt and evidence could not be stored securely.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Screen>
      {draftError ? <Text style={{ color: theme.colors.danger, paddingHorizontal: 16 }}>{draftError}</Text> : null}
      {!draftReady ? <Text style={{ color: theme.colors.textMuted, paddingHorizontal: 16 }}>Restoring encrypted receipt draft…</Text> : null}
      <Card style={{ marginTop: 14 }}>
        <Text style={s.billNo}>{billNo}</Text>
        <Text style={s.addr}>{bill.property_address}</Text>
        {bill.property_id ? <Text style={s.refLine}>Property ID: {bill.property_id}</Text> : null}
        {bill.tin ? <Text style={s.refLine}>TIN: {bill.tin}</Text> : null}
        {bill.tax_period ? <Text style={s.refLine}>Tax period: {bill.tax_period}</Text> : null}
        {bill.total_tax_due != null ? <Text style={s.refLine}>Total tax due: US$ {Number(bill.total_tax_due).toLocaleString()}</Text> : null}
        {bill.outstanding_balance != null && (
          <View style={s.dueRow}>
            <Text style={s.dueLabel}>Outstanding balance</Text>
            <Text style={s.dueValue}>US$ {Number(bill.outstanding_balance || 0).toLocaleString()}</Text>
          </View>
        )}
      </Card>

      <Card>
        <Text style={s.cardHeading}>Payment Details</Text>
        <Field label="Bill Number *">
          <Input value={billNo} onChangeText={setBillNo} placeholder="e.g. 2026/95b12" />
        </Field>
        <Field label="Receipt Number *">
          <Input value={receiptNo} onChangeText={setReceiptNo} placeholder="From the printed receipt" />
        </Field>
        <Field label="Property ID *">
          <Input value={propertyId} onChangeText={setPropertyId} placeholder="e.g. P-12b82e" autoCapitalize="characters" />
        </Field>
        <Field label="Tax Identification Number (TIN) *">
          <Input value={tin} onChangeText={setTin} placeholder="e.g. 200012b82e" keyboardType="numeric" />
        </Field>
        <Field label="Amount Paid (US$) *">
          <Input value={amount} onChangeText={setAmount} keyboardType="numeric" placeholder="e.g. 15000" />
        </Field>
        <Field label="Tax Period">
          <Input value={period} onChangeText={setPeriod} placeholder="e.g. 2026" />
        </Field>
        <Field label="Payment Date (YYYY-MM-DD)">
          <Input value={payDate} onChangeText={setPayDate} placeholder="YYYY-MM-DD" />
        </Field>
      </Card>

      <Card>
        <Text style={s.cardHeading}>Receipt Photo *</Text>
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <Btn tone={photo ? 'success' : 'ghost'} label="📷 Take photo" onPress={takePhoto} style={{ flex: 1 }} />
          <Btn tone="outline" label="⬆ Upload" onPress={uploadPhoto} style={{ flex: 1 }} />
        </View>
        {photo && <Image source={{ uri: photo.uri }} style={{ width: '100%', height: 170, borderRadius: 12, marginTop: 12 }} resizeMode="cover" />}
      </Card>

      <View style={{ paddingHorizontal: 16, marginTop: 6 }}>
        <Btn label="Submit Receipt" onPress={submit} loading={busy} disabled={busy || !draftReady} />
      </View>
    </Screen>
  )
}

const s = StyleSheet.create({
  billNo: { fontSize: 18, fontWeight: '900', color: theme.colors.navy },
  addr: { fontSize: 13, color: theme.colors.textMuted, marginTop: 3 },
  refLine: { fontSize: 12, color: theme.colors.textMuted, marginTop: 3 },
  dueRow: { flexDirection: 'row', justifyContent: 'space-between', backgroundColor: theme.colors.warningBg, borderRadius: 8, paddingHorizontal: 10, paddingVertical: 8, marginTop: 12 },
  dueLabel: { fontSize: 12, color: theme.colors.warning, fontWeight: '700' },
  dueValue: { fontSize: 13, fontWeight: '900', color: theme.colors.warning },
  cardHeading: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 10 },
})