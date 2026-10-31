import { useCallback, useEffect, useState } from 'react'
import { View, Text, TouchableOpacity, Alert, useWindowDimensions } from 'react-native'
import api from '../../api'
import { theme } from '../../theme'
import { Card, FilterDropdown } from '../../components'
import { Donut, HBars } from '../../charts'

const fmtMoney = (v) => (v == null || Number.isNaN(Number(v)) ? '—' : '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
const fmtNum = (v) => (v == null || Number.isNaN(Number(v)) ? '—' : `${Number(v)}`)

// Queue routes sent by the backend → the closest mobile destination.
const ROUTE_TARGET = { '/bills': 'Tasks', '/payments': 'Verifications', '/enforcement': 'Tasks', '/notices': 'Notices' }

const toneColor = (t) => ({
  navy: theme.colors.navy, blue: theme.colors.info, green: theme.colors.success,
  red: theme.colors.danger, amber: theme.colors.warning, brand: theme.colors.primary,
}[t] || theme.colors.navy)

/**
 * Role-specific operational dashboard for Account & Records Officers and
 * Enforcement Officers — the same /dashboard/officer payload the web console
 * renders, so web/mobile figures always agree. The merged "position & work
 * queues" card opens the register from status cards and keeps money figures
 * read-only; bar + donut are read from the same data.
 */
export default function OfficerHome({ navigation }) {
  const { width } = useWindowDimensions()
  const donutSize = Math.min(Math.min(width, 700) - 128, 210)

  const [data, setData] = useState(null)
  const [err, setErr] = useState('')
  const [loading, setLoading] = useState(true)
  const [year, setYear] = useState('')
  const [status, setStatus] = useState('')
  const [paymentStatus, setPaymentStatus] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (year) params.year = year
      if (status) params[data?.meta?.filters?.status_param || 'bill_status'] = status
      if (paymentStatus) params.payment_status = paymentStatus
      const r = await api.get('/dashboard/officer', { params })
      setData(r.data?.data || r.data || {})
      setErr('')
    } catch (e) {
      setErr('Unable to load your dashboard data.')
    }
    setLoading(false)
  }, [year, status, paymentStatus]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { load() }, [load])

  const openQueue = (route) => {
    const wanted = ROUTE_TARGET[route] || 'Tasks'
    const names = navigation.getParent?.()?.getState?.()?.routeNames || []
    const target = names.includes(wanted) ? wanted : (names.includes('Bills') ? 'Bills' : names[0])
    navigation.navigate(target)
  }

  const tapBar = (d) => Alert.alert(String(d.label), `${fmtNum(d.value)} bill(s) in the applied filters.`, [{ text: 'OK' }])
  const tapSlice = (d) => {
    const total = (data?.pie?.data || []).reduce((s, x) => s + Number(x.value), 0)
    Alert.alert(String(d.label), `${fmtNum(d.value)} · ${total ? Math.round((Number(d.value) / total) * 100) : 0}% of the range.`, [{ text: 'OK' }])
  }

  if (err && !data) {
    return (
      <Card style={{ marginTop: 12 }}>
        <Text style={{ color: theme.colors.danger, fontWeight: '600' }}>{err}</Text>
        <TouchableOpacity onPress={load} style={{ marginTop: 8, minHeight: 44, justifyContent: 'center' }}>
          <Text style={{ color: theme.colors.primary, fontWeight: '700', fontSize: 13 }}>⟳ Try again</Text>
        </TouchableOpacity>
      </Card>
    )
  }

  if (!data) {
    return (
      <Card style={{ marginTop: 12 }}>
        <Text style={{ color: theme.colors.textMuted }}>{loading ? 'Loading your dashboard…' : 'No dashboard data yet.'}</Text>
      </Card>
    )
  }

  const type = data.meta?.type
  const filters = data.meta?.filters || {}
  const kpis = data.kpis || []
  const queues = data.queues || []
  const moneyKpis = kpis.filter((k) => k.money)
  const queueTwin = (type === 'enforcement'
    ? ['my_assigned', 'field_delivery', 'payment_followups', 'notices', 'escalated']
    : ['bills_lodged', 'bills_pending'])
  const extraKpis = kpis.filter((k) => !k.money && !queueTwin.includes(k.key))

  return (
    <>
      <Card style={{ marginTop: 12 }}>
        <Text style={s.cardHeader}>{type === 'enforcement' ? 'Enforcement Dashboard' : 'Account & Records Dashboard'}</Text>
        <Text style={s.period}>
          {data.meta?.range?.start} → {data.meta?.range?.end}
          {'  ·  '}as of {data.meta?.as_of ? new Date(data.meta.as_of).toLocaleDateString() : ''}
        </Text>

        {(filters.years || filters.statuses || filters.payment_statuses).length > 0 && (
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
                label={type === 'enforcement' ? 'Stage' : 'Bill status'}
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
        )}
      </Card>

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
      )}

      {(queues.length > 0 || moneyKpis.length > 0 || extraKpis.length > 0) && (
        <Card style={s.card}>
          <Text style={s.cardHeader}>My Position & Work Queues</Text>
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
        <Text style={s.cardHeader}>{type === 'enforcement' ? 'Bills by Enforcement Stage' : 'Bills by Case Status'}</Text>
        <HBars data={data.bar?.data || []} format={fmtNum} onPress={tapBar} />
      </Card>

      <Card style={s.card}>
        <Text style={s.cardHeader}>Bills by Payment Status</Text>
        <View style={{ alignItems: 'center' }}>
          <Donut data={data.pie?.data || []} size={donutSize} center="Bills" onSlice={tapSlice} />
        </View>
      </Card>
    </>
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
  sectionLabel: { fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.8, color: theme.colors.textLight, fontWeight: '800', marginTop: 12, marginBottom: 6 },
  dropRow: { flexDirection: 'row', gap: 8 },
  miniGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  miniTile: { flexBasis: '30%', flexGrow: 1, minWidth: 0, backgroundColor: '#F8FAFC', borderRadius: 10, borderWidth: 1, borderColor: theme.colors.border, paddingVertical: 9, paddingHorizontal: 6, alignItems: 'center' },
  miniValue: { fontSize: 15, fontWeight: '800' },
  miniLabel: { fontSize: 10, color: theme.colors.textMuted, marginTop: 2, fontWeight: '600', textAlign: 'center' },
  qGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  qTile: { flexBasis: '44%', flexGrow: 1, minWidth: 0, minHeight: 58, backgroundColor: '#F8FAFC', borderRadius: 10, borderWidth: 1, borderColor: theme.colors.border, paddingVertical: 8, paddingHorizontal: 8, justifyContent: 'center' },
  qValue: { fontSize: 16, fontWeight: '900' },
  qLabel: { fontSize: 10, color: theme.colors.textMuted, marginTop: 2, fontWeight: '700' },
  qGo: { position: 'absolute', right: 8, top: 6, fontSize: 12, color: theme.colors.textLight, fontWeight: '800' },
}