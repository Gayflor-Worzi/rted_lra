import { useEffect, useMemo, useState } from 'react'
import api, { unwrap, errMsg } from '../../api'
import { useAuth } from '../../auth'
import { Spinner, ErrorBox, Card, Stat, Badge } from '../../ui'
import { HBars, Donut, CHART_COLORS } from '../../charts'
import { fmtMoney } from '../../lib/constants'

const MONTH = (d) => (d ? String(d).slice(0, 7) : '—')
const MONTH_LABEL = (m) => {
  const [y, mo] = m.split('-')
  return `${['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][Number(mo) - 1]} ${y}`
}

const GROUPS = [
  { key: 'status', label: 'Task status' },
  { key: 'payment', label: 'Payment status' },
  { key: 'classification', label: 'Property classification' },
  { key: 'month', label: 'Month' },
]

const COMPLETED = ['Resolved', 'Closed', 'Paid', 'Partially Paid']

export default function Overview() {
  const { can } = useAuth()
  const [tasks, setTasks] = useState(null)
  const [stats, setStats] = useState(null)
  const [err, setErr] = useState('')
  const [group, setGroup] = useState('status')

  useEffect(() => {
    const fetchBoth = async () => {
      try {
        const [t, s] = await Promise.all([
          api.get('/tasks', { params: { view: 'mine', per_page: 200 } }),
          can('discovery.view', 'discovery.review')
            ? api.get('/discoveries/stats').catch(() => null)
            : Promise.resolve(null),
        ])
        if (t) setTasks(unwrap(t).data)
        if (s) setStats(unwrap(s).data)
      } catch (ex) {
        setErr(errMsg(ex))
      }
    }
    fetchBoth()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const list = tasks?.data || []
  const active = list.filter((t) => !COMPLETED.includes(t.status))
  const overdue = list.filter((t) => !COMPLETED.includes(t.status) && t.due_date && String(t.due_date).slice(0, 10) < new Date().toISOString().slice(0, 10))
  const escalated = list.filter((t) => t.status === 'Escalated')
  const collected = list.filter((t) => t.bill?.payment_status === 'Paid')
    .reduce((s, t) => s + Number(t.bill?.total_tax_due || 0), 0)

  const buckets = useMemo(() => {
    const counts = {}
    for (const t of list) {
      let key = t.status || '—'
      if (group === 'payment') key = t.bill?.payment_status || '—'
      if (group === 'classification') key = t.bill?.property_classification || '—'
      if (group === 'month') key = MONTH(t.due_date)
      counts[key] = (counts[key] || 0) + 1
    }
    return Object.entries(counts)
      .sort((a, b) => group === 'month' ? a[0].localeCompare(b[0]) : b[1] - a[1])
      .map(([label, value], i) => ({
        label: group === 'month' ? MONTH_LABEL(label) : label,
        value,
        color: CHART_COLORS[i % CHART_COLORS.length],
      }))
  }, [list, group])

  if (!tasks && !err) return <Spinner label="Loading enforcement overview…" />
  if (err && !tasks) return <div><ErrorBox error={err} /></div>

  return (
    <div className="space-y-4">
      <ErrorBox error={err} />

      {/* Stat cards */}
      <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <Stat label="My tasks" value={list.length} tone="navy" icon="📋" />
        <Stat label="Active" value={active.length} tone="blue" icon="🔄" />
        <Stat label="Overdue" value={overdue.length} tone="amber" icon="⏰" />
        <Stat label="Escalated" value={escalated.length} tone="red" icon="🚨" />
      </div>

      {stats && (
        <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <Stat label="Discoveries" value={stats.total ?? '—'} tone="navy" icon="📡" />
          <Stat label="Awaiting review" value={stats.awaiting_review ?? '—'} tone="amber" icon="🕵️" />
          <Stat label="Requiring valuation" value={stats.requiring_valuation ?? '—'} tone="blue" icon="🏷️" />
          <Stat label="Completed" value={stats.completed ?? '—'} tone="green" icon="✅" />
        </div>
      )}

      {/* Filter-driven chart row — exactly one bar + one donut, sharing one selector. */}
      <Card
        title="Workload"
        right={
          <select value={group} onChange={(e) => setGroup(e.target.value)}
            className="px-3 py-1.5 border border-slate-300 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/30">
            {GROUPS.map((g) => <option key={g.key} value={g.key}>Group by {g.label}</option>)}
          </select>
        }>
        <div className="grid lg:grid-cols-2 gap-8">
          <div>
            <div className="text-xs uppercase tracking-wider text-slate-400 font-bold mb-3">By {GROUPS.find((g) => g.key === group)?.label} — ranked</div>
            <HBars data={buckets} />
          </div>
          <div>
            <div className="text-xs uppercase tracking-wider text-slate-400 font-bold mb-3">Share of workload</div>
            <Donut data={buckets} center="tasks" />
          </div>
        </div>
      </Card>

      {/* Discovery pipeline strip */}
      {stats && (
        <Card title="Discovery pipeline">
          <div className="flex flex-wrap gap-4">
            {['awaiting_review', 'sent_to_account', 'requiring_valuation', 'under_valuation', 'pending_ac', 'processed_in_litas', 'completed'].map((k) => {
              const label = {
                awaiting_review: 'Awaiting review', sent_to_account: 'Sent to account',
                requiring_valuation: 'Requiring valuation', under_valuation: 'Under valuation',
                pending_ac: 'Pending AC approval', processed_in_litas: 'Processed in LITAS', completed: 'Completed',
              }[k]
              return (
                <div key={k} className="flex-1 min-w-[120px] text-center">
                  <div className={`text-2xl font-bold ${k === 'completed' ? 'text-emerald-600' : k.includes('processing') || k === 'processed_in_litas' ? 'text-navy-800' : 'text-amber-600'}`}>
                    {stats[k] ?? 0}
                  </div>
                  <div className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">{label}</div>
                </div>
              )
            })}
          </div>
        </Card>
      )}

      {list.some((t) => t.bill?.payment_status === 'Paid') && (
        <div className="flex items-center gap-2 text-sm text-slate-500">
          <Badge tone="green">Paid this cohort</Badge>
          <span>{list.filter((t) => t.bill?.payment_status === 'Paid').length} payments</span>
          <span className="font-semibold text-navy-800">{fmtMoney(collected)}</span>
        </div>
      )}
    </div>
  )
}