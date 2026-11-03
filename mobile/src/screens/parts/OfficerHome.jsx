import { useCallback, useEffect, useState } from 'react'
import { View, Text, TouchableOpacity, Alert, AppState, useWindowDimensions } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import api from '../../api'
import { useAuth } from '../../auth'
import { fetchOfflineRead } from '../../offline'
import { theme } from '../../theme'
import { Card, Badge, FilterDropdown, OfflineReadStatus } from '../../components'
import { Donut, HBars } from '../../charts'

const fmtMoney = (v) => (v == null || Number.isNaN(Number(v)) ? '—' : '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
const fmtNum = (v) => (v == null || Number.isNaN(Number(v)) ? '—' : `${Number(v)}`)

// Queue routes sent by the backend → the closest mobile destination.
const ROUTE_TARGET = { '/bills': 'Tasks', '/payments': 'Verifications', '/enforcement': 'Tasks', '/notices': 'Notices' }

const toneColor = (t) => ({
  navy: theme.colors.navy, blue: theme.colors.info, green: theme.colors.success,
  red: theme.colors.danger, amber: theme.colors.warning, brand: theme.colors.primary,
}[t] || theme.colors.navy)

const todayIcon = { deliveries: '🚚', followups: '📞', verifications: '✅', reminders: '📨', demands: '🚨', other: '🗂️' }
const todayTone = { deliveries: 'blue', followups: 'amber', verifications: 'blue', reminders: 'amber', demands: 'red', other: 'navy' }

/**
 * Role-specific officer home — the same /dashboard/officer payload the web
 * console renders, so web/mobile figures always agree.
 *
 * Enforcement role builds a personal operational cockpit: compact KPI tiles,
 * Today's Work near the top, collapsible charts, "My Active Assignments"
 * (from /enforcement-assignments/my) with "View Case ›" into TaskDetail, and
 * compact quick actions — no supervisory or divisional statistics.
 *
 * Account & Records role keeps the merged register portals + money tiles.
 * The device's bottom navigation is never modified here.
 */
export default function OfficerHome({ navigation, quickActions = [] }) {
  const { user, canUseOfflineCache } = useAuth()
  const { width } = useWindowDimensions()
  const donutSize = Math.min(Math.min(width, 700) - 128, 210)

  const [data, setData] = useState(null)
  const [err, setErr] = useState('')
  const [loading, setLoading] = useState(true)
  const [year, setYear] = useState('')
  const [status, setStatus] = useState('')
  const [paymentStatus, setPaymentStatus] = useState('')
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [chartsOpen, setChartsOpen] = useState(false)
  const [assignments, setAssignments] = useState([])
  const [dashboardReadStatus, setDashboardReadStatus] = useState(null)
  const [dashboardSyncedAt, setDashboardSyncedAt] = useState(null)
  const [assignmentReadStatus, setAssignmentReadStatus] = useState(null)
  const [assignmentSyncedAt, setAssignmentSyncedAt] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (year) params.year = year
      if (status) params[data?.meta?.filters?.status_param || 'bill_status'] = status
      if (paymentStatus) params.payment_status = paymentStatus
      const result = await fetchOfflineRead({
        module: 'dashboard',
        cacheKey: `${user?.role}:officer:${JSON.stringify(params)}`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get('/dashboard/officer', { params }),
        select: (response) => response.data?.data || response.data || {},
      })
      if (result.status === 'expired') {
        setData(null)
        setErr('Offline dashboard data has expired. Connect to refresh.')
      } else {
        setData(result.data)
        setErr('')
      }
      setDashboardReadStatus(result.status)
      setDashboardSyncedAt(result.syncedAt)
    } catch (e) {
      setErr(e?.response?.data?.message || e?.message || 'Unable to load your dashboard data.')
      setData(null)
      setDashboardReadStatus(null)
    }
    setLoading(false)
  }, [year, status, paymentStatus, user, canUseOfflineCache]) // eslint-disable-line react-hooks/exhaustive-deps

  const type = data?.meta?.type
  const isEnf = type === 'enforcement'

  // The officer's own active assignments (assigned_to == current user).
  const loadAssignments = useCallback(async () => {
    try {
      const result = await fetchOfflineRead({
        module: 'tasks',
        cacheKey: `${user?.role}:officer-home:active`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get('/enforcement-assignments/my'),
        select: (response) => response.data?.data?.data || response.data?.data || [],
      })
      const list = result.status === 'expired' ? [] : result.data
      setAssignments(Array.isArray(list) ? list : [])
      setAssignmentReadStatus(result.status)
      setAssignmentSyncedAt(result.syncedAt)
    } catch {
      setAssignments([])
      setAssignmentReadStatus(null)
    }
  }, [user, canUseOfflineCache])

  useFocusEffect(useCallback(() => { load() }, [load])) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (isEnf) loadAssignments()
    else setAssignments([])
  }, [isEnf, loadAssignments])

  // Keep figures live when the app returns to the foreground — a payment
  // confirmed on another device must show up on this dashboard without a
  // manual pull-to-refresh.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') {
        load()
        if (isEnf) loadAssignments()
      }
    })
    return () => sub.remove()
  }, [load, isEnf, loadAssignments]) // eslint-disable-line react-hooks/exhaustive-deps

  const openQueue = (route) => {
    const wanted = ROUTE_TARGET[route] || 'Tasks'
    const names = navigation.getParent?.()?.getState?.()?.routeNames || []
    const target = names.includes(wanted) ? wanted : (names.includes('Tasks') ? 'Tasks' : names[0])
    navigation.navigate(target)
  }

  const tapBar = (d) => Alert.alert(String(d.label), `${fmtNum(d.value)} bill(s) in the applied filters.`, [{ text: 'OK' }])
  const tapSlice = (d) => {
    const total = (data?.pie?.data || []).reduce((s, x) => s + Number(x.value), 0)
    Alert.alert(String(d.label), `${fmtNum(d.value)} · ${total ? Math.round((Number(d.value) / total) * 100) : 0}% of the range.`, [{ text: 'OK' }])
  }

  const kpiTarget = (k) => {
    if (k.key === 'notices') return 'Notices'
    if (k.key === 'outstanding') return 'Tasks'
    if (typeof k.drill === 'object' && k.drill) return 'Tasks'
    return null
  }
  const tapKpi = (k) => {
    const t = kpiTarget(k)
    if (t) navigation.navigate(t)
    else Alert.alert(k.label, `${k.money ? fmtMoney(k.value) : fmtNum(k.value)} in the applied filters.`, [{ text: 'OK' }])
  }

  if (err && !data) {
    return (
      <>
        <OfflineReadStatus status={dashboardReadStatus} syncedAt={dashboardSyncedAt} />
        <Card style={{ marginTop: 12 }}>
          <Text style={{ color: theme.colors.danger, fontWeight: '600' }}>{err}</Text>
          <TouchableOpacity onPress={load} style={{ marginTop: 8, minHeight: 44, justifyContent: 'center' }}>
            <Text style={{ color: theme.colors.primary, fontWeight: '700', fontSize: 13 }}>⟳ Try again</Text>
          </TouchableOpacity>
        </Card>
      </>
    )
  }

  if (!data) {
    return (
      <Card style={{ marginTop: 12 }}>
        <Text style={{ color: theme.colors.textMuted }}>{loading ? 'Loading your dashboard…' : 'No dashboard data yet.'}</Text>
      </Card>
    )
  }

  const filters = data.meta?.filters || {}
  const kpis = data.kpis || []
  const queues = data.queues || []
  const moneyKpis = kpis.filter((k) => k.money)
  const queueTwin = isEnf
    ? ['my_assigned', 'field_delivery', 'payment_followups', 'notices', 'escalated']
    : ['bills_lodged', 'bills_pending']
  const extraKpis = kpis.filter((k) => !k.money && !queueTwin.includes(k.key))
  const hasFilterOptions = (filters.years || filters.statuses || filters.payment_statuses).length > 0
  const filterBits = []
  if (year) filterBits.push('Year ' + year)
  if (status) filterBits.push('Stage')
  if (paymentStatus) filterBits.push('Payment')
  const filterActive = filterBits.length > 0

  const renderFilters = () => (
    <View style={[s.dropRow, { marginTop: 8 }]}>
      {(filters.years || []).length > 0 && (
        <FilterDropdown
          label="Year"
          options={filters.years}
          value={year.length ? String(year) : ''}
          onSelect={(v) => setYear(v)}
          color={theme.colors.navy}
        />
      )}
      {(filters.statuses || []).length > 0 && (
        <FilterDropdown
          label={isEnf ? 'Stage' : 'Bill status'}
          options={filters.statuses}
          value={status.length ? String(status) : ''}
          onSelect={setStatus}
        />
      )}
      {(filters.payment_statuses || []).length > 0 && (
        <FilterDropdown
          label="Payment status"
          options={filters.payment_statuses}
          value={paymentStatus.length ? String(paymentStatus) : ''}
          onSelect={setPaymentStatus}
        />
      )}
    </View>
  )

  return (
    <>
      <OfflineReadStatus status={dashboardReadStatus} syncedAt={dashboardSyncedAt} />
      <OfflineReadStatus status={assignmentReadStatus} syncedAt={assignmentSyncedAt} />
      <Card style={{ marginTop: 12 }}>
        {isEnf ? (
          <>
            <View style={s.headRow}>
              <View style={{ flex: 1 }}>
                <Text style={s.cardHeader}>My Work</Text>
                <Text style={s.period}>
                  {data.meta?.range?.start} → {data.meta?.range?.end}
                  {'  ·  '}as of {data.meta?.as_of ? new Date(data.meta.as_of).toLocaleDateString() : ''}
                </Text>
                <Text style={s.hint}>
                  {filterActive
                    ? `Filtered · ${filterBits.join(' · ')}`
                    : 'All my assigned records · tap a card to open the register'}
                </Text>
              </View>
              {hasFilterOptions && (
                <TouchableOpacity onPress={() => setFiltersOpen((v) => !v)} activeOpacity={0.7} style={[s.filtersPill, filterActive && s.filtersPillActive]}>
                  <Text style={[s.filtersPillText, filterActive && s.filtersPillTextActive]}>
                    {filtersOpen ? 'Close' : 'Filter'} {filterActive ? `(${filterBits.length})` : ''}
                  </Text>
                </TouchableOpacity>
              )}
            </View>
            {filtersOpen && renderFilters()}
          </>
        ) : (
          <>
            <Text style={s.cardHeader}>Account &amp; Records Overview</Text>
            <Text style={s.period}>
              {data.meta?.range?.start} → {data.meta?.range?.end}
              {'  ·  '}as of {data.meta?.as_of ? new Date(data.meta.as_of).toLocaleDateString() : ''}
            </Text>
            {hasFilterOptions && renderFilters()}
          </>
        )}
      </Card>

      {isEnf ? <EnforcementCockpit
        data={data}
        kpis={kpis}
        onKpi={tapKpi}
        assignments={assignments}
        chartsOpen={chartsOpen}
        onToggleCharts={() => setChartsOpen((v) => !v)}
        donutSize={donutSize}
        onBar={tapBar}
        onSlice={tapSlice}
        navigation={navigation}
        quickActions={quickActions}
      /> : (
        <>
          {/* Task register snapshot */}
          <Card style={s.card}>
            <Text style={s.cardHeader}>Task Register</Text>
            <Text style={s.hint}>Your live assignments — the same records as the Tasks tab.</Text>
            <View style={s.miniGrid}>
              <TaskStat label="My tasks" value={data.my_tasks.total} />
              <TaskStat label="Active" value={data.my_tasks.active} />
              <TaskStat label="Overdue" value={data.my_tasks.overdue} danger={(data.my_tasks.overdue ?? 0) > 0} />
              <TaskStat label="Due today" value={data.my_tasks.due_today} warn={(data.my_tasks.due_today ?? 0) > 0} />
              <TaskStat label="Escalated" value={data.my_tasks.escalated} danger={(data.my_tasks.escalated ?? 0) > 0} />
              <TaskStat label="Completed" value={data.my_tasks.completed} ok />
            </View>
          </Card>

          {(queues.length > 0 || moneyKpis.length > 0 || extraKpis.length > 0) && (
            <Card style={s.card}>
              <Text style={s.cardHeader}>My Position &amp; Work Queues</Text>
              <Text style={s.hint}>Tap a status card to open that record's register; money figures are totals in this scope.</Text>
              <View style={s.qGrid}>
                {queues.map((q) => (
                  <TouchableOpacity key={q.key} onPress={() => openQueue(q.route)} activeOpacity={0.7} style={s.qTile}>
                    <Text style={[s.qValue, { color: toneColor(q.tone) }]} numberOfLines={1}>{fmtNum(q.value)}</Text>
                    <Text style={s.qLabel} numberOfLines={2}>{q.label}</Text>
                    <Text style={s.qGo}>›</Text>
                  </TouchableOpacity>
                ))}
                {moneyKpis.map((k) => (
                  <View key={k.key} style={s.qTile}>
                    <Text style={[s.qValue, { color: theme.colors.danger }]} numberOfLines={1}>{fmtMoney(k.value)}</Text>
                    <Text style={s.qLabel} numberOfLines={2}>{k.label}</Text>
                  </View>
                ))}
                {extraKpis.map((k) => (
                  <View key={k.key} style={s.qTile}>
                    <Text style={[s.qValue, { color: toneColor(k.tone) }]} numberOfLines={1}>{fmtNum(k.value)}</Text>
                    <Text style={s.qLabel} numberOfLines={2}>{k.label}</Text>
                  </View>
                ))}
              </View>
            </Card>
          )}

          <Card style={s.card}>
            <Text style={s.cardHeader}>Bills by Case Status</Text>
            <HBars data={data.bar?.data || []} format={fmtNum} onPress={tapBar} />
          </Card>

          <Card style={s.card}>
            <Text style={s.cardHeader}>Bills by Payment Status</Text>
            <View style={{ alignItems: 'center' }}>
              <Donut data={data.pie?.data || []} size={donutSize} center="Bills" onSlice={tapSlice} />
            </View>
          </Card>
        </>
      )}
    </>
  )
}

function EnforcementCockpit({ data, kpis, onKpi, assignments, chartsOpen, onToggleCharts, donutSize, onBar, onSlice, navigation, quickActions }) {
  const myTasks = data.my_tasks || {}

  return (
    <>
      {/* My Tasks compact link */}
      <Card style={s.card}>
        <View style={s.headRow}>
          <View style={{ flex: 1 }}>
            <Text style={s.cardHeader}>My Tasks</Text>
            <Text style={s.hint}>
              <Text style={{ fontWeight: '800', color: theme.colors.navy }}>{fmtNum(myTasks.active)}</Text> active
              {'   ·   Overdue '}
              <Text style={{ fontWeight: '700', color: theme.colors.warning }}>{fmtNum(myTasks.overdue)}</Text>
              {'   ·   Due today '}
              <Text style={{ fontWeight: '700', color: theme.colors.warning }}>{fmtNum(myTasks.due_today)}</Text>
            </Text>
          </View>
          <TouchableOpacity onPress={() => navigation.navigate('Tasks')} hitSlop={6}>
            <Text style={s.cardLink}>View all ›</Text>
          </TouchableOpacity>
        </View>
      </Card>

      {/* Essential KPI cards */}
      <Card style={s.card}>
        <Text style={s.cardHeader}>My Position at a Glance</Text>
        <Text style={s.hint}>Tap a card to open the matching register.</Text>
        <View style={s.qGrid}>
          {kpis.map((k) => (
            <TouchableOpacity key={k.key} onPress={() => onKpi(k)} activeOpacity={0.7} style={s.kTile}>
              <View style={s.kTop}>
                <Text style={[s.kValue, { color: toneColor(k.tone) }]} numberOfLines={1}>{k.money ? fmtMoney(k.value) : fmtNum(k.value)}</Text>
                <Text style={s.kIcon}>{k.icon}</Text>
              </View>
              <Text style={s.qLabel} numberOfLines={2}>{k.label}</Text>
            </TouchableOpacity>
          ))}
        </View>
      </Card>

      {/* Today's Work — near the top so "what do I do today" is answered first */}
      <Card style={s.card}>
        <View style={s.headRow}>
          <View style={{ flex: 1 }}>
            <Text style={s.cardHeader}>Today's Work</Text>
          </View>
          {(data.today_work?.total || 0) > 0
            ? <Badge status="Overdue" label={`${data.today_work.total} action${data.today_work.total === 1 ? '' : 's'}`} />
            : <Badge status="Completed" label="All clear" />}
        </View>
        {!data.today_work || data.today_work.items.length === 0 ? (
          <Text style={s.mutedLine}>No today's-work section for this role.</Text>
        ) : (data.today_work.total || 0) === 0 ? (
          <Text style={s.mutedLine}>No outstanding actions for today.</Text>
        ) : (
          <View style={s.miniGrid}>
            {data.today_work.items.filter((b) => b.count > 0).map((b) => (
              <View key={b.key} style={s.miniTile}>
                <Text style={[s.miniValue, { color: toneColor(todayTone[b.key]) }]} numberOfLines={1}>{fmtNum(b.count)}</Text>
                <Text style={[s.miniLabel, { fontSize: 9 }]} numberOfLines={2}>{`${todayIcon[b.key] || '•'} ${b.label}`}</Text>
              </View>
            ))}
          </View>
        )}
      </Card>

      {/* Collapsible charts */}
      <TouchableOpacity onPress={onToggleCharts} activeOpacity={0.8}>
        <Card style={s.card}>
          <View style={s.headRow}>
            <View style={{ flex: 1 }}>
              <Text style={s.cardHeader}>Stages &amp; Payments</Text>
              <Text style={s.hint}>{chartsOpen ? 'Tap to collapse' : 'My cases by stage and payment status'}</Text>
            </View>
            <Text style={s.chev}>{chartsOpen ? '⌃' : '⌄'}</Text>
          </View>
          {chartsOpen && (
            <>
              <Text style={s.chartSub}>My cases by current stage</Text>
              <HBars data={data.bar?.data || []} format={fmtNum} onPress={onBar} />
              <Text style={[s.chartSub, { marginTop: 12 }]}>My cases by payment status</Text>
              <View style={{ alignItems: 'center' }}>
                <Donut data={data.pie?.data || []} size={donutSize} center="Bills" onSlice={onSlice} />
              </View>
            </>
          )}
        </Card>
      </TouchableOpacity>

      {/* My Active Assignments */}
      <Card style={s.card}>
        <View style={s.headRow}>
          <View style={{ flex: 1 }}>
            <Text style={s.cardHeader}>My Active Assignments</Text>
            <Text style={s.hint}>Your own authorized cases, most urgent first.</Text>
          </View>
          {assignments.length > 0 && (
            <TouchableOpacity onPress={() => navigation.navigate('Tasks')} hitSlop={6}>
              <Text style={s.cardLink}>View all ›</Text>
            </TouchableOpacity>
          )}
        </View>
        {assignments.length === 0 ? (
          <Text style={s.mutedLine}>Nothing assigned right now.</Text>
        ) : (
          assignments.slice(0, 5).map((a, i) => (
            <AssignmentCard
              key={a.id}
              item={a}
              onOpen={() => navigation.navigate('TaskDetail', { taskId: a.id, assignment: a })}
              divider={i > 0}
            />
          ))
        )}
      </Card>

      {/* Quick actions — compact, permission-driven from the backend */}
      {quickActions.length > 0 && (
        <Card style={s.card}>
          <Text style={s.cardHeader}>Quick Actions</Text>
          <View style={s.miniGrid}>
            {quickActions.map((qa) => (
              <TouchableOpacity key={qa.key} onPress={() => navigation.navigate(qa.route)} activeOpacity={0.7} style={s.qaTile}>
                <Text style={s.qaIcon}>{qa.icon || '•'}</Text>
                <Text style={s.qaLabel} numberOfLines={2}>{qa.label}</Text>
              </TouchableOpacity>
            ))}
          </View>
        </Card>
      )}
    </>
  )
}

function AssignmentCard({ item, onOpen, divider }) {
  const bill = item.property_bill || {}
  const overdue = item.deadline_status?.is_overdue
  const next = item.next_action && (typeof item.next_action === 'object' ? (item.next_action.verb || JSON.stringify(item.next_action)) : String(item.next_action))
  return (
    <TouchableOpacity onPress={onOpen} activeOpacity={0.7} style={[s.assignRow, divider && s.rowBorder]}>
      <View style={s.assignTop}>
        <Text style={s.assignTitle} numberOfLines={1}>{bill.document_number || item.task_reference || `#${item.id}`}</Text>
        <Badge status={item.status} label={item.status} />
      </View>
      <View style={s.assignMeta}>
        {bill.property_id ? <AssignMeta icon="📍" text={`Prop ${bill.property_id}`} /> : null}
        {item.due_date ? <AssignMeta icon="📅" text={overdue ? `Due ${item.due_date.slice(0, 10)}` : item.due_date.slice(0, 10)} urgent={overdue} /> : null}
      </View>
      <Text style={s.assignAddr} numberOfLines={1}>{bill.property_address || '—'}</Text>
      <View style={s.assignBottom}>
        <View style={{ flex: 1 }}>
          <Text style={s.assignMoney}>{fmtMoney(bill.total_tax_due)} <Text style={s.assignMoneyDim}>due</Text></Text>
          <Text style={s.assignOutstanding}>Outstanding {fmtMoney(bill.outstanding_balance)}</Text>
        </View>
      </View>
      {next ? <Text style={s.assignNext}>Next: {next}</Text> : null}
      <Text style={s.assignView}>View Case ›</Text>
    </TouchableOpacity>
  )
}

function AssignMeta({ icon, text, urgent }) {
  return (
    <View style={s.assignMetaItem}>
      <Text style={s.assignMetaIcon}>{icon}</Text>
      <Text style={[s.assignMetaText, urgent && { color: theme.colors.danger, fontWeight: '700' }]} numberOfLines={1}>{text}</Text>
    </View>
  )
}

function TaskStat({ label, value, danger, warn, ok }) {
  const c = danger ? theme.colors.danger : warn ? theme.colors.warning : ok ? theme.colors.success : theme.colors.navy
  return (
    <View style={s.miniTile}>
      <Text style={[s.miniValue, { color: c }]} numberOfLines={1}>{fmtNum(value)}</Text>
      <Text style={s.miniLabel} numberOfLines={2}>{label}</Text>
    </View>
  )
}

const s = {
  card: { marginTop: 12 },
  cardHeader: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 10 },
  period: { fontSize: 11, color: theme.colors.textMuted, marginTop: -6, marginBottom: 8 },
  hint: { fontSize: 11, color: theme.colors.textLight, marginTop: -6, marginBottom: 8 },
  headRow: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: 10 },
  cardLink: { fontSize: 13, fontWeight: '700', color: theme.colors.primary },
  chev: { fontSize: 18, color: theme.colors.textLight, fontWeight: '800', marginTop: 0 },
  chartSub: { fontSize: 12, fontWeight: '700', color: theme.colors.navy, marginBottom: 8, textTransform: 'capitalize' },
  mutedLine: { fontSize: 12.5, color: theme.colors.textLight, paddingVertical: 6 },
  sectionLabel: { fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.8, color: theme.colors.textLight, fontWeight: '800', marginTop: 12, marginBottom: 6 },
  dropRow: { flexDirection: 'row', gap: 8 },
  filtersPill: { borderWidth: 1, borderColor: theme.colors.border, backgroundColor: '#F8FAFC', borderRadius: 9, paddingHorizontal: 12, paddingVertical: 6 },
  filtersPillActive: { borderColor: theme.colors.primary, backgroundColor: theme.colors.primary },
  filtersPillText: { fontSize: 11.5, fontWeight: '700', color: theme.colors.navy },
  filtersPillTextActive: { color: '#fff' },
  miniGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  miniTile: { flexBasis: '30%', flexGrow: 1, minWidth: 0, backgroundColor: '#F8FAFC', borderRadius: 10, borderWidth: 1, borderColor: theme.colors.border, paddingVertical: 9, paddingHorizontal: 6, alignItems: 'center' },
  miniValue: { fontSize: 15, fontWeight: '800' },
  miniLabel: { fontSize: 10, color: theme.colors.textMuted, marginTop: 2, fontWeight: '600', textAlign: 'center' },
  qGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  qTile: { flexBasis: '44%', flexGrow: 1, minWidth: 0, minHeight: 72, backgroundColor: '#F8FAFC', borderRadius: 10, borderWidth: 1, borderColor: theme.colors.border, paddingVertical: 8, paddingHorizontal: 10, justifyContent: 'center' },
  qValue: { fontSize: 16, fontWeight: '900' },
  qLabel: { fontSize: 10, color: theme.colors.textMuted, marginTop: 2, fontWeight: '700' },
  qGo: { position: 'absolute', right: 8, top: 6, fontSize: 12, color: theme.colors.textLight, fontWeight: '800' },
  kTile: { flexBasis: '44%', flexGrow: 1, minWidth: 0, minHeight: 64, backgroundColor: '#F8FAFC', borderRadius: 10, borderWidth: 1, borderColor: theme.colors.border, paddingVertical: 8, paddingHorizontal: 10, justifyContent: 'center' },
  kTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 6 },
  kValue: { fontSize: 16, fontWeight: '900', flexShrink: 1 },
  kIcon: { fontSize: 15 },
  assignRow: { paddingVertical: 9 },
  assignTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  assignTitle: { flex: 1, fontSize: 14, fontWeight: '800', color: theme.colors.navy },
  assignMeta: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, marginTop: 6 },
  assignMetaItem: { flexDirection: 'row', alignItems: 'center', gap: 3 },
  assignMetaIcon: { fontSize: 10 },
  assignMetaText: { fontSize: 11, color: theme.colors.textMuted, fontWeight: '600' },
  assignAddr: { fontSize: 12, color: theme.colors.text, marginTop: 4 },
  assignBottom: { flexDirection: 'row', alignItems: 'center', marginTop: 6 },
  assignMoney: { fontSize: 13, fontWeight: '800', color: theme.colors.navy },
  assignMoneyDim: { fontSize: 11, color: theme.colors.textLight, fontWeight: '600' },
  assignOutstanding: { fontSize: 11, color: theme.colors.textMuted, marginTop: 1, fontWeight: '600' },
  assignNext: { fontSize: 11, color: theme.colors.textMuted, marginTop: 6, backgroundColor: theme.colors.navyBg, borderRadius: 6, paddingHorizontal: 8, paddingVertical: 4, fontWeight: '600' },
  assignView: { fontSize: 12.5, fontWeight: '800', color: theme.colors.primary, marginTop: 8, textAlign: 'right' },
  rowBorder: { borderTopWidth: 1, borderTopColor: theme.colors.border },
  qaTile: { flexBasis: '22%', flexGrow: 1, minWidth: 0, minHeight: 72, backgroundColor: '#F8FAFC', borderRadius: 12, borderWidth: 1, borderColor: theme.colors.border, alignItems: 'center', justifyContent: 'center', paddingVertical: 8, paddingHorizontal: 4 },
  qaIcon: { fontSize: 22 },
  qaLabel: { fontSize: 10, fontWeight: '700', color: theme.colors.navy, textAlign: 'center', marginTop: 4 },
}