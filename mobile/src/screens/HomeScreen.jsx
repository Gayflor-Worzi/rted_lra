import { useCallback, useEffect, useMemo, useState } from 'react'
import { View, Text, TouchableOpacity, RefreshControl, StyleSheet, Alert, useWindowDimensions } from 'react-native'
import { useFocusEffect, useNavigation } from '@react-navigation/native'
import api from '../api'
import { useAuth } from '../auth'
import { fetchOfflineRead } from '../offline'
import { hasAny, hasAll, serverMessage } from '../rbac'
import { theme } from '../theme'
import { Screen, BrandHeader, Card, Badge, FilterDropdown, OfflineReadStatus } from '../components'
import { Donut, HBars } from '../charts'
import OfficerHome from './parts/OfficerHome'
import ValuationOfficerHome from './parts/ValuationOfficerHome'
import ManagerHome from './parts/ManagerHome'
import ManagementPerformance from './parts/ManagementPerformance'
import { performanceProgress } from '../performanceProgress.mjs'

const fmtMoney = (v) => {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '—'
  return '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
}

const fmtNum = (v) => {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '-'
  return `${Number(v)}`
}

const fmtPerformanceNum = (v) => {
  if (v === null || v === undefined || v === '') return '—'
  const n = Number(v)
  return Number.isFinite(n) ? `${n}` : '—'
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
  gps: ['gps.capture', 'gps.certify'],
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

// Target dates arrive as YYYY-MM-DD; format them without shifting the day.
const fmtDay = (d) => {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(d || ''))
  if (!m) return ''
  return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
    .toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

// Staff target metric → readable label ("bills_logged" → "Bills Logged").
const metricLabel = (m) => String(m || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())

const ACT_ICON = { task: '📋', visit: '🚗', payment: '🧾', discovery: '📍', valuation: '🏷️' }

export default function HomeScreen() {
  const { user, logout, canUseOfflineCache } = useAuth()
  const navigation = useNavigation()
  const safeNav = useCallback((name, params) => { try { navigation.navigate(name, params) } catch {} }, [navigation])
  const { width } = useWindowDimensions()
  const tablet = width >= 768
  const donutSize = Math.min(Math.min(width, 700) - 96, 230)

  const [data, setData] = useState(null)
  const [assignments, setAssignments] = useState([])
  const [err, setErr] = useState('')
  const [readStatus, setReadStatus] = useState(null)
  const [syncedAt, setSyncedAt] = useState(null)
  const [cacheError, setCacheError] = useState(null)
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
    const assignment = data?.profile?.organizational_assignment
    Alert.alert(
      data?.profile?.full_name || user?.full_name || 'Profile',
      `${data?.profile?.role || user?.role || ''}\n${data?.profile?.section || ''}\n${user?.email || ''}${user?.role === 'Enforcement Officer' ? `\n${assignment?.tax_region || 'Not Assigned'}\n${assignment?.tax_district || 'Not Assigned'}` : ''}`,
      [
        { text: 'Close', style: 'cancel' },
        { text: 'Log out', style: 'destructive', onPress: () => logout() },
      ],
    )
  }

  const showNotice = () => {
    if (hasAny(user, ['notifications.view'])) {
      safeNav('Notices')
      return
    }
    Alert.alert('Notices', `${data?.notifications?.unread || 0} unread notice(s) in your section.`, [{ text: 'OK' }])
  }

  const load = useCallback(async (fresh = false) => {
    try {
      // `fresh` bypasses the backend's 45s dashboard cache so a pull-to-refresh
      // re-reads the latest target/achievement values.
      const result = await fetchOfflineRead({
        module: 'dashboard',
        cacheKey: `${user?.role}:my`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get('/dashboard/my', fresh ? { params: { fresh: 1 } } : undefined),
        select: (response) => response.data?.data || response.data || {},
      })
      if (result.status === 'expired') {
        setData(null)
        setErr('Your offline dashboard copy has expired. Connect to refresh your dashboard.')
      } else {
        setData(result.data)
        setErr('')
      }
      setReadStatus(result.status)
      setSyncedAt(result.syncedAt)
      setCacheError(result.cacheError || null)
    } catch (e) {
      setErr(serverMessage(e, 'Could not load your dashboard.'))
      setData(null)
      setReadStatus(null)
    } finally {
      setTick((t) => t + 1)
    }
  }, [user, canUseOfflineCache])

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
    await Promise.all([load(true), loadAssignments()])
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
  // The backend returns every approved target for this user and the aggregate
  // computed from those same presented target values.
  const targets = Array.isArray(perf.targets) ? perf.targets : []
  const overall = perf.overall || null
  const overallProgress = performanceProgress(overall?.completion_rate)

  const taskOverview = data?.task_overview || {}
  const tasks = data?.tasks || {}
  const billsArea = data?.bills_area || {}
  // The My Bills money block is server-gated to bill-handling roles; a read-only
  // viewer (e.g. Valuation Officer) receives no bills_area payload at all.
  const showBills = !!(billsArea && billsArea.total_bills)
  // Enforcement staff are keyed off OPERATIONAL permissions only — the
  // 'enforcement.view_assignments' / 'enforcement.escalate' shortcuts are held by
  // oversight roles (Assistant Commissioner, Account Manager) and grant no
  // enforcement-screen presence (§23–§24).
  const isEnforcement = hasAny(user, ['enforcement.record_visit', 'enforcement.bill_delivery', 'enforcement.payment_followup', 'enforcement.issue_warning'])

  // Role-specific operational dashboards: officers get a dedicated home that
  // reads /dashboard/officer, keeping web/mobile in exact parity.
  const isOfficer = user?.role === 'Account & Records Officer' || user?.role === 'Enforcement Officer'

  // Valuation Officers get their own workload home fed by /dashboard/my.
  const isValOfficer = user?.role === 'Valuation Officer'
  const canViewEnforcementOrganization = hasAny(user, [
    'enforcement.org.manage',
    'enforcement.org.recommend',
    'enforcement.org.review',
  ])

  // Managers (Account & Record, Valuation, Enforcement) get a role-specific
  // manager dashboard fed by /dashboard/manager/* — mirrors the web.
  const isManager = ['Account Manager', 'Valuation Manager', 'Enforcement Manager', 'Enforcement Supervisor', 'Assistant Commissioner'].includes(user?.role)
  const isManagementPerformance = [
    'Account Manager', 'Valuation Manager', 'Enforcement Manager',
    'Account Supervisor', 'Valuation Supervisor', 'Enforcement Supervisor',
    'Assistant Commissioner',
  ].includes(user?.role)

  const priority = data?.priority_actions || []
  const engagement = data?.current_engagement
  const recent = data?.recent_activity || []
  const quick = (data?.quick_actions || []).filter((q) => {
    const needed = QUICK_ACTION_PERMS[q.key] || []
    return q.key === 'gps' ? hasAll(user, needed) : hasAny(user, needed)
  })

  // Operational task counts are separate from accumulated performance targets.
  const completedCount = taskOverview.completed ?? tasks.completed ?? 0
  const activeCount = taskOverview.assigned ?? tasks.total_active ?? 0
  // Single most urgent/current priority — the hidden rest still present in full tab for admins.
  const currentPriority = priority[0] || null

  // Reorder the officer's OWN assignments for display by the enforcement flow.
  const preview = useMemo(() => {
    return [...assignments].sort((a, b) => {
      const rankA = PRIORITY_ORDER.indexOf(a.status)
      const rankB = PRIORITY_ORDER.indexOf(b.status)
      if (rankA !== -1 || rankB !== -1) {
        const ra = rankA === -1 ? 999 : rankA
        const rb = rankB === -1 ? 999 : rankB
        if (ra !== rb) return ra - rb
      }
      const oa = a.deadline_status?.is_overdue
      const ob = b.deadline_status?.is_overdue
      if (oa !== ob) return oa ? -1 : 1
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
        subtitle={data?.profile
          ? `${data.profile.role} · ${data.profile.section}${user?.role === 'Enforcement Officer' ? ` · ${data.profile.organizational_assignment?.tax_region || 'Not Assigned'} · ${data.profile.organizational_assignment?.tax_district || 'Not Assigned'}` : ''}`
          : 'Field operations'}
        subtitleNumberOfLines={user?.role === 'Enforcement Officer' ? null : 1}
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
      <OfflineReadStatus status={readStatus} syncedAt={syncedAt} cacheError={cacheError} />

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
              {canViewEnforcementOrganization && (
                <Card style={styles.block} onPress={() => navigation.navigate('EnforcementOrganization')}>
                  <View style={styles.cardHeadRow}>
                    <Text style={styles.cardHeader}>Team Management</Text>
                    <Text style={styles.cardLink}>View regions and teams ›</Text>
                  </View>
                  <Text style={styles.cardHint}>Review your scoped tax regions, districts, teams, supervisors, and officer assignments.</Text>
                </Card>
              )}

              {isValOfficer
                ? (
                  <ValuationOfficerHome navigation={navigation} data={data} />
                ) : isOfficer ? (
                  <OfficerHome navigation={navigation} quickActions={quick} />
                ) : isManager ? (
                  <ManagerHome navigation={navigation} user={user} refreshKey={tick} />
                ) : (
                  <HeroBlock
                    showBills={showBills}
                    billsArea={billsArea}
                    followUps={followUps}
                    completedCount={completedCount}
                    taskOverview={taskOverview}
                    navigation={navigation}
                  />
                )}

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

              {!isOfficer && quick.length > 0 && (
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

              {isEnforcement && (
                assignments.length > 0 ? (
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
                )
              )}
            </>
          )}

          {tab === 'Performance' && (
            isManagementPerformance ? <ManagementPerformance refreshKey={tick} /> : (
            <>
              <Card style={[styles.block, styles.performanceCard]}>
                <View style={styles.cardHeadRow}>
                  <Text style={styles.cardHeader}>Overall Performance</Text>
                  <TouchableOpacity onPress={refresh} hitSlop={6} disabled={refreshing}>
                    <Text style={styles.cardLink}>{refreshing ? 'Refreshing…' : '⟳ Refresh'}</Text>
                  </TouchableOpacity>
                </View>
                {overall?.has_targets ? (
                  <View style={styles.overallBox}>
                    <View style={styles.overallTop}>
                      <View style={{ flex: 1 }}>
                        <Text style={styles.overallSub}>
                          Achieved {fmtPerformanceNum(overall.achieved_value)} of {fmtPerformanceNum(overall.target_value)}
                        </Text>
                      </View>
                      <View style={{ alignItems: 'flex-end' }}>
                        <Text style={styles.overallPct}>{overallProgress.label}</Text>
                        <Text style={styles.overallPctLabel}>overall progress</Text>
                      </View>
                    </View>
                    {overallProgress.width !== null && (
                      <View style={styles.overallBarTrack}>
                        <View style={[styles.overallBarFill, { width: `${overallProgress.width}%` }]} />
                      </View>
                    )}
                    <View style={styles.overallStatsRow}>
                      <OverallStat label="Total achieved" value={fmtPerformanceNum(overall.achieved_value)} />
                      <OverallStat label="Combined target" value={fmtPerformanceNum(overall.target_value)} />
                      <OverallStat label="Targets included" value={fmtPerformanceNum(overall.targets_count)} />
                    </View>
                  </View>
                ) : overall ? (
                  <Text style={styles.cardHint}>No approved performance targets for your account yet.</Text>
                ) : (
                  <Text style={styles.cardHint}>Overall performance is unavailable.</Text>
                )}
              </Card>

              <Card style={[styles.block, styles.performanceCard]}>
                <Text style={styles.cardHeader}>Approved Targets{targets.length > 1 ? ` (${targets.length})` : ''}</Text>
                {targets.length === 0 ? (
                  <Text style={styles.cardHint}>
                    {Array.isArray(perf.targets)
                      ? 'No approved performance targets for your account yet.'
                      : 'Target details are unavailable.'}
                  </Text>
                ) : (
                  <>
                    {targets.map((t, i) => {
                      const progress = performanceProgress(t.completion_rate)
                      const window = t.start_date || t.end_date
                        ? `${fmtDay(t.start_date) || '—'} → ${fmtDay(t.end_date) || '—'}`
                        : ''
                      const meta = [t.section, t.frequency, window].filter(Boolean).join(' · ')
                      return (
                        <View key={t.id} style={[styles.targetRow, styles.targetCardRow, i > 0 && styles.rowBorder]}>
                          <View style={styles.targetTop}>
                            <View style={{ flex: 1 }}>
                              <Text style={styles.targetMetric}>{metricLabel(t.metric)}</Text>
                              <Text style={styles.targetMeta}>{meta || '—'}</Text>
                            </View>
                            <Badge status={t.status} label={t.status} />
                          </View>
                          <View style={styles.targetValues}>
                            <Text style={styles.targetValueText}>
                              {fmtPerformanceNum(t.achieved_value)} / {fmtPerformanceNum(t.target_value)}{t.measurement_unit ? ` ${t.measurement_unit}` : ''}
                            </Text>
                            <Text style={styles.targetPct}>{progress.label}</Text>
                          </View>
                          {progress.width !== null && (
                            <View style={styles.targetBarTrack}>
                              <View style={[styles.targetBarFill, { width: `${progress.width}%` }]} />
                            </View>
                          )}
                        </View>
                      )
                    })}
                  </>
                )}
              </Card>

              {Object.keys(indicators).filter((k) => !['visits', 'bills_delivered', 'payment_followups'].includes(k)).length > 0 && (
                <Card style={styles.block}>
                  <Text style={styles.cardHeader}>Operational Metrics</Text>
                  {(perf.window?.start || perf.window?.end) && (
                    <Text style={styles.cardHint}>
                      {fmtDay(perf.window.start) || '—'} → {fmtDay(perf.window.end) || '—'}
                    </Text>
                  )}
                  <View style={[styles.chipRow, tablet && styles.chipRowTablet]}>
                    {Object.entries(indicators)
                      .filter(([k]) => !['visits', 'bills_delivered', 'payment_followups'].includes(k))
                      .map(([k, ind]) => (
                        <View key={k} style={styles.chip}>
                          <Text style={styles.chipValue}>{fmtPerformanceNum(ind.value)}</Text>
                          <Text style={styles.chipLabel}>{ind.label.replace(/\s+/g, ' ')}</Text>
                        </View>
                      ))}
                  </View>
                </Card>
              )}

              {metricKeys.length > 0 && (
                <Card style={styles.block}>
                  <Text style={styles.cardHeader}>Breakdown</Text>
                  <View style={styles.filterRow}>
                    <FilterDropdown
                      label="Period"
                      options={RANGES.map((r) => ({ value: r, label: RANGE_LABEL[r] }))}
                      value={range}
                      onSelect={(v) => setRange(v || 'month')}
                    />
                    <FilterDropdown
                      label="Metric"
                      options={metricKeys.map((k) => ({ value: k, label: chartMetrics[k] || k.replace(/_/g, ' ') }))}
                      value={metric || ''}
                      onSelect={(v) => selectMetric(v || metricKeys[0])}
                      color={theme.colors.info}
                    />
                    {groupOptions.length > 1 && (
                      <FilterDropdown
                        label="Group by"
                        options={groupOptions.map((o) => ({ value: o.key, label: o.key.replace(/_/g, ' ') }))}
                        value={group || ''}
                        onSelect={setGroup}
                      />
                    )}
                  </View>
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
            )
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

function HeroBlock({ showBills, billsArea, followUps, completedCount, taskOverview, navigation }) {
  return (
    <>
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
  const overdue = item.deadline_status?.is_overdue
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

function OverallStat({ label, value }) {
  return (
    <View style={styles.overallStat}>
      <Text style={styles.overallStatValue} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.6}>{String(value)}</Text>
      <Text style={styles.overallStatLabel} numberOfLines={2}>{label}</Text>
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
  performanceCard: { backgroundColor: theme.colors.white, borderColor: theme.colors.border },

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
  filterRow: { flexDirection: 'row', gap: 8, marginTop: 2 },
  targetRow: { paddingVertical: 10 },
  targetTop: { flexDirection: 'row', alignItems: 'flex-start', gap: 8 },
  targetMetric: { fontSize: 14, fontWeight: '800', color: theme.colors.white },
  targetMeta: { fontSize: 11, color: '#E0E7FF', marginTop: 2, fontWeight: '700' },
  targetValues: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 8 },
  targetValueText: { fontSize: 12.5, fontWeight: '800', color: theme.colors.white },
  targetPct: { fontSize: 13, fontWeight: '900', color: theme.colors.white },
  targetBarTrack: { height: 6, borderRadius: 6, backgroundColor: 'rgba(255,255,255,0.35)', marginTop: 6, overflow: 'hidden' },
  targetBarFill: { height: '100%', borderRadius: 6, backgroundColor: theme.colors.white },
  targetCardRow: { marginHorizontal: -8, paddingHorizontal: 8, backgroundColor: '#151B54', borderRadius: 10 },
  overallBox: {
    backgroundColor: theme.colors.primary,
    borderRadius: theme.radius.md,
    padding: 12,
    marginBottom: 8,
  },
  overallTop: { flexDirection: 'row', alignItems: 'flex-start', gap: 8 },
  overallLabel: {
    fontSize: 10,
    fontWeight: '900',
    letterSpacing: 0.8,
    textTransform: 'uppercase',
    color: theme.colors.white,
  },
  overallSub: { fontSize: 12, color: theme.colors.white, marginTop: 3, fontWeight: '700' },
  overallPct: { fontSize: 24, fontWeight: '900', color: theme.colors.white },
  overallPctLabel: {
    fontSize: 9,
    fontWeight: '800',
    letterSpacing: 0.6,
    textTransform: 'uppercase',
    color: theme.colors.primaryLight,
  },
  overallBarTrack: {
    height: 8,
    borderRadius: 8,
    backgroundColor: 'rgba(255,255,255,0.35)',
    marginTop: 10,
    overflow: 'hidden',
  },
  overallBarFill: { height: '100%', borderRadius: 8, backgroundColor: theme.colors.white },
  overallStatsRow: { flexDirection: 'row', gap: 8, marginTop: 10 },
  overallStat: {
    flex: 1,
    backgroundColor: '#151B54',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.25)',
    borderRadius: theme.radius.sm,
    paddingVertical: 6,
    paddingHorizontal: 4,
    alignItems: 'center',
  },
  overallStatValue: { fontSize: 14, fontWeight: '900', color: theme.colors.white },
  overallStatLabel: {
    fontSize: 10,
    fontWeight: '800',
    textTransform: 'uppercase',
    color: theme.colors.white,
    textAlign: 'center',
    marginTop: 1,
  },
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
