import { useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../../api'
import { useAuth } from '../../auth'
import { Badge, Btn, Input, Spinner, ErrorBox, taskTone, fmtMoney, fmtDate } from '../../ui'
import BillDrawer from '../../components/BillDrawer'

const STATUS_GROUPS = [
  { key: '', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'pending', label: 'Pending' },
  { key: 'overdue', label: 'Overdue' },
  { key: 'escalated', label: 'Escalated' },
  { key: 'completed', label: 'Completed' },
]

const PAYMENT_TONES = {
  Paid: 'green', 'Partially Paid': 'blue', Unpaid: 'slate',
  'Payment Claimed': 'amber', 'Verification Pending': 'amber', 'Payment Rejected': 'red', 'Payment Mismatch': 'red',
}

export default function TasksTab({ presetScope, presetGroup }) {
  const { can } = useAuth()
  const [rows, setRows] = useState(null)
  const [err, setErr] = useState('')
  const [q, setQ] = useState('')
  const [view, setView] = useState(presetScope === 'team' && can('tasks.view_section', 'tasks.view_division') ? 'team' : 'mine')
  const [group, setGroup] = useState(presetGroup || '')
  const [page, setPage] = useState(1)
  const [drawerId, setDrawerId] = useState(null)

  const canTeam = can('tasks.view_section', 'tasks.view_division')
  const canAll = can('tasks.view_division')

  const load = (extra = {}) => {
    const params = { view, per_page: 25, page: extra.page ?? page, ...(group ? { status_group: group } : {}), ...(q ? { q } : {}) }
    api.get('/tasks', { params })
      .then((r) => setRows(unwrap(r).data))
      .catch((ex) => setErr(errMsg(ex)))
  }
  useEffect(() => { load() }, [view, group, page]) // eslint-disable-line react-hooks/exhaustive-deps

  const list = rows?.data || []

  const cell = (children) => <td className="px-3 py-2.5 align-middle">{children}</td>

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2 flex-wrap">
        <div className="flex rounded-xl bg-slate-100 p-1">
          <button onClick={() => { setView('mine'); setPage(1) }}
            className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition ${view === 'mine' ? 'bg-white shadow-sm text-navy-800' : 'text-slate-500'}`}>My</button>
          {canTeam && (
            <button onClick={() => { setView('team'); setPage(1) }}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition ${view === 'team' ? 'bg-white shadow-sm text-navy-800' : 'text-slate-500'}`}>Team</button>
          )}
          {canAll && (
            <button onClick={() => { setView('all'); setPage(1) }}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition ${view === 'all' ? 'bg-white shadow-sm text-navy-800' : 'text-slate-500'}`}>All tasks</button>
          )}
        </div>
        {STATUS_GROUPS.map((g) => (
          <button key={g.key} onClick={() => { setGroup(g.key); setPage(1) }}
            className={`px-3 py-1.5 rounded-full text-xs font-semibold transition ${group === g.key ? 'bg-navy-800 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>
            {g.label}
          </button>
        ))}
      </div>

      <div className="flex gap-2">
        <Input className="flex-1" placeholder="Search property ID, TIN or tax period…" value={q}
          onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && load({ page: 1 })} />
        <Btn tone="white" onClick={() => load({ page: 1 })}>Search</Btn>
      </div>

      <ErrorBox error={err} />

      {!rows ? <Spinner label="Loading tasks…" /> : (
        <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-wider text-slate-400 border-b border-slate-100">
                  <th className="px-3 py-3">Property ID</th>
                  <th className="px-3 py-3">Workflow</th>
                  <th className="px-3 py-3 text-right">Total tax due</th>
                  <th className="px-3 py-3">TIN</th>
                  <th className="px-3 py-3">Payment</th>
                  <th className="px-3 py-3">Assignment</th>
                  <th className="px-3 py-3">Property address</th>
                  <th className="px-3 py-3">Due</th>
                  <th className="px-3 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {list.length === 0 && (
                  <tr><td colSpan={9} className="px-3 py-8 text-center text-slate-400">No tasks match.</td></tr>
                )}
                {list.map((t) => (
                  <tr key={t.id} className="hover:bg-slate-50">
                    {cell(<span className="font-semibold text-navy-800">{t.bill?.property_id || '—'}</span>)}
                    {cell(<span className="flex items-center gap-1.5"><Badge tone={taskTone(t.status)}>{t.status}</Badge>{t.priority > 1 && <Badge tone="red">P{t.priority}</Badge>}</span>)}
                    {cell(<span className="text-right font-medium text-navy-800 block">{fmtMoney(t.bill?.total_tax_due)}</span>)}
                    {cell(<span className="text-slate-600">{t.bill?.tin || '—'}</span>)}
                    {cell(<Badge tone={PAYMENT_TONES[t.bill?.payment_status] || 'slate'}>{t.bill?.payment_status || '—'}</Badge>)}
                    {cell(<span className="text-xs text-slate-500">{t.assignment_status} · {t.assigned_to || 'unassigned'}</span>)}
                    {cell(<span className="text-slate-600 max-w-[200px] truncate block">{t.bill?.property_address || '—'}</span>)}
                    {cell(<span className={`text-xs ${t.due_date && String(t.due_date).slice(0, 10) < new Date().toISOString().slice(0, 10) && !['Resolved', 'Closed', 'Paid'].includes(t.status) ? 'text-red-600 font-semibold' : 'text-slate-500'}`}>{fmtDate(t.due_date)}</span>)}
                    {cell(<div className="flex justify-end"><Btn tone="white" className="!px-3 !py-1.5" onClick={() => setDrawerId({ taskId: t.id, billId: t.bill?.id || t.reference_id })}>View</Btn></div>)}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {rows.last_page > 1 && (
            <div className="flex items-center justify-between px-4 py-3 border-t border-slate-100">
              <span className="text-xs text-slate-400">Page {rows.current_page} of {rows.last_page} · {rows.total} tasks</span>
              <div className="flex gap-2">
                <Btn tone="white" disabled={!rows.prev_page_url} onClick={() => setPage(rows.current_page - 1)}>‹ Prev</Btn>
                <Btn tone="white" disabled={!rows.next_page_url} onClick={() => setPage(rows.current_page + 1)}>Next ›</Btn>
              </div>
            </div>
          )}
        </div>
      )}

      {drawerId && (
        <BillDrawer billId={drawerId.billId} taskId={drawerId.taskId} onClose={() => setDrawerId(null)} onChanged={load} />
      )}
    </div>
  )
}