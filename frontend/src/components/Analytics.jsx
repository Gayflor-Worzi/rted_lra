import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api, { unwrap, errMsg } from '../api'
import { Card, Stat, Spinner, ErrorBox, Btn, Input, fmtMoney } from '../ui'
import { useAuth } from '../auth'
import { VBars, Donut } from '../charts'

const RANGES = [
  { v: 'today', l: 'Today' },
  { v: 'week', l: 'Week' },
  { v: 'month', l: 'Month' },
  { v: 'quarter', l: 'Quarter' },
  { v: 'year', l: 'Year' },
  { v: 'custom', l: 'Custom…' },
]

// Mirrors the permission sets the backend checks per metric (AnalyticsController::METRICS).
const METRIC_PERMS = {
  tasks: ['tasks.view_division', 'tasks.view_section', 'tasks.view_own'],
  bills: ['bills.view'],
  collections: ['reports.view', 'bills.view'],
  payments: ['payments.view_queue', 'payments.view_history'],
  discoveries: ['discovery.view', 'discovery.create'],
  valuations: ['valuation.view_history', 'valuation.review', 'valuation.create'],
  visits: ['enforcement.view_assignments', 'enforcement.record_visit'],
  targets: ['targets.view'],
}
const METRIC_ORDER = ['tasks', 'bills', 'collections', 'payments', 'discoveries', 'valuations', 'visits', 'targets']

function Sel({ value, onChange, options = [], placeholder, className = '' }) {
  return (
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className={`px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 ${className}`}>
      {placeholder && <option value="">{placeholder}</option>}
      {options.map((o) => (
        <option key={o.v} value={o.v}>{o.l}</option>
      ))}
    </select>
  )
}

/**
 * Shared dashboard analytics — filter bar, KPI band, two interactive bar charts
 * and a drill-down detail table. Charts are modeless: clicking a bar narrows the
 * detail table below. Backed by /dashboard/analytics.
 */
export default function Analytics({ heading = 'Dashboard analytics' }) {
  const { can } = useAuth()
  const initialMetric = METRIC_ORDER.find((m) => METRIC_PERMS[m].some((p) => can(p))) || 'tasks'

  const [metric, setMetric] = useState(initialMetric)
  const [groupBy, setGroupBy] = useState('')
  const [pie, setPie] = useState('')
  const [range, setRange] = useState('month')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [staffId, setStaffId] = useState('')
  const [section, setSection] = useState('')
  const [propertyId, setPropertyId] = useState('')
  const [tin, setTin] = useState('')
  const [docNum, setDocNum] = useState('')
  const [classification, setClassification] = useState('')
  const [propertyType, setPropertyType] = useState('')
  const [taxPeriod, setTaxPeriod] = useState('')
  const [deliveryStatus, setDeliveryStatus] = useState('')
  const [paymentStatus, setPaymentStatus] = useState('')
  const [drill, setDrill] = useState(null)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(10)
  const [dl, setDl] = useState(null)

  const [data, setData] = useState(null)
  const [err, setErr] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let alive = true
    setLoading(true)
    const params = { metric, range }
    if (groupBy) params.group_by = groupBy
    if (pie) params.pie = pie
    if (range === 'custom') { params.from = from || undefined; params.to = to || undefined }
    if (staffId) params.staff_id = staffId
    if (section) params.section = section
    if (propertyId) params.property_id = propertyId
    if (tin) params.tin = tin
    if (docNum) params.document_number = docNum
    if (classification) params.property_classification = classification
    if (propertyType) params.property_type = propertyType
    if (taxPeriod) params.tax_period = taxPeriod
    if (deliveryStatus) params.delivery_status = deliveryStatus
    if (paymentStatus) params.payment_status = paymentStatus
    if (drill) { params.record_dim = drill.dim; params.record_value = drill.value }
    params.page = page
    params.per_page = perPage

    api.get('/dashboard/analytics', { params })
      .then((r) => {
        if (!alive) return
        const body = unwrap(r).data
        setData(body)
        setErr(null)
      })
      .catch((e) => { if (alive) setErr(e) })
      .finally(() => { if (alive) setLoading(false) })

    return () => { alive = false }
  }, [metric, groupBy, pie, range, from, to, staffId, section, propertyId, tin, docNum, classification, propertyType, taxPeriod, deliveryStatus, paymentStatus, drill, page, perPage])

  const resetDrill = () => { setDrill(null); setPage(1) }

  const changeMetric = (m) => {
    setMetric(m)
    setGroupBy('')
    setPie('')
    resetDrill()
  }

  const changeFilter = (fn) => (v) => { fn(v); resetDrill() }

  const applyDrill = (dim, item) => {
    let value = item.label
    if (dim === 'staff' && item.id != null) value = item.id
    const label = ((data?.meta?.group_options || {})[dim] || (data?.meta?.pie_options || {})[dim]) || dim
    setDrill({ dim, value, label: `${label}: ${item.label}` })
    setPage(1)
  }

  // Export the exact dataset currently on screen (same filters, range and RBAC
  // scope) as CSV or PDF. Drives Req #16: the exported report carries the applied
  // filter, generation time, generating user, KPI summary, detail records and page
  // numbering, and always matches the on-screen figures.
  const exportParams = () => {
    const params = { metric, format: 'csv', range }
    if (groupBy) params.group_by = groupBy
    if (pie) params.pie = pie
    if (range === 'custom') { params.from = from || undefined; params.to = to || undefined }
    if (staffId) params.staff_id = staffId
    if (section) params.section = section
    if (propertyId) params.property_id = propertyId
    if (tin) params.tin = tin
    if (docNum) params.document_number = docNum
    if (classification) params.property_classification = classification
    if (propertyType) params.property_type = propertyType
    if (taxPeriod) params.tax_period = taxPeriod
    if (deliveryStatus) params.delivery_status = deliveryStatus
    if (paymentStatus) params.payment_status = paymentStatus
    if (drill) { params.record_dim = drill.dim; params.record_value = drill.value }
    return params
  }

  const download = async (format) => {
    setErr(''); setDl(format)
    try {
      const r = await api.get('/dashboard/analytics/export', { params: { ...exportParams(), format }, responseType: 'blob' })
      const blob = r.data
      const cd = (r.headers['content-disposition'] || '').match(/filename="?([^"]+)"?/)
      const name = cd ? cd[1] : `retd_dashboard_${metric}.${format}`
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = name
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } catch (ex) { setErr(errMsg(ex, 'Export failed.')) }
    setDl(null)
  }

  if (err && !data) return <ErrorBox error={errMsg(err, 'Could not load analytics.')} />

  const meta = data?.meta
  const effectiveGroup = groupBy || meta?.group_by || 'staff'
  const effectivePie = pie || meta?.pie || ''

  return (
    <div className="space-y-6">
      {meta && (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex items-center gap-3">
            <div className="text-xs font-bold uppercase tracking-wide text-slate-500">{heading}</div>
            <div className="text-xs text-slate-400">As of {new Date(meta.as_of).toLocaleString()}</div>
          </div>
          <div className="flex items-center gap-2">
            <Btn tone="white" onClick={() => download('csv')} disabled={dl === 'csv' || loading}>
              {dl === 'csv' ? 'Downloading.' : 'CSV'}
            </Btn>
            <Btn tone="navy" onClick={() => download('pdf')} disabled={dl === 'pdf' || loading}>
              {dl === 'pdf' ? 'Downloading.' : 'PDF'}
            </Btn>
          </div>
        </div>
      )}
      {err && <ErrorBox error={errMsg(err, 'Could not export.')} />}

      {/* Filter bar */}
      <Card>
        <div className="grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-10 gap-3">
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">Metric</span>
            <Sel className="w-full" value={metric} onChange={changeMetric}
              options={Object.entries(meta?.available_metrics || {}).map(([v, l]) => ({ v, l }))} />
          </label>
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">Period</span>
            <Sel className="w-full" value={range} onChange={changeFilter(setRange)} options={RANGES} />
          </label>
          {range === 'custom' && (
            <>
              <label className="text-xs text-slate-400 font-semibold">
                <span className="block mb-1">From</span>
                <Input className="w-full" type="date" value={from} onChange={(e) => changeFilter(setFrom)(e.target.value)} />
              </label>
              <label className="text-xs text-slate-400 font-semibold">
                <span className="block mb-1">To</span>
                <Input className="w-full" type="date" value={to} onChange={(e) => changeFilter(setTo)(e.target.value)} />
              </label>
            </>
          )}
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">Staff</span>
            <Sel className="w-full" value={staffId} onChange={changeFilter(setStaffId)}
              placeholder="All staff"
              options={(meta?.staff_options || []).map((s) => ({ v: String(s.id), l: s.name }))} />
          </label>
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">Section</span>
            <Sel className="w-full" value={section} onChange={changeFilter(setSection)}
              placeholder="All sections"
              options={(meta?.section_options || []).map((s) => ({ v: String(s.name), l: String(s.name) }))} />
          </label>
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">Property ID</span>
            <Input className="w-full" value={propertyId} placeholder="e.g. P-1001"
              onChange={(e) => changeFilter(setPropertyId)(e.target.value)} />
          </label>
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">TIN</span>
            <Input className="w-full" value={tin} placeholder="TIN #"
              onChange={(e) => changeFilter(setTin)(e.target.value)} />
          </label>
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">Document #</span>
            <Input className="w-full" value={docNum} placeholder="Doc #"
              onChange={(e) => changeFilter(setDocNum)(e.target.value)} />
          </label>
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">Classification</span>
            <Input className="w-full" value={classification} placeholder="e.g. Residential"
              onChange={(e) => changeFilter(setClassification)(e.target.value)} />
          </label>
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">Property type</span>
            <Input className="w-full" value={propertyType} placeholder="e.g. Commercial"
              onChange={(e) => changeFilter(setPropertyType)(e.target.value)} />
          </label>
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">Tax period</span>
            <Input className="w-full" value={taxPeriod} placeholder="e.g. 2026"
              onChange={(e) => changeFilter(setTaxPeriod)(e.target.value)} />
          </label>
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">Delivery status</span>
            <Input className="w-full" value={deliveryStatus} placeholder="Delivered / Pending"
              onChange={(e) => changeFilter(setDeliveryStatus)(e.target.value)} />
          </label>
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">Payment status</span>
            <Input className="w-full" value={paymentStatus} placeholder="Paid / Unpaid"
              onChange={(e) => changeFilter(setPaymentStatus)(e.target.value)} />
          </label>
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">Bar group</span>
            <Sel className="w-full" value={effectiveGroup} onChange={(v) => { setGroupBy(v); resetDrill() }}
              options={Object.entries(meta?.group_options || {}).map(([k, l]) => ({ v: k, l }))} />
          </label>
          <label className="text-xs text-slate-400 font-semibold">
            <span className="block mb-1">Pie</span>
            <Sel className="w-full" value={effectivePie} onChange={(v) => { setPie(v); resetDrill() }}
              options={Object.entries(meta?.pie_options || {}).map(([k, l]) => ({ v: k, l }))} />
          </label>
        </div>
        <div className="text-[11px] text-slate-400 mt-3">
          {loading ? 'Refreshing…' : 'Click any bar or pie slice to filter the detail table below.'}
        </div>
      </Card>

      {!data ? (
        <Spinner label="Loading analytics…" />
      ) : (
        <>
          {/* KPI band — each card links to the underlying register for provenance
              (Req #21: KPI = query result = the records you can drill into). */}
          <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
            {data.kpis.map((k) => (
              <Stat key={k.key} label={k.label} tone={k.tone}
                value={k.money ? fmtMoney(k.value) : Number(k.value).toLocaleString()} />
            ))}
          </div>
          {meta.drill && (
            <div className="flex items-center justify-between text-xs text-slate-400 -mt-3">
              <span>Every figure above is computed live from the records in the register.</span>
              <Link to={meta.drill} className="font-semibold text-brand-500 hover:underline">View source records →</Link>
            </div>
          )}

          {/* Bar + distribution pie (two interactive charts; stack on mobile) */}
          <div className="grid lg:grid-cols-5 gap-4">
            <Card title={meta.group_options[effectiveGroup]} className="lg:col-span-3"
              right={meta.drill ? <Link to={meta.drill} className="text-sm text-brand-500 font-semibold">Open register →</Link> : null}>
              {data.bar.shape === 'grouped' ? (
                <VBars data={data.bar.data} series={data.bar.series} height={240} onClick={(item) => applyDrill(effectiveGroup, item)} />
              ) : (
                <VBars data={data.bar.data} height={240}
                  series={[{ key: 'value', label: data.bar.metric_label || 'Count', color: '#002060' }]}
                  onClick={(item) => applyDrill(effectiveGroup, item)} />
              )}
            </Card>
            <Card title={meta.pie_options[effectivePie] || effectivePie} className="lg:col-span-2">
              <Donut data={data.pie.data || []} size={170} thickness={28}
                center={meta.pie_options[effectivePie] || 'Breakdown'}
                onClick={(item) => applyDrill(effectivePie, item)} />
            </Card>
          </div>

          {/* Drill-down detail table */}
          <Card title={`${meta.label} detail`}
            right={
              <div className="flex items-center gap-2">
                {drill && (
                  <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand-50 text-brand-700 text-xs font-semibold">
                    {drill.label}
                    <button onClick={resetDrill} className="hover:text-brand-900 font-bold" aria-label="Clear drill">×</button>
                  </span>
                )}
                <Sel value={String(perPage)} onChange={(v) => { setPerPage(Number(v)); setPage(1) }}
                  options={[{ v: '10', l: '10 rows' }, { v: '25', l: '25 rows' }, { v: '50', l: '50 rows' }]} />
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