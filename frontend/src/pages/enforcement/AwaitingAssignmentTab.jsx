import { useEffect, useState } from 'react'
import api, { errMsg, unwrap } from '../../api'
import { Badge, Btn, ErrorBox, Spinner, taskTone, fmtMoney, fmtDate } from '../../ui'
import BillDrawer from '../../components/BillDrawer'

export default function AwaitingAssignmentTab() {
  const [rows, setRows] = useState(null)
  const [error, setError] = useState('')
  const [page, setPage] = useState(1)
  const [drawer, setDrawer] = useState(null)

  const load = (targetPage = page) => {
    setError('')
    api.get('/tasks', {
      params: {
        workspace: 'enforcement',
        view: 'unassigned',
        task_type: 'Bill Delivery',
        status: 'Awaiting Assignment',
        per_page: 25,
        page: targetPage,
      },
    })
      .then((response) => setRows(unwrap(response).data))
      .catch((ex) => setError(errMsg(ex, 'Could not load bills awaiting assignment.')))
  }

  useEffect(() => { load() }, [page]) // eslint-disable-line react-hooks/exhaustive-deps

  const list = rows?.data || []

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold text-navy-800">Bills Awaiting Assignment</h2>
          <p className="text-sm text-slate-500">Unassigned Bill Delivery cases in the Enforcement queue.</p>
        </div>
        <Btn tone="white" onClick={() => load()}>Refresh</Btn>
      </div>

      <ErrorBox error={error} />

      {!rows ? <Spinner label="Loading bills awaiting assignment…" /> : (
        <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-wider text-slate-400 border-b border-slate-100">
                  <th className="px-3 py-3">Property ID</th>
                  <th className="px-3 py-3">Status</th>
                  <th className="px-3 py-3">Taxpayer</th>
                  <th className="px-3 py-3">TIN</th>
                  <th className="px-3 py-3 text-right">Total tax due</th>
                  <th className="px-3 py-3">Property address</th>
                  <th className="px-3 py-3">Due</th>
                  <th className="px-3 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {list.length === 0 && (
                  <tr><td colSpan={8} className="px-3 py-8 text-center text-slate-400">No bills are awaiting assignment.</td></tr>
                )}
                {list.map((task) => (
                  <tr key={task.id} className="hover:bg-slate-50">
                    <td className="px-3 py-2.5 font-semibold text-navy-800">{task.bill?.property_id || '—'}</td>
                    <td className="px-3 py-2.5"><Badge tone={taskTone(task.status)}>{task.status}</Badge></td>
                    <td className="px-3 py-2.5 text-slate-600">{task.bill?.taxpayer_name || '—'}</td>
                    <td className="px-3 py-2.5 text-slate-600">{task.bill?.tin || '—'}</td>
                    <td className="px-3 py-2.5 text-right font-medium text-navy-800">{fmtMoney(task.bill?.total_tax_due)}</td>
                    <td className="px-3 py-2.5 text-slate-600 max-w-[240px] truncate">{task.bill?.property_address || '—'}</td>
                    <td className="px-3 py-2.5 text-xs text-slate-500">{fmtDate(task.due_date)}</td>
                    <td className="px-3 py-2.5 text-right">
                      <Btn tone="white" className="!px-3 !py-1.5" onClick={() => setDrawer({ taskId: task.id, billId: task.bill?.id || task.reference_id })}>View</Btn>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {rows.last_page > 1 && (
            <div className="flex items-center justify-between px-4 py-3 border-t border-slate-100">
              <span className="text-xs text-slate-400">Page {rows.current_page} of {rows.last_page} · {rows.total} bills</span>
              <div className="flex gap-2">
                <Btn tone="white" disabled={!rows.prev_page_url} onClick={() => setPage(rows.current_page - 1)}>‹ Prev</Btn>
                <Btn tone="white" disabled={!rows.next_page_url} onClick={() => setPage(rows.current_page + 1)}>Next ›</Btn>
              </div>
            </div>
          )}
        </div>
      )}

      {drawer && (
        <BillDrawer billId={drawer.billId} taskId={drawer.taskId} onClose={() => setDrawer(null)} onChanged={() => load()} />
      )}
    </div>
  )
}
