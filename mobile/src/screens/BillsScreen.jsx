import { useCallback, useState } from 'react'
import { View, Text, TouchableOpacity, TextInput, StyleSheet, RefreshControl } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import api from '../api'
import { useAuth } from '../auth'
import { fetchOfflineRead } from '../offline'
import { can, serverMessage } from '../rbac'
import { theme } from '../theme'
import { Screen, BrandHeader, Card, Badge, Btn, Empty, OfflineReadStatus } from '../components'
import BillAmendSheet from './parts/BillAmendSheet'

const fmtMoney = (v) => (v == null || Number.isNaN(Number(v)) ? '—' : '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
const fmtDate = (d) => (d ? new Date(String(d).replace(' ', 'T')).toLocaleDateString() : '—')

const BillRow = ({ bill, canAmend, onAmend }) => (
  <TouchableOpacity
    activeOpacity={0.7}
    onPress={canAmend ? () => onAmend(bill) : undefined}
    style={st.row}
  >
    <View style={st.rowTop}>
      <Text style={st.rowDoc} numberOfLines={1}>{bill.document_number}</Text>
      <View style={{ flexDirection: 'row', gap: 6 }}>
        <Badge status={bill.case_status} label={bill.case_status} />
        <Badge status={bill.payment_status} label={bill.payment_status} />
      </View>
    </View>
    <Text style={st.rowTaxpayer} numberOfLines={1}>{bill.taxpayer_name}</Text>
    <View style={st.rowMeta}>
      <Text style={st.rowMetaItem}>📍 {bill.property_id || '—'}</Text>
      <Text style={st.rowMetaItem}>🗓 {bill.tax_period || '—'}</Text>
    </View>
    <Text style={st.rowAddr} numberOfLines={1}>{bill.property_address || '—'}</Text>
    <View style={st.rowBottom}>
      <View style={{ flex: 1 }}>
        <Text style={st.rowTotal}>{fmtMoney(bill.total_tax_due)} <Text style={st.rowTotalDim}>due</Text></Text>
        <Text style={st.rowSub}>Logged {fmtDate(bill.date_logged)} · Outstanding {fmtMoney(bill.outstanding_balance)}</Text>
      </View>
      {canAmend && <Text style={st.rowAmend}>Amend ›</Text>}
    </View>
  </TouchableOpacity>
)

/**
 * Bills bottom-tab for Account & Records staff.
 *
 * Shows ONLY the bills the staff member logged themselves (own scope).
 * A bill logged by another staff member is deliberately invisible here — the
 * sole exception is the targeted "Find a bill to amend" lookup, which calls
 * up a specific record by its Property ID + Tax Period (POST-able only
 * through the audit-trailed amendment sheet; backed by GET /property-bills/lookup
 * + POST /property-bills/{id}/amend).
 */
export default function BillsScreen() {
  const { user, canUseOfflineCache } = useAuth()
  const canDoc = can(user, 'bills.amend_document')
  const canFin = can(user, 'bills.amend_penalty_interest')
  const canAmend = canDoc || canFin

  const [mine, setMine] = useState([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [readStatus, setReadStatus] = useState(null)
  const [syncedAt, setSyncedAt] = useState(null)
  const [refreshing, setRefreshing] = useState(false)

  const [propertyId, setPropertyId] = useState('')
  const [taxPeriod, setTaxPeriod] = useState('')
  const [finding, setFinding] = useState(false)
  const [findErr, setFindErr] = useState('')
  const [findDone, setFindDone] = useState('')

  const [amendBill, setAmendBill] = useState(null)

  const load = useCallback(async () => {
    try {
      const result = await fetchOfflineRead({
        module: 'bills',
        cacheKey: `${user?.role}:logged-by:${user?.id}:50`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get('/property-bills', { params: { logged_by: user?.id, per_page: 50 } }),
        select: (response) => response.data?.data?.data || response.data?.data || [],
      })
      setMine(result.status === 'expired' ? [] : result.data)
      setReadStatus(result.status)
      setSyncedAt(result.syncedAt)
      setErr(result.status === 'expired' ? 'Offline bill data has expired. Connect to refresh it.' : '')
    } catch (e) {
      setErr(serverMessage(e, 'Unable to load your bills.'))
    }
    setLoading(false)
  }, [user, canUseOfflineCache])

  useFocusEffect(useCallback(() => { load() }, [load]))

  const refresh = async () => { setRefreshing(true); await load(); setRefreshing(false) }

  const findBill = async () => {
    if (!propertyId.trim() || !taxPeriod.trim()) {
      setFindErr('Enter both Property ID and Tax Period to locate the bill.')
      return
    }
    setFinding(true); setFindErr(''); setFindDone('')
    try {
      const result = await fetchOfflineRead({
        module: 'bills',
        cacheKey: `${user?.role}:lookup:${propertyId.trim().toUpperCase()}:${taxPeriod.trim()}`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get('/property-bills/lookup', { params: { property_id: propertyId.trim(), tax_period: taxPeriod.trim() } }),
        select: (response) => response.data?.data,
      })
      const b = result.status === 'expired' ? null : result.data
      if (b) {
        setFindDone(`Found ${b.document_number} — logged by ${b.account_staff || 'another staff member'}. Tap Amend to make changes.`)
        setAmendBill(b)
      } else if (result.status === 'expired') {
        setFindErr('The saved bill lookup has expired. Connect to verify this record before preparing an amendment.')
      } else {
        setFindErr('No bill matched that Property ID + Tax Period.')
      }
    } catch (e) {
      const msg = serverMessage(e, 'Lookup failed. The bill must be looked up online once before it is available offline.')
      setFindErr(msg)
    }
    setFinding(false)
  }

  const onAmended = (res) => {
    setMine((prev) => prev.map((b) => (b.id === res.id ? { ...b, ...res } : b)))
  }

  return (
    <Screen refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={theme.colors.primary} />}>
      <BrandHeader
        title="Bills"
        subtitle={canAmend ? 'Bills I logged · central registry' : 'Bills I logged'}
      />
      <OfflineReadStatus status={readStatus} syncedAt={syncedAt} />

      {canAmend && (
        <Card style={{ marginTop: 12 }}>
          <Text style={st.cardHeader}>Find a bill to amend</Text>
          <Text style={st.hint}>Locate any logged bill by its Property ID and Tax Period — including bills logged by another staff member. Only amendment fields are changeable.</Text>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <TextInput
              value={propertyId}
              onChangeText={(t) => { setPropertyId(t); setFindErr(''); setFindDone('') }}
              placeholder="Property ID"
              placeholderTextColor={theme.colors.textLight}
              autoCapitalize="characters"
              style={[st.findInput, { flex: 1.2 }]}
            />
            <TextInput
              value={taxPeriod}
              onChangeText={(t) => { setTaxPeriod(t); setFindErr(''); setFindDone('') }}
              placeholder="Tax Period"
              placeholderTextColor={theme.colors.textLight}
              style={[st.findInput, { flex: 1 }]}
            />
            <Btn label={finding ? '…' : 'Find'} onPress={findBill} disabled={finding || !propertyId.trim() || !taxPeriod.trim()} style={st.findBtn} />
          </View>
          {findErr ? <Text style={st.err}>{findErr}</Text> : null}
          {findDone ? <Text style={st.done}>{findDone}</Text> : null}
        </Card>
      )}

      {err && !loading ? (
        <Card style={{ marginTop: 12 }}>
          <Text style={{ color: theme.colors.danger, fontWeight: '600' }}>{err}</Text>
          <TouchableOpacity onPress={refresh} style={{ marginTop: 8, minHeight: 44, justifyContent: 'center' }}>
            <Text style={{ color: theme.colors.primary, fontWeight: '700', fontSize: 13 }}>⟳ Try again</Text>
          </TouchableOpacity>
        </Card>
      ) : (
        <Card style={{ marginTop: 12 }}>
          <Text style={st.cardHeader}>Bills I logged</Text>
          {!loading && mine.length === 0 ? (
            <Empty icon="🧾" title="No bills logged yet" sub="Bills you register in the Bill Register will appear here." />
          ) : (
            mine.map((b) => <BillRow key={b.id} bill={b} canAmend={canAmend} onAmend={setAmendBill} />)
          )}
        </Card>
      )}

      {amendBill && canAmend && (
        <BillAmendSheet
          bill={amendBill}
          onClose={() => { setAmendBill(null); setFindDone('') }}
          onAmended={onAmended}
          canDoc={canDoc}
          canFin={canFin}
        />
      )}
    </Screen>
  )
}

const st = StyleSheet.create({
  cardHeader: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 8 },
  hint: { fontSize: 11, color: theme.colors.textMuted, marginBottom: 10 },
  findInput: { backgroundColor: '#fff', borderRadius: 12, paddingHorizontal: 12, paddingVertical: 11, fontSize: 15, borderWidth: 1.5, borderColor: theme.colors.border, minHeight: 46 },
  findBtn: { minWidth: 90, paddingHorizontal: 16, minHeight: 46 },
  err: { color: theme.colors.danger, fontSize: 13, fontWeight: '600', marginTop: 10 },
  done: { color: theme.colors.success, fontSize: 13, fontWeight: '600', marginTop: 10 },

  row: { borderTopWidth: 1, borderTopColor: theme.colors.border, paddingVertical: 10 },
  rowTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  rowDoc: { flex: 1, fontSize: 15, fontWeight: '800', color: theme.colors.navy },
  rowTaxpayer: { fontSize: 13, color: theme.colors.text, fontWeight: '600', marginTop: 4 },
  rowMeta: { flexDirection: 'row', flexWrap: 'wrap', gap: 14, marginTop: 6 },
  rowMetaItem: { fontSize: 11.5, color: theme.colors.textMuted, fontWeight: '600' },
  rowAddr: { fontSize: 12, color: theme.colors.textMuted, marginTop: 3 },
  rowBottom: { flexDirection: 'row', alignItems: 'flex-end', marginTop: 8 },
  rowTotal: { fontSize: 14, fontWeight: '800', color: theme.colors.danger },
  rowTotalDim: { fontSize: 11, color: theme.colors.textLight, fontWeight: '600' },
  rowSub: { fontSize: 11, color: theme.colors.textMuted, marginTop: 1, fontWeight: '600' },
  rowAmend: { fontSize: 13, fontWeight: '700', color: theme.colors.primary, marginLeft: 8, paddingVertical: 4 },
})