import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api, { unwrap, errMsg } from '../api'
import { Card, Stat, Spinner, ErrorBox, Btn, Input, fmtMoney } from '../ui'
import { useAuth } from '../auth'
import { VBars, Donut } from '../charts'

/**
 * Role-specific operational dashboards for Account & Records Officers and
 * Enforcement Officers, served by /dashboard/officer. Every KPI, queue, chart
 * and table is computed live from authorization-scoped property_bills records
 * with cascading Year → Quarter → Month → Status filters; charts drill straight
 * into the detail table (modeless), and CSV/PDF exports carry the same applied
 * filters. All other roles keep the Analytics dashboard.
 */
export default function OfficerDashboard() {
  const { user, can } = useAuth()

  const [year, setYear] = useState('')
  const [quarter, setQuarter] = useState('')
  const [month, setMonth] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [status, setStatus] = useState('')
  const [paymentStatus, setPaymentStatus] = useState('')
  const [drill, setDrill] = useState(null)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(10)
  const [dl, setDl] = useState(null)
  const [disc, setDisc] = useState(null)

  const [data, setData] = useState(null)
  const [err, setErr] = useState(null)
  const [loading, setLoading] = useState(true)

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
  }, [year, quarter, month, from, to, status, paymentStatus, drill, page, perPage])

  const applyDrill = (dim, item) => {
    const value = item.label ?? item.value
    setDrill({ dim, value, label: `${dim === 'payment_status' ? 'Payment status' : data?.meta?.label}: ${value}` })
    setPage(1)
  }

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
  const title = type === 'enforcement' ? 'Enforcement Officer Dashboard'
    : type === 'account' ? 'Account & Records Officer Dashboard'
    : 'Officer Dashboard'

  // Discovery pipeline (the enforcement section's live register) folded into
  // the landing so the single dashboard carries both designs.
  useEffect(() => {
    if (type !== 'enforcement') { setDisc(null); return }
    let alive = true
    api.get('/discoveries/stats')
      .then((r) => { if (alive) setDisc(unwrap(r).data) })
      .catch(() => { if (alive) setDisc(null) })
    return () => { alive = false }
  }, [type])

  const filters = data?.meta?.filters
  const queTone = (t) => ({ navy: 'text-navy-500', blue: 'text-brand-500', green: 'text-emerald-600', red: 'text-red-600', amber: 'text-amber-600', brand: 'text-brand-600' }[t] || 'text-navy-500')

  // One merged "position & queues" block: every queue becomes a register link,
  // money KPIs (Collected value / Outstanding / Total tax value …) and any
  // status KPI without a queue twin (Completed / Bills processed) stay as
  // read-only tiles so no count is shown twice.
  const queueTwin = type === 'enforcement'
    ? { my_assigned: 1, field_delivery: 1, payment_followups: 1, notices: 1, escalated: 1 }
    : { bills_lodged: 1, bills_pending: 1 }
  const mergedTiles = [
    ...(data?.queues || []).map((q) => ({ ...q, link: true, money: false })),
    ...(data?.kpis || []).filter((k) => k.money || !queueTwin[k.key]).map((k) => ({ ...k, link: false })),
  ]

  return (
    <div className="space-y-6">
      {data && (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex items-center gap-3">
            <div className="text-xs font-bold uppercase tracking-wide text-slate-500">{title}</div>
            <div className="text-xs text-slate-400">
              Period {data.meta.range.start} → {data.meta.range.end} · as of {new Date(data.meta.as_of).toLocaleString()}
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Btn tone="white" onClick={() => download('csv')} disabled={dl === 'csv' || loading}>{dl === 'csv' ? 'Downloading.' : 'CSV'}</Btn>
            <Btn tone="navy" onClick={() => download('pdf')} disabled={dl === 'pdf' || loading}>{dl === 'pdf' ? 'Downloading.' : 'PDF'}</Btn>
          </div>
        </div>
      )}
      {err && !data && <ErrorBox error={errMsg(err, 'Unable to load dashboard data.')} />}
      {err && data && <div className="mb-3"><ErrorBox error={errMsg(err, 'Could not export.')} /></div>}

      {/* Cascading filter bar */}
      <Card>
        <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-7 gap-3">
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
            <span className="block mb-1">{type === 'enforcement' ? 'Stage' : 'Bill status'}</span>
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
        </div>
        <div className="text-[11px] text-slate-400 mt-3">
          {loading ? 'Refreshing…' : 'Click any bar or pie slice to filter the detail table below.'}
        </div>
      </Card>

      {!data ? (
        <Spinner label="Loading dashboard…" />
      ) : (
        <>
          {/* Task register snapshot — merged from the enforcement section's
              Overview so the landing shows the officer's live task register. */}
          {type === 'enforcement' && data.my_tasks && (
            <Card title="Task register snapshot"
              right={<span className="text-[11px] text-slate-400 font-normal">Mirrors the reg checked in My Tasks</span>}>
              <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
                <Stat label="My tasks" value={Number(data.my_tasks.total || 0).toLocaleString()} tone="navy" icon="📋" />
                <Stat label="Active" value={Number(data.my_tasks.active || 0).toLocaleString()} tone="blue" icon="🔄" />
                <Stat label="Overdue" value={Number(data.my_tasks.overdue || 0).toLocaleString()} tone="amber" icon="⏰" />
                <Stat label="Due today" value={Number(data.my_tasks.due_today || 0).toLocaleString()} tone="amber" icon="🗓️" />
                <Stat label="Escalated" value={Number(data.my_tasks.escalated || 0).toLocaleString()} tone="red" icon="🚨" />
                <Stat label="Completed" value={Number(data.my_tasks.completed || 0).toLocaleString()} tone="green" icon="✅" />
              </div>
            </Card>
          )}

          {/* Discovery register (the enforcement section's other live data). */}
          {type === 'enforcement' && disc && (
            <>
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
                          {disc[k] ?? 0}
                        </div>
                        <div className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">{label}</div>
                      </div>
                    )
                  })}
                </div>
              </Card>
            </>
          )}

          {/* My position & work queues — merged KPI band + register portals:
              status cards are links into the register, money figures and the
              top-line counts without a portal are read-only tiles. */}
          <Card title={type === 'enforcement' ? 'My position & work queues' : 'My register & work queues'}
            right={<span className="text-[11px] text-slate-400 font-normal">Each status card opens the register where those records live; money figures are totals in this scope</span>}>
            <div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-3">
              {mergedTiles.map((t) => t.link ? (
                <Link key={t.key} to={t.route}
                  className="group rounded-xl border border-slate-200 bg-white px-3 py-2.5 hover:border-brand-300 hover:shadow-sm transition">
                  <div className="text-[10px] uppercase tracking-wide text-slate-400 truncate">{t.label}</div>
                  <div className={`text-lg font-bold mt-0.5 ${queTone(t.tone)}`}>{Number(t.value).toLocaleString()}</div>
                  <div className="text-[10px] text-brand-500 font-semibold mt-1 opacity-0 group-hover:opacity-100 transition">Open register →</div>
                </Link>
              ) : (
                <div key={t.key} className="rounded-xl border border-slate-200 bg-white px-3 py-2.5">
                  <div className="text-[10px] uppercase tracking-wide text-slate-400 truncate">{t.label}</div>
                  <div className={`text-lg font-bold mt-0.5 ${queTone(t.tone)}`}>{t.money ? fmtMoney(t.value) : Number(t.value).toLocaleString()}</div>
                  <div className="text-[10px] text-slate-300 font-semibold mt-1">Scope total</div>
                </div>
              ))}
            </div>
          </Card>

          {/* Charts — click to drill */}
          <div className="grid lg:grid-cols-5 gap-4">
            <Card title={(type === 'enforcement' ? 'Bills by enforcement stage' : 'Bills by case status')} className="lg:col-span-3">
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

          {/* Drill-down detail table */}
          <Card title={`${title} detail`}
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
                    {data.records.columns.map((c) => <th key={c} className="px-3 py-2 first:pl-5 last:pr-5 whitespace-nowrap">{c}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {data.records.rows.length === 0 ? (
                    <tr><td colSpan={data.records.columns.length} className="px-5 py-8 text-center text-slate-400">No records in scope for these filters.</td></tr>
                  ) : data.records.rows.map((row, i) => (
                    <tr key={i} className="border-b border-slate-50 hover:bg-slate-50/60">
                      {row.map((cell, j) => (
                        <td key={j} className="px-3 py-2.5 first:pl-5 last:pr-5 whitespace-nowrap text-slate-600">
                          {j === 0 ? <span className="font-semibold text-navy-800">{cell}</span> : cell}
                        </td>
                      ))}
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
        </>
      )}
    </div>
  )
}