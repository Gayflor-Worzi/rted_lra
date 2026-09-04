import { useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../../api'
import { useAuth } from '../../auth'
import { Badge, Btn, Input, Spinner, ErrorBox, PageTitle, taskTone, fmtMoney, fmtDate } from '../../ui'
import BillDrawer from '../../components/BillDrawer'

const PAYMENT_STATUSES = ['Unpaid', 'Payment Claimed', 'Verification Pending', 'Partially Paid', 'Paid', 'Payment Rejected', 'Payment Mismatch']

export default function RecordsTab() {
  useAuth()
  const [rows, setRows] = useState(null)
  const [err, setErr] = useState('')
  const [q, setQ] = useState('')
  const [group, setGroup] = useState('')
  const [payment, setPayment] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)
  const [drawerId, setDrawerId] = useState(null)

  const load = (extra = {}) => {
    const params = { per_page: 25, page: extra.page ?? page, ...(group ? { status_group: group } : {}), ...(payment ? { payment } : {}), ...(q ? { q } : {}), ...(from ? { date_from: from } : {}), ...(to ? { date_to: to } : {}) }
    api.get('/tasks', { params })
      .then((r) => setRows(unwrap(r).data))
      .catch((ex) => setErr(errMsg(ex)))
  }
  useEffect(() => { load() }, [page]) // eslint-disable-line react-hooks/exhaustive-deps

  const list = rows?.data || []

  return (
    <div className="space-y-3">
      <PageTitle sub="Historical case record within your data scope — every task, closed or open.">
        Enforcement Records
      </PageTitle>

      <ErrorBox error={err} />

      <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-2">
        <Input className="w-full" placeholder="Search property ID, TIN, reference…" value={q} onChange={(e) => setQ(e.target.value)} />
        <select className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={group} onChange={(e) => setGroup(e.target.value)}>
          <option value="">All groups</option>
          <option value="active">Active</option>
          <option value="pending">Pending</option>
          <option value="overdue">Overdue</option>
          <option value="escalated">Escalated</option>
          <option value="completed">Completed</option>
        </select>
        <select className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={payment} onChange={(e) => setPayment(e.target.value)}>
          <option value="">All payment statuses</option>
          {PAYMENT_STATUSES.map((p) => <option key={p} value={p}>{p}</option>)}
        </select>
        <div className="flex gap-2">
          <Input type="date" className="w-full" title="Due from" value={from} onChange={(e) => setFrom(e.target.value)} />
          <Input type="date" className="w-full" title="Due to" value={to} onChange={(e) => setTo(e.target.value)} />
        </div>
      </div>
      <div className="flex justify-end gap-2">
        <Btn tone="white" onClick={() => { setPage(1); load({ page: 1 }) }}>Search records</Btn>
      </div>

      {!rows ? <Spinner label="Loading records…" /> : (
        <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-wider text-slate-400 border-b border-slate-100">
                  <th className="px-3 py-3">Reference</th>
                  <th className="px-3 py-3">Property ID</th>
                  <th className="px-3 py-3">Status</th>
                  <th className="px-3 py-3 text-right">Total tax due</th>
                  <th className="px-3 py-3">Payment</th>
                  <th className="px-3 py-3">Officer</th>
                  <th className="px-3 py-3">Opened</th>
                  <th className="px-3 py-3">Due</th>
                  <th className="px-3 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {list.length === 0 && (
                  <tr><td colSpan={9} className="px-3 py-8 text-center text-slate-400">No records match.</td></tr>
                )}
                {list.map((t) => (
                  <tr key={t.id} className="hover:bg-slate-50">
                    <td className="px-3 py-2.5 text-xs text-slate-500">{t.task_reference}</td>
                    <td className="px-3 py-2.5 font-semibold text-navy-800">{t.bill?.property_id || '—'}</td>
                    <td className="px-3 py-2.5"><Badge tone={taskTone(t.status)}>{t.status}</Badge></td>
                    <td className="px-3 py-2.5 text-right font-medium text-navy-800">{fmtMoney(t.bill?.total_tax_due)}</td>
                    <td className="px-3 py-2.5"><Badge tone="slate">{t.bill?.payment_status || '—'}</Badge></td>
                    <td className="px-3 py-2.5 text-xs text-slate-500">{t.assigned_to || '—'}</td>
                    <td className="px-3 py-2.5 text-xs text-slate-500">{fmtDate(t.created_at)}</td>
                    <td className="px-3 py-2.5 text-xs text-slate-500">{fmtDate(t.due_date)}</td>
                    <td className="px-3 py-2.5 text-right">
                      <Btn tone="white" className="!px-3 !py-1.5" onClick={() => setDrawerId({ taskId: t.id, billId: t.bill?.id || t.reference_id })}>View</Btn>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {rows.last_page > 1 && (
            <div className="flex items-center justify-between px-4 py-3 border-t border-slate-100">
              <span className="text-xs text-slate-400">Page {rows.current_page} of {rows.last_page} · {rows.total} records</span>
              <div className="flex gap-2">
                <Btn tone="white" disabled={!rows.prev_page_url} onClick={() => setPage(rows.current_page - 1)}>‹ Prev</Btn>
                <Btn tone="white" disabled={!rows.next_page_url} onClick={() => setPage(rows.current_page + 1)}>Next ›</Btn>
              </div>
            </div>
          )}
        </div>
      )}

      {drawerId && (
        <BillDrawer billId={drawerId.billId} taskId={drawerId.taskId} onClose={() => setDrawerId(null)} />
      )}
    </div>
  )
}