import { useState, useCallback, useEffect, useRef } from 'react'
import { View, Text, FlatList, TouchableOpacity, StyleSheet, RefreshControl, Alert, ActivityIndicator } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import NetInfo from '@react-native-community/netinfo'
import { useSync } from '../sync'
import { useAuth } from '../auth'
import { getDb, outboxUnsolved, outboxFailed, outboxSynced, outboxDelete } from '../db'
import { theme } from '../theme'
import { BrandHeader, Card, Empty, Btn } from '../components'

const KIND_META = {
  visit: { icon: '📋', label: 'Enforcement Visit', tone: theme.colors.primary },
  discovery: { icon: '📍', label: 'Property Discovery', tone: theme.colors.navy },
  valuation: { icon: '🏷️', label: 'Valuation Draft', tone: theme.colors.navy },
  receipt: { icon: '💰', label: 'Payment Receipt', tone: theme.colors.success },
  action: { icon: '⚡', label: 'Task Action', tone: theme.colors.warning },
  evidence: { icon: '📷', label: 'Evidence Photo', tone: theme.colors.primary },
  gps_certify: { icon: '🧭', label: 'GPS Certification', tone: theme.colors.navy },
  engagement: { icon: '📝', label: 'Field Note', tone: theme.colors.primary },
  notification_read: { icon: '🔔', label: 'Notice Read State', tone: theme.colors.primary },
  notification_read_all: { icon: '🔔', label: 'Notice Read State', tone: theme.colors.primary },
  bill_amendment: { icon: '🧾', label: 'Bill Amendment Request', tone: theme.colors.warning },
}

const FILTERS = [
  { key: 'pending', label: 'Awaiting' },
  { key: 'failed', label: 'Failed' },
  { key: 'synced', label: 'Synced' },
]

const STATUS_TONE = {
  pending: theme.colors.warning,
  syncing: theme.colors.primary,
  synced: theme.colors.success,
  failed: theme.colors.danger,
}

const STATUS_LABEL = { pending: 'Pending Sync', syncing: 'Syncing', synced: 'Synced', failed: 'Sync Failed' }

function statusLabel(item) {
  if (item.kind === 'receipt' && item.status === 'synced') return 'Submitted to Server'
  return STATUS_LABEL[item.status] || item.status
}

function statusTone(item) {
  if (item.kind === 'receipt' && item.status === 'synced') return theme.colors.primary
  return STATUS_TONE[item.status] || theme.colors.textLight
}

function fmtTime(t) {
  if (!t) return ''
  const s = String(t).slice(0, 16)
  return s.replace('T', ' ')
}

export default function OutboxScreen() {
  const { counts, pending, syncing, processOutbox, retryRow } = useSync()
  const { user } = useAuth()
  const userId = user?.id == null ? null : String(user.id)
  const userIdRef = useRef(userId)
  userIdRef.current = userId
  const loadVersion = useRef(0)
  const insets = useSafeAreaInsets()
  const [filter, setFilter] = useState('pending')
  const [rows, setRows] = useState([])
  const [refreshing, setRefreshing] = useState(false)
  const [expanded, setExpanded] = useState({})

  const load = useCallback(async (f) => {
    const version = ++loadVersion.current
    if (userId == null) {
      setRows([])
      return
    }
    await getDb()
    const key = f || filter
    const src = key === 'synced' ? outboxSynced : key === 'failed' ? outboxFailed : outboxUnsolved
    const nextRows = await src(userId)
    if (version === loadVersion.current && userIdRef.current === userId) setRows(nextRows)
  }, [filter, userId])

  useEffect(() => {
    setRows([])
    load()
  }, [filter, userId, counts.pending, counts.syncing, counts.synced, counts.failed, load])

  useFocusEffect(useCallback(() => {
    (async () => {
      const net = await NetInfo.fetch()
      if (net.isConnected && pending > 0) await processOutbox({ quiet: true })
    })()
  }, [pending, processOutbox]))

  const remove = (item) => {
    Alert.alert('Remove pending entry?', 'The local record will be deleted. If it was not yet received by the server it will be lost.', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Remove', style: 'destructive', onPress: async () => {
        if (userIdRef.current !== String(item.owner_user_id)) return
        await outboxDelete(item.id, userIdRef.current)
        await load()
      } },
    ])
  }

  const metaOf = (item) => {
    let p = {}
    try { p = JSON.parse(item.payload) } catch {}
    const kind = KIND_META[item.kind]?.label || item.kind
    const primary = p.billing_number || p.document_number || p.bill_number || p.property_address || p.action || item.kind
    return { kind, primary, p }
  }

  return (
    <View style={s.container}>
      <BrandHeader title="Sync" subtitle={pending > 0 ? `${pending} field records awaiting upload` : 'All field records synced to LRA'} />

      {/* Summary chips */}
      <View style={s.summaryRow}>
        {[
          { label: 'Pending', value: counts.pending, tone: theme.colors.warning },
          { label: 'Syncing', value: counts.syncing, tone: theme.colors.primary },
          { label: 'Failed', value: counts.failed, tone: theme.colors.danger },
          { label: 'Synced', value: counts.synced, tone: theme.colors.success },
        ].map((c) => (
          <View key={c.label} style={s.stat}>
            <Text style={[s.statValue, { color: c.tone }]}>{c.value}</Text>
            <Text style={s.statLabel}>{c.label}</Text>
          </View>
        ))}
      </View>

      {/* Filter chips */}
      <View style={s.filters}>
        {FILTERS.map((f) => (
          <TouchableOpacity
            key={f.key}
            onPress={() => setFilter(f.key)}
            style={[s.filterChip, filter === f.key && { backgroundColor: theme.colors.primary }]}
          >
            <Text style={[s.filterText, filter === f.key && { color: theme.colors.white }]}>{f.label}</Text>
          </TouchableOpacity>
        ))}
      </View>

      <FlatList
        style={{ flex: 1 }}
        data={rows}
        keyExtractor={(i) => String(i.id)}
        contentContainerStyle={{ paddingVertical: 6, paddingBottom: 24 + insets.bottom }}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); processOutbox({ quiet: true }).finally(() => { setRefreshing(false); load() })} } tintColor={theme.colors.primary} />}
        renderItem={({ item }) => {
          const { kind, primary, p } = metaOf(item)
          const tone = statusTone(item)
          const isOpen = !!expanded[item.id]
          const busy = syncing && (item.status === 'syncing' || (item.status === 'pending' && syncing))
          return (
            <Card style={s.card} onPress={() => setExpanded((e) => ({ ...e, [item.id]: !e[item.id] }))}>
              <View style={s.rowTop}>
                <Text style={{ fontSize: 24 }}>{KIND_META[item.kind]?.icon || '🗂'}</Text>
                <View style={{ flex: 1, marginHorizontal: 10 }}>
                  <Text style={s.itemTitle} numberOfLines={1}>{primary}</Text>
                  <Text style={s.itemKind}>{kind}</Text>
                </View>
                <View style={[s.statusChip, { backgroundColor: tone }]}>
                  <Text style={s.statusText}>{statusLabel(item)}</Text>
                </View>
              </View>

              <Text style={s.meta}>
                Queued {fmtTime(item.created_at)}
                {item.status === 'synced' && item.synced_at ? ` · Synced ${fmtTime(item.synced_at)}` : ''}
                {(item.attempts || 0) > 0 && item.status !== 'synced' ? ` · Attempt ${item.attempts}` : ''}
              </Text>

              {item.status === 'failed' && item.last_error ? (
                <Text style={s.error} numberOfLines={2}>✕ {item.last_error}</Text>
              ) : null}

              {isOpen ? (
                <View style={s.detail}>
                  <DetailRow label="Local ID" value={item.local_transaction_id || '—'} />
                  {item.kind === 'receipt' && item.server_entity_id ? <DetailRow label="Server claim" value={item.server_entity_id} /> : null}
                  {item.kind === 'receipt' && item.status === 'synced' ? <DetailRow label="Payment status" value="Awaiting Review — not verified or marked paid" /> : null}
                  <DetailRow label="Property / Bill" value={p.property_address || p.billing_number || p.document_number || p.bill_number || '—'} />
                  <DetailRow label="GPS" value={p.gps_coordinate || (p.gps_lat ? `${p.gps_lat}, ${p.gps_lng}` : '—')} />
                  <DetailRow label="Amount" value={p.amount != null ? `USD ${p.amount}` : '—'} />
                  <DetailRow label="Receipt #" value={p.receipt_number || '—'} />
                  <DetailRow label="Notes" value={p.notes || p.remarks || '—'} />
                  {item.kind === 'action' ? <DetailRow label="Action" value={`${p.action} ${p.visit_date ? '@ ' + fmtTime(p.visit_date) : ''}`} /> : null}
                  <DetailRow label="Queued" value={fmtTime(item.created_at)} />
                </View>
              ) : null}

              <View style={s.actions}>
                {item.status !== 'synced' ? (
                  <TouchableOpacity
                    onPress={async () => { await retryRow(item.id); await load() }}
                    disabled={busy}
                    hitSlop={8}
                    style={[s.actionBtn, { backgroundColor: theme.colors.primary }]}
                  >
                    {busy ? <ActivityIndicator size="small" color="#fff" /> : <Text style={s.actionLabel}>Sync Now</Text>}
                  </TouchableOpacity>
                ) : (
                  <View style={{ flex: 1 }} />
                )}
                <TouchableOpacity onPress={() => setExpanded((e) => ({ ...e, [item.id]: !e[item.id] }))} hitSlop={8} style={s.actionBtnGhost}>
                  <Text style={s.actionGhostLabel}>{isOpen ? 'Hide details' : 'View details'}</Text>
                </TouchableOpacity>
                <TouchableOpacity onPress={() => remove(item)} hitSlop={8} style={s.actionBtnGhost}>
                  <Text style={[s.actionGhostLabel, { color: theme.colors.danger }]}>Remove</Text>
                </TouchableOpacity>
              </View>
            </Card>
          )
        }}
        ListEmptyComponent={
          filter === 'failed'
            ? <Empty icon="✅" title="No failures" sub="Every field record has reached the LRA servers." />
            : filter === 'synced'
              ? <Empty icon="🔄" title="Nothing synced yet" sub="Completed uploads will be listed here." />
              : <Empty icon="✅" title="All synced" sub="Field data captured offline uploads automatically when connectivity returns." />
        }
        ListFooterComponent={
          <View style={s.footer}>
            <Btn tone={pending > 0 ? 'navy' : 'success'} label={pending > 0 ? `🔄 Sync All (${pending})` : '✓ Up to date'} onPress={() => processOutbox({ quiet: true })} loading={syncing} />
            <Text style={s.footerNote}>Offline records stay on this device with retries until the server confirms receipt. Replays are de-duplicated server-side.</Text>
          </View>
        }
      />
    </View>
  )
}

function DetailRow({ label, value }) {
  return (
    <View style={s.detailRow}>
      <Text style={s.detailLabel}>{label}</Text>
      <Text style={s.detailValue} numberOfLines={2}>{value}</Text>
    </View>
  )
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: theme.colors.bg },
  summaryRow: { flexDirection: 'row', paddingHorizontal: 16, paddingTop: 12 },
  stat: { flex: 1, alignItems: 'center', backgroundColor: theme.colors.white, borderRadius: 12, paddingVertical: 10, marginRight: 8, borderWidth: 1, borderColor: theme.colors.border },
  statValue: { fontSize: 18, fontWeight: '800' },
  statLabel: { fontSize: 10, fontWeight: '700', color: theme.colors.textLight, marginTop: 2, textTransform: 'uppercase' },
  filters: { flexDirection: 'row', paddingHorizontal: 16, paddingVertical: 10 },
  filterChip: { paddingHorizontal: 14, paddingVertical: 6, borderRadius: 18, backgroundColor: theme.colors.white, marginRight: 8, borderWidth: 1, borderColor: theme.colors.border },
  filterText: { fontSize: 12, fontWeight: '700', color: theme.colors.textLight },
  card: { marginHorizontal: 16, marginBottom: 8 },
  rowTop: { flexDirection: 'row', alignItems: 'center' },
  itemTitle: { fontSize: 14, fontWeight: '700', color: theme.colors.text },
  itemKind: { fontSize: 11, color: theme.colors.textLight, marginTop: 2 },
  statusChip: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 14 },
  statusText: { color: theme.colors.white, fontSize: 11, fontWeight: '800' },
  meta: { fontSize: 11, color: theme.colors.textLight, marginTop: 8 },
  error: { fontSize: 11, color: theme.colors.danger, marginTop: 6, fontWeight: '600' },
  detail: { marginTop: 10, paddingTop: 8, borderTopWidth: 1, borderTopColor: theme.colors.border },
  detailRow: { flexDirection: 'row', paddingVertical: 2 },
  detailLabel: { width: 84, fontSize: 11, fontWeight: '700', color: theme.colors.textLight },
  detailValue: { flex: 1, fontSize: 11, color: theme.colors.text },
  actions: { flexDirection: 'row', alignItems: 'center', marginTop: 10, gap: 8 },
  actionBtn: { paddingHorizontal: 12, paddingVertical: 6, borderRadius: 8, minWidth: 74, alignItems: 'center', justifyContent: 'center' },
  actionLabel: { color: theme.colors.white, fontSize: 12, fontWeight: '800' },
  actionBtnGhost: { paddingHorizontal: 10, paddingVertical: 6, borderRadius: 8, borderWidth: 1, borderColor: theme.colors.border, backgroundColor: theme.colors.white },
  actionGhostLabel: { color: theme.colors.textLight, fontSize: 11, fontWeight: '700' },
  footer: { paddingHorizontal: 16, paddingTop: 8, paddingBottom: 24 },
  footerNote: { fontSize: 11, color: theme.colors.textLight, textAlign: 'center', marginTop: 10 },
})