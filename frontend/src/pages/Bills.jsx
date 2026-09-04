import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api, { unwrap } from '../api'
import { Spinner, ErrorBox, PageTitle, Badge, Card, Btn, Input, billCaseTone, fmtMoney, fmtDate } from '../ui'
import { useAuth } from '../auth'
import { CASE_STATUSES, PAYMENT_STATUSES } from '../lib/constants'
import BillCreate from './BillCreate'
import BillDrawer from '../components/BillDrawer'

function useDebounced(value, ms = 400) {
  const [v, setV] = useState(value)
  useEffect(() => {
    const id = setTimeout(() => setV(value), ms)
    return () => clearTimeout(id)
  }, [value, ms])
  return v
}

export default function Bills() {
  const { can } = useAuth()
  const [rows, setRows] = useState(null)
  const [err, setErr] = useState(null)
  const [q, setQ] = useState('')
  const debouncedQ = useDebounced(q)
  const [caseStatus, setCaseStatus] = useState('')
  const [paymentStatus, setPaymentStatus] = useState('')
  const [loggedBy, setLoggedBy] = useState('')
  const [assignedTo, setAssignedTo] = useState('')
  const [users, setUsers] = useState([])
  const [page, setPage] = useState(1)
  const [showCreate, setShowCreate] = useState(false)
  const [drawerId, setDrawerId] = useState(null)

  useEffect(() => {
    api.get('/users', { params: { per_page: 200 } }).then((r) => setUsers(unwrap(r).data.data)).catch(() => {})
  }, [])

  const load = () => {
    setErr(null)
    const params = { per_page: 20, page }
    if (debouncedQ) params.q = debouncedQ
    if (caseStatus) params.case_status = caseStatus
    if (paymentStatus) params.payment_status = paymentStatus
    if (loggedBy) params.logged_by = loggedBy
    if (assignedTo) params.assigned_to = assignedTo
    return api.get('/property-bills', { params }).then((r) => {
      setRows(unwrap(r).data)
    }).catch(setErr)
  }

  useEffect(() => { load().catch(() => {}) }, [debouncedQ, caseStatus, paymentStatus, loggedBy, assignedTo, page]) // eslint-disable-line

  if (err) return <ErrorBox error={err} />
  if (!rows) return <Spinner label="Loading bill register…" />

  return (
    <div className="space-y-4">
      <PageTitle
        sub="Bills logged and tracked by the RETD task engine."
        right={can('bills.create') && <Btn onClick={() => setShowCreate(true)}>+ Log a bill</Btn>}>
        Bill Register
      </PageTitle>

      {showCreate && (
        <BillCreate
          onClose={() => setShowCreate(false)}
          onCreated={() => { setShowCreate(false); setPage(1); load() }}
        />
      )}

      <Card>
        <div className="flex flex-wrap gap-3 mb-4">
          <Input placeholder="Search Document #, Property ID, TIN, taxpayer, address…"
            value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} className="w-full md:flex-1 md:min-w-[240px]" />
          <select value={caseStatus} onChange={(e) => { setCaseStatus(e.target.value); setPage(1) }}
            className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white">
            <option value="">All case statuses</option>
            {CASE_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
          <select value={paymentStatus} onChange={(e) => { setPaymentStatus(e.target.value); setPage(1) }}
            className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white">
            <option value="">All payment statuses</option>
            {PAYMENT_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
          {users.length > 0 && (
            <>
              <select value={loggedBy} onChange={(e) => { setLoggedBy(e.target.value); setPage(1) }}
                className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white">
                <option value="">All loggers</option>
                {users.map((u) => <option key={u.id} value={u.id}>{u.full_name}</option>)}
              </select>
              <select value={assignedTo} onChange={(e) => { setAssignedTo(e.target.value); setPage(1) }}
                className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white">
                <option value="">All assignments</option>
                {users.map((u) => <option key={u.id} value={u.id}>{u.full_name}</option>)}
              </select>
            </>
          )}
        </div>

        <div className="overflow-x-auto hidden md:block">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs uppercase tracking-wider text-slate-400 border-b border-slate-100">
                <th className="py-2 pr-3">TIN</th>
                <th className="py-2 pr-3">Property ID</th>
                <th className="py-2 pr-3">Document #</th>
                <th className="py-2 pr-3">Taxpayer</th>
                <th className="py-2 pr-3">Address</th>
                <th className="py-2 pr-3">Classification</th>
                <th className="py-2 pr-3">Type</th>
                <th className="py-2 pr-3">Period</th>
                <th className="py-2 pr-3">Assessed</th>
                <th className="py-2 pr-3">Tax</th>
                <th className="py-2 pr-3">Interest</th>
                <th className="py-2 pr-3">Penalty</th>
                <th className="py-2 pr-3">Total Due</th>
                <th className="py-2 pr-3">Outstanding</th>
                <th className="py-2 pr-3">Case</th>
                <th className="py-2 pr-3">Pay</th>
                <th className="py-2 pr-3">Logged by</th>
                <th className="py-2 pr-3">Assigned to</th>
                <th className="py-2 pr-3">Logged</th>
              </tr>
            </thead>
            <tbody>
              {rows.data.length === 0 && (
                <tr><td colSpan={19} className="py-10 text-center text-slate-400">No bills match this filter.</td></tr>
              )}
              {rows.data.map((b) => (
                <tr key={b.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                  <td className="py-2.5 pr-3 font-mono text-xs">{b.tin || '—'}</td>
                  <td className="py-2.5 pr-3 font-mono text-xs">{b.property_id}</td>
                  <td className="py-2.5 pr-3">
                    <span className="inline-flex items-center gap-1.5">
                      <Link to={`/bills/${b.id}`} className="font-medium text-brand-500 hover:underline">{b.document_number}</Link>
                      <button onClick={() => setDrawerId(b.id)} title="Open bill drawer" className="text-slate-300 hover:text-navy-700 transition">⤢</button>
                    </span>
                  </td>
                  <td className="py-2.5 pr-3">{b.taxpayer_name}</td>
                  <td className="py-2.5 pr-3 text-slate-500 max-w-[180px] truncate">{b.property_address}</td>
                  <td className="py-2.5 pr-3 text-slate-500">{b.property_classification || '—'}</td>
                  <td className="py-2.5 pr-3 text-slate-500">{b.property_type || '—'}</td>
                  <td className="py-2.5 pr-3 text-slate-500">{b.tax_period || '—'}</td>
                  <td className="py-2.5 pr-3">{fmtMoney(b.assessed_value)}</td>
                  <td className="py-2.5 pr-3">{fmtMoney(b.tax_amount)}</td>
                  <td className="py-2.5 pr-3">{fmtMoney(b.interest_charged)}</td>
                  <td className="py-2.5 pr-3">{fmtMoney(b.penalty_charged)}</td>
                  <td className="py-2.5 pr-3 font-semibold">{fmtMoney(b.total_tax_due)}</td>
                  <td className="py-2.5 pr-3">{fmtMoney(b.outstanding_balance)}</td>
                  <td className="py-2.5 pr-3"><Badge tone={billCaseTone(b.case_status)}>{b.case_status}</Badge></td>
                  <td className="py-2.5 pr-3"><Badge>{b.payment_status}</Badge></td>
                  <td className="py-2.5 pr-3 text-slate-500">{b.account_staff || '—'}</td>
                  <td className="py-2.5 pr-3 text-slate-500">{b.enforcement_officer || '—'}</td>
                  <td className="py-2.5 pr-3 text-slate-400">{fmtDate(b.date_logged)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Mobile / small-screen card list */}
        <div className="space-y-3 md:hidden">
          {rows.data.length === 0 && (
            <p className="py-6 text-center text-slate-400">No bills match this filter.</p>
          )}
          {rows.data.map((b) => (
            <div key={b.id} className="border border-slate-200 rounded-xl p-3 bg-white">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <div className="flex items-center gap-1.5">
                    <Link to={`/bills/${b.id}`} className="font-semibold text-brand-500 break-all">{b.document_number}</Link>
                    <button onClick={() => setDrawerId(b.id)} title="Open bill drawer" className="text-slate-300 hover:text-navy-700 transition">⤢</button>
                  </div>
                  <div className="text-xs text-slate-500 font-mono mt-0.5">TIN {b.tin || '—'} · {b.property_id}</div>
                </div>
                <div className="text-right shrink-0">
                  <div className="text-sm font-bold text-navy-800">{fmtMoney(b.total_tax_due)}</div>
                  <div className="text-[11px] text-slate-500">due</div>
                </div>
              </div>
              <div className="text-sm font-medium text-navy-800 truncate mt-1">{b.taxpayer_name}</div>
              <div className="text-xs text-slate-500 truncate">{b.property_address}</div>
              <div className="flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-slate-500 mt-1">
                {b.property_classification && <span>{b.property_classification}</span>}
                {b.property_type && <span>{b.property_type}</span>}
                {b.tax_period && <span>Period {b.tax_period}</span>}
              </div>
              <div className="flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] mt-1">
                <span>Tax {fmtMoney(b.tax_amount)}</span>
                {Number(b.interest_charged) > 0 && <span>Int {fmtMoney(b.interest_charged)}</span>}
                {Number(b.penalty_charged) > 0 && <span>Pen {fmtMoney(b.penalty_charged)}</span>}
                {Number(b.outstanding_balance) > 0 && <span className="text-slate-400">O/S {fmtMoney(b.outstanding_balance)}</span>}
              </div>
              <div className="flex flex-wrap items-center gap-1.5 mt-2">
                <Badge tone={billCaseTone(b.case_status)}>{b.case_status}</Badge>
                <Badge>{b.payment_status}</Badge>
              </div>
              <div className="flex items-center justify-between mt-2 text-[11px] text-slate-500">
                <span>{b.enforcement_officer ? `🔴 ${b.enforcement_officer}` : '🔴 Unassigned'}</span>
                <span>{fmtDate(b.date_logged)}</span>
              </div>
              {b.account_staff && <div className="mt-1 text-[11px] text-slate-400">🧾 {b.account_staff}</div>}
            </div>
          ))}
        </div>

        {rows.last_page > 1 && (
          <div className="flex items-center justify-between mt-4 text-sm">
            <span className="text-slate-400">Page {rows.current_page} of {rows.last_page}</span>
            <div className="flex gap-2">
              <Btn tone="white" disabled={!rows.prev_page_url} onClick={() => setPage(rows.current_page - 1)}>‹ Prev</Btn>
              <Btn tone="white" disabled={!rows.next_page_url} onClick={() => setPage(rows.current_page + 1)}>Next ›</Btn>
            </div>
          </div>
        )}
      </Card>

      {drawerId != null && <BillDrawer billId={drawerId} onClose={() => setDrawerId(null)} onChanged={load} />}
    </div>
  )
}