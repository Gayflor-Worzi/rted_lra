import { useCallback, useEffect, useMemo, useState } from 'react'
import { View, Text, TouchableOpacity, RefreshControl, StyleSheet, Alert, useWindowDimensions } from 'react-native'
import { useFocusEffect, useNavigation } from '@react-navigation/native'
import api from '../api'
import { useAuth } from '../auth'
import { hasAny, serverMessage } from '../rbac'
import { theme } from '../theme'
import { Screen, BrandHeader, Card, Badge } from '../components'
import { Donut, HBars, HScrollChips, CHART_COLORS } from '../charts'

const fmtMoney = (v) => {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '—'
  return '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
}

const fmtNum = (v) => {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '—'
  return `${Number(v)}`
}

/** Dashboard tabs. */
const TABS = ['Overview', 'Tasks', 'Performance', 'Activity']

/** Day / Week / Month / Quarter / Year period toggles used on the Performance tab. */
const RANGES = ['today', 'week', 'month', 'quarter', 'year']
const RANGE_LABEL = { today: 'Day', week: 'Week', month: 'Month', quarter: 'Quarter', year: 'Year' }

/** Quick-action perms client-side; matches the server gate. Actions are hidden, never disabled. */
const QUICK_ACTION_PERMS = {
  tasks: ['tasks.view_own'],
  discovery: ['discovery.create'],
  visit: ['enforcement.record_visit'],
  receipt: ['payments.claim'],
  verify: ['payments.verify', 'payments.reject', 'payments.view_queue'],
  valuations: ['valuation.create', 'valuation.review', 'valuation.view_history'],
}

/** Role-aware registration order used to display the officer's own assignments. */
const PRIORITY_ORDER = [
  'Escalated', '30-Day Warning', '72-Hour Warning', 'Overdue', 'Due Today',
  'Assigned', 'Out for Delivery', 'Delivered', 'Payment Follow-up', 'Payment Claimed',
  'Verification Pending', 'Payment Verification', 'Outstanding',
]

const timeAgo = (iso) => {
  if (!iso) return ''
  const diff = Math.max(0, Date.now() - new Date(iso).getTime())
  const m = Math.floor(diff / 60000)
  if (m < 1) return 'just now'
  if (m < 60) return `${m}m ago`
  const h = Math.floor(m / 60)
  if (h < 24) return `${h}h ago`
  const d = Math.floor(h / 24)
  if (d < 30) return `${d}d ago`
  return new Date(iso).toLocaleDateString()
}

const initials = (name) => (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase()

const ACT_ICON = { task: '📋', visit: '🚗', payment: '🧾', discovery: '📍', valuation: '🏷️' }

export default function HomeScreen() {
  const { user, logout } = useAuth()
  const navigation = useNavigation()
  const safeNav = useCallback((name, params) => { try { navigation.navigate(name, params) } catch {} }, [navigation])
  const { width } = useWindowDimensions()
  const tablet = width >= 768
  const donutSize = Math.min(Math.min(width, 700) - 96, 230)

  const [data, setData] = useState(null)
  const [assignments, setAssignments] = useState([])
  const [err, setErr] = useState('')
  const [refreshing, setRefreshing] = useState(false)

  const [tab, setTab] = useState('Overview')

  const [metric, setMetric] = useState(null)
  const [range, setRange] = useState('month')
  const [group, setGroup] = useState(null)
  const [pie, setPie] = useState(null)
  const [chart, setChart] = useState(null)
  const [chartErr, setChartErr] = useState('')
  const [tick, setTick] = useState(0)

  const confirmLogout = () => {
    Alert.alert('Log out', `Sign out ${user?.full_name || 'this account'}?`, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Log out', style: 'destructive', onPress: () => logout() },
    ])
  }

  const showProfile = () => {
    Alert.alert(
      data?.profile?.full_name || user?.full_name || 'Profile',
      `${data?.profile?.role || user?.role || ''}\n${data?.profile?.section || ''}\n${user?.email || ''}`,
      [
        { text: 'Close', style: 'cancel' },
        { text: 'Log out', style: 'destructive', onPress: () => logout() },
      ],
    )
  }

  const showNotice = () => {
    Alert.alert('Notices', `${data?.notifications?.unread || 0} unread notice(s) in your section.`, [{ text: 'OK' }])
  }

  const load = useCallback(async () => {
    try {
      const r = await api.get('/dashboard/my')
      setData(r.data?.data || r.data || {})
      setErr('')
    } catch (e) {
      setErr('Could not load your dashboard.')
    }
    setTick((t) => t + 1)
  }, [])

  // The officer's own assignments from a backend-scoped endpoint — used only for
  // the compact "Active Assignments" preview. The backend returns ONLY this
  // user's records (assigned_to == current user), so no local filtering is needed.
  const loadAssignments = useCallback(async () => {
    try {
      const r = await api.get('/enforcement-assignments/my')
      setAssignments(r.data?.data?.data || r.data?.data || [])
    } catch (e) {
      if (!e.response) setAssignments([])
    }
  }, [])

  useEffect(() => {
    if (!data) return
    setMetric((m) => {
      if (m) return m
      const cm = data.chart_metrics
      if (cm && Object.keys(cm).length > 0) return cm.tasks ? 'tasks' : Object.keys(cm)[0]
      return null
    })
  }, [data])

  useFocusEffect(useCallback(() => { load(); loadAssignments() }, [load, loadAssignments]))

  const refresh = async () => {
    setRefreshing(true)
    await Promise.all([load(), loadAssignments()])
    setRefreshing(false)
  }

  const loadCharts = useCallback(async () => {
    if (!metric) return
    try {
      setChartErr('')
      const r = await api.get('/dashboard/analytics', { params: { metric, range, group_by: group, pie } })
      const d = r.data?.data || r.data || {}
      setChart(d)
      if (group && d.meta?.group_options && !d.meta.group_options[group]) setGroup(null)
      if (pie && d.meta?.pie_options && !d.meta.pie_options[pie]) setPie(null)
      if (!group && d.meta?.group_by) setGroup(d.meta.group_by)
      if (!pie && d.meta?.pie) setPie(d.meta.pie)
    } catch (e) {
      setChartErr(serverMessage(e, 'Could not load analytics.'))
    }
  }, [metric, range, group, pie])

  useEffect(() => { loadCharts() }, [loadCharts, tick])

  const perf = data?.performance || {}
  const indicators = perf.indicators || {}
  const hasTarget = !!perf.has_target

  const taskOverview = data?.task_overview || {}
  const tasks = data?.tasks || {}
  const billsArea = data?.bills_area || {}
  const showBills = billsArea && (billsArea.total_bills || hasAny(user, ['bills.view', 'bills.create']))
  const isEnforcement = hasAny(user, ['enforcement.record_visit', 'enforcement.view_assignments', 'tasks.view_own'])

  const priority = data?.priority_actions || []
  const engagement = data?.current_engagement
  const recent = data?.recent_activity || []
  const quick = (data?.quick_actions || []).filter((q) => hasAny(user, QUICK_ACTION_PERMS[q.key] || []))

  // Completed / Pending / Target / Completion% used by the compact performance block.
  const completedCount = taskOverview.completed ?? tasks.completed ?? 0
  const pendingCount = taskOverview.in_progress ?? 0
  const activeCount = taskOverview.assigned ?? tasks.total_active ?? 0
  const overdueCount = taskOverview.overdue ?? 0
  const completionPct = hasTarget
    ? Math.round(perf.completion_rate)
    : (activeCount + pendingCount) > 0
      ? Math.round((completedCount / (completedCount + Math.max(activeCount, pendingCount))) * 100)
      : 0

  // Single most urgent/current priority — the hidden rest still present in full tab for admins.
  const currentPriority = priority[0] || null

  // Reorder the officer's OWN assignments for display by the enforcement flow.
  const preview = useMemo(() => {
    const today = new Date()
    const todayStr = today.toISOString().slice(0, 10)
    return [...assignments].sort((a, b) => {
      const rankA = PRIORITY_ORDER.indexOf(a.status)
      const rankB = PRIORITY_ORDER.indexOf(b.status)
      if (rankA !== -1 || rankB !== -1) {
        const ra = rankA === -1 ? 999 : rankA
        const rb = rankB === -1 ? 999 : rankB
        if (ra !== rb) return ra - rb
      }
      const od = (a.due_date || '').slice(0, 10)
      const ob = (b.due_date || '').slice(0, 10)
      if ((od && od < todayStr) !== (ob && ob < todayStr)) return od < todayStr ? -1 : 1
      return 0
    })
  }, [assignments])

  const selectMetric = (m) => { setMetric(m); setGroup(null); setPie(null) }

  const tapBar = (d, meta) => {
    const value = meta?.metric === 'collections' ? fmtMoney(d.value) : fmtNum(d.value)
    Alert.alert(d.label, `${value} within the selected range.`, [{ text: 'OK' }])
  }

  const tapSlice = (d) => {
    const total = (chart?.pie?.data || []).reduce((s, x) => s + Number(x.value), 0)
    Alert.alert(d.label, `${fmtNum(d.value)} in range — ${total ? Math.round((Number(d.value) / total) * 100) : 0}% of total.`, [{ text: 'OK' }])
  }

  const chartMetrics = data?.chart_metrics || {}
  const metricKeys = chartMetrics ? Object.keys(chartMetrics) : []
  const activeMetricLabel = chartMetrics[metric] || ''
  const groupOptions = chart?.meta?.group_options
    ? Object.keys(chart.meta.group_options).map((k) => ({ key: k, label: chart.meta.group_options[k] }))
    : []
  const pieOptions = chart?.meta?.pie_options
    ? Object.keys(chart.meta.pie_options).map((k) => ({ key: k, label: chart.meta.pie_options[k] }))
    : []
  const sourceRoute = { tasks: 'Tasks', discoveries: 'Discover', payments: 'Verifications', valuations: 'Valuations' }[metric]
  const sourceLabel = { tasks: 'Task register', discoveries: 'Discovery register', payments: 'Payment verifications', valuations: 'Valuation register' }[metric]

  // Bill follow-up / outstanding stats (role-aware). Follow-ups = payment follow-up tasks.
  const followUps = isEnforcement
    ? (indicators.payment_followups?.value ?? 0)
    : (data?.tasks?.statuses?.['Payment Follow-up'] ?? 0)

  // Role-scoped type breakdown (already scoped server-side), rendered as compact chips.
  const byType = Object.entries(taskOverview.by_type || {}).slice(0, 6)

  return (
    <Screen refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={theme.colors.primary} />}>
      <BrandHeader
        title={`Welcome, ${data?.profile?.first_name || user?.full_name?.split(' ')[0] || 'there'}`}
        subtitle={data?.profile ? `${data.profile.role} · ${data.profile.section}` : 'Field operations'}
        right={
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
            <TouchableOpacity onPress={showNotice} hitSlop={6} style={styles.iconBtn}>
              <Text style={styles.icon}>🔔</Text>
              {data?.notifications?.unread > 0 && (
                <View style={styles.badgeDot}>
                  <Text style={styles.badgeDotText}>{data.notifications.unread}</Text>
                </View>
              )}
            </TouchableOpacity>
            <TouchableOpacity onPress={showProfile} hitSlop={6} style={styles.avatar}>
              <Text style={styles.avatarText}>{initials(data?.profile?.full_name || user?.full_name)}</Text>
            </TouchableOpacity>
          </View>
        }
      />

      {err ? (
        <Card style={{ marginTop: 12 }}>
          <Text style={{ color: theme.colors.danger, fontWeight: '600' }}>{err}</Text>
          <TouchableOpacity onPress={refresh} style={{ marginTop: 8, minHeight: 44, justifyContent: 'center' }}>
            <Text style={{ color: theme.colors.primary, fontWeight: '700', fontSize: 13 }}>⟳ Try again</Text>
          </TouchableOpacity>
        </Card>
      ) : !data ? (
        <Card style={{ marginTop: 12 }}><Text style={{ color: theme.colors.textMuted }}>Loading your dashboard…</Text></Card>
      ) : (
        <>
          {/* ── Tab bar: Overview | Tasks | Performance | Activity ─────── */}
          <View style={[styles.tabBar, tablet && styles.tabBarTablet]}>
            {TABS.map((t) => {
              const activeTab = tab === t
              return (
                <TouchableOpacity key={t} onPress={() => setTab(t)} activeOpacity={0.8} style={[styles.tabBtn, activeTab && styles.tabBtnActive]}>
                  <Text style={[styles.tabLabel, activeTab && styles.tabLabelActive]}>{t}</Text>
                </TouchableOpacity>
              )
            })}
          </View>

          {tab === 'Overview' && (
            <>
              <HeroBlock
                hasTarget={hasTarget}
                perf={perf}
                completedCount={completedCount}
                pendingCount={pendingCount + activeCount}
                overdueCount={overdueCount}
                completionPct={completionPct}
                tablet={tablet}
                showBills={showBills}
                billsArea={billsArea}
                followUps={followUps}
                taskOverview={taskOverview}
                navigation={navigation}
              />

              {currentPriority && (
                <Card style={styles.block}>
                  <Text style={styles.cardHeader}>Current Priority</Text>
                  <TouchableOpacity onPress={() => safeNav(currentPriority.route)} activeOpacity={0.7} style={styles.priorityRow}>
                    <View style={[styles.priorityDot, { backgroundColor: currentPriority.urgency === 'overdue' ? theme.colors.danger : currentPriority.urgency === 'today' ? theme.colors.warning : theme.colors.navy }]} />
                    <View style={{ flex: 1 }}>
                      <Text style={styles.priorityLabel}>{currentPriority.label}</Text>
                      <Text style={styles.priorityDetail} numberOfLines={2}>{currentPriority.detail}</Text>
                    </View>
                    <View style={[styles.priorityCount, { backgroundColor: currentPriority.urgency === 'overdue' ? theme.colors.danger : currentPriority.urgency === 'today' ? theme.colors.warning : theme.colors.navy }]}>
                      <Text style={styles.priorityCountText}>{currentPriority.count}</Text>
                    </View>
                    <Text style={styles.priorityAction}>{currentPriority.action} ›</Text>
                  </TouchableOpacity>
                </Card>
              )}

              {quick.length > 0 && (
                <Card style={styles.block}>
                  <Text style={styles.cardHeader}>Quick Actions</Text>
                  <View style={[styles.qaGrid, tablet && styles.qaGridTablet]}>
                    {quick.map((q) => (
                      <TouchableOpacity key={q.key} onPress={() => safeNav(q.route)} activeOpacity={0.7} style={[styles.qaTile, tablet && styles.qaTileTablet]}>
                        <Text style={styles.qaIcon}>{q.icon}</Text>
                        <Text style={styles.qaLabel} numberOfLines={2}>{q.label}</Text>
                      </TouchableOpacity>
                    ))}
                  </View>
                </Card>
              )}
            </>
          )}

          {tab === 'Tasks' && (
            <>
              <Card style={styles.block}>
                <Text style={styles.cardHeader}>Task Overview</Text>
                <View style={styles.miniGrid}>
                  <MiniStat label="Total" value={fmtNum(activeCount)} />
                  <MiniStat label="Due Today" value={fmtNum(taskOverview.due_today)} warn={(taskOverview.due_today ?? 0) > 0} />
                  <MiniStat label="Due Soon" value={fmtNum(taskOverview.due_soon)} />
                  <MiniStat label="Overdue" value={fmtNum(taskOverview.overdue)} danger={(taskOverview.overdue ?? 0) > 0} />
                  <MiniStat label="Escalated" value={fmtNum(taskOverview.escalated)} danger={(taskOverview.escalated ?? 0) > 0} />
                  <MiniStat label="Completed" value={fmtNum(completedCount)} ok />
                </View>
              </Card>

              {byType.length > 0 && (
                <Card style={styles.block}>
                  <Text style={styles.cardHeader}>By Type</Text>
                  <View style={styles.chipRow}>
                    {byType.map(([type, count]) => (
                      <View key={type} style={styles.chip}>
                        <Text style={styles.chipValue}>{fmtNum(count)}</Text>
                        <Text style={styles.chipLabel} numberOfLines={1}>{type?.replace(/_/g, ' ')}</Text>
                      </View>
                    ))}
                  </View>
                </Card>
              )}

              {isEnforcement && assignments.length > 0 ? (
                <Card style={styles.block}>
                  <View style={styles.cardHeadRow}>
                    <Text style={styles.cardHeader}>Active Assignments</Text>
                    <TouchableOpacity onPress={() => navigation.navigate('Tasks')} hitSlop={6}>
                      <Text style={styles.cardLink}>View all ›</Text>
                    </TouchableOpacity>
                  </View>
                  <Text style={styles.cardHint}>Ordered by urgency · Overdue, escalated, due soon first.</Text>
                  {preview.slice(0, 8).map((a, i) => (
                    <AssignmentRow key={a.id} item={a} onOpen={() => navigation.navigate('TaskDetail', { taskId: a.id, assignment: a })} divider={i > 0} />
                  ))}
                </Card>
              ) : (
                <Card style={styles.block}>
                  <Text style={styles.cardHeader}>Active Assignments</Text>
                  <Text style={styles.cardHint}>Your own task assignments will appear here.</Text>
                  <EmptyRow />
                </Card>
              )}
            </>
          )}

          {tab === 'Performance' && (
            <>
              <Card style={[styles.hero, tablet && styles.heroTablet]}>
                <View style={styles.heroTop}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.heroTitle}>{hasTarget ? 'My Performance' : 'My Activity'}</Text>
                    <Text style={styles.heroSub}>
                      {hasTarget
                        ? `Target ${fmtNum(perf.target_value)} · ${completionPct}% complete`
                        : data.profile?.date || ''}
                    </Text>
                  </View>
                  <View style={styles.heroPctWrap}>
                    <Text style={styles.heroPct}>{hasTarget ? `${completionPct}%` : `${completedCount}`}</Text>
                    <Text style={styles.heroPctLabel}>{hasTarget ? 'complete' : 'completed'}</Text>
                  </View>
                </View>
                {hasTarget && (
                  <View style={styles.heroBarTrack}>
                    <View style={[styles.heroBarFill, { width: `${Math.min(completionPct, 100)}%` }]} />
                  </View>
                )}
                <View style={[styles.heroStats, tablet && styles.heroStatsTablet]}>
                  <HeroStat label="Target" value={hasTarget ? fmtNum(perf.target_value) : '—'} />
                  <HeroStat label="Completed" value={fmtNum(completedCount)} />
                  <HeroStat label="Pending" value={fmtNum(pendingCount + activeCount)} />
                  <HeroStat label="Overdue" value={fmtNum(overdueCount)} />
                  <HeroStat label="Completion" value={`${completionPct}%`} />
                </View>
                {Object.keys(indicators).filter((k) => !['visits', 'bills_delivered', 'payment_followups'].includes(k)).length > 0 && (
                  <View style={[styles.chipRow, tablet && styles.chipRowTablet]}>
                    {Object.entries(indicators)
                      .filter(([k]) => !['visits', 'bills_delivered', 'payment_followups'].includes(k))
                      .map(([k, ind]) => (
                        <View key={k} style={styles.chip}>
                          <Text style={styles.chipValue}>{fmtNum(ind.value)}</Text>
                          <Text style={styles.chipLabel}>{ind.label.replace(/\s+/g, ' ')}</Text>
                        </View>
                      ))}
                  </View>
                )}
              </Card>

              {metricKeys.length > 0 && (
                <Card style={styles.block}>
                  <Text style={styles.cardHeader}>Breakdown</Text>
                  <Text style={styles.sectionLabel}>Period</Text>
                <HScrollChips options={RANGES.map((r) => RANGE_LABEL[r])} value={RANGE_LABEL[range]} onChange={(lbl) => {
                  const key = Object.keys(RANGE_LABEL).find((k) => RANGE_LABEL[k] === lbl)
                  if (key) setRange(key)
                }} />
                <Text style={styles.sectionLabel}>Metric</Text>
                <HScrollChips options={metricKeys} value={metric} onChange={selectMetric} colors={Object.fromEntries(metricKeys.map((k, i) => [k, CHART_COLORS[i % CHART_COLORS.length]]))} />
                {groupOptions.length > 1 && (
                  <>
                    <Text style={styles.sectionLabel}>Group by</Text>
                    <HScrollChips options={groupOptions.map((o) => o.key)} value={group} onChange={setGroup} />
                  </>
                )}
                {chartErr ? (
                  <Text style={{ color: theme.colors.danger, fontSize: 13, paddingVertical: 8 }}>{chartErr}</Text>
                ) : !chart ? (
                  <Text style={{ color: theme.colors.textMuted, paddingVertical: 8 }}>Loading chart…</Text>
                ) : (
                  <>
                    <Text style={[styles.chartTitle, { marginTop: 12 }]}>{activeMetricLabel} · {RANGE_LABEL[range]} · by {group?.replace(/_/g, ' ')}</Text>
                    <HBars data={chart.bar?.data || []} format={metric === 'collections' ? fmtMoney : fmtNum} onPress={(d) => tapBar(d, chart.meta)} />
                    <Text style={[styles.chartTitle, { marginTop: 16 }]}>Breakdown · {pie?.replace(/_/g, ' ')}</Text>
                    <View style={{ alignItems: 'center', marginTop: 4 }}>
                      <Donut data={chart.pie?.data || []} size={donutSize} center={RANGE_LABEL[range]} onSlice={tapSlice} />
                    </View>
                  </>
                )}
                {sourceRoute && (
                  <TouchableOpacity onPress={() => safeNav(sourceRoute)} style={styles.sourceRow} activeOpacity={0.7}>
                    <Text style={styles.sourceText}>View source records · {sourceLabel.toLowerCase()} ›</Text>
                    <Text style={styles.sourceNote}>Every figure is computed live from these records.</Text>
                  </TouchableOpacity>
                )}
                </Card>
              )}
            </>
          )}

          {tab === 'Activity' && (
            <>
              {engagement && (
                <Card style={styles.block}>
                  <Text style={styles.cardHeader}>Current Engagement</Text>
                  <TouchableOpacity onPress={() => engagement.task?.id && navigation.navigate('TaskDetail', { taskId: engagement.task.id })} activeOpacity={0.7}>
                    <View style={styles.ceRow}>
                      <View style={styles.ceIconWrap}><Text style={styles.ceIcon}>📋</Text></View>
                      <View style={{ flex: 1 }}>
                        <Text style={styles.ceTitle}>{engagement.task?.task_reference}</Text>
                        <Text style={styles.ceSub}>{engagement.task?.task_type?.replace(/_/g, ' ')} · due {engagement.task?.due_date || '—'}</Text>
                      </View>
                      <Badge status={engagement.task?.status} label={engagement.task?.status} />
                    </View>
                  </TouchableOpacity>
                  {engagement.bill && (
                    <View style={styles.ceRow}>
                      <View style={styles.ceIconWrap}><Text style={styles.ceIcon}>🏠</Text></View>
                      <View style={{ flex: 1 }}>
                        <Text style={styles.ceTitle} numberOfLines={1}>{engagement.bill.property_address || engagement.bill.tin || `Bill #${engagement.bill.document_number}`}</Text>
                        <Text style={styles.ceSub}>Outstanding {fmtMoney(engagement.bill.outstanding_balance)}</Text>
                      </View>
                      <Badge status={engagement.bill.case_status} label={engagement.bill.case_status} />
                    </View>
                  )}
                  {engagement.last_action?.label && (
                    <Text style={styles.ceFooter}>
                      Last action: {engagement.last_action.label}
                      {engagement.last_action.performed_by ? ` · ${engagement.last_action.performed_by}` : ''}
                      {engagement.last_action.at ? ` · ${timeAgo(engagement.last_action.at)}` : ''}
                    </Text>
                  )}
                </Card>
              )}

              <Card style={styles.block}>
                <Text style={styles.cardHeader}>Recent Activity</Text>
                {recent.length > 0 ? (
                  recent.slice(0, 10).map((a, i) => (
                    <View key={`${a.type}-${i}-${a.at}`} style={[styles.actRow, i > 0 && styles.rowBorder]}>
                      <Text style={styles.actIcon}>{ACT_ICON[a.type] || '•'}</Text>
                      <View style={{ flex: 1 }}>
                        <Text style={styles.actLabel}>{a.label}</Text>
                        {(a.ref || a.user) && <Text style={styles.actRef}>{a.ref || a.user}</Text>}
                      </View>
                      {a.status && <Badge status={a.status} label={a.status} />}
                      <Text style={styles.actTime}>{timeAgo(a.at)}</Text>
                    </View>
                  ))
                ) : (
                  <Text style={{ color: theme.colors.textMuted, fontSize: 13 }}>No recent activity yet.</Text>
                )}
              </Card>
            </>
          )}
        </>
      )}
    </Screen>
  )
}

function HeroBlock({ hasTarget, perf, completedCount, pendingCount, overdueCount, completionPct, tablet, showBills, billsArea, followUps, taskOverview, navigation }) {
  return (
    <>
      <Card style={[styles.hero, tablet && styles.heroTablet]}>
        <View style={styles.heroTop}>
          <View style={{ flex: 1 }}>
            <Text style={styles.heroTitle}>{hasTarget ? 'My Performance' : 'My Activity'}</Text>
            <Text style={styles.heroSub}>
              {hasTarget
                ? `Target ${fmtNum(perf.target_value)} · ${completionPct}% complete`
                : `Pending ${fmtNum(pendingCount)} · Overdue ${fmtNum(overdueCount)}`}
            </Text>
          </View>
          <View style={styles.heroPctWrap}>
            <Text style={styles.heroPct}>{hasTarget ? `${completionPct}%` : `${completedCount}`}</Text>
            <Text style={styles.heroPctLabel}>{hasTarget ? 'complete' : 'completed'}</Text>
          </View>
        </View>
        {hasTarget && (
          <View style={styles.heroBarTrack}>
            <View style={[styles.heroBarFill, { width: `${Math.min(completionPct, 100)}%` }]} />
          </View>
        )}
        <View style={[styles.heroStats, tablet && styles.heroStatsTablet]}>
          <HeroStat label="Target" value={hasTarget ? fmtNum(perf.target_value) : '—'} />
          <HeroStat label="Completed" value={fmtNum(completedCount)} />
          <HeroStat label="Pending" value={fmtNum(pendingCount)} />
          <HeroStat label="Overdue" value={fmtNum(overdueCount)} />
          <HeroStat label="Completion" value={`${completionPct}%`} />
        </View>
      </Card>

      {showBills && (
        <Card style={styles.block}>
          <View style={styles.cardHeadRow}>
            <Text style={styles.cardHeader}>My Bills</Text>
            <TouchableOpacity onPress={() => navigation.navigate('Tasks')} hitSlop={6}>
              <Text style={styles.cardLink}>View ›</Text>
            </TouchableOpacity>
          </View>
          <Text style={styles.billsAmount}>{fmtMoney(billsArea.total_tax_due)}</Text>
          <Text style={styles.billsMuted}>{billsArea.total_bills ?? 0} assigned bill(s)</Text>
          <View style={styles.miniGrid}>
            <MiniStat label="Outstanding" value={fmtMoney(billsArea.outstanding)} danger={(billsArea.outstanding ?? 0) > 0} />
            <MiniStat label="Paid" value={fmtMoney(billsArea.amount_paid)} ok />
            <MiniStat label="Properties" value={fmtNum(billsArea.properties)} />
            <MiniStat label="Follow-ups" value={fmtNum(followUps)} warn={(followUps ?? 0) > 0} />
          </View>
        </Card>
      )}

      <Card style={styles.block}>
        <Text style={styles.cardHeader}>Task Overview</Text>
        <View style={styles.miniGrid}>
          <MiniStat label="Total" value={fmtNum(taskOverview.assigned ?? 0)} />
          <MiniStat label="Due Today" value={fmtNum(taskOverview.due_today)} warn={(taskOverview.due_today ?? 0) > 0} />
          <MiniStat label="Due Soon" value={fmtNum(taskOverview.due_soon)} />
          <MiniStat label="Overdue" value={fmtNum(taskOverview.overdue)} danger={(taskOverview.overdue ?? 0) > 0} />
          <MiniStat label="Escalated" value={fmtNum(taskOverview.escalated)} danger={(taskOverview.escalated ?? 0) > 0} />
          <MiniStat label="Completed" value={fmtNum(completedCount)} ok />
        </View>
      </Card>
    </>
  )
}

function EmptyRow() {
  return <Text style={{ color: theme.colors.textLight, fontSize: 12.5, paddingVertical: 6 }}>Nothing assigned right now.</Text>
}

function AssignmentRow({ item, onOpen, divider }) {
  const bill = item.property_bill || {}
  const overdue = item.due_date && item.due_date.slice(0, 10) < new Date().toISOString().slice(0, 10)
  const title = bill.document_number || item.task_reference || `#${item.id}`
  return (
    <TouchableOpacity onPress={onOpen} activeOpacity={0.7} style={[styles.assignRow, divider && styles.rowBorder]}>
      <View style={styles.assignTop}>
        <Text style={styles.assignTitle} numberOfLines={1}>{title}</Text>
        <Badge status={item.status} label={item.status} />
      </View>
      <View style={styles.assignMeta}>
        {bill.property_id ? <AssignMeta icon="📍" text={`Prop ${bill.property_id}`} /> : null}
        {bill.tin ? <AssignMeta icon="🪪" text={`TIN ${bill.tin}`} /> : null}
        {item.due_date ? <AssignMeta icon="📅" text={overdue ? `Due ${item.due_date.slice(0, 10)}` : item.due_date.slice(0, 10)} urgent={overdue} /> : null}
      </View>
      <Text style={styles.assignAddr} numberOfLines={1}>{bill.property_address || '—'}</Text>
      <View style={styles.assignBottom}>
        <View style={{ flex: 1 }}>
          <Text style={styles.assignMoney}>{fmtMoney(bill.total_tax_due)} <Text style={styles.assignMoneyDim}>due</Text></Text>
          <Text style={styles.assignOutstanding}>Outstanding {fmtMoney(bill.outstanding_balance)}</Text>
        </View>
        <Text style={styles.assignView}>View ›</Text>
      </View>
      {item.next_action && (
        <Text style={styles.assignNext}>Next: {typeof item.next_action === 'object' ? (item.next_action.verb || JSON.stringify(item.next_action)) : String(item.next_action)}</Text>
      )}
    </TouchableOpacity>
  )
}

function AssignMeta({ icon, text, urgent }) {
  return (
    <View style={styles.assignMetaItem}>
      <Text style={styles.assignMetaIcon}>{icon}</Text>
      <Text style={[styles.assignMetaText, urgent && { color: theme.colors.danger, fontWeight: '700' }]} numberOfLines={1}>{text}</Text>
    </View>
  )
}

function HeroStat({ label, value }) {
  return (
    <View style={styles.heroStat}>
      <Text style={styles.heroStatValue}>{value}</Text>
      <Text style={styles.heroStatLabel}>{label}</Text>
    </View>
  )
}

function MiniStat({ label, value, warn, danger, ok }) {
  const c = danger ? theme.colors.danger : warn ? theme.colors.warning : ok ? theme.colors.success : theme.colors.navy
  return (
    <View style={styles.miniTile}>
      <Text style={[styles.miniValue, { color: c }]} numberOfLines={1}>{value}</Text>
      <Text style={styles.miniLabel} numberOfLines={1}>{label}</Text>
    </View>
  )
}

const styles = StyleSheet.create({
  iconBtn: { position: 'relative', minHeight: 44, minWidth: 32, alignItems: 'center', justifyContent: 'center' },
  icon: { fontSize: 22 },
  badgeDot: { position: 'absolute', top: 2, right: -4, minWidth: 18, height: 18, borderRadius: 9, backgroundColor: theme.colors.primary, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 4 },
  badgeDotText: { color: '#fff', fontSize: 10, fontWeight: '800' },
  avatar: { width: 38, height: 38, borderRadius: 19, backgroundColor: theme.colors.navy, alignItems: 'center', justifyContent: 'center' },
  avatarText: { color: '#fff', fontSize: 14, fontWeight: '800' },

  // Tab bar
  tabBar: { flexDirection: 'row', marginHorizontal: 16, marginTop: 12, backgroundColor: '#EEF2F7', borderRadius: 12, padding: 4, gap: 4 },
  tabBarTablet: { maxWidth: 640, alignSelf: 'center', width: '100%' },
  tabBtn: { flex: 1, minHeight: 38, borderRadius: 9, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 6 },
  tabBtnActive: { backgroundColor: theme.colors.navy },
  tabLabel: { fontSize: 12.5, fontWeight: '700', color: theme.colors.textMuted },
  tabLabelActive: { color: '#fff' },

  block: { marginTop: 12 },

  hero: { backgroundColor: theme.colors.primary, borderRadius: 14, marginHorizontal: 16, marginTop: 12, marginBottom: 4, padding: 16 },
  heroTablet: { marginHorizontal: 16 },
  heroTop: { flexDirection: 'row', alignItems: 'center' },
  heroTitle: { color: '#fff', fontSize: 17, fontWeight: '800' },
  heroSub: { color: 'rgba(255,255,255,0.85)', fontSize: 12, marginTop: 2 },
  heroPctWrap: { alignItems: 'center', marginLeft: 12 },
  heroPct: { color: '#fff', fontSize: 30, fontWeight: '900' },
  heroPctLabel: { color: 'rgba(255,255,255,0.85)', fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.4, fontWeight: '700' },
  heroBarTrack: { height: 7, borderRadius: 6, backgroundColor: 'rgba(255,255,255,0.35)', marginTop: 12, overflow: 'hidden' },
  heroBarFill: { height: '100%', borderRadius: 6, backgroundColor: '#fff' },
  heroStats: { flexDirection: 'row', flexWrap: 'wrap', marginTop: 12, gap: 12 },
  heroStatsTablet: { gap: 10 },
  heroStat: { minWidth: 72, flexGrow: 1, flexBasis: '18%' },
  heroStatValue: { color: '#fff', fontSize: 16, fontWeight: '800' },
  heroStatLabel: { color: 'rgba(255,255,255,0.85)', fontSize: 10.5, marginTop: 1, fontWeight: '600' },

  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 4 },
  chipRowTablet: { gap: 8 },
  chip: { backgroundColor: '#F8FAFC', borderRadius: 9, paddingHorizontal: 10, paddingVertical: 5, minWidth: 76, borderWidth: 1, borderColor: theme.colors.border },
  chipValue: { fontSize: 14, fontWeight: '800', color: theme.colors.navy },
  chipLabel: { fontSize: 9.5, color: theme.colors.textMuted, marginTop: 1, fontWeight: '600' },

  cardHeader: { fontSize: 13, fontWeight: '800', color: theme.colors.navy, textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 10 },
  cardHeadRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  cardLink: { fontSize: 13, fontWeight: '700', color: theme.colors.primary },
  cardHint: { fontSize: 11, color: theme.colors.textLight, marginTop: -6, marginBottom: 8 },

  miniGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  miniTile: { flexBasis: '30%', flexGrow: 1, minWidth: 0, backgroundColor: '#F8FAFC', borderRadius: 10, borderWidth: 1, borderColor: theme.colors.border, paddingVertical: 9, paddingHorizontal: 6, alignItems: 'center' },
  miniValue: { fontSize: 17, fontWeight: '800' },
  miniLabel: { fontSize: 10.5, color: theme.colors.textMuted, marginTop: 2, fontWeight: '600', textAlign: 'center' },

  billsAmount: { fontSize: 22, fontWeight: '900', color: theme.colors.danger },
  billsMuted: { fontSize: 11, color: theme.colors.textMuted, marginTop: 2, marginBottom: 10 },

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
  assignView: { fontSize: 13, fontWeight: '700', color: theme.colors.primary, marginLeft: 8 },
  assignNext: { fontSize: 11, color: theme.colors.textMuted, marginTop: 6, backgroundColor: theme.colors.navyBg, borderRadius: 6, paddingHorizontal: 8, paddingVertical: 4, fontWeight: '600' },

  rowBorder: { borderTopWidth: 1, borderTopColor: theme.colors.border },

  sectionLabel: { fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.8, color: theme.colors.textLight, fontWeight: '800', marginTop: 12, marginBottom: 6 },
  chartTitle: { fontSize: 13, fontWeight: '700', color: theme.colors.navy, textTransform: 'capitalize', marginBottom: 8 },
  sourceRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 12, paddingTop: 10, borderTopWidth: 1, borderTopColor: theme.colors.border },
  sourceText: { fontSize: 13, fontWeight: '700', color: theme.colors.primary },
  sourceNote: { fontSize: 11, color: theme.colors.textLight, fontWeight: '600' },

  ceRow: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 8 },
  ceIconWrap: { width: 38, height: 38, borderRadius: 10, backgroundColor: theme.colors.navyBg, alignItems: 'center', justifyContent: 'center' },
  ceIcon: { fontSize: 18 },
  ceTitle: { fontSize: 14, fontWeight: '700', color: theme.colors.text },
  ceSub: { fontSize: 12, color: theme.colors.textMuted, marginTop: 1 },
  ceFooter: { fontSize: 12, color: theme.colors.textMuted, marginTop: 10, paddingTop: 8, borderTopWidth: 1, borderTopColor: theme.colors.border },

  priorityRow: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 10 },
  priorityDot: { width: 10, height: 10, borderRadius: 5 },
  priorityLabel: { fontSize: 13.5, fontWeight: '700', color: theme.colors.text },
  priorityDetail: { fontSize: 11.5, color: theme.colors.textMuted, marginTop: 2 },
  priorityCount: { minWidth: 26, height: 26, borderRadius: 13, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 7 },
  priorityCountText: { color: '#fff', fontSize: 12, fontWeight: '800' },
  priorityAction: { fontSize: 12.5, fontWeight: '700', color: theme.colors.primary },

  actRow: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 8 },
  actIcon: { fontSize: 15, width: 22, textAlign: 'center' },
  actLabel: { fontSize: 12.5, fontWeight: '600', color: theme.colors.text },
  actRef: { fontSize: 11, color: theme.colors.textMuted, marginTop: 1 },
  actTime: { fontSize: 11, color: theme.colors.textLight, fontWeight: '600' },

  qaGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  qaGridTablet: { gap: 10 },
  qaTile: { flexBasis: '30%', flexGrow: 1, minWidth: 0, minHeight: 86, backgroundColor: '#F8FAFC', borderRadius: 12, borderWidth: 1, borderColor: theme.colors.border, alignItems: 'center', justifyContent: 'center', paddingVertical: 10, paddingHorizontal: 4 },
  qaTileTablet: { flexBasis: '22%' },
  qaIcon: { fontSize: 24 },
  qaLabel: { fontSize: 10.5, fontWeight: '700', color: theme.colors.navy, textAlign: 'center', marginTop: 5 },
})
