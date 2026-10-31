import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api, { unwrap, errMsg } from '../api'
import { useAuth } from '../auth'
import { Card, Stat, PageTitle, Spinner, ErrorBox, Btn, fmtMoney, fmtTime } from '../ui'
import { Donut, VBars } from '../charts'
import ManagementPerformance from '../components/ManagementPerformance'

// Role → manager dashboard type. Mirrors ManagerDashboardController::typeFor().
const TYPE_ROLE = {
  'Account Manager': 'account',
  'Valuation Manager': 'valuation',
  'Enforcement Manager': 'enforcement',
  'Enforcement Supervisor': 'enforcement',
  'Assistant Commissioner': 'ac',
}

const ENDPOINTS = { account: '/dashboard/manager/account', valuation: '/dashboard/manager/valuation', enforcement: '/dashboard/manager/enforcement', ac: '/dashboard/ac' }

export function managerType(user) {
  return TYPE_ROLE[user?.role] || null
}

const TONE_COLOR = { green: '#10B981', blue: '#0EA5E9', red: '#C70000', amber: '#F59E0B', navy: '#002060', brand: '#002060', slate: '#64748B' }

function SectionList({ title, items = [] }) {
  return (
    <Card title={title}>
      {items.length === 0 ? (
        <p className="text-sm text-slate-400">No data to display.</p>
      ) : (
        <ul className="space-y-2.5">
          {items.map((it, i) => (
            <li key={i} className="flex items-center justify-between gap-3">
              <span className="text-sm text-slate-600">{it.label}</span>
              <span className={`text-lg font-bold ${it.tone === 'red' ? 'text-red-600' : it.tone === 'green' ? 'text-emerald-600' : it.tone === 'amber' ? 'text-amber-600' : 'text-navy-800'}`}>
                {Number(it.count ?? it.value ?? 0).toLocaleString()}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function StatTable({ title, columns = [], rows = [], empty = 'No records.' }) {
  return (
    <Card title={title} className="overflow-hidden">
      <div className="-mx-5 overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              {columns.map((c) => <th key={c} className="px-3 py-2 first:pl-5 last:pr-5 whitespace-nowrap">{c}</th>)}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr><td colSpan={columns.length} className="px-5 py-8 text-center text-slate-400">{empty}</td></tr>
            ) : rows.map((row, i) => {
              const cells = Array.isArray(row) ? row : row.cols ? [row.officer, ...Object.values(row.cols)] : []
              return (
                <tr key={i} className="border-b border-slate-50 hover:bg-slate-50/60">
                  {cells.map((cell, j) => (
                    <td key={j} className="px-3 py-2.5 first:pl-5 last:pr-5 whitespace-nowrap text-slate-600">
                      {j === 0 ? <span className="font-semibold text-navy-800">{cell ?? '—'}</span> : <span className="font-semibold">{Number(cell ?? 0).toLocaleString()}</span>}
                    </td>
                  ))}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </Card>
  )
}

// status label -> segment colour (both the stacked bars and the Detail donut/HBars).
const SEG_COLOR = (label) =>
  /completed/i.test(label) ? '#10B981'
    : /in.?progress/i.test(label) ? '#0EA5E9'
      : /review|verification/i.test(label) ? '#F59E0B'
        : /delivery/i.test(label) ? '#8B5CF6'
          : /follow.?up/i.test(label) ? '#EC4899'
            : /notices/i.test(label) ? '#14B8A6'
              : /returned/i.test(label) ? '#EF4444'
                : /overdue/i.test(label) ? '#002060'
                  : /assigned/i.test(label) ? '#94A3B8'
                    : '#64748B'

/**
 * Officer Workload — compact XY bar chart.
 *  X-axis: staff members — each bar group shows that staff member's progress.
 *  Y-axis: status category counts (Assigned, In Progress, …, Completed).
 * A staff filter narrows the chart to a single officer's progress. Deliberately
 * compact: one chart + a filter, no stacked lists or donuts.
 */
function WorkloadPanel({ workload }) {
  if (!workload) return null
  const { title = 'Officer Workload', columns = [], rows = [] } = workload
  const statuses = columns.slice(1)
  const [selId, setSelId] = useState(null)
  if (statuses.length === 0) return null

  // The backend emits `cols` keys in the same order as the status columns,
  // so status column i aligns with cols key i for every manager scope.
  const valAt = (row, i) => Number((row.cols || {})[Object.keys(row.cols || {})[i]] ?? 0)

  const colIdx = (re) => statuses.findIndex((s) => re.test(s))
  const iAssigned = colIdx(/^assigned$/i)
  const iActive = colIdx(/^active$/i)
  const iCompleted = colIdx(/^completed$/i)
  const exclusiveMode = workload.mode === 'exclusive'

  const rowsFor = selId ? rows.filter((r) => r.officer_id === selId) : rows
  const data = rowsFor.map((r) => {
    const g = { label: r.officer, id: r.officer_id }
    statuses.forEach((_, i) => { g[`s${i}`] = valAt(r, i) })
    return g
  })
  const series = statuses.map((l, i) => ({ key: `s${i}`, label: l, color: SEG_COLOR(l) }))

  const totals = statuses.map((_, i) => rows.reduce((s, r) => s + valAt(r, i), 0))
  const totalAll = exclusiveMode
    ? rows.reduce((sum, row) => sum + valAt(row, iActive) + valAt(row, iCompleted), 0)
    : totals.reduce((s, v) => s + v, 0)
  const asgTotal = exclusiveMode
    ? rows.reduce((sum, row) => sum + valAt(row, iActive) + valAt(row, iCompleted), 0)
    : iAssigned >= 0 ? totals[iAssigned] : 0
  const doneTotal = iCompleted >= 0 ? totals[iCompleted] : 0
  const teamRate = asgTotal > 0 ? Math.round((doneTotal / asgTotal) * 100) : null

  const selected = rows.find((r) => r.officer_id === selId) || null
  const selRate = (r) => {
    const asg = exclusiveMode
      ? valAt(r, iActive) + valAt(r, iCompleted)
      : iAssigned >= 0 ? valAt(r, iAssigned) : 0
    const done = iCompleted >= 0 ? valAt(r, iCompleted) : 0
    return asg > 0 ? Math.round((done / asg) * 100) : null
  }
  const selTotal = selected
    ? exclusiveMode
      ? valAt(selected, iActive) + valAt(selected, iCompleted)
      : statuses.reduce((s, _, i) => s + valAt(selected, i), 0)
    : 0
  const operationalValues = (key) => selected
    ? Number(selected.operational?.[key] || 0)
    : rows.reduce((sum, row) => sum + Number(row.operational?.[key] || 0), 0)
  const rateTone = (v) => (v === null ? 'text-slate-300' : v >= 60 ? 'text-emerald-600' : v >= 30 ? 'text-amber-600' : 'text-red-600')

  if (rows.length === 0) return <Card title={title}><p className="text-sm text-slate-400">No officers in your section yet.</p></Card>

  return (
    <Card
      title={title}
      right={
        <select
          value={selected ? String(selected.officer_id) : ''}
          onChange={(e) => setSelId(e.target.value ? Number(e.target.value) : null)}
          className="px-3 py-1.5 border border-slate-300 rounded-xl bg-white text-sm text-slate-700 focus:ring-2 focus:ring-brand-500/30 focus:border-brand-400 outline-none">
          <option value="">All staff</option>
          {rows.map((r) => <option key={r.officer_id} value={r.officer_id}>{r.officer}</option>)}
        </select>
      }
    >
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <span className="text-sm font-semibold text-navy-800">
          {selected ? `${selected.officer.split(' ')[0]}'s progress` : 'Overall staff progress'}
        </span>
        <span className="text-sm text-slate-500">
          {selected
            ? <><b className={rateTone(selRate(selected))}>{selRate(selected) === null ? '—' : `${selRate(selected)}%`}</b> completed · <span className="text-slate-400">{selTotal.toLocaleString()} tasks</span></>
            : <>{teamRate !== null && <b className={rateTone(teamRate)}>{teamRate}% completed</b>} <span className="text-slate-400">· {totalAll.toLocaleString()} tasks · click a bar to inspect</span></>}
        </span>
      </div>
      <VBars data={data} series={series} height={210} format={(v) => String(v)} />
      {exclusiveMode && (
        <div className="mt-4 border-t border-slate-100 pt-3">
          <div className="mb-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">Operational detail (subset counts)</div>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {(workload.operational_metrics || []).map((metric) => (
              <div key={metric.key} className="rounded-xl bg-slate-50 px-3 py-2">
                <div className="text-lg font-bold text-navy-800">{operationalValues(metric.key).toLocaleString()}</div>
                <div className="text-[11px] text-slate-500">{metric.label}</div>
              </div>
            ))}
          </div>
        </div>
      )}
    </Card>
  )
}

function PriorityQueue({ priority }) {
  if (!priority) return null
  const rows = priority.rows || []
  const color = (p) => (p === 'Critical' ? 'bg-red-100 text-red-700' : p === 'High' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600')
  return (
    <Card title={priority.title || 'Manager Attention / Priority Queue'}>
      {rows.length === 0 ? (
        <p className="text-sm text-slate-400">Nothing requires your attention right now.</p>
      ) : (
        <ul className="space-y-3">
          {rows.map((r, i) => (
            <li key={i} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-50 pb-3 last:border-0 last:pb-0">
              <div className="flex items-center gap-3 min-w-0">
                <span className={`shrink-0 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide ${color(r.priority)}`}>{r.priority}</span>
                <div className="min-w-0">
                  <div className="text-sm font-semibold text-navy-800 truncate max-w-[260px]">{r.reference} · {r.label}</div>
                  <div className="text-[11px] text-slate-400">
                    {r.staff && r.staff !== '—' ? `${r.staff} · ` : ''}{r.status}{r.due && r.due !== '—' ? ` · due ${r.due}` : ''}
                  </div>
                </div>
              </div>
              <span className="text-xs font-semibold text-brand-500">{r.action}</span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function ActivityFeed({ activity = [] }) {
  return (
    <Card title="Recent Activity">
      {activity.length === 0 ? (
        <p className="text-sm text-slate-400">No recent activity in scope.</p>
      ) : (
        <ul className="space-y-3">
          {activity.map((a, i) => (
            <li key={i} className="flex items-center justify-between gap-3 border-b border-slate-50 pb-3 last:border-0 last:pb-0">
              <div className="min-w-0">
                <div className="text-sm text-slate-700 truncate">{a.event}</div>
                <div className="text-[11px] text-slate-400 truncate">{a.reference}</div>
              </div>
              <span className="shrink-0 text-[11px] text-slate-400">{a.time ? fmtTime(a.time) : '—'}</span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

// Period activity KPIs — event-date based, default reporting period = Current
// Month (§7, §31.1). Each card states what event/date field it counts so a
// "This Month" figure can never be confused with a current snapshot.
function MonthlyStrip({ monthly = [], reporting }) {
  if (!monthly || monthly.length === 0) return null
  const pad = reporting?.period
  const title = `This Month · ${pad?.period || pad?.label || monthly[0]?.period || 'Current Month'}`
  return (
    <div className="rounded-2xl border border-brand-100 bg-brand-50/50 p-4">
      <div className="flex items-baseline justify-between gap-3 mb-3">
        <h3 className="text-xs font-bold uppercase tracking-wider text-brand-800">{title}</h3>
        <span className="text-[11px] text-slate-400">Period activity · event-based</span>
      </div>
      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        {monthly.map((m) => (
          <div key={m.key} className="bg-white rounded-xl border border-slate-200 p-3">
            <div className="text-lg font-bold text-navy-800">{Number(m.value ?? 0).toLocaleString()}</div>
            <div className="text-xs text-slate-500 font-medium mt-0.5">{m.label}</div>
            <div className="text-[10px] text-slate-400 mt-1">
              {m.event} <span className="text-slate-300">· {m.event_field}</span>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

function QuickActions({ actions = [] }) {
  if (actions.length === 0) return null
  return (
    <Card title="Quick Actions">
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2.5">
        {actions.map((a, i) => (
          <Link key={i} to={a.link}
            className="flex items-center gap-2 px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white hover:border-brand-300 hover:bg-brand-50/40 text-sm text-slate-700 font-medium transition">
            <span className="text-base">{a.icon}</span>
            <span className="truncate">{a.label}</span>
          </Link>
        ))}
      </div>
    </Card>
  )
}

function AlertsStrip({ alerts = [] }) {
  if (alerts.length === 0) return null
  return (
    <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
      {alerts.map((a, i) => {
        const body = (
          <>
            <span className="font-medium">{a.label}</span>
            <span className={`font-bold ${a.count > 0 ? '' : 'text-slate-300'}`}>{Number(a.count ?? 0).toLocaleString()}</span>
          </>
        )
        const base = a.tone === 'red' ? 'border-red-100 bg-red-50/50 hover:bg-red-50 text-red-800'
          : a.tone === 'amber' ? 'border-amber-100 bg-amber-50/50 hover:bg-amber-50 text-amber-800'
            : 'border-slate-200 bg-white hover:bg-slate-50 text-slate-700'

        // An alert with no drill-down is shown, not linked: pointing it at an unfiltered
        // page would show records the count does not describe.
        if (!a.link) {
          return <div key={i} className={`flex items-center justify-between gap-2 rounded-xl border px-4 py-3 text-sm ${base}`}>{body}</div>
        }

        return <Link key={i} to={a.link} className={`flex items-center justify-between gap-2 rounded-xl border px-4 py-3 text-sm transition ${base}`}>{body}</Link>
      })}
    </div>
  )
}

export default function ManagerDashboard() {
  const { user } = useAuth()
  const type = managerType(user)
  const [data, setData] = useState(null)
  const [err, setErr] = useState(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)

  const load = (silent = false) => {
    if (!type) return
    if (!silent) setLoading(true)
    setErr(null)
    api.get(ENDPOINTS[type])
      .then((r) => {
        setData(unwrap(r).data)
        setErr(null)
      })
      .catch((e) => setErr(e))
      .finally(() => { setLoading(false); setRefreshing(false) })
  }

  useEffect(() => { load() }, [type])

  const refresh = () => { setRefreshing(true); load(true) }

  if (!type) return null

  if (err && !data) return <ErrorBox error={errMsg(err, `Could not load the ${user?.role} dashboard.`)} />

  return (
    <div className="space-y-6">
      <PageTitle
        sub={`${user?.role} · ${data?.meta?.section || ''} ${data?.meta?.section_code ? `(${data.meta.section_code})` : ''}${data?.meta?.range?.start ? ` · ${data.meta.range.start} — ${data.meta.range.end}` : ''}`}
        right={
          <div className="flex items-center gap-3">
            <span className="hidden md:block text-right text-[11px] leading-tight text-slate-400">
              {data?.meta?.reporting?.as_of ? `Data as of: ${data.meta.reporting.as_of} ${data?.meta?.timezone ? `(${data.meta.timezone})` : ''}` : data ? `As of ${fmtTime(data.meta.as_of)}` : ''}
            </span>
            <Btn tone="white" onClick={refresh} disabled={refreshing || loading}>{refreshing ? 'Refreshing…' : 'Refresh'}</Btn>
          </div>
        }>
        {data?.meta?.label || `${user?.role} Dashboard`}
      </PageTitle>

      {err && <ErrorBox error={errMsg(err, 'Could not refresh the dashboard.')} />}
      {!data ? (
        <Spinner label="Loading dashboard…" />
      ) : (
        <>
          <AlertsStrip alerts={data.alerts} />

          {/* Period activity — This Month (event-based) */}
          <MonthlyStrip monthly={data.monthly} reporting={data.meta?.reporting} />

          {/* KPI cards — each links to the underlying register where possible. */}
          <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
            {data.kpis.map((k) => {
              const card = (
                <Stat key={k.key} label={k.label} tone={k.tone} icon={k.icon}
                  value={k.money ? fmtMoney(k.value) : Number(k.value ?? 0).toLocaleString()}
                  badge={k.kind === 'period' ? 'This Month' : 'Current'} />
              )
              return k.link ? <Link key={k.key} to={k.link}>{card}</Link> : card
            })}
          </div>

          {/* Financial summary (Account) */}
          {data.financial && (
            <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
              {data.financial.map((f, i) => (
                <div key={i} className="bg-white rounded-2xl shadow-sm border border-slate-200 p-4">
                  <div className="text-xs uppercase tracking-wider text-slate-400">{f.label}</div>
                  <div className="text-xl font-bold mt-1 text-navy-800">{fmtMoney(f.value)}</div>
                </div>
              ))}
            </div>
          )}

          {/* Donuts */}
          {data.donuts && data.donuts.map((d, i) => (
            <Card key={i} title={d.title}>
              <div className="max-w-md mx-auto">
                <Donut data={d.data.map((x) => ({ ...x, color: TONE_COLOR[x.tone] }))} center={d.center} size={180} thickness={26} />
              </div>
            </Card>
          ))}

          {/* Pipeline / workflow stages */}
          {data.pipeline && (
            <Card title={data.pipeline.title}>
              {data.pipeline.stages.length === 0 ? (
                <p className="text-sm text-slate-400">No pipeline data.</p>
              ) : (
                <div className="flex flex-wrap gap-2.5">
                  {data.pipeline.stages.map((s, i) => (
                    <div key={i} className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-2.5">
                      <span className="text-lg font-bold text-navy-800">{Number(s.count).toLocaleString()}</span>
                      <span className="text-xs text-slate-500">{s.label}</span>
                    </div>
                  ))}
                </div>
              )}
            </Card>
          )}

          {/* Officer workload — interactive staff progress bars */}
          {data.workload && <WorkloadPanel workload={data.workload} />}

          <ManagementPerformance />

          {/* Review queue (Valuation) */}
          {data.review_queue && (
            <StatTable title="Valuations Awaiting Manager Review"
              columns={['Property ID', 'Classification', 'Officer', 'Submitted', 'Status']}
              rows={data.review_queue.map((r) => [r.property_id, r.property_classification, r.officer, r.submitted, r.status])}
              empty="No valuations awaiting your review." />
          )}

          {/* Quality + GPS + AC approval (Valuation) */}
          {data.quality && data.quality.length > 0 && (
            <div className="grid gap-4 md:grid-cols-3">
              <SectionList title="Valuation Quality" items={data.quality} />
              <SectionList title="GPS / Evidence" items={data.gps_evidence || []} />
              <SectionList title="AC Approval" items={data.ac_approval || []} />
            </div>
          )}

          {/* Enforcement panels */}
          {data.field_activity && (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
              <SectionList title="Field Activity Today" items={data.field_activity} />
              <SectionList title="Payment Follow-up" items={data.payment_followup} />
              <SectionList title="Notice & Escalation" items={data.notice_escalation} />
              <SectionList title="Payment Security" items={data.payment_security} />
            </div>
          )}

          {/* Charts */}
          {data.charts && data.charts.length > 0 && (
            <div className="grid lg:grid-cols-2 gap-4">
              {data.charts.map((c, i) => (
                <Card key={i} title={c.title}>
                  <VBars data={c.data} height={220} series={[{ key: 'value', label: 'Count', color: '#002060' }]} />
                </Card>
              ))}
            </div>
          )}

          {/* Priority queue + activity */}
          <div className="grid lg:grid-cols-2 gap-4">
            <PriorityQueue priority={data.priority} />
            <ActivityFeed activity={data.activity} />
          </div>

          {/* Quick actions */}
          <QuickActions actions={data.quick_actions} />
        </>
      )}
    </div>
  )
}