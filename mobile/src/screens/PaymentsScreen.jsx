import { useState, useCallback } from 'react'
import { View, Text, FlatList, TouchableOpacity, StyleSheet, Alert, RefreshControl, ActivityIndicator, Image } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import api from '../api'
import { useAuth } from '../auth'
import { can, serverMessage, isOnlineRejection } from '../rbac'
import { theme } from '../theme'
import { BrandHeader, Badge, Empty, Card, Field, Input, Btn, ChipRow } from '../components'

const fm = (v) => (v == null ? '—' : 'US$ ' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
const STATUSES = ['Pending', 'Confirmed', 'Rejected', 'Exception', 'all']

export default function PaymentsScreen() {
  const { user } = useAuth()
  const insets = useSafeAreaInsets()
  const [status, setStatus] = useState('Pending')
  const [claims, setClaims] = useState(null)
  const [refreshing, setRefreshing] = useState(false)
  const [open, setOpen] = useState(null)
  const [receipt, setReceipt] = useState(null)
  const [busy, setBusy] = useState('')
  const [mode, setMode] = useState(null)
  const [input, setInput] = useState('')
  const [remarks, setRemarks] = useState('')
  const [mismatch, setMismatch] = useState(false)

  const canVerify = can(user, 'payments.verify')
  const canReject = can(user, 'payments.reject')
  const canViewHistory = can(user, 'payments.view_history')
  const canViewQueue = can(user, 'payments.view_queue')

  const load = useCallback(async () => {
    if (!canViewQueue && !canViewHistory) return setClaims([])
    try {
      const r = await api.get('/payments/queue', { params: { status, per_page: 30 } })
      setClaims(r.data.data?.data || r.data.data || [])
    } catch {
      try {
        const r = await api.get('/payments/history', { params: { per_page: 30 } })
        setClaims(r.data.data?.data || r.data.data || [])
      } catch { setClaims([]) }
    }
  }, [status, canViewQueue, canViewHistory])

  useFocusEffect(useCallback(() => { load() }, [load]))
  const refresh = async () => { setRefreshing(true); await load(); setRefreshing(false) }

  const openClaim = async (id) => {
    setOpen(id); setReceipt(null); setMode(null); setRemarks(''); setInput(''); setMismatch(false)
    try {
      const r = await api.get(`/payments/verifications/${id}/receipt`)
      setReceipt(r.data.data)
    } catch { setReceipt(null) }
  }

  const doConfirm = async () => {
    setBusy('confirm')
    try {
      await api.post(`/payments/verifications/${open}/confirm`, {
        verified_amount: input.trim() ? Number(input) : undefined,
        litas_reference: undefined,
        remarks: remarks.trim() || undefined,
      })
      Alert.alert('Payment confirmed', 'The bill outstanding balance has been recalculated.')
      setOpen(null); setMode(null)
      load()
    } catch (e) {
      if (isOnlineRejection(e)) Alert.alert('Could not confirm', serverMessage(e, 'The server rejected the confirmation.'))
      else Alert.alert('Offline', 'Connect to the network and retry.')
    } finally { setBusy('') }
  }

  const doReject = async () => {
    if (!remarks.trim()) return Alert.alert('Required', 'A rejection reason is required.')
    setBusy('reject')
    try {
      await api.post(`/payments/verifications/${open}/reject`, {
        reason: remarks.trim(),
        mismatch: mismatch || undefined,
      })
      Alert.alert('Payment rejected', 'The claim was returned to the officer.')
      setOpen(null); setMode(null)
      load()
    } catch (e) {
      if (isOnlineRejection(e)) Alert.alert('Could not reject', serverMessage(e, 'The server rejected the action.'))
      else Alert.alert('Offline', 'Connect to the network and retry.')
    } finally { setBusy('') }
  }

  const noAccess = !canVerify && !canReject && !canViewQueue && !canViewHistory

  return (
    <View style={s.container}>
      <BrandHeader title="Payment Verifications" subtitle="Confirm or reject field payment claims" />

      {noAccess ? (
        <Empty icon="🚫" title="No access" sub="Your role does not include payment verification permissions." />
      ) : (
        <>
          <View style={{ paddingHorizontal: 16, marginTop: 12 }}>
            <ChipRow options={STATUSES} value={status} onChange={setStatus} />
          </View>

          {!open ? (
            <FlatList
              data={claims || []}
              keyExtractor={(i) => String(i.id)}
              renderItem={({ item }) => (
                <Card onPress={() => openClaim(item.id)}>
                  <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
                    <View style={{ flex: 1 }}>
                      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                        <Text style={s.docNo}>{item.document_number || `Claim #${item.id}`}</Text>
                        <Badge status={item.verification_status} />
                      </View>
                      <Text style={s.meta}>Receipt <Text style={{ fontWeight: '800' }}>{item.receipt_number}</Text></Text>
                      <Text style={s.meta}>Claimed {fm(item.amount_claimed)}{item.payment_period ? ` · ${item.payment_period}` : ''}</Text>
                      {item.match_status ? <Text style={s.meta}>Match: {item.match_status}</Text> : null}
                    </View>
                    <Text style={s.chevron}>›</Text>
                  </View>
                </Card>
              )}
              contentContainerStyle={{ paddingVertical: 8, paddingBottom: 30 + insets.bottom }}
              refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={theme.colors.primary} />}
              ListEmptyComponent={<Empty icon="🧾" title="No payment claims" sub="Claims submitted by field officers appear here." />}
            />
          ) : (
            <FlatList
              data={[{ id: open }]}
              keyExtractor={(i) => String(i.id)}
              renderItem={() => (
                <>
                  <Card style={{ marginTop: 14 }}>
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 }}>
                      <Text style={s.docNo}>{receipt?.document_number || '…'}</Text>
                    </View>
                    <Text style={s.el}>Property ID: <Text style={{ fontWeight: '800' }}>{receipt?.property_id || '—'}</Text></Text>
                    <Text style={s.el}>TIN: <Text style={{ fontWeight: '800' }}>{receipt?.tin || '—'}</Text></Text>
                    <Text style={s.el}>Tax due date: {receipt?.tax_due_date || '—'}</Text>
                    <Text style={s.el}>Amount claimed: <Text style={{ fontWeight: '800' }}>{fm(receipt?.amount_claimed)}</Text></Text>
                    <Text style={s.el}>Payment period: {receipt?.payment_period || '—'}</Text>
                    <Text style={s.el}>Receipt date: {receipt?.receipt_date || '—'}</Text>
                  </Card>

                  <Card style={s.compareCard}>
                    <Text style={s.compareTitle}>⚖ Bill number vs Receipt number</Text>
                    <Text style={s.compareItem}>Bill / Document #: <Text style={{ fontWeight: '800' }}>{receipt?.document_number || '—'}</Text></Text>
                    <Text style={s.compareItem}>Receipt bill #: <Text style={{ fontWeight: '800' }}>{receipt?.receipt_bill_number || '—'}</Text></Text>
                    <Text style={s.compareHint}>The Account Officer compares the two. A mismatch is grounds for rejection.</Text>
                  </Card>

                  {receipt?.receipt_attachment && /^https?:/.test(receipt.receipt_attachment) ? (
                    <Card>
                      <Text style={s.compareTitle}>Receipt photo</Text>
                      <Image source={{ uri: receipt.receipt_attachment }} style={{ width: '100%', height: 180, borderRadius: 12 }} resizeMode="contain" />
                    </Card>
                  ) : null}

                  {!mode && (
                    <View style={{ paddingHorizontal: 16, gap: 10, marginTop: 4 }}>
                      {canVerify && <Btn label="✓ Confirm Payment" onPress={() => setMode('confirm')} />}
                      {canReject && <Btn tone="danger" label="✕ Reject Claim" onPress={() => setMode('reject')} />}
                      <Btn tone="ghost" label="← Back to queue" onPress={() => { setOpen(null); setReceipt(null) }} />
                    </View>
                  )}

                  {mode === 'confirm' && (
                    <Card style={{ marginTop: 14 }}>
                      <Text style={s.compareTitle}>Confirm payment</Text>
                      <Field label="Verified amount (leave empty to use claimed)">
                        <Input value={input} onChangeText={setInput} keyboardType="numeric" placeholder={receipt?.amount_claimed != null ? String(receipt.amount_claimed) : '0.00'} />
                      </Field>
                      <Field label="Remarks">
                        <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 60, textAlignVertical: 'top' }} placeholder="Optional" />
                      </Field>
                      <View style={{ flexDirection: 'row', gap: 8 }}>
                        <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setMode(null)} disabled={busy !== ''} />
                        <Btn label="Confirm Payment" style={{ flex: 1 }} loading={busy === 'confirm'} onPress={doConfirm} />
                      </View>
                    </Card>
                  )}

                  {mode === 'reject' && (
                    <Card style={{ marginTop: 14 }}>
                      <Text style={s.compareTitle}>Reject claim</Text>
                      <View style={{ marginBottom: 10 }}>
                        <ChipRow options={['yes', 'no']} value={mismatch ? 'yes' : 'no'} onChange={(v) => setMismatch(v === 'yes')} colors={{ yes: theme.colors.danger, no: theme.colors.border }} />
                        <Text style={s.compareHint}>Mismatch — mark if the receipt number does not match the bill.</Text>
                      </View>
                      <Field label="Rejection reason *">
                        <Input value={remarks} onChangeText={setRemarks} multiline style={{ minHeight: 60, textAlignVertical: 'top' }} placeholder="Reason for rejection" />
                      </Field>
                      <View style={{ flexDirection: 'row', gap: 8 }}>
                        <Btn tone="ghost" label="Cancel" style={{ flex: 1 }} onPress={() => setMode(null)} disabled={busy !== ''} />
                        <Btn tone="danger" label="Reject Claim" style={{ flex: 1 }} loading={busy === 'reject'} onPress={doReject} />
                      </View>
                    </Card>
                  )}
                </>
              )}
              contentContainerStyle={{ paddingBottom: 30 + insets.bottom }}
            />
          )}
        </>
      )}
    </View>
  )
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: theme.colors.bg },
  docNo: { fontSize: 15, fontWeight: '900', color: theme.colors.navy, flexShrink: 1 },
  meta: { fontSize: 12, color: theme.colors.textMuted, marginTop: 3 },
  chevron: { fontSize: 22, color: theme.colors.textLight, marginLeft: 8 },
  el: { fontSize: 13, color: theme.colors.text, marginTop: 4, lineHeight: 19 },
  compareCard: { backgroundColor: theme.colors.warningBg, borderColor: theme.colors.warning },
  compareTitle: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, marginBottom: 8 },
  compareItem: { fontSize: 13, color: theme.colors.text, marginTop: 3 },
  compareHint: { fontSize: 11, color: theme.colors.textMuted, marginTop: 8, lineHeight: 16 },
})