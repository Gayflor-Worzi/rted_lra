import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import api, { unwrap, errMsg } from '../api'
import { Card, Stat, Spinner, ErrorBox, Btn, Input, Badge, fmtMoney } from '../ui'
import { useAuth } from '../auth'
import { VBars, Donut } from '../charts'
import BillDrawer from '../components/BillDrawer'
import PerformanceTargets from '../components/PerformanceTargets'

/**
 * Role-specific operational dashboards for Account & Records Officers and
 * Enforcement Officers, served by /dashboard/officer. Every KPI, Today's Work,
 * chart and table is computed live from authorization-scoped property_bills and
 * tasks records with cascading Year → Quarter → Month → Date → Stage →
 * Payment filters; charts and KPI cards drill straight into the detail table
 * (modeless), and CSV/PDF exports carry the same applied filters.
 *
 * Enforcement builds a personal operational cockpit ("what is assigned to me,
 * what do I do today, which cases need my next action") — no supervisory or
 * divisional statistics. All other roles keep the Analytics dashboard, and
 * other modules are untouched.
 */
export default function OfficerDashboard({ showExports = true }) {
  const { user, can } = useAuth()

  const [year, setYear] = useState('')
  const [quarter, setQuarter] = useState('')
  const [month, setMonth] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [status, setStatus] = useState('')
  const [paymentStatus, setPaymentStatus] = useState('')
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [drill, setDrill] = useState(null)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(10)
  const [dl, setDl] = useState(null)
  const [drawer, setDrawer] = useState(null)
  const detailRef = useRef(null)

  const [data, setData] = useState(null)
  const [err, setErr] = useState(null)
  const [loading, setLoading] = useState(true)
  const [tick, setTick] = useState(0)

  // Refresh when the tab regains focus — payments confirmed elsewhere (or on
  // a device) should appear here without a manual reload.
  useEffect(() => {
    const onVis = () => { if (document.visibilityState === 'visible') setTick((t) => t + 1) }
    document.addEventListener('visibilitychange', onVis)
    return () => document.removeEventListener('visibilitychange', onVis)
  }, [])

  const reset = (fn) => (v) => { fn(v); setDrill(null); setPage(1) }

  useEffect(() => {
    let alive = true
    setLoading(true)
    const params = {}
    if (year) params.year = year
    if (quarter) params.quarter = quarter
    if (month) params.month = month
    if (from || to) { if (from) params.from = from; if (to) params.to = to }
    if (status) params[data?.meta?.filters?.status_param || 'bill_status'] = status
    if (paymentStatus) params.payment_status = paymentStatus
    if (drill) { params.record_dim = drill.dim; params.record_value = drill.value }
    params.page = page
    params.per_page = perPage

    api.get('/dashboard/officer', { params })
      .then((r) => { if (!alive) return; setData(unwrap(r).data); setErr(null) })
      .catch((e) => { if (alive) setErr(e) })
      .finally(() => { if (alive) setLoading(false) })

    return () => { alive = false }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [year, quarter, month, from, to, status, paymentStatus, drill, page, perPage, tick])

  const applyDrill = (dim, item, labelPrefix) => {
    const value = item.label ?? item.value
    setDrill({ dim, value, label: `${labelPrefix ?? (dim === 'payment_status' ? 'Payment status' : data?.meta?.label)}: ${value}` })
    setPage(1)
    detailRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  const onKpi = (k) => {
    if (k.drill) {
      setDrill({ dim: k.drill.dim, value: k.drill.value, label: `${k.label}: ${k.drill.value}` })
      setPage(1)
    } else {
      setDrill(null)
      setPage(1)
    }
    detailRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  const activeFilterCount = [year, quarter, month, from, to, status, paymentStatus].filter(Boolean).length + (drill ? 1 : 0)

  const download = async (format) => {
    setErr(''); setDl(format)
    try {
      const params = { format }
      if (year) params.year = year
      if (quarter) params.quarter = quarter
      if (month) params.month = month
      if (from || to) { if (from) params.from = from; if (to) params.to = to }
      if (status) params[data?.meta?.filters?.status_param || 'bill_status'] = status
      if (paymentStatus) params.payment_status = paymentStatus
      if (drill) { params.record_dim = drill.dim; params.record_value = drill.value }
      const r = await api.get('/dashboard/officer/export', { params, responseType: 'blob' })
      const blob = r.data
      const cd = (r.headers['content-disposition'] || '').match(/filename="?([^"]+)"?/)
      const name = cd ? cd[1] : `retd_officer_dashboard.${format}`
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url; a.download = name
      document.body.appendChild(a); a.click(); a.remove()
      URL.revokeObjectURL(url)
    } catch (ex) { setErr(errMsg(ex, 'Export failed.')) }
    setDl(null)
  }

  const type = data?.meta?.type
  const isEnf = type === 'enforcement'
  const title = isEnf ? 'Enforcement Officer Dashboard'
    : type === 'account' ? 'Account & Records Officer Dashboard'
    : 'Officer Dashboard'
  const filters = data?.meta?.filters
  const queTone = (t) => ({ navy: 'text-navy-500', blue: 'text-brand-500', green: 'text-emerald-600', red: 'text-red-600', amber: 'text-amber-600', brand: 'text-brand-600' }[t] || 'text-navy-500')

  const renderCascadingFilters = () => (
    <>
      <label className="text-xs text-slate-400 font-semibold">
        <span className="block mb-1">Year</span>
        <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={year}
          onChange={(e) => { setYear(e.target.value); setQuarter(''); setMonth(''); setDrill(null); setPage(1) }}>
          <option value="">All years</option>
          {(filters?.years || []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
      </label>
      <label className="text-xs text-slate-400 font-semibold">
        <span className="block mb-1">Quarter</span>
        <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={quarter}
          onChange={(e) => { setQuarter(e.target.value); setMonth(''); setDrill(null); setPage(1) }}>
          <option value="">All quarters</option>
          {(filters?.quarters || []).map((o) => <option key={o.value} value={o.value}>{o.label}{o.count != null ? ` (${o.count})` : ''}</option>)}
        </select>
      </label>
      <label className="text-xs text-slate-400 font-semibold">
        <span className="block mb-1">Month</span>
        <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={month}
          onChange={reset(setMonth)}>
          <option value="">All months</option>
          {(filters?.months || []).map((o) => <option key={o.value} value={o.value}>{o.label}{o.count != null ? ` (${o.count})` : ''}</option>)}
        </select>
      </label>
      <label className="text-xs text-slate-400 font-semibold">
        <span className="block mb-1">From (overrides)</span>
        <Input className="w-full" type="date" value={from} onChange={reset(setFrom)} />
      </label>
      <label className="text-xs text-slate-400 font-semibold">
        <span className="block mb-1">To (overrides)</span>
        <Input className="w-full" type="date" value={to} onChange={reset(setTo)} />
      </label>
      <label className="text-xs text-slate-400 font-semibold">
        <span className="block mb-1">{isEnf ? 'Stage' : 'Bill status'}</span>
        <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={status}
          onChange={reset(setStatus)}>
          <option value="">All</option>
          {(filters?.statuses || []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
      </label>
      <label className="text-xs text-slate-400 font-semibold">
        <span className="block mb-1">Payment status</span>
        <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={paymentStatus}
          onChange={reset(setPaymentStatus)}>
          <option value="">All</option>
          {(filters?.payment_statuses || []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
      </label>
    </>
  )

  // -----------------------------------------------------------------
  //  Enforcement Officer — personal operational cockpit
  // -----------------------------------------------------------------
  const todayTone = { deliveries: 'blue', followups: 'amber', verifications: 'blue', reminders: 'amber', demands: 'red', other: 'slate' }
  const todayIcon = { deliveries: '🚚', followups: '📞', verifications: '✅', reminders: '📨', demands: '🚨', other: '🗂️' }
  const kpiCard = (k) => (
    <button key={k.key} onClick={() => onKpi(k)} disabled={!k.drill && k.key !== 'my_assigned'}
      className={`rounded-lg border px-2.5 py-2 text-left transition ${k.drill ? 'border-slate-200 bg-white hover:border-brand-300 hover:shadow-sm cursor-pointer' : 'border-slate-100 bg-slate-50 cursor-default'}`}>
      <div className="flex items-start justify-between gap-2">
        <div className="text-[9px] uppercase tracking-wide text-slate-400 font-semibold leading-tight">{k.label}</div>
        <div className="text-sm leading-none">{k.icon}</div>
      </div>
      <div className={`mt-0.5 text-base font-bold ${queTone(k.tone)}`}>{k.money ? fmtMoney(k.value) : Number(k.value).toLocaleString()}</div>
      <div className="text-[9px] text-brand-500 font-semibold mt-0.5 h-2.5 truncate">{k.drill ? 'See Mores →' : k.key === 'my_assigned' ? 'All my assigned' : ''}</div>
    </button>
  )

  // "My Tasks — N active · View all" compact link (data can be null on first render).
  // Account & Records officers land in the Account My Tasks module; enforcement
  // officers keep the Enforcement tasks tab.
  const myTasksTo = type === 'account' ? '/account/my-tasks' : '/enforcement?tab=tasks'
  const myTasksLine = data?.my_tasks ? (
    <div className="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-slate-500">
      <span><span className="font-bold text-navy-800">{Number(data.my_tasks.active || 0)}</span> active</span>
      <span>Overdue <span className="font-semibold text-amber-600">{Number(data.my_tasks.overdue || 0)}</span></span>
      <span>Due today <span className="font-semibold text-amber-600">{Number(data.my_tasks.due_today || 0)}</span></span>
      <Link to={myTasksTo} className="text-brand-600 font-semibold hover:underline">View all →</Link>
    </div>
  ) : null

  // Account & Records — workflow stepper + money tiles read from the same payload.
  const queues = data?.queues || []
  const kpiList = data?.kpis || []
  const queBy = (key) => queues.find((x) => x.key === key)
  const kpiBy = (key) => kpiList.find((x) => x.key === key)
  const qv = (key) => Number(queBy(key)?.value || 0)
  const kv = (key) => Number(kpiBy(key)?.value || 0)
  const mon = (key) => Number(kpiBy(key)?.value ?? 0)

  const acctSteps = [
    { key: 'assigned', label: 'Assigned / newly lodged', value: qv('my_bills') || kv('bills_lodged'), route: queBy('my_bills')?.route || '/bills', tone: 'navy' },
    { key: 'processing', label: 'Processing', value: qv('pending_processing'), route: queBy('pending_processing')?.route || '/bills', tone: 'blue' },
    { key: 'verification', label: 'Payment verification', value: qv('payment_verification'), route: queBy('payment_verification')?.route || '/payments', tone: 'amber' },
    { key: 'correction', label: 'Amendment / data correction', value: qv('amendments') + qv('data_quality'), route: queBy('amendments')?.route || '/amendments', tone: 'red' },
    { key: 'completed', label: 'Completed', value: kv('bills_processed'), route: queBy('my_bills')?.route || '/bills', tone: 'green' },
  ]

  const accVerificationLink = (
    <Link to={queBy('payment_verification')?.route || '/payments'}
      className="block rounded-xl border border-slate-200 bg-white px-3 py-2.5 hover:border-brand-300 hover:shadow-sm transition">
      <div className="flex items-center justify-between text-[10px] uppercase tracking-wide text-slate-400">
        <span>Pending payment verification</span><span>✅</span>
      </div>
      <div className="text-lg font-bold mt-1 text-brand-500">{qv('payment_verification').toLocaleString()}</div>
      <div className="text-[10px] text-brand-500 font-semibold mt-1">Open verification queue →</div>
    </Link>
  )

  const accQualityLink = (
    <Link to={queBy('data_quality')?.route || '/drill/flags'}
      className={`block rounded-xl border bg-white px-3 py-2.5 transition ${qv('data_quality') > 0 || qv('amendments') > 0 ? 'border-red-200 hover:border-red-300 hover:shadow-sm' : 'border-slate-200 hover:border-slate-300'}`}>
      <div className="flex items-center justify-between text-[10px] uppercase tracking-wide text-slate-400">
        <span>Amendments &amp; data-quality flags</span><span>🔧</span>
      </div>
      <div className={`text-lg font-bold mt-1 ${qv('data_quality') > 0 || qv('amendments') > 0 ? 'text-red-600' : 'text-slate-500'}`}>
        {(qv('amendments') + qv('data_quality')).toLocaleString()}
      </div>
      <div className="text-[10px] text-slate-400 font-semibold mt-1">Review corrections needed →</div>
    </Link>
  )

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-3">
          <div className="text-xs font-bold uppercase tracking-wide text-slate-500">{title}</div>
          <div className="text-xs text-slate-400">
            Period {data?.meta?.range?.start} → {data?.meta?.range?.end} · as of {data ? new Date(data.meta.as_of).toLocaleString() : ''}
          </div>
        </div>
        {showExports && (
          <div className="flex items-center gap-2">
            <Btn tone="white" onClick={() => download('csv')} disabled={dl === 'csv' || loading}>{dl === 'csv' ? 'Downloading.' : 'CSV'}</Btn>
            <Btn tone="navy" onClick={() => download('pdf')} disabled={dl === 'pdf' || loading}>{dl === 'pdf' ? 'Downloading.' : 'A4 PDF'}</Btn>
          </div>
        )}
      </div>
      {err && !data && <ErrorBox error={errMsg(err, 'Unable to load this information. Please refresh or try again.')} />}
      {err && data && <div className="mb-3"><ErrorBox error={errMsg(err, 'Could not export.')} /></div>}

      {/* My performance targets — every approved target for this officer */}
      <PerformanceTargets />

      {!data ? (
        <Spinner label="Loading dashboard…" />
      ) : isEnf ? (
        <>
          {/* Compact filters — hidden behind a toggle until needed */}
          <Card>
            <div className="flex items-center justify-between gap-2">
              <div>
                <div className="font-semibold text-slate-800 text-sm">Filters</div>
                <div className="text-[11px] text-slate-400 mt-0.5">
                  {activeFilterCount === 0
                    ? 'Assigned Task'
                    : `${activeFilterCount} active filter${activeFilterCount === 1 ? '' : 's'} applied`}
                </div>
              </div>
              <Btn tone={activeFilterCount ? 'amber' : 'white'} onClick={() => setFiltersOpen((v) => !v)}>
                {filtersOpen ? 'Hide filters' : 'Filter'}
                {activeFilterCount > 0 && <span className="ml-1.5 rounded-full bg-amber-500 text-white px-1.5 py-0.5 text-[10px]">{activeFilterCount}</span>}
              </Btn>
            </div>
            {filtersOpen && (
              <div className="mt-4 grid grid-cols-2 md:grid-cols-3 xl:grid-cols-7 gap-3 border-t border-slate-100 pt-4">
                {renderCascadingFilters()}
              </div>
            )}
            {!filtersOpen && (
              <div className="text-[11px] text-slate-400 mt-3">Click any KPI card or chart section to filter the assignments below.</div>
            )}
          </Card>

          {/* My Tasks compact link */}
          {myTasksLine && (
            <Card>
              <div className="flex items-center justify-between gap-2">
                <h2 className="font-semibold text-slate-800 text-sm flex items-center gap-2">
                  <span className="w-1 h-4 rounded bg-brand-500 inline-block" />My Tasks
                </h2>
                {myTasksLine}
              </div>
            </Card>
          )}

          {/* KPI cards — the officer's workload at a glance */}
          <Card title="My position at a glance"
            right={<span className="text-[11px] text-slate-400 font-normal">Tap any card to bring up the matching assignments</span>}>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-2.5">
              {(data.kpis || []).map(kpiCard)}
            </div>
          </Card>

          {/* Today's Work — what to do today */}
          <Card title="Today's work"
            right={data.today_work?.total > 0
              ? <Badge tone="amber">{data.today_work.total} action{data.today_work.total === 1 ? '' : 's'}</Badge>
              : <Badge tone="green">All clear</Badge>}>
            {!data.today_work || data.today_work.items.length === 0 ? (
              <div className="text-sm text-slate-500">No today's-work section for this role.</div>
            ) : data.today_work.total === 0 ? (
              <div className="text-sm text-slate-500">No outstanding actions for today.</div>
            ) : (
              <div className="flex flex-wrap gap-2.5">
                {data.today_work.items.filter((b) => b.count > 0).map((b) => (
                  <div key={b.key} className="flex items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2.5">
                    <span className="text-base">{todayIcon[b.key]}</span>
                    <div>
                      <div className={`text-lg font-bold leading-tight ${queTone(todayTone[b.key])}`}>{b.count}</div>
                      <div className="text-[10px] uppercase tracking-wide text-slate-400">{b.label}</div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Card>

          {/* Charts — click to drill */}
          <div className="grid lg:grid-cols-5 gap-4">
            <Card title="My cases by current stage" className="lg:col-span-3">
              <VBars data={data.bar.data} height={220}
                series={[{ key: 'value', label: data.bar.metric_label || 'Count', color: '#C70000' }]}
                onClick={(item) => applyDrill(data.bar.dimension, item, 'Stage')} />
            </Card>
            <Card title="My cases by payment status" className="lg:col-span-2">
              <Donut data={data.pie.data || []} size={170} thickness={28}
                center="Bills"
                onClick={(item) => applyDrill(data.pie.dimension, item, 'Payment status')} />
            </Card>
          </div>

          {/* Quick actions */}
          <Card title="Quick actions">
            <div className="flex flex-wrap gap-2">
              <Link to={myTasksTo}><Btn tone="white">📋 My Tasks</Btn></Link>
              <Link to="/enforcement?tab=records"><Btn tone="white">🗂️ Enforcement Register</Btn></Link>
              <Link to="/enforcement?tab=followups"><Btn tone="white">📞 Record Field Visit</Btn></Link>
              <Link to="/payments"><Btn tone="white">💵 Payments &amp; Notices</Btn></Link>
              {can('discovery.create') && <Link to="/enforcement?tab=discoveries"><Btn tone="navy">📡 New Discovery</Btn></Link>}
            </div>
          </Card>

          {/* My active assignments (drill-down target) */}
          <div ref={detailRef}>
            <Card title="My active assignments"
              right={
                <div className="flex items-center gap-2">
                  {drill && (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand-50 text-brand-700 text-xs font-semibold">
                      {drill.label}
                      <button onClick={() => { setDrill(null); setPage(1) }} className="hover:text-brand-900 font-bold" aria-label="Clear drill">×</button>
                    </span>
                  )}
                  <select className="px-3 py-1.5 border border-slate-300 rounded-xl text-sm bg-white" value={String(perPage)}
                    onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1) }}>
                    <option value="10">10 rows</option>
                    <option value="25">25 rows</option>
                    <option value="50">50 rows</option>
                  </select>
                </div>
              }>
              <div className="-mx-5 overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                      {data.records.columns.map((c) => <th key={c} className="px-3 py-2 first:pl-5 whitespace-nowrap">{c}</th>)}
                      <th className="px-3 py-2 pr-5 text-right whitespace-nowrap">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.records.rows.length === 0 ? (
                      <tr><td colSpan={data.records.columns.length + 1} className="px-5 py-8 text-center text-slate-400">No records in scope for these filters.</td></tr>
                    ) : data.records.rows.map((row, i) => (
                      <tr key={i} className="border-b border-slate-50 hover:bg-slate-50/60">
                        {row.cells.map((cell, j) => (
                          <td key={j} className="px-3 py-2.5 first:pl-5 whitespace-nowrap text-slate-600">
                            {j === 0 ? <span className="font-semibold text-navy-800">{cell}</span> : cell}
                          </td>
                        ))}
                        <td className="px-3 py-2.5 pr-5 text-right whitespace-nowrap">
                          <Btn tone="white" className="!px-3 !py-1.5" onClick={() => setDrawer({ billId: row.id, taskId: row.task_id })}>View →</Btn>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {data.records.total > 0 && (
                <div className="flex items-center justify-between mt-4 text-sm">
                  <span className="text-slate-500">
                    Showing {(data.records.current_page - 1) * data.records.per_page + 1}–{Math.min(data.records.current_page * data.records.per_page, data.records.total)} of {data.records.total}
                  </span>
                  <div className="flex gap-2">
                    <Btn tone="white" disabled={data.records.current_page <= 1} onClick={() => setPage((p) => p - 1)}>Prev</Btn>
                    <span className="self-center text-xs text-slate-400">Page {data.records.current_page} / {data.records.last_page}</span>
                    <Btn tone="white" disabled={data.records.current_page >= data.records.last_page} onClick={() => setPage((p) => p + 1)}>Next</Btn>
                  </div>
                </div>
              )}
            </Card>
          </div>

          {drawer && <BillDrawer billId={drawer.billId} taskId={drawer.taskId} onClose={() => setDrawer(null)} />}
        </>
      ) : (
        <>
          {/* Account & Records Officer — lodgement workflow cockpit.
              The stepper, KPIs, charts, register and exports are all rendered
              from the SAME /dashboard/officer payload, scoped to their own
              section/own records by the backend. */}
          <Card>
            <div className="flex items-center justify-between gap-2">
              <div>
                <div className="font-semibold text-slate-800 text-sm">Filters</div>
                <div className="text-[11px] text-slate-400 mt-0.5">
                  {activeFilterCount === 0
                    ? 'All records you lodged · all time'
                    : `${activeFilterCount} active filter${activeFilterCount === 1 ? '' : 's'} applied`}
                </div>
              </div>
              <Btn tone={activeFilterCount ? 'amber' : 'white'} onClick={() => setFiltersOpen((v) => !v)}>
                {filtersOpen ? 'Hide filters' : 'Filter'}
                {activeFilterCount > 0 && <span className="ml-1.5 rounded-full bg-amber-500 text-white px-1.5 py-0.5 text-[10px]">{activeFilterCount}</span>}
              </Btn>
            </div>
            {filtersOpen && (
              <div className="mt-4 grid grid-cols-2 md:grid-cols-3 xl:grid-cols-7 gap-3 border-t border-slate-100 pt-4">
                {renderCascadingFilters()}
              </div>
            )}
            {!filtersOpen && (
              <div className="text-[11px] text-slate-400 mt-3">Click any workflow step, KPI or chart section to filter the register below.</div>
            )}
          </Card>

          {myTasksLine && (
            <Card>
              <div className="flex items-center justify-between gap-2">
                <h2 className="font-semibold text-slate-800 text-sm flex items-center gap-2">
                  <span className="w-1 h-4 rounded bg-brand-500 inline-block" />My Tasks
                </h2>
                {myTasksLine}
              </div>
            </Card>
          )}

          {Number(data?.my_discoveries ?? 0) > 0 && (
            <Link to="/account/my-tasks">
              <Card>
                <div className="flex items-center justify-between gap-2">
                  <h2 className="font-semibold text-slate-800 text-sm flex items-center gap-2">
                    <span className="w-1 h-4 rounded bg-brand-500 inline-block" />My Discoveries
                  </h2>
                  <span className="text-xs text-slate-500">
                    <span className="font-bold text-navy-800">{Number(data.my_discoveries || 0)}</span> records recorded by you · <span className="text-brand-600 font-semibold hover:underline">View all →</span>
                  </span>
                </div>
              </Card>
            </Link>
          )}
          {/* My workflow — the lodgement pipeline as a 5-step stepper */}
          <Card title="My workflow"
            right={<span className="text-[11px] text-slate-400 font-normal">Assigned → Processing → Payment verification → Correction → Completed</span>}>
            <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-2.5">
              {acctSteps.map((s, i) => (
                <Link key={s.key} to={s.route}
                  className="group rounded-xl border border-slate-200 bg-white px-3 py-2.5 hover:border-brand-300 hover:shadow-sm transition">
                  <div className="flex items-center gap-2 text-[10px] uppercase tracking-wide text-slate-400">
                    <span className={`w-5 h-5 rounded-full grid place-items-center text-[10px] font-bold text-white ${s.tone === 'green' ? 'bg-emerald-600' : s.tone === 'amber' ? 'bg-amber-500' : s.tone === 'red' ? 'bg-red-500' : 'bg-brand-500'}`}>
                      {i + 1}
                    </span>
                    <span className="truncate">{s.label}</span>
                  </div>
                  <div className={`text-lg font-bold mt-1.5 ${queTone(s.tone)}`}>{Number(s.value).toLocaleString()}</div>
                  <div className="text-[10px] text-brand-500 font-semibold mt-1 opacity-0 group-hover:opacity-100 transition">Open →</div>
                </Link>
              ))}
            </div>
          </Card>

          {/* Money tiles + verification / data-quality panel — financial concepts
              kept strictly separate so figures are never mixed or implied equal */}
          <div className="grid lg:grid-cols-3 gap-4">
            <Card title="Financial summary" className="lg:col-span-2"
              right={<span className="text-[11px] text-slate-400 font-normal">Bills you lodged in this scope</span>}>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div className="rounded-xl border border-slate-200 bg-navy-50/40 px-3 py-2.5">
                  <div className="text-[10px] uppercase tracking-wide text-slate-500">Total tax value</div>
                  <div className="text-lg font-bold mt-0.5 text-navy-800">{fmtMoney(mon('total_tax_value'))}</div>
                  <div className="text-[10px] text-slate-400 mt-1">Assessments lodged in scope</div>
                </div>
                <div className="rounded-xl border border-slate-200 bg-emerald-50/60 px-3 py-2.5">
                  <div className="text-[10px] uppercase tracking-wide text-slate-500">Paid / verified</div>
                  <div className="text-lg font-bold mt-0.5 text-emerald-700">{fmtMoney(mon('verified_value'))}</div>
                  <div className="text-[10px] text-slate-400 mt-1">Confirmed taxpayer payments</div>
                </div>
                <div className="rounded-xl border border-slate-200 bg-red-50/60 px-3 py-2.5">
                  <div className="text-[10px] uppercase tracking-wide text-slate-500">Outstanding</div>
                  <div className="text-lg font-bold mt-0.5 text-red-600">{fmtMoney(mon('outstanding'))}</div>
                  <div className="text-[10px] text-slate-400 mt-1">Value still to be collected</div>
                </div>
              </div>
              <p className="text-[11px] text-slate-400 mt-3">Total tax value, paid/verified and outstanding are reported as separate, non-interchangeable figures.</p>
            </Card>

            <Card title="Payment verification & data quality">
              <div className="space-y-3">
                {(accVerificationLink)}
                {(accQualityLink)}
              </div>
            </Card>
          </div>

          {/* Charts — click to drill */}
          <div className="grid lg:grid-cols-5 gap-4">
            <Card title="Bills by case status" className="lg:col-span-3">
              <VBars data={data.bar.data} height={220}
                series={[{ key: 'value', label: data.bar.metric_label || 'Count', color: '#C70000' }]}
                onClick={(item) => applyDrill(data.bar.dimension, item)} />
            </Card>
            <Card title="Bills by payment status" className="lg:col-span-2">
              <Donut data={data.pie.data || []} size={170} thickness={28}
                center="Bills"
                onClick={(item) => applyDrill(data.pie.dimension, item)} />
            </Card>
          </div>

          {/* My register — the drill-down detail table */}
          <div ref={detailRef}>
            <Card title="My register"
              right={
                <div className="flex items-center gap-2">
                  {drill && (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand-50 text-brand-700 text-xs font-semibold">
                      {drill.label}
                      <button onClick={() => { setDrill(null); setPage(1) }} className="hover:text-brand-900 font-bold" aria-label="Clear drill">×</button>
                    </span>
                  )}
                  <select className="px-3 py-1.5 border border-slate-300 rounded-xl text-sm bg-white" value={String(perPage)}
                    onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1) }}>
                    <option value="10">10 rows</option>
                    <option value="25">25 rows</option>
                    <option value="50">50 rows</option>
                  </select>
                </div>
              }>
              <div className="-mx-5 overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                      {data.records.columns.map((c) => <th key={c} className="px-3 py-2 first:pl-5 whitespace-nowrap">{c}</th>)}
                      <th className="px-3 py-2 pr-5 text-right whitespace-nowrap">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.records.rows.length === 0 ? (
                      <tr><td colSpan={data.records.columns.length + 1} className="px-5 py-8 text-center text-slate-400">No records in scope for these filters.</td></tr>
                    ) : data.records.rows.map((row, i) => (
                      <tr key={i} className="border-b border-slate-50 hover:bg-slate-50/60">
                        {row.cells.map((cell, j) => (
                          <td key={j} className="px-3 py-2.5 first:pl-5 whitespace-nowrap text-slate-600">
                            {j === 0 ? <span className="font-semibold text-navy-800">{cell}</span> : cell}
                          </td>
                        ))}
                        <td className="px-3 py-2.5 pr-5 text-right whitespace-nowrap">
                          <Btn tone="white" className="!px-3 !py-1.5" onClick={() => setDrawer({ billId: row.id, taskId: row.task_id })}>View →</Btn>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {data.records.total > 0 && (
                <div className="flex items-center justify-between mt-4 text-sm">
                  <span className="text-slate-500">
                    Showing {(data.records.current_page - 1) * data.records.per_page + 1}–{Math.min(data.records.current_page * data.records.per_page, data.records.total)} of {data.records.total}
                  </span>
                  <div className="flex gap-2">
                    <Btn tone="white" disabled={data.records.current_page <= 1} onClick={() => setPage((p) => p - 1)}>Prev</Btn>
                    <span className="self-center text-xs text-slate-400">Page {data.records.current_page} / {data.records.last_page}</span>
                    <Btn tone="white" disabled={data.records.current_page >= data.records.last_page} onClick={() => setPage((p) => p + 1)}>Next</Btn>
                  </div>
                </div>
              )}
            </Card>
          </div>

          {drawer && <BillDrawer billId={drawer.billId} taskId={drawer.taskId} onClose={() => setDrawer(null)} />}
        </>
      )}
    </div>
  )
}