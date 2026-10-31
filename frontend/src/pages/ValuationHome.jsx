import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import api, { unwrap } from '../api'
import { Card, Stat, Badge, PageTitle, Spinner, ErrorBox, Btn, Input, fmtDate } from '../ui'
import { VBars, Donut, CHART_COLORS } from '../charts'
import { useAuth } from '../auth'
import PerformanceTargets from '../components/PerformanceTargets'

const TYPE_LABEL = { new_property: 'New property', reassessment: 'Reassessment' }
const typeLabel = (t) => TYPE_LABEL[t] || (t || '—').replace(/_/g, ' ')

const STATUS_TONE = {
  Assigned: 'blue',
  'Assessment in Progress': 'amber',
  'Returned for Correction': 'amber',
  'Submitted to Manager': 'navy',
  'Under Manager Review': 'navy',
  'Submitted to AC': 'navy',
  'Awaiting AC Approval': 'navy',
  'AC Approved': 'green',
  'AC Returned': 'amber',
  'AC Rejected': 'red',
  'Sent to Account & Record': 'brand',
  'Registered': 'brand',
  Completed: 'green',
}

// Register buckets. The stage keys and their member statuses mirror
// `Valuation::STAGE_TABS` on the server, which is also what `/valuations?status=` and
// the manager's drill-down links resolve against — so a counter, the bucket it opens and
// the records behind it are one population. `dueToday` buckets are not workflow stages;
// they filter the deadline flag the server already calculated.
const BUCKETS = [
  { key: 'All', label: 'All', statuses: null },
  { key: 'Assigned', label: 'Assigned', statuses: ['Assigned'] },
  { key: 'Assessment in Progress', label: 'In Progress', statuses: ['Assessment in Progress'] },
  { key: 'Returned for Correction', label: 'Returned for Correction', statuses: ['Returned for Correction', 'AC Returned', 'AC Rejected'] },
  { key: 'Awaiting Review', label: 'Submitted · Awaiting Review', statuses: ['Submitted to Manager', 'Under Manager Review'] },
  { key: 'Awaiting AC', label: 'Awaiting AC', statuses: ['Submitted to AC', 'Awaiting AC Approval'] },
  { key: 'Awaiting Account & Record', label: 'Account & Record', statuses: ['AC Approved', 'Sent to Account & Record', 'Registered'] },
  { key: 'Completed', label: 'Completed', statuses: ['Completed'] },
  { key: 'Due Today', label: 'Due Today', statuses: null, dueToday: true },
]

// The stage a status belongs to. Derived from BUCKETS rather than written out again, so
// there is one list of stages in this file rather than two that can disagree.
const ValuationStage = (status) => BUCKETS.find((b) => b.statuses?.includes(status))?.key ?? null

export default function ValuationHome({ showExports = true }) {
  const { user } = useAuth()
  const [data, setData] = useState(null)
  const [err, setErr] = useState(null)
  const [loading, setLoading] = useState(true)
  const [filter, setFilter] = useState('All')
  const [typeFilter, setTypeFilter] = useState('')
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [dl, setDl] = useState(false)

  useEffect(() => {
    let alive = true
    api.get('/dashboard/my')
      .then((r) => {
        if (!alive) return
        setData(unwrap(r).data)
        setErr(null)
      })
      .catch((e) => { if (alive) setErr(e) })
      .finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [])

  const downloadPdf = async () => {
    setDl(true); setErr(null)
    try {
      const r = await api.get('/dashboard/my/export', { responseType: 'blob' })
      const cd = (r.headers['content-disposition'] || '').match(/filename="?([^"]+)"?/)
      const name = cd ? cd[1] : 'retd_valuation_dashboard.pdf'
      const url = URL.createObjectURL(r.data)
      const a = document.createElement('a')
      a.href = url; a.download = name
      document.body.appendChild(a); a.click(); a.remove()
      URL.revokeObjectURL(url)
    } catch (ex) { setErr(ex) }
    setDl(false)
  }

  const work = data?.valuation_work
  const records = work?.records || []

  // Hooks must run on every render. This drill-filter memo is computed before
  // the conditional returns below, so the hook count is stable across the
  // loading → loaded render transition (no "more hooks than previous render").
  const filtered = useMemo(() => {
    const b = BUCKETS.find((x) => x.key === filter)
    return records.filter((r) => {
      if (b && b.statuses && !b.statuses.includes(r.status)) return false
      if (b && b.dueToday && !r.due_today) return false
      if (typeFilter && r.valuation_type !== typeFilter) return false
      if (q) {
        const hay = `${r.valuation_reference} ${r.property_id || ''} ${r.document_number || ''} ${r.property_address || ''}`.toLowerCase()
        if (!hay.includes(q.toLowerCase())) return false
      }
      return true
    })
  }, [records, filter, typeFilter, q])

  if (loading) return <Spinner label="Loading your valuation dashboard…" />

  const profile = data?.profile || {}

  if (err && !data) return <ErrorBox error={err} />

  if (!work) {
    return (
      <div className="space-y-6">
        <PageTitle sub={user?.role}>My Dashboard</PageTitle>
        {err && <ErrorBox error={err} />}
        <Card title="Valuation workload">
          <p className="text-sm text-slate-500">Your valuation dashboard data is not available for this account.</p>
          <div className="mt-4"><Link to="/valuations" className="text-brand-600 font-semibold text-sm hover:underline">Open the valuation register →</Link></div>
        </Card>
      </div>
    )
  }

  const k = work.kpis
  const today = new Date().toISOString().slice(0, 10)

  const setAndDrill = (bkey) => { setFilter(bkey); setTypeFilter(''); setQ(''); setPage(1) }

  const statusData = Object.entries(work.by_status || {}).filter(([, n]) => n > 0).map(([label, value]) => ({ label, value }))
  const typeData = Object.entries(work.by_type || {}).filter(([, n]) => n > 0)
    .map(([label, value]) => ({ label: typeLabel(label), value, type: label }))

  // A stage bucket's count is the server's count for that stage, so the pill and the rows
  // behind it are the same records. `by_stage` is the stage-level view of exactly the
  // records `by_status` was counted from.
  const byStage = work.by_stage || {}
  const bucketCount = (b) => (b.dueToday
    ? records.filter((r) => r.due_today && !r.terminal).length
    : byStage[b.key] ?? 0)

  const openWork = k.my_assigned + k.in_progress + k.returned
  const accountStage = byStage['Awaiting Account & Record'] ?? 0

  const focus = k.returned > 0
    ? { label: 'Returned assessments need your correction', detail: 'Correct the reviewer/AC concerns and resubmit assessments that came back to you.', count: k.returned, tone: 'danger', icon: '↩️', drill: 'Returned for Correction' }
    : k.due_today > 0
      ? { label: 'Assessments due today', detail: 'Finish the assessments that are due today before they slip.', count: k.due_today, tone: 'primary', icon: '⏰', drill: 'Due Today' }
      : k.in_progress > 0
        ? { label: 'Continue your assessments in progress', detail: 'The quickest win is finishing assessments you have already started.', count: k.in_progress, tone: 'primary', icon: '✍️', drill: 'Assessment in Progress' }
        : k.my_assigned > 0
          ? { label: 'Start your newly assigned assessments', detail: 'Begin work on the assessments newly assigned to you.', count: k.my_assigned, tone: 'primary', icon: '📥', drill: 'Assigned' }
          : { label: 'You are up to date', detail: 'No assessment currently needs a decision from you. Well done.', count: 0, tone: 'primary', icon: '✅', drill: null }

  // Each counter's drill is the bucket that holds the records it counted.
  const kpis = [
    { key: 'my_assigned', label: 'My Assigned Valuation Tasks', icon: '📥', tone: 'blue', drill: 'Assigned', value: k.my_assigned },
    { key: 'in_progress', label: 'In Progress', icon: '✍️', tone: 'amber', drill: 'Assessment in Progress', value: k.in_progress },
    { key: 'returned', label: 'Returned for Correction', icon: '↩️', tone: 'red', drill: 'Returned for Correction', value: k.returned },
    { key: 'submitted', label: 'Submitted · Awaiting Review', icon: '📤', tone: 'navy', drill: 'Awaiting Review', value: k.submitted },
    { key: 'due_today', label: 'Due Today', icon: '⏰', tone: 'amber', drill: 'Due Today', value: k.due_today },
    { key: 'completed', label: 'Completed', icon: '✅', tone: 'green', drill: 'Completed', value: k.completed },
  ]

  const pageSize = 8
  const pages = Math.max(1, Math.ceil(filtered.length / pageSize))
  const safePage = Math.min(page, pages)
  const rows = filtered.slice((safePage - 1) * pageSize, safePage * pageSize)

  return (
    <div className="space-y-6">
      <PageTitle
        sub={`${profile.role || user?.role} · ${profile.section || ''} · ${profile.date || fmtDate(today)}`}
        right={showExports ? (
          <Btn tone="navy" onClick={downloadPdf} disabled={dl}>
            {dl ? 'Preparing PDF…' : 'Export A4 PDF'}
          </Btn>
        ) : null}>
        {profile.first_name || 'Valuation Officer'} — My Valuation Dashboard
      </PageTitle>

      {err && <ErrorBox error={err} />}

      {/* Workload KPI cards — the officer's own pipeline, each drills the register */}
      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
        {kpis.map((s) => (
          <button key={s.key} type="button" onClick={() => setAndDrill(s.drill)} className="text-left w-full" aria-label={`${s.label}: ${s.value} — filter register`}>
            <Stat label={s.label} value={s.value} tone={s.tone} icon={s.icon} />
          </button>
        ))}
      </div>

      {/* Current focus — recommended next action */}
      <div className="grid md:grid-cols-3 gap-4">
        <Card title="Current focus" className="md:col-span-2">
          <div className="flex items-start gap-4">
            <span className="text-3xl">{focus.icon}</span>
            <div className="flex-1">
              <div className="font-semibold text-slate-800">{focus.label}</div>
              <p className="text-sm text-slate-500 mt-1">{focus.detail}</p>
              {focus.drill && (
                <div className="mt-3">
                  <Btn tone={focus.tone} onClick={() => setAndDrill(focus.drill)}>
                    {focus.count > 0 ? `Go to ${focus.count} item(s)` : 'Open list'}
                  </Btn>
                </div>
              )}
            </div>
            <div className="hidden sm:flex flex-col items-center justify-center px-5 gap-1 border-l border-slate-100">
              <span className="text-3xl font-extrabold text-navy-800">{openWork}</span>
              <span className="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Open in my queue</span>
            </div>
          </div>
        </Card>
        <Card title="Workload summary">
          <ul className="space-y-2.5 text-sm">
            <li className="flex justify-between"><span className="text-slate-500">Awaiting my action</span><b>{openWork}</b></li>
            <li className="flex justify-between"><span className="text-slate-500">Submitted for review</span><b>{k.submitted}</b></li>
            <li className="flex justify-between"><span className="text-slate-500">Account &amp; Record / Registration</span><b>{accountStage}</b></li>
            <li className="flex justify-between"><span className="text-slate-500">Completed</span><b>{k.completed}</b></li>
            <li className="pt-2 mt-2 border-t border-slate-100 flex justify-between">
              <span className="text-slate-500">Total in my pipeline</span><b className="text-navy-800">{work.total}</b>
            </li>
          </ul>
        </Card>
      </div>

      {/* Performance targets — every approved target for this officer, not just one */}
      <PerformanceTargets performance={data?.performance} />

      {/* Two interactive charts — click a slice/bar to filter the register below */}
      <div className="grid md:grid-cols-2 gap-4">
        <Card title="My assessments by stage" right={<span className="text-xs text-slate-400">Click an item to filter the register</span>}>
          <VBars
            data={statusData}
            series={[{ key: 'n', label: 'Assessments', color: CHART_COLORS[1] }]}
            onClick={(d) => {
              // Drill to the stage that actually holds the status that was clicked. The
              // old fallback sent anything unrecognised to Returned for Correction, so
              // clicking AC Approved opened an unrelated list.
              const stage = ValuationStage(d.label)
              if (stage) setAndDrill(stage)
            }}
          />
        </Card>
        <Card title="Valuations by type" right={<span className="text-xs text-slate-400">Click a slice to filter the register</span>}>
          <Donut
            data={typeData}
            center="valuations"
            onClick={(d) => { setTypeFilter(typeFilter === d.type ? '' : d.type); setFilter('All'); setQ(''); setPage(1) }}
          />
        </Card>
      </div>

      {/* My Valuation Tasks — the drill register, same records as the KPIs */}
      <Card
        title="My Valuation Tasks"
        right={
          <Link to="/valuations" className="text-brand-600 font-semibold text-sm hover:underline whitespace-nowrap">
            Open full register →
          </Link>
        }>
        <div className="flex flex-wrap items-center gap-2 mb-4">
          {BUCKETS.map((b) => {
            const n = b.key === 'All' ? records.length : bucketCount(b)
            if (b.key !== 'All' && n <= 0) return null
            return (
              <button key={b.key} type="button"
                onClick={() => setAndDrill(b.key)}
                className={`px-3 py-1.5 rounded-full text-sm font-semibold border ${filter === b.key && !typeFilter ? 'bg-brand-500 text-white border-brand-500' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'}`}>
                {b.label} <span className="opacity-70">({n})</span>
              </button>
            )
          })}
          {typeData.map((t) => (
            <button key={t.type} type="button"
              onClick={() => { setTypeFilter(typeFilter === t.type ? '' : t.type); setFilter('All'); setPage(1) }}
              className={`px-2.5 py-1.5 rounded-full text-xs font-semibold border ${typeFilter === t.type ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50'}`}>
              {t.label} ({t.value})
            </button>
          ))}
          <div className="ml-auto min-w-[180px]">
            <Input placeholder="Search reference, property, document…" value={q}
              onChange={(e) => { setQ(e.target.value); setPage(1) }} />
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="py-2.5 pr-3">Reference</th>
                <th className="py-2.5 pr-3">Property ID</th>
                <th className="py-2.5 pr-3">Document #</th>
                <th className="py-2.5 pr-3">Type</th>
                <th className="py-2.5 pr-3">Classification</th>
                <th className="py-2.5 pr-3 hidden lg:table-cell">Address</th>
                <th className="py-2.5 pr-3">Status</th>
                <th className="py-2.5 pr-3">Due date</th>
                <th className="py-2.5">Action</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((r) => {
                const overdue = r.deadline_status?.is_overdue
                return (
                  <tr key={r.id} className="hover:bg-slate-50">
                    <td className="py-2.5 pr-3">
                      <Link to={`/valuations?focus=${r.id}&status=${encodeURIComponent(r.status)}`} className="font-semibold text-navy-700 hover:underline">
                        {r.valuation_reference}
                      </Link>
                    </td>
                    <td className="py-2.5 pr-3 text-slate-500">{r.property_id || '—'}</td>
                    <td className="py-2.5 pr-3 text-slate-500">{r.document_number || '—'}</td>
                    <td className="py-2.5 pr-3 text-slate-500">{typeLabel(r.valuation_type)}</td>
                    <td className="py-2.5 pr-3 text-slate-500">{r.property_classification || '—'}</td>
                    <td className="py-2.5 pr-3 text-slate-500 hidden lg:table-cell"><span className="inline-block max-w-[160px] truncate align-middle">{r.property_address || '—'}</span></td>
                    <td className="py-2.5 pr-3"><Badge tone={STATUS_TONE[r.status] || 'slate'}>{r.status}</Badge></td>
                    <td className={`py-2.5 pr-3 whitespace-nowrap ${overdue ? 'text-red-600 font-semibold' : 'text-slate-500'}`}>
                      {r.due_date ? `${fmtDate(r.due_date)}${overdue ? ' · overdue' : ''}` : '—'}
                    </td>
                    <td className="py-2.5">
                      <Link to={`/valuations?focus=${r.id}&status=${encodeURIComponent(r.status)}`} className="text-brand-600 font-semibold text-sm hover:underline">
                        View →
                      </Link>
                    </td>
                  </tr>
                )
              })}
              {rows.length === 0 && (
                <tr>
                  <td colSpan={9} className="py-8 text-center text-sm text-slate-400">No valuations match the current filter{filter !== 'All' ? ` (${filter})` : ''}.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        <div className="flex items-center justify-between mt-4">
          <span className="text-xs text-slate-400">Showing {rows.length} of {filtered.length} valuation(s)</span>
          <div className="flex items-center gap-2">
            <Btn tone="white" onClick={() => setPage(safePage - 1)} disabled={safePage <= 1}>← Prev</Btn>
            <span className="text-xs text-slate-400">Page {safePage} of {pages}</span>
            <Btn tone="white" onClick={() => setPage(safePage + 1)} disabled={safePage >= pages}>Next →</Btn>
          </div>
        </div>
      </Card>
    </div>
  )
}