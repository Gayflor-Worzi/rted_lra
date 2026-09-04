import { useState, useCallback } from 'react'
import { View, Text, FlatList, TouchableOpacity, StyleSheet, RefreshControl, Alert, ActivityIndicator } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import api from '../api'
import { cacheTasks, getCachedTasks } from '../db'
import { useSync } from '../sync'
import { useAuth } from '../auth'
import { can, serverMessage } from '../rbac'
import { theme } from '../theme'
import { BrandHeader, Badge, Empty, Card } from '../components'

export default function TasksScreen({ navigation }) {
  const { user } = useAuth()
  const insets = useSafeAreaInsets()
  const [tasks, setTasks] = useState(null)
  const [refreshing, setRefreshing] = useState(false)
  const [offline, setOffline] = useState(false)
  const [error, setError] = useState('')
  const { pending } = useSync()

  const load = async (showSpinner = true) => {
    if (showSpinner) setRefreshing(true)
    try {
      const r = await api.get('/enforcement-assignments/my')
      const list = r.data.data?.data || r.data.data || []
      setTasks(list)
      setOffline(false)
      setError('')
      await cacheTasks(list)
    } catch (e) {
      if (e.response) {
        setError(serverMessage(e, 'Could not load your assignments.'))
        setTasks([])
      } else {
        const cached = await getCachedTasks()
        setTasks(cached)
        setOffline(cached.length > 0)
      }
    } finally { setRefreshing(false) }
  }

  useFocusEffect(useCallback(() => { load(false) }, []))

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
    completed: tasks?.filter((t) => ['Delivered', 'Closed', 'Resolved', 'Paid', 'Payment Claimed'].includes(t.status)).length || 0,
    overdue: tasks?.filter((t) => ['Escalated', '30-Day Warning', '72-Hour Warning'].includes(t.status) || (t.due_date && t.due_date.slice(0, 10) < new Date().toISOString().slice(0, 10))).length || 0,
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
        title="Assigned Tasks"
        subtitle={offline ? 'Offline — showing cached data' : 'Field enforcement queue'}
        right={pending > 0 ? <View style={s.pendingChip}><Text style={s.pendingText}>{pending} to sync</Text></View> : null}
      />

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
            ListEmptyComponent={<Empty icon="✅" title="No assignments" sub="When enforcement cases are assigned to you, they will appear here." />}
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