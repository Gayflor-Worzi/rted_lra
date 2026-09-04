import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import api, { unwrap, errMsg } from '../api'
import { Badge, Btn, Spinner, billCaseTone, taskTone, fmtMoney, fmtDate, fmtTime } from '../ui'
import { useAuth } from '../auth'
import { engagementInfo } from '../lib/constants'
import { TaskLadder, TaskNextAction, RecordVisitModal, EngagementModal, SubmitReceiptModal, engagementOutcomeTone, fmtWhen } from './TaskUi'

const TABS = ['Overview', 'Engagement', 'Payments', 'Evidence', 'Status History', 'Assignment', 'Audit']

const paymentTone = (s) => s === 'Paid' ? 'green' : s === 'Partially Paid' ? 'amber' : s === 'Payment Rejected' ? 'red' : 'navy'
const priorityTone = (p) => p === 'Urgent' ? 'red' : p === 'High' ? 'amber' : 'slate'

function Field({ label, value, mono }) {
  return (
    <div>
      <div className="text-[11px] uppercase tracking-wider text-slate-400">{label}</div>
      <div className={`mt-0.5 text-sm font-medium text-slate-700 ${mono ? 'font-mono' : ''}`}>{value ?? '—'}</div>
    </div>
  )
}

function PhotoTile({ photo }) {
  const [url, setUrl] = useState(null)
  useEffect(() => {
    if (!photo.file_path) return
    let alive = true
    let blobUrl = null
    api.get(`/evidence/photos/${photo.id}/download`, { responseType: 'blob' })
      .then((r) => { if (alive) { blobUrl = URL.createObjectURL(r.data); setUrl(blobUrl) } })
      .catch(() => {})
    return () => { alive = false; if (blobUrl) URL.revokeObjectURL(blobUrl) }
  }, [photo.id, photo.file_path])
  if (!url) return null
  return <img src={url} alt={photo.photo_reference} className="w-full h-28 object-cover rounded-lg border border-slate-200" />
}

/**
 * Unified Task + Property/Bill workspace — opened as a drawer from the task
 * list / bill register (VIEW), or embedded full-page at /bills/:id.
 * List first → view one task → take the next permitted action.
 */
export default function BillDrawer({ billId, taskId, onClose, onChanged, embedded }) {
  const { can } = useAuth()
  const [bill, setBill] = useState(null)
  const [taskDetail, setTaskDetail] = useState(null)
  const [photos, setPhotos] = useState(null)
  const [audits, setAudits] = useState([])
  const [tab, setTab] = useState('Overview')
  const [busyId, setBusyId] = useState(null)
  const [modal, setModal] = useState(null)
  const [err, setErr] = useState(null)
  const [notice, setNotice] = useState('')
  const [users, setUsers] = useState(null)
  const [assignTo, setAssignTo] = useState('')
  const [profile, setProfile] = useState(null)

  const loadRest = (b) => {
    api.get('/evidence/photos', { params: { bill_id: b.id, per_page: 50 } })
      .then((r) => setPhotos(unwrap(r).data?.data || []))
      .catch(() => setPhotos([]))

    if (can('audit.view')) {
      api.get('/audit-logs', { params: { per_page: 500 } }).then((r) => {
        const rows = unwrap(r).data?.data || []
        const tids = new Set((b.tasks || []).map((t) => t.id))
        setAudits(rows.filter((a) =>
          (a.auditable_type === 'PropertyBill' && Number(a.auditable_id) === Number(b.id)) ||
          (a.auditable_type === 'Task' && tids.has(Number(a.auditable_id)))
        ))
      }).catch(() => setAudits([]))
    }
  }

  const loadTask = (task) => {
    if (!task?.id) { setTaskDetail(null); return }
    api.get(`/tasks/${task.id}`)
      .then((r2) => setTaskDetail(unwrap(r2).data))
      .catch(() => setTaskDetail(null))
  }

  const load = () => {
    api.get(`/property-bills/${billId}`).then((r) => {
      const b = unwrap(r).data
      setBill(b)
      loadRest(b)
      const t = taskId
        ? (b.tasks || []).find((x) => x.id === taskId)
        : (b.tasks || [])[0]
      loadTask(t)
    }).catch((e) => {
      if (e.response?.status === 403 && taskId) {
        // No account-record access (e.g. Enforcement Officer): still show the
        // assigned task within the enforcement workspace, using the task's
        // embedded bill snapshot instead of the account record endpoint.
        api.get(`/tasks/${taskId}`).then((r2) => {
          const td = unwrap(r2).data
          const b = td.bill ? { ...td.bill, tasks: [{ id: td.id }] } : { id: billId }
          setBill(b)
          setTaskDetail(td)
          loadRest(b)
        }).catch(() => onClose?.())
      } else if (e.response?.status === 403 || e.response?.status === 404) {
        onClose?.()
      } else {
        setErr(e)
      }
    })
  }

  useEffect(() => { load() }, [billId, taskId]) // eslint-disable-line

  useEffect(() => {
    if (!can('bills.assign')) return
    api.get('/users', { params: { role: 'Enforcement Officer', per_page: 200 } })
      .then((r) => setUsers(unwrap(r).data?.data || []))
      .catch(() => setUsers([]))
  }, []) // eslint-disable-line

  const canVisit = can('enforcement.record_visit')
  const isClosed = taskDetail ? ['Closed', 'Resolved', 'Paid'].includes(taskDetail.status) : false

  const advanceStep = async (task) => {
    try {
      setBusyId(task.id)
      const r = await api.post(`/tasks/${task.id}/advance`, {})
      const d = unwrap(r)
      setNotice(d.advanced ? `Advanced — ${d.result?.stage}` : 'No pending step eligible yet.')
      load()
      onChanged?.()
    } catch (ex) { setErr({ message: errMsg(ex, 'Could not run this step.') }) }
    setBusyId(null)
  }

  const propsFor = (task) => (task.bill ? task : { ...task, bill })

  const assign = async (e) => {
    e.preventDefault()
    if (!assignTo) return
    setBusyId(-1)
    try {
      await api.post(`/property-bills/${bill.id}/assign`, { officer_id: Number(assignTo) })
      setNotice('Bill assigned — task created/updated.')
      setAssignTo('')
      load()
      onChanged?.()
    } catch (ex) { setErr({ message: errMsg(ex, 'Could not assign this bill.') }) }
    setBusyId(null)
  }

  const engagementItem = (e, i) => (
    <div key={i} className="flex items-start gap-2 text-sm">
      <span className="shrink-0">{engagementInfo(e.engagement_type)?.icon || '•'}</span>
      <div className="flex-1 min-w-0">
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="font-semibold text-slate-700">{engagementInfo(e.engagement_type)?.label || e.engagement_type}</span>
          {e.outcome && <Badge tone={engagementOutcomeTone(e.outcome)}>{e.outcome}</Badge>}
          <span className="text-xs text-slate-400">· {fmtWhen(e.occurred_at)}</span>
        </div>
        {e.notes && <div className="text-xs text-slate-500 mt-0.5">{e.notes}</div>}
        {e.officer && <div className="text-xs text-slate-400 mt-0.5">by {e.officer}</div>}
      </div>
    </div>
  )

  const blankTask = (bill) => ({
    status: bill?.case_status || 'Logged',
    assigned_to_id: null,
    previous_status: null,
    stage: null,
    timeline: null,
    next_action: { kind: 'none', verb: null, notes: 'No enforcement task exists for this bill yet.', permissions: [] },
    deadline: {},
    engagements: [],
  })

  if (err && !bill) {
    if (embedded) return <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 text-sm text-red-600">{errMsg(err)}</div>
    return <DrawerWrap onClose={onClose}><div className="p-6 text-sm text-red-600">{errMsg(err)}</div></DrawerWrap>
  }
  if (!bill) {
    if (embedded) return <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-6"><Spinner label="Loading bill…" /></div>
    return <DrawerWrap onClose={onClose}><Spinner label="Loading bill…" /></DrawerWrap>
  }

  const body = (() => {
    if (tab === 'Overview') {
      const task = taskDetail || blankTask(bill)
      const nowEng = (task.engagements || [])[0]
      return (
        <div className="space-y-4">
          {/* CURRENT / PREVIOUS STATUS */}
          <div className="rounded-xl border border-slate-200 overflow-hidden">
            <div className="px-4 py-3 grid sm:grid-cols-2 gap-4">
              <div>
                <div className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Current status</div>
                <div className="mt-1.5 flex items-center gap-2">
                  <span className={`w-2 h-2 rounded-full ${isClosed ? 'bg-emerald-400' : taskTone(task.status) === 'red' ? 'bg-red-500' : taskTone(task.status) === 'amber' ? 'bg-amber-500' : 'bg-brand-500'}`} />
                  <span className="font-bold text-navy-800 uppercase tracking-wide text-sm">{task.stage || task.status}</span>
                  <Badge tone={taskTone(task.status)}>{task.status}</Badge>
                </div>
                {task.previous_status && (
                  <div className="text-xs text-slate-500 mt-1">
                    Previous: <span className="font-semibold">✓ {task.previous_status}</span>
                  </div>
                )}
              </div>
              <div>
                <div className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Assigned</div>
                <div className="mt-1.5 text-sm font-medium text-slate-700">{task.assigned_to || (task.assigned_to_id ? `#${task.assigned_to_id}` : 'Unassigned')}</div>
                {task.due_date && <div className={`text-xs mt-1 ${task.deadline?.due_overdue && !isClosed ? 'text-red-500 font-semibold' : 'text-slate-400'}`}>Due {fmtDate(task.due_date)}</div>}
                {task.priority && <Badge tone={priorityTone(task.priority)}>{task.priority}</Badge>}
              </div>
            </div>

            {/* PROCESS FLOW */}
            {(task.timeline || []).length > 0 && (
              <div className="border-t border-slate-100">
                <TaskLadder timeline={task.timeline} />
              </div>
            )}

            {/* CURRENT REQUIRED ACTION */}
            <div className="px-4 py-3 border-t border-slate-100 bg-slate-50/50">
              <div className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold mb-1.5">Current required action</div>
              <TaskNextAction task={task} canVisit={canVisit && !isClosed}
                onRecordVisit={(t) => setModal({ id: t.id, task: propsFor(t) })}
                onClaim={(t) => setModal({ id: t.id, type: 'receipt', task: propsFor(t) })}
                onAdvance={advanceStep} busyId={busyId} />
              {task.next_action?.notes && task.next_action.kind !== 'none' && (
                <div className="text-xs text-slate-400 mt-1.5">{task.next_action.notes}</div>
              )}
              {task.deadline?.milestone && (
                <div className={`text-xs mt-1.5 ${task.deadline.milestone.overdue ? 'text-red-500 font-semibold' : 'text-slate-400'}`}>
                  {task.deadline.milestone.overdue ? 'Overdue · ' : ''}{task.deadline.milestone.label} {fmtDate(task.deadline.milestone.date)}
                </div>
              )}
              {!taskDetail && can('tasks.assign', 'tasks.reassign') && (
                <button onClick={() => setTab('Assignment')} className="mt-2 px-4 py-2 rounded-xl bg-navy-50 text-navy-800 hover:bg-navy-100 text-sm font-semibold transition">
                  Assign an officer →
                </button>
              )}
            </div>
          </div>

          {/* LAST ENGAGEMENT */}
          <div className="rounded-xl border border-slate-100 px-4 py-3">
            <div className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold mb-1.5">Last engagement</div>
            {nowEng ? engagementItem(nowEng, 0) : <p className="text-xs text-slate-400">No engagements recorded yet.</p>}
          </div>

          {/* PROPERTY / BILL */}
          <div className="rounded-xl border border-slate-100 px-4 py-3">
            <div className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold mb-2">Property / Bill</div>
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
              <Field label="Taxpayer" value={bill.taxpayer_name} />
              <Field label="Address" value={bill.property_address} />
              <Field label="Classification" value={bill.property_classification} />
              <Field label="Property type" value={bill.property_type} />
              <Field label="Tax period" value={bill.tax_period} />
              <Field label="TIN" value={bill.tin} mono />
              <Field label="Document #" value={bill.document_number} mono />
              <Field label="Property ID" value={bill.property_id} mono />
              <Field label="Recipient" value={bill.recipient_name || bill.recipient_type} />
            </div>
            <div className="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-3 border-t border-slate-100 pt-3">
              {[
                ['Assessed value', bill.assessed_value],
                ['Tax amount', bill.tax_amount],
                ['Interest', bill.interest_charged],
                ['Penalty', bill.penalty_charged],
                ['Total due', bill.total_tax_due],
              ].map(([l, v]) => (
                <div key={l} className="text-sm">
                  <div className="text-[11px] uppercase tracking-wider text-slate-400">{l}</div>
                  <div className="font-medium text-slate-700">{v == null ? '—' : fmtMoney(v)}</div>
                </div>
              ))}
              <div className="text-sm">
                <div className="text-[11px] uppercase tracking-wider text-slate-400">Outstanding</div>
                <div className="font-bold text-navy-800">{fmtMoney(bill.outstanding_balance)}</div>
              </div>
            </div>
            <div className="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
              <Badge tone={billCaseTone(bill.case_status)}>{bill.case_status}</Badge>
              <Badge tone="blue">{bill.delivery_status}</Badge>
              <Badge tone={paymentTone(bill.payment_status)}>{bill.payment_status}</Badge>
              {bill.escalation_stage && <Badge tone="amber">{bill.escalation_stage}</Badge>}
            </div>
            <button onClick={() => setProfile(bill)} className="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-700 hover:underline">
              View full property lifecycle (discovery · valuation · bill) →
            </button>
          </div>
        </div>
      )
    }

    if (tab === 'Engagement') {
      const engs = taskDetail?.engagements || []
      if (!engs.length) return <p className="text-sm text-slate-400">No engagements recorded yet.</p>
      return (
        <div className="space-y-3">
          {engs.map((e, i) => engagementItem(e, i))}
          <p className="text-xs text-slate-400 pt-2 border-t border-slate-100">{engs.length} engagement{engs.length === 1 ? '' : 's'} — every attempt is permanently associated with the case.</p>
        </div>
      )
    }

    if (tab === 'Payments') {
      const verifications = bill.verifications || []
      const payments = bill.payments || []
      const claimTask = taskDetail || blankTask(bill)
      return (
        <div className="space-y-4">
          {can('payments.claim') && (
            <div className="rounded-xl bg-brand-50/60 border border-brand-100 px-4 py-3 flex items-center justify-between gap-3">
              <div className="text-sm">
                <div className="font-semibold text-navy-800">Received a payment?</div>
                <div className="text-xs text-slate-500 mt-0.5">Submit the receipt to create a claim for Accounts to verify.</div>
              </div>
              <Btn tone="white" onClick={() => setModal({ id: claimTask.id, type: 'receipt', task: propsFor(claimTask) })} disabled={busyId === claimTask.id}>🧾 Submit payment claim</Btn>
            </div>
          )}
          {!verifications.length && !payments.length && <p className="text-sm text-slate-400">No payment activity yet.</p>}
          {payments.length > 0 && (
            <div>
              <div className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold mb-2">Verified payments</div>
              <div className="space-y-2">
                {payments.map((p) => (
                  <div key={p.id} className="rounded-xl border border-slate-100 px-4 py-2.5 flex items-center justify-between text-sm">
                    <div>
                      <div className="font-semibold text-navy-800">{fmtMoney(p.amount)}</div>
                      <div className="text-xs text-slate-400">{p.reference || 'Payment'} · {fmtDate(p.payment_date)}</div>
                    </div>
                    <Badge tone="green">Confirmed</Badge>
                  </div>
                ))}
              </div>
            </div>
          )}
          {verifications.length > 0 && (
            <div>
              <div className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold mb-2">Payment claims (verification)</div>
              <div className="space-y-2">
                {verifications.map((v) => (
                  <div key={v.id} className="rounded-xl border border-slate-100 px-4 py-2.5 text-sm">
                    <div className="flex items-center justify-between gap-2 flex-wrap">
                      <span className="font-semibold text-slate-700">{fmtMoney(v.amount_claimed)}</span>
                      <Badge tone={v.verification_status === 'Confirmed' ? 'green' : v.verification_status === 'Rejected' ? 'red' : 'amber'}>{v.verification_status}</Badge>
                    </div>
                    <div className="text-xs text-slate-400 mt-1">
                      Receipt {v.receipt_number || '—'} · {v.match_status || 'no match status'} · {fmtTime(v.created_at)}
                      {v.verified_by ? ` · verified by ${v.verified_by}` : ''}
                    </div>
                    {v.rejection_reason && <div className="text-xs text-red-500 mt-1">{v.rejection_reason}</div>}
                  </div>
                ))}
              </div>
              <div className="mt-3 text-xs text-slate-500">Total claims: {fmtMoney(verifications.reduce((s, v) => s + Number(v.amount_claimed || 0), 0))}</div>
            </div>
          )}
        </div>
      )
    }

    if (tab === 'Evidence') {
      if (photos === null) return <Spinner label="Loading evidence…" />
      if (!photos.length) return <p className="text-sm text-slate-400">No photo evidence linked to this bill.</p>
      return (
        <div className="space-y-3">
          {photos.map((p) => (
            <div key={p.id} className="rounded-xl border border-slate-100 p-3">
              <PhotoTile photo={p} />
              <div className="mt-2 flex items-center justify-between flex-wrap gap-2">
                <div>
                  <div className="font-mono text-xs font-semibold text-slate-700">{p.photo_reference}</div>
                  <div className="text-xs text-slate-400 mt-0.5">
                    {p.photo_type} · {fmtDate(p.captured_at)}{p.gps_coordinate ? ` · 📍 ${p.gps_coordinate}` : ''}
                  </div>
                </div>
                <Badge tone="slate">{p.officer?.full_name || '—'}</Badge>
              </div>
              {p.remarks && <div className="text-xs text-slate-500 mt-1.5">{p.remarks}</div>}
            </div>
          ))}
        </div>
      )
    }

    if (tab === 'Status History') {
      const history = taskDetail?.history
      if (!taskDetail) return <p className="text-sm text-slate-400">No task yet — no history to show.</p>
      if (!history || !history.length) return <p className="text-sm text-slate-400">No status transitions yet.</p>
      return (
        <div className="space-y-3">
          {history.map((h, i) => (
            <div key={i} className="flex items-start gap-2 text-sm">
              <span className="mt-1.5 w-2 h-2 rounded-full bg-brand-400 shrink-0" />
              <div>
                <div className="font-medium text-slate-700">
                  {h.from_status && h.to_status ? `${h.from_status} → ${h.to_status}` : (h.to_status || h.status || 'Updated')}
                  {h.action && <span className="ml-1.5 text-[11px] text-slate-400 uppercase">({h.action})</span>}
                </div>
                {h.remarks && <div className="text-xs text-slate-500 mt-0.5">{h.remarks}</div>}
                <div className="text-xs text-slate-400 mt-0.5">
                  {h.actor?.full_name || h.actor || h.actor_id || 'System'} · {fmtWhen(h.created_at)}
                </div>
              </div>
            </div>
          ))}
        </div>
      )
    }

    if (tab === 'Assignment') {
      return (
        <div className="space-y-4">
          <div className="rounded-xl bg-slate-50/70 border border-slate-100 px-4 py-3 grid grid-cols-2 gap-3">
            <Field label="Assigned officer" value={bill.enforcement_officer} />
            <Field label="Account staff" value={bill.account_staff} />
            {(bill.tasks || []).map((tk) => (
              <div key={tk.id} className="col-span-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                <div className="flex items-center gap-2">
                  <span className="font-mono font-medium text-brand-600">{tk.task_reference}</span>
                  <Badge tone="slate">{tk.status}</Badge>
                </div>
                <div className="text-xs text-slate-500 mt-1">
                  {typeof tk.assigned_to === 'string' ? tk.assigned_to : (tk.assigned_to?.full_name ?? 'Unassigned')} · due {fmtDate(tk.due_date)}
                </div>
              </div>
            ))}
          </div>

          {can('bills.assign') && (
            <form onSubmit={assign} className="rounded-xl border border-slate-200 p-4 space-y-3">
              <div className="text-xs font-semibold text-slate-600">Assign / reassign enforcement officer</div>
              <select value={assignTo} onChange={(e) => setAssignTo(e.target.value)} className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white">
                <option value="">Select officer…</option>
                {(users || []).map((u) => (
                  <option key={u.id} value={u.id}>{u.full_name} ({u.staff_id})</option>
                ))}
              </select>
              <Btn type="submit" disabled={busyId === -1 || !assignTo}>{busyId === -1 ? 'Assigning…' : 'Assign'}</Btn>
            </form>
          )}

          {taskDetail && (
            <div className="flex flex-wrap items-center gap-2">
              {(canVisit || can('tasks.complete', 'tasks.assign', 'enforcement.record_visit', 'me.view')) && (
                <Btn tone="white" onClick={() => setModal({ id: taskDetail.id, type: 'engagement', task: propsFor(taskDetail) })} disabled={busyId === taskDetail.id}>📝 Log engagement</Btn>
              )}
              {canVisit && (
                <Btn tone="white" onClick={() => setModal({ id: taskDetail.id, type: 'visit', task: propsFor(taskDetail) })} disabled={busyId === taskDetail.id}>📍 Record visit</Btn>
              )}
              {can('payments.claim') && (
                <Btn tone="white" onClick={() => setModal({ id: taskDetail.id, type: 'receipt', task: propsFor(taskDetail) })} disabled={busyId === taskDetail.id}>🧾 Submit payment claim</Btn>
              )}
            </div>
          )}
        </div>
      )
    }

    if (tab === 'Audit') {
      if (!can('audit.view')) return <p className="text-sm text-slate-400">You don't have audit access.</p>
      if (!audits.length) return <p className="text-sm text-slate-400">No audit records for this bill yet.</p>
      return (
        <div className="space-y-2.5">
          {audits.slice(0, 60).map((a) => (
            <div key={a.id} className="rounded-xl border border-slate-100 px-3.5 py-2.5 text-sm">
              <div className="flex items-center justify-between gap-2 flex-wrap">
                <span className="font-mono text-[11px] text-brand-600">{a.action}</span>
                <span className="text-[11px] text-slate-400">#{a.id} · {fmtWhen(a.created_at)}</span>
              </div>
              <div className="text-xs text-slate-500 mt-0.5">
                by {a.actor || 'System'}
                {a.new_values ? ` · ${Object.entries(a.new_values).map(([k, v]) => `${k}: ${JSON.stringify(v)}`).join(', ')}` : ''}
              </div>
              <div className="text-[10px] text-slate-300 mt-1 font-mono">{a.hash || ''}</div>
            </div>
          ))}
        </div>
      )
    }

    return null
  })()

  const header = (
    <div className="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3">
      <div className="min-w-0">
        <div className="font-bold text-navy-800 truncate">
          {taskDetail ? taskDetail.task_reference : `Bill ${bill.document_number}`}
        </div>
        <div className="text-xs text-slate-400 truncate">
          {bill.property_id} · {bill.taxpayer_name || '—'}
          {' · '}{bill.document_number}
          {!embedded && can('bills.view') && <Link to={`/bills/${bill.id}`} className="ml-1.5 text-brand-500 hover:underline font-semibold">Open page →</Link>}
        </div>
      </div>
      {onClose && <button onClick={onClose} className="text-slate-400 hover:text-slate-600 text-2xl leading-none px-1">×</button>}
    </div>
  )

  const tabBar = (
    <div className="px-3 pt-3 pb-1 border-b border-slate-100 flex gap-1.5 overflow-x-auto bg-white z-10">
      {TABS.map((t) => (
        <button key={t} onClick={() => setTab(t)}
          className={`px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap transition ${tab === t ? 'bg-navy-800 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>
          {t}
        </button>
      ))}
    </div>
  )

  const content = (
    <div className="flex-1 overflow-y-auto p-5">
      {notice && <div className="rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100 px-4 py-2.5 text-sm mb-4">{notice}</div>}
      {err?.message && <div className="rounded-xl bg-red-50 text-red-700 border border-red-100 px-4 py-2.5 text-sm mb-4">{err.message}</div>}
      {body}
    </div>
  )

  const modals = (
    <>
      {modal && modal.task && modal.id === taskDetail?.id && modal.type === 'visit' && (
        <RecordVisitModal task={modal.task} onClose={() => setModal(null)} onSaved={() => { setModal(null); setNotice('Visit recorded.'); load(); onChanged?.() }} />
      )}
      {modal && modal.task && modal.id === taskDetail?.id && modal.type === 'engagement' && (
        <EngagementModal task={modal.task} onClose={() => setModal(null)} onSaved={() => { setModal(null); setNotice('Engagement recorded.'); load(); onChanged?.() }} />
      )}
      {modal && modal.task && modal.id === taskDetail?.id && modal.type === 'receipt' && (
        <SubmitReceiptModal task={modal.task} onClose={() => setModal(null)} onSaved={() => { setModal(null); setNotice('Payment claim submitted.'); load(); onChanged?.() }} />
      )}
      {profile && (
        <PropertyProfileModal
          query={{ bill_id: profile.id, property_id: profile.property_id, document_number: profile.document_number }}
          onClose={() => setProfile(null)}
        />
      )}
    </>
  )

  if (embedded) {
    return (
      <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        {header}
        {tabBar}
        {content}
        {modals}
      </div>
    )
  }

  return (
    <div className="fixed inset-0 z-50 flex justify-end">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <div className="relative w-full max-w-xl bg-white shadow-2xl h-full flex flex-col">
        {header}
        {tabBar}
        {content}
        {modals}
      </div>
    </div>
  )
}

function DrawerWrap({ onClose, children }) {
  return (
    <div className="fixed inset-0 z-50 flex justify-end">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <div className="relative w-full max-w-xl bg-white shadow-2xl h-full flex flex-col overflow-y-auto">{children}</div>
    </div>
  )
}

/**
 * Read-only unified property lifecycle view (spec §11/§17): for a given LITAS
 * bill/property it shows the linked Discovery record(s), Valuation record(s) and
 * the tax Bill itself — so account/enforcement users see one integrated picture
 * of the property rather than unrelated datasets. Pulled from /property-profile.
 */
function PropertyProfileModal({ query, onClose }) {
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')
  useEffect(() => {
    const params = {}
    if (query.bill_id) params.bill_id = query.bill_id
    if (query.property_id) params.property_id = query.property_id
    if (query.document_number) params.document_number = query.document_number
    api.get('/property-profile', { params })
      .then((r) => setData(unwrap(r).data))
      .catch((e) => setErr(errMsg(e)))
  }, [query.bill_id, query.property_id, query.document_number])

  const bills = data?.bills || []
  const discoveries = data?.discoveries || []
  const valuations = data?.valuations || []

  return (
    <div className="fixed inset-0 z-[60] bg-black/40 grid place-items-center p-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-hidden flex flex-col">
        <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
          <h3 className="font-bold text-navy-800">Property lifecycle</h3>
          <button onClick={onClose} className="w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-500 font-bold">✕</button>
        </div>
        <div className="flex-1 overflow-y-auto p-6 space-y-6">
          {err && <p className="text-sm text-red-600">{err}</p>}
          {!data && !err && <p className="text-sm text-slate-400">Loading property history…</p>}
          {data && (
            <>
              <div>
                <SectionLabel>LITAS Bill</SectionLabel>
                {bills.length === 0 ? (
                  <EmptyLine text="No registered bill for this property." />
                ) : (
                  <div className="space-y-2">
                    {bills.map((b) => (
                      <div key={b.id} className="rounded-xl border border-slate-200 px-4 py-3">
                        <div className="flex flex-wrap gap-2 items-center">
                          <span className="font-semibold text-navy-800">{b.document_number}</span>
                          <Badge tone={billCaseTone(b.case_status)}>{b.case_status}</Badge>
                          <Badge tone={paymentTone(b.payment_status)}>{b.payment_status}</Badge>
                        </div>
                        <div className="mt-2 grid grid-cols-2 sm:grid-cols-3 gap-2 text-sm">
                          <Field label="Property ID" value={b.property_id} mono />
                          <Field label="TIN" value={b.tin} mono />
                          <Field label="Taxpayer" value={b.taxpayer_name} />
                          <Field label="Total due" value={b.total_tax_due == null ? '—' : fmtMoney(b.total_tax_due)} />
                          <Field label="Outstanding" value={b.outstanding_balance == null ? '—' : fmtMoney(b.outstanding_balance)} />
                          <Field label="Date logged" value={b.date_logged ? fmtDate(b.date_logged) : '—'} />
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div>
                <SectionLabel>Discovery / unregistered record</SectionLabel>
                {discoveries.length === 0 ? (
                  <EmptyLine text="No discovery record linked. This property was logged directly as a LITAS bill." />
                ) : (
                  <div className="space-y-2">
                    {discoveries.map((d) => (
                      <div key={d.id} className="rounded-xl border border-slate-200 px-4 py-3">
                        <div className="flex flex-wrap gap-2 items-center">
                          <span className="font-semibold text-navy-800">{d.discovery_reference}</span>
                          <Badge tone={discoveryTone(d.status)}>{d.status}</Badge>
                          {d.decision_path && <Badge tone="navy">Path {d.decision_path === 'valuation' ? 'B - Valuation' : 'A - Account'}</Badge>}
                          {d.valuation_id && <Badge tone="blue">has valuation</Badge>}
                        </div>
                        <div className="mt-2 grid grid-cols-2 sm:grid-cols-3 gap-2 text-sm">
                          <Field label="Owner" value={d.owner_name || '—'} />
                          <Field label="Address" value={d.property_address || '—'} />
                          <Field label="LITAS Property ID" value={d.property_id || '— (unregistered)'} mono />
                          <Field label="LITAS Document #" value={d.document_number || '—'} mono />
                          <Field label="Discovered" value={d.discovery_date ? fmtDate(d.discovery_date) : '—'} />
                          <Field label="Discovered by" value={d.discovered_by || '—'} />
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div>
                <SectionLabel>Valuation / reassessment history</SectionLabel>
                {valuations.length === 0 ? (
                  <EmptyLine text="No valuation / reassessment record for this property." />
                ) : (
                  <div className="space-y-2">
                    {valuations.map((v) => (
                      <div key={v.id} className="rounded-xl border border-slate-200 px-4 py-3">
                        <div className="flex flex-wrap gap-2 items-center">
                          <span className="font-semibold text-navy-800">{v.valuation_reference}</span>
                          <Badge tone={valuationTone(v.status)}>{v.status}</Badge>
                          <Badge tone={v.valuation_type === 'reassessment' ? 'amber' : 'slate'}>{v.valuation_type === 'reassessment' ? 'Reassessment' : 'New property'}</Badge>
                        </div>
                        <div className="mt-2 grid grid-cols-2 sm:grid-cols-3 gap-2 text-sm">
                          <Field label="Property ID" value={v.property_id || '—'} mono />
                          <Field label="Assessed value" value={v.assessed_value ? fmtMoney(v.assessed_value) : '—'} />
                          <Field label="Approx annual tax" value={v.annual_tax ? fmtMoney(v.annual_tax) : '—'} />
                          <Field label="Officer" value={v.valuation_officer || '—'} />
                          <Field label="Date" value={v.created_at ? fmtDate(v.created_at) : '—'} />
                          <Field label="LITAS" value={v.litas_processing_status || '—'} />
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  )
}

function SectionLabel({ children }) {
  return <div className="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">{children}</div>
}

function EmptyLine({ text }) {
  return <p className="text-sm text-slate-400">{text}</p>
}

const discoveryTone = (s) => (['PROCESSED_IN_LITAS', 'COMPLETED'].includes(s) ? 'green' : s === 'AC_REJECTED' || s === 'RETURNED_FOR_CORRECTION' ? 'red' : 'navy')
const valuationTone = (s) => (s === 'Approved' ? 'green' : s === 'Rejected' ? 'red' : s === 'Returned' ? 'amber' : 'navy')