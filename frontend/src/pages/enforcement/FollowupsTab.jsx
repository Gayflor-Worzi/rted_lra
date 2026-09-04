import { useEffect, useMemo, useState } from 'react'
import api, { unwrap, errMsg } from '../../api'
import { useAuth } from '../../auth'
import { Badge, Btn, Input, Spinner, ErrorBox, PageTitle, fmtTime, taskTone } from '../../ui'
import { engagementInfo } from '../../lib/constants'
import BillDrawer from '../../components/BillDrawer'

const TRACKED = ['follow_up', 'reminder_30_day', 'demand_72_hour', 'final_enforcement', 'delivery_attempt', 'bill_delivered']

export default function FollowupsTab() {
  useAuth()
  const [tasks, setTasks] = useState(null)
  const [err, setErr] = useState('')
  const [type, setType] = useState('')
  const [q, setQ] = useState('')
  const [drawerId, setDrawerId] = useState(null)

  useEffect(() => {
    api.get('/tasks/my', { params: { per_page: 200 } })
      .then((r) => setTasks(unwrap(r).data))
      .catch((ex) => setErr(errMsg(ex)))
  }, [])

  const rows = useMemo(() => {
    const out = []
    for (const t of tasks?.data || []) {
      for (const e of t.engagements || []) {
        if (!TRACKED.includes(e.engagement_type)) continue
        if (type && e.engagement_type !== type) continue
        const hay = String(t.bill?.property_id || '') + t.task_reference + (e.officer || '') + (e.notes || '') + (e.outcome || '')
        if (q && !hay.toLowerCase().includes(q.toLowerCase())) continue
        out.push({ t, e, d: e.occurred_at || t.created_at })
      }
    }
    return out.sort((a, b) => String(b.d).localeCompare(String(a.d)))
  }, [tasks, type, q])

  if (!tasks && !err) return <Spinner label="Loading follow-up register…" />

  return (
    <div className="space-y-3">
      <PageTitle sub="Engagement activity across your field work — delivery attempts, follow-ups, reminders and demands.">
        Follow-ups
      </PageTitle>

      <ErrorBox error={err} />

      <div className="flex items-center gap-2 flex-wrap">
        <select value={type} onChange={(e) => setType(e.target.value)}
          className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white">
          <option value="">All engagement types</option>
          {TRACKED.map((t) => <option key={t} value={t}>{engagementInfo(t).label}</option>)}
        </select>
        <Input className="min-w-[220px]" placeholder="Search property, reference, officer…" value={q} onChange={(e) => setQ(e.target.value)} />
        <span className="text-xs text-slate-400">{rows.length} engagements</span>
      </div>

      <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-[11px] uppercase tracking-wider text-slate-400 border-b border-slate-100">
                <th className="px-3 py-3">Property ID</th>
                <th className="px-3 py-3">Task</th>
                <th className="px-3 py-3">Engagement</th>
                <th className="px-3 py-3">Officer</th>
                <th className="px-3 py-3">Outcome</th>
                <th className="px-3 py-3">Notes</th>
                <th className="px-3 py-3">Task status</th>
                <th className="px-3 py-3">Occurred</th>
                <th className="px-3 py-3 text-right">Action</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-50">
              {rows.length === 0 && (
                <tr><td colSpan={9} className="px-3 py-8 text-center text-slate-400">No engagement activity.</td></tr>
              )}
              {rows.map(({ t, e }, i) => (
                <tr key={i} className="hover:bg-slate-50">
                  <td className="px-3 py-2.5 font-semibold text-navy-800">{t.bill?.property_id || '—'}</td>
                  <td className="px-3 py-2.5 text-xs text-slate-500">{t.task_reference}</td>
                  <td className="px-3 py-2.5"><span className="flex items-center gap-1.5"><Badge tone={engagementInfo(e.engagement_type).tone}>{engagementInfo(e.engagement_type).label}</Badge></span></td>
                  <td className="px-3 py-2.5 text-xs text-slate-500">{e.officer}</td>
                  <td className="px-3 py-2.5"><span className="text-xs">{e.outcome || '—'}</span></td>
                  <td className="px-3 py-2.5 text-slate-500 max-w-[240px] truncate block">{e.notes || '—'}</td>
                  <td className="px-3 py-2.5"><Badge tone={taskTone(t.status)}>{t.status}</Badge></td>
                  <td className="px-3 py-2.5 text-xs text-slate-500">{fmtTime(e.occurred_at)}</td>
                  <td className="px-3 py-2.5 text-right">
                    <Btn tone="white" className="!px-3 !py-1.5" onClick={() => setDrawerId({ taskId: t.id, billId: t.bill?.id || t.reference_id })}>View</Btn>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {drawerId && (
        <BillDrawer billId={drawerId.billId} taskId={drawerId.taskId} onClose={() => setDrawerId(null)} />
      )}
    </div>
  )
}