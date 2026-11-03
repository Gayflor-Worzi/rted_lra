import { useState, useCallback } from 'react'
import { View, Text, FlatList, TouchableOpacity, StyleSheet, RefreshControl, ActivityIndicator } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import api from '../api'
import { useAuth } from '../auth'
import { fetchOfflineRead } from '../offline'
import { serverMessage } from '../rbac'
import { theme } from '../theme'
import { BrandHeader, Badge, Empty, Card, OfflineReadStatus } from '../components'

/**
 * Generic section workspace task screen (Assistant Commissioner / Valuation).
 * Reads the section workspace API — the assigned list is your personal work and
 * the queue is the section desk queue. The backend scopes everything, so no
 * enforcement route is involved for these roles.
 */
export default function WorkspaceTasksScreen({ navigation, route }) {
  const { workspace, subtitle } = route?.params || {}
  const { user, canUseOfflineCache } = useAuth()
  const insets = useSafeAreaInsets()
  const [tab, setTab] = useState('assigned')
  const [tasks, setTasks] = useState(null)
  const [queue, setQueue] = useState(null)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState('')
  const [readStatus, setReadStatus] = useState({ assigned: null, queue: null })
  const [syncedAt, setSyncedAt] = useState({ assigned: null, queue: null })

  const load = useCallback(async (showSpinner = true) => {
    if (showSpinner) setRefreshing(true)
    setError('')
    try {
      const [t, q] = await Promise.all([
        fetchOfflineRead({
          module: 'tasks',
          cacheKey: `${user?.role}:${workspace}:assigned:50`,
          user,
          allowCache: canUseOfflineCache,
          request: () => api.get(`/${workspace}/tasks`, { params: { per_page: 50 } }),
          select: (response) => response.data?.data?.data || response.data?.data || [],
        }),
        fetchOfflineRead({
          module: 'tasks',
          cacheKey: `${user?.role}:${workspace}:queue:50`,
          user,
          allowCache: canUseOfflineCache,
          request: () => api.get(`/${workspace}/tasks/queue`, { params: { per_page: 50 } }),
          select: (response) => response.data?.data?.data || response.data?.data || [],
        }),
      ])
      setTasks(t.status === 'expired' ? [] : t.data)
      setQueue(q.status === 'expired' ? [] : q.data)
      setReadStatus({ assigned: t.status, queue: q.status })
      setSyncedAt({ assigned: t.syncedAt, queue: q.syncedAt })
      if (t.status === 'expired' || q.status === 'expired') setError('Some offline work data has expired. Connect to refresh it.')
    } catch (e) {
      setError(serverMessage(e, 'Could not load your workspace queue.'))
    } finally { setRefreshing(false) }
  }, [workspace, user, canUseOfflineCache])

  useFocusEffect(useCallback(() => { load(false) }, [load]))

  const taskRow = ({ item }) => (
    <Card onPress={() => navigation.navigate('TaskDetail', { taskId: item.id, assignment: item })}>
      <View style={s.cardTop}>
        <View style={{ flex: 1 }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
            <Text style={s.title}>{String(item.task_type || 'Task').replace(/_/g, ' ')}</Text>
            <Badge status={item.status} />
          </View>
          <Text style={s.sub}>{item.property_address || item.tin || '—'}</Text>
        </View>
        <Text style={s.chevron}>›</Text>
      </View>

      <View style={s.metaRow}>
        {item.due_date && (
          <View style={s.metaPill}>
            <Text style={s.metaIcon}>📅</Text>
            <Text style={s.metaText}>Due {item.due_date.slice(0, 10)}</Text>
          </View>
        )}
        {item.next_action && (
          <View style={s.metaPill}>
            <Text style={s.metaIcon}>➡️</Text>
            <Text style={s.metaText}>Next: {typeof item.next_action === 'object' ? (item.next_action.verb || '—') : String(item.next_action)}</Text>
          </View>
        )}
      </View>
    </Card>
  )

  const list = tab === 'assigned' ? tasks : queue

  return (
    <View style={s.container}>
      <BrandHeader title="My Tasks" subtitle={subtitle || 'Section workspace'} />
      <OfflineReadStatus status={tab === 'assigned' ? readStatus.assigned : readStatus.queue} syncedAt={tab === 'assigned' ? syncedAt.assigned : syncedAt.queue} />

      <View style={s.seg}>
        {[
          { key: 'assigned', label: `Assigned (${tasks?.length ?? '…'})` },
          { key: 'queue', label: `Queue (${queue?.length ?? '…'})` },
        ].map((seg) => (
          <TouchableOpacity key={seg.key} onPress={() => setTab(seg.key)}
            style={[s.segBtn, tab === seg.key && s.segBtnActive]}>
            <Text style={[s.segText, tab === seg.key && s.segTextActive]}>{seg.label}</Text>
          </TouchableOpacity>
        ))}
      </View>

      {list === null ? (
        <View style={{ paddingTop: 60, alignItems: 'center' }}>
          <ActivityIndicator size="large" color={theme.colors.primary} />
          <Text style={{ color: theme.colors.textMuted, marginTop: 12, fontSize: 13 }}>Loading…</Text>
        </View>
      ) : (
        <FlatList
          data={list}
          keyExtractor={(i) => String(i.id)}
          renderItem={taskRow}
          contentContainerStyle={{ paddingVertical: 8, paddingBottom: 24 + insets.bottom }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => load(true)} tintColor={theme.colors.primary} />}
          ListEmptyComponent={
            error ? (
              <Card style={{ marginTop: 20 }}>
                <Text style={{ color: theme.colors.danger, fontWeight: '700', marginBottom: 8 }}>⚠ Unable to load</Text>
                <Text style={{ color: theme.colors.textMuted, fontSize: 13, marginBottom: 12 }}>{error}</Text>
                <TouchableOpacity style={s.retryBtn} onPress={() => load(true)}>
                  <Text style={{ color: '#fff', fontWeight: '800' }}>⟳ Retry</Text>
                </TouchableOpacity>
              </Card>
            ) : (
              <Empty icon={tab === 'assigned' ? '📋' : '🗂️'}
                title={tab === 'assigned' ? 'No tasks assigned' : 'Desk queue empty'}
                sub={tab === 'assigned' ? 'Your section work will appear here.' : 'No unassigned desk tasks right now.'} />
            )
          }
        />
      )}
    </View>
  )
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: theme.colors.bg },
  retryBtn: { backgroundColor: theme.colors.primary, borderRadius: 10, paddingVertical: 14, alignItems: 'center', minHeight: 48, justifyContent: 'center' },
  seg: { flexDirection: 'row', backgroundColor: '#fff', marginHorizontal: 16, marginTop: 12, borderRadius: 12, borderWidth: 1, borderColor: theme.colors.border, padding: 3 },
  segBtn: { flex: 1, alignItems: 'center', paddingVertical: 8, borderRadius: 9 },
  segBtnActive: { backgroundColor: theme.colors.primary },
  segText: { fontSize: 12, fontWeight: '700', color: theme.colors.textMuted },
  segTextActive: { color: '#fff' },
  cardTop: { flexDirection: 'row', alignItems: 'center' },
  title: { fontSize: 15, fontWeight: '800', color: theme.colors.navy, textTransform: 'capitalize', flexShrink: 1 },
  sub: { fontSize: 13, color: theme.colors.textMuted, marginTop: 3 },
  chevron: { fontSize: 22, color: theme.colors.textLight, marginLeft: 8 },
  metaRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 10 },
  metaPill: { flexDirection: 'row', alignItems: 'center', backgroundColor: theme.colors.bg, borderRadius: 8, paddingHorizontal: 8, paddingVertical: 4 },
  metaIcon: { fontSize: 11, marginRight: 4 },
  metaText: { fontSize: 11, color: theme.colors.textMuted, fontWeight: '600', textTransform: 'capitalize' },
})