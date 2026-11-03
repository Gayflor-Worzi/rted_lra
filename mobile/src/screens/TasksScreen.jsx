import { useState, useCallback } from 'react'
import { View, Text, FlatList, TouchableOpacity, StyleSheet, RefreshControl, Alert, ActivityIndicator, ScrollView } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import api from '../api'
import { fetchOfflineRead } from '../offline'
import { useSync } from '../sync'
import { useAuth } from '../auth'
import { can, serverMessage } from '../rbac'
import { theme } from '../theme'
import { BrandHeader, Badge, Empty, Card, OfflineReadStatus } from '../components'

const STATUS_GROUPS = [
  { key: 'all', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'pending', label: 'Pending' },
  { key: 'overdue', label: 'Overdue' },
  { key: 'escalated', label: 'Escalated' },
  { key: 'completed', label: 'Completed' },
]

const isCompleted = (task) => ['Resolved', 'Closed', 'Paid', 'Partially Paid'].includes(task.status)

export default function TasksScreen({ navigation }) {
  const { user, canUseOfflineCache } = useAuth()
  const insets = useSafeAreaInsets()
  const [tasks, setTasks] = useState(null)
  const [refreshing, setRefreshing] = useState(false)
  const [offline, setOffline] = useState(false)
  const [readStatus, setReadStatus] = useState(null)
  const [syncedAt, setSyncedAt] = useState(null)
  const [cacheError, setCacheError] = useState(null)
  const [error, setError] = useState('')
  const [statusGroup, setStatusGroup] = useState('active')
  const { pending } = useSync()

  const load = useCallback(async (showSpinner = true, group = statusGroup) => {
    if (showSpinner) setRefreshing(true)
    try {
      const result = await fetchOfflineRead({
        module: 'tasks',
        cacheKey: `${user?.role}:status_group:${group}`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get('/enforcement-assignments/my', { params: { status_group: group } }),
        select: (response) => response.data?.data?.data || response.data?.data || [],
      })
      if (result.status === 'expired') {
        setTasks([])
        setError('Your offline task copy has expired. Connect to refresh your assignments.')
      } else {
        setTasks(result.data)
        setError('')
      }
      setOffline(result.status === 'cached')
      setReadStatus(result.status)
      setSyncedAt(result.syncedAt)
      setCacheError(result.cacheError || null)
      if (result.status !== 'expired') setError('')
    } catch (error) {
      setError(serverMessage(error, 'Could not load your assignments.'))
      setTasks([])
      setOffline(false)
      setReadStatus(null)
    } finally { setRefreshing(false) }
  }, [statusGroup, user, canUseOfflineCache])

  useFocusEffect(useCallback(() => { load(false) }, [load]))

  const changeGroup = (group) => {
    setStatusGroup(group)
    setTasks(null)
    setError('')
    load(true, group)
  }

  const navigate = (item) => {
    navigation.navigate('TaskDetail', { taskId: item.id, assignment: item })
  }

  const openVisit = (item) => {
    const bill = item.property_bill || { id: item.property_bill_id, bill_number: `#${item.property_bill_id}` }
    navigation.navigate('VisitForm', { assignment: item, bill })
  }

  const openReceipt = (item) => {
    const bill = item.property_bill || { id: item.property_bill_id, bill_number: `#${item.property_bill_id}` }
    navigation.navigate('SubmitReceipt', { bill })
  }

  const stats = {
    total: tasks?.length || 0,
    completed: tasks?.filter(isCompleted).length || 0,
    overdue: tasks?.filter((t) => t.deadline_status?.is_overdue).length || 0,
  }

  const canLogVisit = can(user, 'tasks.complete') || can(user, 'enforcement.record_visit')
  const canClaimPayment = can(user, 'payments.claim')
  const row = ({ item }) => {
    const bill = item.property_bill
    return (
      <Card onPress={() => navigate(item)}>
        <View style={s.cardTop}>
          <View style={{ flex: 1 }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
              <Text style={s.billNo}>{bill?.bill_number || `Bill #${item.property_bill_id}`}</Text>
              <Badge status={item.status} />
            </View>
            <Text style={s.addr}>{bill?.property_address || '—'}</Text>
          </View>
          <Text style={s.chevron}>›</Text>
        </View>

        <View style={s.taskMeta}>
          <View style={s.metaPill}>
            <Text style={s.metaIcon}>📋</Text>
            <Text style={s.metaText}>{item.task_type?.replace(/_/g, ' ')}</Text>
          </View>
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
          {item.stage && (
            <View style={s.metaPill}>
              <Text style={s.metaIcon}>🏷</Text>
              <Text style={s.metaText}>Stage: {String(item.stage).replace(/_/g, ' ')}</Text>
            </View>
          )}
        </View>

        {bill?.outstanding_balance != null && (
          <View style={s.balanceRow}>
            <Text style={s.balanceLabel}>Outstanding</Text>
            <Text style={s.balanceValue}>US$ {Number(bill.outstanding_balance || 0).toLocaleString()}</Text>
          </View>
        )}

        <View style={s.actions}>
          {canLogVisit && (
            <TouchableOpacity style={s.visitBtn} onPress={() => openVisit(item)}>
              <Text style={s.visitBtnText}>Log Visit</Text>
            </TouchableOpacity>
          )}
          {canClaimPayment && (
            <TouchableOpacity style={s.receiptBtn} onPress={() => openReceipt(item)}>
              <Text style={s.receiptBtnText}>Receipt</Text>
            </TouchableOpacity>
          )}
        </View>
      </Card>
    )
  }

  return (
    <View style={s.container}>
      <BrandHeader
        title="My Tasks"
        subtitle={offline ? 'Offline — showing cached data' : 'Field enforcement queue'}
        right={pending > 0 ? <View style={s.pendingChip}><Text style={s.pendingText}>{pending} to sync</Text></View> : null}
      />
      <OfflineReadStatus status={readStatus} syncedAt={syncedAt} cacheError={cacheError} />

      {tasks === null ? (
        <View style={{ paddingTop: 60, alignItems: 'center' }}>
          <ActivityIndicator size="large" color={theme.colors.primary} />
          <Text style={{ color: theme.colors.textMuted, marginTop: 12, fontSize: 13 }}>Loading your assignments…</Text>
        </View>
      ) : error ? (
        <Card style={{ marginTop: 20 }}>
          <Text style={{ color: theme.colors.danger, fontWeight: '700', marginBottom: 8 }}>⚠ Unable to load tasks</Text>
          <Text style={{ color: theme.colors.textMuted, fontSize: 13, marginBottom: 12 }}>{error}</Text>
          <TouchableOpacity style={s.retryBtn} onPress={() => load(true)}>
            <Text style={{ color: '#fff', fontWeight: '800' }}>⟳ Retry</Text>
          </TouchableOpacity>
        </Card>
      ) : (
        <>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={s.filters}>
            {STATUS_GROUPS.map((group) => {
              const active = statusGroup === group.key
              return (
                <TouchableOpacity
                  key={group.key}
                  accessibilityRole="button"
                  accessibilityState={{ selected: active }}
                  onPress={() => changeGroup(group.key)}
                  style={[s.filterChip, active && s.filterChipActive]}>
                  <Text style={[s.filterText, active && s.filterTextActive]}>{group.label}</Text>
                </TouchableOpacity>
              )
            })}
          </ScrollView>
          {tasks.length > 0 && (
            <View style={s.statsRow}>
              <View style={s.stat}><Text style={s.statNum}>{stats.total}</Text><Text style={s.statLabel}>Assigned</Text></View>
              <View style={[s.stat, { borderLeftColor: theme.colors.border, borderLeftWidth: 1 }]}><Text style={[s.statNum, { color: theme.colors.success }]}>{stats.completed}</Text><Text style={s.statLabel}>Completed</Text></View>
              <View style={[s.stat, { borderLeftColor: theme.colors.border, borderLeftWidth: 1 }]}><Text style={[s.statNum, { color: theme.colors.danger }]}>{stats.overdue}</Text><Text style={s.statLabel}>Overdue</Text></View>
            </View>
          )}
          <FlatList
            data={tasks}
            keyExtractor={(i) => String(i.id)}
            renderItem={row}
            contentContainerStyle={{ paddingVertical: 8, paddingBottom: 24 + insets.bottom }}
            keyboardShouldPersistTaps="handled"
            refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => load(true)} tintColor={theme.colors.primary} />}
            ListEmptyComponent={<Empty icon="✅" title={`No ${STATUS_GROUPS.find((group) => group.key === statusGroup)?.label.toLowerCase()} tasks`} sub="Tasks matching this status will appear here." />}
          />
        </>
      )}
    </View>
  )
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: theme.colors.bg },
  pendingChip: { backgroundColor: theme.colors.primaryLight, borderRadius: 20, paddingHorizontal: 10, paddingVertical: 4 },
  pendingText: { fontSize: 11, fontWeight: '700', color: theme.colors.primaryDark },
  filters: { paddingHorizontal: 16, paddingTop: 12, paddingBottom: 4, gap: 8 },
  filterChip: { minHeight: 40, justifyContent: 'center', borderRadius: 20, borderWidth: 1, borderColor: theme.colors.border, backgroundColor: theme.colors.white, paddingHorizontal: 14 },
  filterChipActive: { backgroundColor: theme.colors.navy, borderColor: theme.colors.navy },
  filterText: { color: theme.colors.textMuted, fontSize: 12, fontWeight: '700' },
  filterTextActive: { color: theme.colors.white },
  retryBtn: { backgroundColor: theme.colors.primary, borderRadius: 10, paddingVertical: 14, alignItems: 'center', minHeight: 48, justifyContent: 'center' },
  statsRow: { flexDirection: 'row', backgroundColor: '#fff', marginTop: 12, marginHorizontal: 16, borderRadius: 12, borderWidth: 1, borderColor: theme.colors.border, overflow: 'hidden' },
  stat: { flex: 1, alignItems: 'center', paddingVertical: 12 },
  statNum: { fontSize: 20, fontWeight: '900', color: theme.colors.navy },
  statLabel: { fontSize: 11, color: theme.colors.textMuted, marginTop: 2 },
  cardTop: { flexDirection: 'row', alignItems: 'center' },
  billNo: { fontSize: 16, fontWeight: '800', color: theme.colors.navy },
  addr: { fontSize: 13, color: theme.colors.textMuted, marginTop: 3 },
  chevron: { fontSize: 22, color: theme.colors.textLight, marginLeft: 8 },
  taskMeta: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 10 },
  metaPill: { flexDirection: 'row', alignItems: 'center', backgroundColor: theme.colors.bg, borderRadius: 8, paddingHorizontal: 8, paddingVertical: 4 },
  metaIcon: { fontSize: 11, marginRight: 4 },
  metaText: { fontSize: 11, color: theme.colors.textMuted, fontWeight: '600', textTransform: 'capitalize' },
  balanceRow: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 10, backgroundColor: theme.colors.warningBg, borderRadius: 8, paddingHorizontal: 10, paddingVertical: 6 },
  balanceLabel: { fontSize: 12, color: theme.colors.warning, fontWeight: '600' },
  balanceValue: { fontSize: 12, fontWeight: '800', color: theme.colors.warning },
  actions: { flexDirection: 'row', gap: 8, marginTop: 12 },
  visitBtn: { flex: 1, backgroundColor: theme.colors.primary, borderRadius: 10, paddingVertical: 13, alignItems: 'center', minHeight: 46, justifyContent: 'center' },
  visitBtnText: { color: '#fff', fontWeight: '700', fontSize: 13 },
  receiptBtn: { flex: 1, backgroundColor: theme.colors.navy, borderRadius: 10, paddingVertical: 13, alignItems: 'center', minHeight: 46, justifyContent: 'center' },
  receiptBtnText: { color: '#fff', fontWeight: '700', fontSize: 13 },
})