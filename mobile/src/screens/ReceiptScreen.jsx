import { useState } from 'react'
import { View, Text, StyleSheet, Alert, Image } from 'react-native'
import * as ImagePicker from 'expo-image-picker'
import api from '../api'
import { useSync } from '../sync'
import { useAuth } from '../auth'
import { can, serverMessage, isOnlineRejection } from '../rbac'
import { theme } from '../theme'
import { Screen, Field, Btn, Input, Card, Empty } from '../components'

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
    const result = await ImagePicker.launchCameraAsync({ quality: 0.7 })
    if (!result.canceled) setPhoto(result.assets[0].uri)
  }

  const uploadPhoto = async () => {
    const { status } = await ImagePicker.requestMediaLibraryPermissionsAsync()
    if (status !== 'granted') return Alert.alert('Permission', 'Gallery access required to attach the receipt photo.')
    const result = await ImagePicker.launchImageLibraryAsync({ mediaTypes: 'images', quality: 0.7 })
    if (!result.canceled) setPhoto(result.assets[0].uri)
  }

  const submit = async () => {
    if (!billNo.trim()) return Alert.alert('Required', 'Enter the bill number.')
    if (!propertyId.trim()) return Alert.alert('Required', 'Enter the property ID.')
    if (!tin.trim()) return Alert.alert('Required', 'Enter the taxpayer TIN.')
    if (!amount) return Alert.alert('Required', 'Enter the amount paid.')
    if (!receiptNo.trim()) return Alert.alert('Required', 'Enter the receipt number.')
    if (!photo) return Alert.alert('Required', 'Capture the receipt photo.')
    setBusy(true)
    const payload = {
      billing_number: billNo.trim(),
      property_id: propertyId.trim(),
      tin: tin.trim(),
      amount: parseFloat(amount),
      period: period.trim() || undefined,
      payment_date: payDate || undefined,
      receipt_number: receiptNo.trim(),
      receipt_photo: photo,
    }
    try {
      await api.post('/enforcement/submit-receipt', payload)
      Alert.alert('Receipt submitted', 'Sent for validation by the Account & Records section.', [{ text: 'OK', onPress: () => navigation.goBack() }])
    } catch (e) {
      if (isOnlineRejection(e)) {
        Alert.alert('Receipt not submitted', serverMessage(e, 'The server rejected the receipt claim.'))
      } else {
        await queueAndFlush('receipt', payload)
        Alert.alert('Saved offline', 'Receipt queued — it will sync automatically.', [{ text: 'OK', onPress: () => navigation.goBack() }])
      }
    } finally { setBusy(false) }
  }

  return (
    <Screen>
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
        {photo && <Image source={{ uri: photo }} style={{ width: '100%', height: 170, borderRadius: 12, marginTop: 12 }} resizeMode="cover" />}
      </Card>

      <View style={{ paddingHorizontal: 16, marginTop: 6 }}>
        <Btn label="Submit Receipt" onPress={submit} loading={busy} disabled={busy} />
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