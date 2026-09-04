import { useEffect, useState } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import api, { unwrap, errMsg } from '../api'
import { Card, Badge, Spinner, ErrorBox, PageTitle, Btn, Input, fmtDate, fmtTime, fmtMoney } from '../ui'
import { discoveryStatusInfo, targetMetricLabel, TASK_STATUSES, CASE_STATUSES, DELIVERY_STATUSES, DISCOVERY_STATUSES, DISCOVERY_PATHS } from '../lib/constants'

const TABLE_META = {
  tasks: { title: 'Tasks', cols: ['task_reference', 'task_type', 'status', 'assigned_to', 'due_date', 'created_at'], filter: ['status', 'section'], options: { status: TASK_STATUSES, section: ['Enforcement', 'Valuation'] } },
  bills: { title: 'Bills', cols: ['tin', 'property_id', 'document_number', 'taxpayer_name', 'property_address', 'property_classification', 'property_type', 'tax_period', 'assessed_value', 'tax_amount', 'interest_charged', 'penalty_charged', 'total_tax_due', 'outstanding_balance', 'case_status', 'payment_status', 'account_staff', 'enforcement_officer', 'date_logged'], filter: ['case_status', 'recipient_type', 'logged_by', 'assigned_to'], options: { case_status: CASE_STATUSES, recipient_type: ['Walk-in', 'Section', 'Dispatch'] } },
  valuations: { title: 'Valuations', cols: ['valuation_reference', 'property_id', 'owner_name', 'status', 'assessed_value', 'annual_tax', 'valuation_officer', 'created_at'], filter: ['status'], options: { status: ['Draft', 'Submitted', 'Pending Review', 'Manager Approved', 'Approved', 'Rejected', 'Completed'] } },
  visits: { title: 'Visits', cols: ['id', 'visit_date', 'delivery_status', 'officer', 'remarks', 'created_at'], filter: ['delivery_status'], options: { delivery_status: DELIVERY_STATUSES } },
  discoveries: { title: 'Discoveries', cols: ['discovery_reference', 'owner_name', 'property_address', 'status', 'decision_path', 'discoverer', 'created_at'], filter: ['status', 'decision_path'], options: { status: DISCOVERY_STATUSES, decision_path: DISCOVERY_PATHS } },
  staff: { title: 'Staff', cols: ['staff_id', 'full_name', 'email', 'role', 'section', 'is_active'], filter: ['section', 'role'] },
  targets: { title: 'Targets', cols: ['id', 'user', 'metric', 'target_value', 'achieved_value', 'achievement_pct', 'status', 'period'], filter: ['status', 'metric', 'period'], options: { status: ['Active', 'Paused', 'Achieved', 'Missed', 'Archived'], period: ['2026'] } },
  payments: { title: 'Payment Verifications', cols: ['id', 'document_number', 'receipt_number', 'amount_claimed', 'match_status', 'verification_status', 'created_at'], filter: ['verification_status'], options: { verification_status: ['Pending', 'Verified', 'Rejected', 'Mismatch'] } },
  queries: { title: 'M&E Queries', cols: ['id', 'query_reference', 'title', 'status', 'raised_by', 'created_at'], filter: ['status'] },
  flags: { title: 'Data-quality Flags', cols: ['id', 'bill', 'issue', 'severity', 'status', 'created_at'], filter: ['status'], options: { status: ['Open', 'Resolved'] } },
}

// Display values for common drill columns (fall back to raw value).
const CELL = {
  status: (v) => <Badge tone={v ? statusTone(v) : 'slate'}>{v ?? '—'}</Badge>,
  case_status: (v) => <Badge tone={v ? statusTone(v) : 'slate'}>{v ?? '—'}</Badge>,
  payment_status: (v) => <Badge tone={v ? statusTone(v) : 'slate'}>{v ?? '—'}</Badge>,
  delivery_status: (v) => <Badge tone={v ? statusTone(v) : 'slate'}>{v ?? '—'}</Badge>,
  verification_status: (v) => <Badge tone={v ? statusTone(v) : 'slate'}>{v ?? '—'}</Badge>,
  severity: (v) => <Badge tone={v === 'High' ? 'red' : 'amber'}>{v ?? '—'}</Badge>,
  amount: (v) => <span className="font-semibold">{fmtMoney(v)}</span>,
  amount_claimed: (v) => <span className="font-semibold">{fmtMoney(v)}</span>,
  target_value: (v) => fmtMoney(v),
  achieved_value: (v) => fmtMoney(v),
  achievement_pct: (v) => <Badge tone={v >= 100 ? 'green' : v >= 60 ? 'amber' : 'red'}>{v}%</Badge>,
  assessed_value: (v) => fmtMoney(v),
  tax_amount: (v) => fmtMoney(v),
  interest_charged: (v) => fmtMoney(v),
  penalty_charged: (v) => fmtMoney(v),
  total_tax_due: (v) => <span className="font-semibold">{fmtMoney(v)}</span>,
  outstanding_balance: (v) => fmtMoney(v),
  annual_tax: (v) => fmtMoney(v),
  due_date: (v) => fmtDate(v),
  visit_date: (v) => fmtDate(v),
  date_logged: (v) => fmtDate(v),
  created_at: (v) => fmtTime(v),
  is_active: (v) => (v ? <Badge tone="green">Active</Badge> : <Badge tone="slate">Inactive</Badge>),
}

function statusTone(s) {
  const m = { Completed: 'green', Approved: 'green', Paid: 'green', Resolved: 'green', Delivered: 'blue', Submitted: 'amber', Escalated: 'red', Rejected: 'red', Overdue: 'red', Closed: 'slate' }
  return m[s] || 'navy'
}

// Render a raw drill cell as a string, collapsing any object values (e.g. a
// { id, full_name } person relation) to their display name rather than crashing.
function cellText(v) {
  if (v === null || v === undefined) return '—'
  if (typeof v === 'object') {
    if (Array.isArray(v)) return v.map(cellText).join(', ')
    return v.full_name ?? v.name ?? v.title ?? v.document_number ?? v.property_id ?? (v.id ? String(v.id) : '—')
  }
  return String(v)
}

export default function Drill() {
  const { table } = useParams()
  const [sp, setSp] = useSearchParams()
  const [rows, setRows] = useState(null)
  const [err, setErr] = useState(null)
  const [total, setTotal] = useState(0)
  const [users, setUsers] = useState([])

  const meta = TABLE_META[table]
  const page = Number(sp.get('page') || 1)

  useEffect(() => {
    api.get('/users', { params: { per_page: 200 } }).then((r) => setUsers(unwrap(r).data.data)).catch(() => {})
  }, [])

  useEffect(() => {
    if (!meta) return
    setRows(null)
    const params = Object.fromEntries(
      Array.from(sp.entries()).filter(([, v]) => v !== ''),
    )
    api.get(`/dashboard/drill`, { params: { table, per_page: 25, page, ...params } })
      .then((r) => {
        const d = unwrap(r).data
        setRows(d.data)
        setTotal(d.total ?? 0)
      })
      .catch((ex) => setErr(errMsg(ex)))
  }, [table, sp]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!meta) return <ErrorBox error={{ message: `Unknown drill table: ${table}` }} />
  if (err) return <ErrorBox error={err} />

  const applyFilter = (key, value) => {
    const next = new URLSearchParams(sp)
    if (value) next.set(key, value)
    else next.delete(key)
    next.delete('page')
    setSp(next)
  }

  const reset = () => setSp({})

  return (
    <div className="space-y-4">
      <PageTitle sub={`Drill through from the Dashboard · ${total} record(s)`}>
        {meta.title}
      </PageTitle>

      {/* Filters */}
      <Card title="Filters" right={<Btn tone="white" onClick={reset}>Clear filters</Btn>}>
        <div className="flex flex-wrap gap-3">
          {meta.filter.map((key) => (
            <div key={key}>
              <label className="block text-xs font-semibold text-slate-600 mb-1">{key.replace(/_/g, ' ')}</label>
              {['logged_by', 'assigned_to'].includes(key) && users.length ? (
                <select value={sp.get(key) || ''} onChange={(e) => applyFilter(key, e.target.value)}
                  className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white">
                  <option value="">All {key.replace(/_/g, ' ')}</option>
                  {users.map((u) => <option key={u.id} value={u.id}>{u.full_name}</option>)}
                </select>
              ) : meta.options?.[key]?.length ? (
                <select value={sp.get(key) || ''} onChange={(e) => applyFilter(key, e.target.value)}
                  className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white">
                  <option value="">All {key.replace(/_/g, ' ')}</option>
                  {meta.options[key].map((v) => <option key={v} value={v}>{v}</option>)}
                </select>
              ) : (
                <input
                  className="px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500"
                  placeholder={`Filter ${key.replace(/_/g, ' ')}…`}
                  value={sp.get(key) || ''}
                  onChange={(e) => applyFilter(key, e.target.value)}
                />
              )}
            </div>
          ))}
          <div>
            <label className="block text-xs font-semibold text-slate-600 mb-1">from</label>
            <Input type="date" value={sp.get('from') || ''} onChange={(e) => applyFilter('from', e.target.value)} />
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-600 mb-1">to</label>
            <Input type="date" value={sp.get('to') || ''} onChange={(e) => applyFilter('to', e.target.value)} />
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-600 mb-1"> <span className="invisible">.</span></label>
            <Btn tone="white" onClick={reset}>Reset</Btn>
          </div>
        </div>
      </Card>

      {!rows ? <Spinner label={`Loading ${meta.title.toLowerCase()}…`} /> : rows.length === 0 ? (
        <Card title="Results"><p className="text-sm text-slate-400">No records match.</p></Card>
      ) : (
        <Card title="Results">
          <div className="-mx-5 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                  {meta.cols.map((c) => <th key={c} className="px-5 py-2 first:pl-5">{c.replace(/_/g, ' ')}</th>)}
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id ?? String(row.full_name) ?? row.document_number} className="border-b border-slate-50 hover:bg-slate-50/60">
                    {meta.cols.map((c) => (
                      <td key={c} className="px-5 py-2.5 whitespace-nowrap">
                        {CELL[c] ? CELL[c](row[c], row) : (cellText(row[c]))}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {total > 25 && (
            <div className="flex items-center justify-between pt-4 text-sm text-slate-500">
              <button className="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-40" disabled={page <= 1}
                onClick={() => { const n = new URLSearchParams(sp); n.set('page', String(page - 1)); setSp(n) }}>
                ← Prev
              </button>
              <span>Page {page} of {Math.ceil(total / 25)}</span>
              <button className="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-40" disabled={page * 25 >= total}
                onClick={() => { const n = new URLSearchParams(sp); n.set('page', String(page + 1)); setSp(n) }}>
                Next →
              </button>
            </div>
          )}
        </Card>
      )}
    </div>
  )
}