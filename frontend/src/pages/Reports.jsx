import { useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import { Card, Stat, Badge, Spinner, ErrorBox, Btn, Input, PageTitle, fmtDate, fmtMoney, fmtTime } from '../ui'
import { CASE_STATUSES, PAYMENT_STATUSES, TASK_STATUSES } from '../lib/constants'

const KINDS = [
  { id: 'bills', label: 'Property Bills', icon: '🧾' },
  { id: 'collections', label: 'Collections', icon: '💰' },
  { id: 'enforcement', label: 'Enforcement', icon: '👮' },
  { id: 'valuations', label: 'Valuations', icon: '🏢' },
  { id: 'payment-queue', label: 'Payment Queue', icon: '🔁' },
]

// Extra filters beyond the date range — applied to the on-screen list AND to
// CSV/PDF exports. `options` renders a select of fixed values, `staff` a
// select of users, otherwise a free-text input.
const EXTRA = {
  bills: [
    { param: 'case_status', label: 'Status', options: CASE_STATUSES },
    { param: 'payment_status', label: 'Payment status', options: PAYMENT_STATUSES },
    { param: 'recipient_type', label: 'Recipient type' },
    { param: 'logged_by', label: 'Logged by', staff: true },
    { param: 'assigned_to', label: 'Assigned to', staff: true },
  ],
  collections: [
    { param: 'verified_by', label: 'Verified by', staff: true },
  ],
  enforcement: [
    { param: 'status', label: 'Status', options: TASK_STATUSES },
    { param: 'assigned_to', label: 'Assigned to', staff: true },
  ],
  valuations: [
    { param: 'status', label: 'Status' },
    { param: 'valuation_officer', label: 'Officer', staff: true },
  ],
  'payment-queue': [
    { param: 'verification_status', label: 'Verification', options: ['Pending', 'Confirmed', 'Rejected'] },
    { param: 'match_status', label: 'Match', options: ['Pending', 'Match'] },
  ],
}

const QUICK = [
  { id: '', label: 'All time' },
  { id: 'today', label: 'Today' },
  { id: 'this_week', label: 'This week' },
  { id: 'this_month', label: 'This month' },
  { id: 'quarter', label: 'Quarter' },
  { id: 'yearly', label: 'Year' },
]

const TONE = { Approved: 'green', Successful: 'green', Paid: 'green', Closed: 'green', 'Fully Verified': 'green', 'Match Found': 'green' }

export default function Reports() {
  const [kind, setKind] = useState('bills')
  const [res, setRes] = useState(null)
  const [err, setErr] = useState('')
  const [quick, setQuick] = useState('')
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [filters, setF] = useState({})
  const [users, setUsers] = useState([])
  const [dl, setDl] = useState(null)

  const setFilter = (key, value) => setF((f) => ({ ...f, [key]: value }))
  const resetAll = () => { setQuick(''); setStartDate(''); setEndDate(''); setF({}) }

  useEffect(() => {
    api.get('/users', { params: { per_page: 200 } }).then((r) => setUsers(unwrap(r).data.data)).catch(() => {})
  }, [])

  const params = () => {
    const p = {}
    if (quick) p.filter = quick
    else if (startDate || endDate) {
      if (startDate) p.start_date = startDate
      if (endDate) p.end_date = endDate
    }
    Object.entries(filters).forEach(([k, v]) => { if (v !== '' && v != null) p[k] = v })
    return p
  }

  const load = () => {
    setErr('')
    api.get(`/reports/${kind}`, { params: params() })
      .then((r) => setRes(unwrap(r).data))
      .catch((ex) => setErr(errMsg(ex)))
  }
  useEffect(() => { load() }, [kind, quick, startDate, endDate, filters]) // eslint-disable-line react-hooks/exhaustive-deps

  const download = async (format) => {
    setErr(''); setDl(format)
    try {
      const r = await api.get(`/reports/${kind}/export`, { params: { ...params(), format }, responseType: 'blob' })
      const blob = r.data
      const cd = (r.headers['content-disposition'] || '').match(/filename="?([^"]+)"?/)
      const name = cd ? cd[1] : `retd_${kind}.${format}`
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

  const rows = res?.rows?.data || []
  const meta = { cols: COLS[kind] }

  return (
    <div className="space-y-4">
      <PageTitle sub="Standard registers — filter by date and export to CSV or PDF.">Reports</PageTitle>
      <ErrorBox error={err} />

      {/* Kind tabs */}
      <div className="flex flex-wrap gap-2">
        {KINDS.map((k) => (
          <button key={k.id} onClick={() => { setKind(k.id); setRes(null); setF({}) }}
            className={`px-4 py-2 rounded-xl text-sm font-semibold transition ${kind === k.id ? 'bg-navy-800 text-white shadow' : 'bg-white text-slate-600 border border-slate-200 hover:border-slate-300'}`}>
            {k.icon} {k.label}
          </button>
        ))}
      </div>

      {/* Summary cards */}
      {res?.summary && (
        <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <Stat label="Total tasks" value={res.summary.total} />
          <Stat label="Assigned" value={res.summary.assigned} />
          <Stat label="Delivered / resolved" value={res.summary.delivered} />
          <Stat label="Escalated" value={res.summary.escalated} />
        </div>
      )}
      {res?.totals && (
        <div className="grid sm:grid-cols-2 gap-3">
          <Stat label="Verified collections" value={fmtMoney(res.totals.verified_amount)} />
          <Stat label="Verifications" value={res.totals.count} />
        </div>
      )}

      {/* Filters + export */}
      <Card title="Date filter & export">
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex flex-wrap gap-1.5">
            {QUICK.map((q) => (
              <button key={q.id} onClick={() => { setQuick(q.id); setStartDate(''); setEndDate('') }}
                className={`px-3 py-1.5 rounded-full text-xs font-semibold transition ${quick === q.id ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>
                {q.label}
              </button>
            ))}
          </div>
          <div className="flex items-center gap-2 ml-auto">
            <div className="flex items-center gap-1.5 text-xs text-slate-500 font-semibold">
              From <Input type="date" className="w-auto" value={startDate} onChange={(e) => { setStartDate(e.target.value); setQuick('') }} />
              To <Input type="date" className="w-auto" value={endDate} onChange={(e) => { setEndDate(e.target.value); setQuick('') }} />
            </div>
          </div>
        </div>
        <div className="flex flex-wrap items-end gap-3 mt-3 pt-3 border-t border-slate-100">
          {EXTRA[kind].map((f) => (
            <div key={f.param}>
              <label className="block text-xs font-semibold text-slate-600 mb-1">{f.label}</label>
              {f.staff ? (
                <select value={filters[f.param] || ''} onChange={(e) => setFilter(f.param, e.target.value)}
                  className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white min-w-[180px]">
                  <option value="">All {f.label}</option>
                  {users.map((u) => <option key={u.id} value={u.id}>{u.full_name}</option>)}
                </select>
              ) : f.options ? (
                <select value={filters[f.param] || ''} onChange={(e) => setFilter(f.param, e.target.value)}
                  className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white min-w-[180px]">
                  <option value="">All {f.label}</option>
                  {f.options.map((o) => <option key={o} value={o}>{o}</option>)}
                </select>
              ) : (
                <Input placeholder={`Filter ${f.label}…`} value={filters[f.param] || ''}
                  onChange={(e) => setFilter(f.param, e.target.value)} />
              )}
            </div>
          ))}
          <button onClick={resetAll}
            className="px-3 py-2 rounded-xl text-sm font-semibold text-slate-500 border border-slate-200 hover:bg-slate-50">
            Reset
          </button>
        </div>
        <div className="flex items-center gap-2 mt-3 pt-3 border-t border-slate-100">
          <span className="text-sm font-semibold text-navy-800">{rows.length} record(s)</span>
          <div className="flex-1" />
          <Btn tone="navy" onClick={() => download('csv')} disabled={dl === 'csv'}>
            {dl === 'csv' ? 'Downloading…' : '⬇ CSV'}
          </Btn>
          <Btn onClick={() => download('pdf')} disabled={dl === 'pdf'}>
            {dl === 'pdf' ? 'Downloading…' : '⬇ PDF'}
          </Btn>
        </div>
      </Card>

      {/* Table */}
      {!res ? <Spinner label="Loading report…" /> : (
        <Card title={`${KINDS.find((k) => k.id === kind)?.label} register`}>
          <div className="-mx-5 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                  {meta.cols.map((c) => <th key={c.key} className={c.align === 'r' ? 'px-2 py-2 text-right' : 'px-2 py-2'}>{c.label}</th>)}
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 ? (
                  <tr><td colSpan={meta.cols.length} className="px-5 py-8 text-center text-sm text-slate-400">No records for this filter.</td></tr>
                ) : rows.map((row, i) => (
                  <tr key={row.id ?? i} className="border-b border-slate-50 hover:bg-slate-50/60">
                    {meta.cols.map((c) => {
                      const v = c.render ? c.render(row) : row[c.key]
                      const badged = c.badge && v
                      const val = c.money ? fmtMoney(v) : c.date ? fmtDate(v) : c.time ? fmtTime(v) : v
                      return (
                        <td key={c.key} className={c.align === 'r' ? 'px-2 py-2.5 text-right' : 'px-2 py-2.5'}>
                          {badged ? <Badge tone={TONE[v] || 'slate'}>{val}</Badge> : (<span className={c.money ? 'font-semibold' : ''}>{val ?? '—'}</span>)}
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  )
}

const COLS = {
  bills: [
    { key: 'property_id', label: 'Property ID' },
    { key: 'tin', label: 'TIN' },
    { key: 'document_number', label: 'Reference' },
    { key: 'taxpayer_name', label: 'Taxpayer' },
    { key: 'total_tax_due', label: 'Total due', align: 'r', money: true },
    { key: 'payment_status', label: 'Payment status', badge: true },
    { key: 'recipient_type', label: 'Recipient type' },
    { key: 'case_status', label: 'Status', badge: true },
    { key: 'date_logged', label: 'Log date', date: true },
  ],
  collections: [
    { key: 'property_id', label: 'Property ID' },
    { key: 'tin', label: 'TIN' },
    { key: 'document_number', label: 'Document #' },
    { key: 'amount', label: 'Amount', align: 'r', money: true },
    { key: 'payment_period', label: 'Period' },
    { key: 'receipt_number', label: 'Receipt #' },
    { key: 'verified_by', label: 'Verified by' },
    { key: 'verified_at', label: 'Verified at', time: true },
  ],
  enforcement: [
    { key: 'property_id', label: 'Property ID' },
    { key: 'tin', label: 'TIN' },
    { key: 'task_reference', label: 'Reference' },
    { key: 'task_type', label: 'Type' },
    { key: 'status', label: 'Status', badge: true },
    { key: 'assigned_to', label: 'Assigned to' },
    { key: 'due_date', label: 'Due', date: true },
    { key: 'created_at', label: 'Created', time: true },
  ],
  valuations: [
    { key: 'valuation_reference', label: 'Reference' },
    { key: 'property_id', label: 'Property' },
    { key: 'owner_name', label: 'Owner' },
    { key: 'status', label: 'Status', badge: true },
    { key: 'assessed_value', label: 'Assessed', align: 'r', money: true },
    { key: 'annual_tax', label: 'Annual tax', align: 'r', money: true },
    { key: 'valuation_officer', label: 'Officer' },
  ],
  'payment-queue': [
    { key: 'property_id', label: 'Property ID' },
    { key: 'tin', label: 'TIN' },
    { key: 'document_number', label: 'Document #' },
    { key: 'receipt_number', label: 'Receipt #' },
    { key: 'amount_claimed', label: 'Amount', align: 'r', money: true },
    { key: 'match_status', label: 'Match', badge: true },
    { key: 'verification_status', label: 'Verification', badge: true },
    { key: 'created_at', label: 'Created', time: true },
  ],
}