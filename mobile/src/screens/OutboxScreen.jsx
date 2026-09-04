import { useState, useCallback } from 'react'
import { View, Text, FlatList, TouchableOpacity, StyleSheet, RefreshControl, Alert, ActivityIndicator } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { useSync } from '../sync'
import { getDb, outboxAll, outboxDelete } from '../db'
import { theme } from '../theme'
import { BrandHeader, Card, Empty, Btn } from '../components'

const KIND_META = {
  visit: { icon: '📋', label: 'Enforcement Visit', tone: theme.colors.primary },
  discovery: { icon: '📍', label: 'Property Discovery', tone: theme.colors.navy },
  receipt: { icon: '💰', label: 'Payment Receipt', tone: theme.colors.success },
  action: { icon: '⚡', label: 'Task Action', tone: theme.colors.warning },
}

export default function OutboxScreen() {
  const { pending, syncing, processOutbox } = useSync()
  const insets = useSafeAreaInsets()
  const [rows, setRows] = useState([])
  const [refreshing, setRefreshing] = useState(false)

  const load = async () => {
    await getDb()
    setRows(await outboxAll())
  }

  useFocusEffect(useCallback(() => { load() }, [pending]))

  const remove = async (id) => {
    await outboxDelete(id)
    load()
  }

  const renderedIcon = (item) => {
    const k = KIND_META[item.kind] || { icon: '🗂' }
    return <Text style={{ fontSize: 24 }}>{k.icon}</Text>
  }

  const title = (item) => {
    const k = KIND_META[item.kind]?.label || item.kind
    let p = {}
    try { p = JSON.parse(item.payload) } catch {}
    return `${k} — ${p.billing_number || p.bill_number || p.property_address || p.action || 'entry'}`
  }

  return (
    <View style={s.container}>
      <BrandHeader title="Offline Queue" subtitle={rows.length ? 'Awaiting upload to LRA servers' : 'Everything synced'} />

      {rows.length === 0 && syncing ? (
        <View style={{ paddingTop: 100, alignItems: 'center' }}>
          <ActivityIndicator size="large" color={theme.colors.primary} />
          <Text style={{ color: theme.colors.textMuted, marginTop: 12, fontSize: 13 }}>Checking for pending uploads…</Text>
        </View>
      ) : (
        <FlatList
          style={{ flex: 1 }}
          data={rows}
          keyExtractor={(i) => String(i.id)}
          contentContainerStyle={{ paddingVertical: 10, paddingBottom: 24 + insets.bottom }}
          keyboardShouldPersistTaps="handled"
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); processOutbox().finally(() => { setRefreshing(false); load() })} } tintColor={theme.colors.primary} />}
          renderItem={({ item }) => (
            <Card style={{ flexDirection: 'row', alignItems: 'center' }}>
              {renderedIcon(item)}
              <View style={{ flex: 1, marginHorizontal: 12 }}>
                <Text style={s.itemTitle} numberOfLines={1}>{title(item)}</Text>
                <Text style={s.itemTime}>Queued {item.created_at?.replace('T', ' ').slice(0, 16)}</Text>
              </View>
              <TouchableOpacity onPress={() => remove(item.id)} hitSlop={10} style={{ padding: 8 }}>
                <Text style={{ color: theme.colors.danger, fontSize: 12, fontWeight: '700' }}>Remove</Text>
              </TouchableOpacity>
            </Card>
          )}
          ListEmptyComponent={<Empty icon="✅" title="All synced" sub="Field data captured offline uploads automatically when connectivity returns." />}
          ListFooterComponent={
            <View style={s.footer}>
              <Btn tone={pending > 0 ? 'navy' : 'success'} label={pending > 0 ? `🔄 Sync Now (${pending} pending)` : '✓ Up to date'} onPress={processOutbox} loading={syncing} />
              <Text style={s.footerNote}>Ensure steady internet before syncing. Offline data stays on device until upload succeeds.</Text>
            </View>
          }
        />
      )}
    </View>
  )
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: theme.colors.bg },
  itemTitle: { fontSize: 14, fontWeight: '700', color: theme.colors.text },
  itemTime: { fontSize: 11, color: theme.colors.textLight, marginTop: 3 },
  footer: { paddingHorizontal: 16, paddingTop: 8, paddingBottom: 24 },
  footerNote: { fontSize: 11, color: theme.colors.textLight, textAlign: 'center', marginTop: 10 },
})