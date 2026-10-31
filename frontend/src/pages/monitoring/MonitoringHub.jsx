import { Fragment, useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useAuth } from '../../auth'
import api, { errMsg } from '../../api'

const PRIORITIES = ['Low', 'Normal', 'High', 'Urgent']
const SEVERITIES = ['Low', 'Moderate', 'High']
const QUERY_STATUSES = ['Open', 'Answered', 'Closed']
const FLAG_STATUSES = ['Open', 'Resolved']
const APPEAL_STATUSES = ['Submitted', 'Under Review', 'Upheld', 'Adjusted', 'Dismissed', 'Withdrawn']

const TABS = [
  { key: 'review-board', label: 'Review Board', icon: '🗂️', need: ['me.view', 'me.assign_walkin', 'me.review'] },
  { key: 'queries', label: 'Queries', icon: '❓', need: ['me.view', 'me.create', 'me.respond', 'me.close'] },
  { key: 'flags', label: 'Data Quality Flags', icon: '🚩', need: ['me.view', 'me.flag_data_quality'] },
  { key: 'appeals', label: 'Appeals', icon: '⚖️', need: ['me.view', 'me.create', 'valuation.approve'] },
]

const money = (v) => (v === null || v === undefined ? '—' : `RWF ${Number(v).toLocaleString()}`)
const dt = (v) => (v ? new Date(v).toLocaleString() : '—')

function Badge({ children, tone = 'slate' }) {
  const tones = {
    slate: 'bg-slate-100 text-slate-600',
    emerald: 'bg-emerald-100 text-emerald-700',
    amber: 'bg-amber-100 text-amber-700',
    red: 'bg-red-100 text-red-700',
    blue: 'bg-sky-100 text-sky-700',
  }
  return <span className={`inline-block text-[11px] px-2 py-0.5 rounded-full font-semibold ${tones[tone]}`}>{children}</span>
}

function statusTone(s) {
  if (['Approved', 'Resolved', 'Upheld', 'Closed', 'Assigned', 'Completed'].includes(s)) return 'emerald'
  if (['Rejected', 'Urgent'].includes(s)) return 'red'
  if (['High', 'Pending'].includes(s)) return 'amber'
  if (s === 'Open') return 'blue'
  return 'slate'
}

function Section({ title, sub, children }) {
  return (
    <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 space-y-4">
      <div>
        <h2 className="font-semibold">{title}</h2>
        {sub && <p className="text-xs text-slate-400 mt-0.5">{sub}</p>}
      </div>
      {children}
    </div>
  )
}

function Notice({ kind, msg, onClose }) {
  if (!msg) return null
  const cls = kind === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-emerald-50 border-emerald-200 text-emerald-800'
  return (
    <div className={`rounded-xl border ${cls} text-sm px-4 py-3 flex items-start justify-between gap-3`}>
      <span>{msg}</span>
      {onClose && <button onClick={onClose} className="shrink-0 opacity-60 hover:opacity-100">✕</button>}
    </div>
  )
}

const inputCls = 'w-full px-3 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500'
const btnCls = (extra = '') => `px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition ${extra}`

/** Officer picker shared by walk-in assignment / reassignment. */
function OfficerSelect({ officers, value, onChange }) {
  return (
    <select value={value} onChange={(e) => onChange(e.target.value)} className={inputCls}>
      <option value="">Select officer…</option>
      {officers.map((o) => (
        <option key={o.id} value={o.id}>{o.full_name} ({o.role || '—'} {o.staff_id ? `· ${o.staff_id}` : ''})</option>
      ))}
    </select>
  )
}

/** Bill document lookup used by flag + appeal creation. */
function BillPicker({ onPick }) {
  const [q, setQ] = useState('')
  const [results, setResults] = useState([])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const search = async (e) => {
    e.preventDefault()
    if (q.trim().length < 2) return
    setBusy(true)
    setErr('')
    try {
      const r = await api.get('/search', { params: { q: q.trim() } })
      setResults(r.data.data || [])
    } catch (e2) {
      setErr(errMsg(e2, 'Search failed.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-2">
      <form onSubmit={search} className="flex gap-2">
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Document no. / property ID / TIN / taxpayer" className={inputCls} />
        <button type="submit" className={btnCls('shrink-0')} disabled={busy}>{busy ? '…' : 'Find bill'}</button>
      </form>
      {err && <p className="text-xs text-red-600">{err}</p>}
      {results.map((b) => (
        <button key={b.id} onClick={() => onPick(b)}
          className="w-full text-left rounded-xl border border-slate-200 hover:border-brand-400 hover:bg-brand-50 px-3 py-2 text-sm transition">
          <span className="font-mono font-semibold">{b.document_number}</span>
          <span className="text-slate-500"> — {b.taxpayer_name}</span>
          <span className="ml-2 text-xs text-slate-400">outstanding {money(b.outstanding_balance)}</span>
        </button>
      ))}
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Review Board — walk-in queue + aging/overdue tasks                  */
/* ------------------------------------------------------------------ */

function ReviewBoardTab({ officers }) {
  const { user, can } = useAuth()
  const [rows, setRows] = useState([])
  const [filter, setFilter] = useState('all')
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [notice, setNotice] = useState('')
  const [openTask, setOpenTask] = useState(null)
  const [form, setForm] = useState({ officer_id: '', priority: '', due_date: '', notes: '' })
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setErr('')
    try {
      const params = {}
      if (filter === 'walkin') params.walkin = '1'
      if (filter === 'aging') params.aging = '1'
      const r = await api.get('/me/review-board', { params })
      setRows(r.data.data || [])
    } catch (e) {
      setErr(errMsg(e, 'Could not load the review board.'))
    } finally {
      setLoading(false)
    }
  }, [filter])

  useEffect(() => { load() }, [load])

  const isEnforcementAssignmentManager = ['Enforcement Manager', 'System Administrator'].includes(user?.role)
  const canAssign = isEnforcementAssignmentManager && can('me.assign_walkin')
  const canReassign = can('me.reassign', 'tasks.reassign')
  const canRevise = can('me.revise')

  const submit = async (action) => {
    if (!openTask) return
    if (action !== 'revise' && !form.officer_id) {
      setErr('Choose an officer first.')
      return
    }
    setSaving(true)
    setErr('')
    try {
      const payload = action === 'assign' ? { officer_id: Number(form.officer_id) } : { officer_id: Number(form.officer_id) }
      if (action === 'revise') {
        const body = {}
        if (form.priority) body.priority = form.priority
        if (form.due_date) body.due_date = form.due_date
        if (form.notes) body.notes = form.notes
        await api.post(`/me/tasks/${openTask.id}/revise`, body)
      } else {
        const endpoint = action === 'assign' ? 'assign-walkin' : 'reassign'
        await api.post(`/me/tasks/${openTask.id}/${endpoint}`, payload)
      }
      setNotice(action === 'assign' ? 'Walk-in case assigned.' : action === 'reassign' ? 'Task reassigned.' : 'Task revised.')
      setOpenTask(null)
      setForm({ officer_id: '', priority: '', due_date: '', notes: '' })
      await load()
    } catch (e2) {
      setErr(errMsg(e2))
    } finally {
      setSaving(false)
    }
  }

  const open = (task, action) => {
    setOpenTask({ ...task, action })
    setErr('')
    setNotice('')
  }

  return (
    <div className="space-y-5">
      <Notice kind="error" msg={err} onClose={() => setErr('')} />
      <Notice kind="ok" msg={notice} onClose={() => setNotice('')} />

      <Section title="Case review" sub="Walk-in cases can be assigned to field officers; overdue work is flagged for attention.">
        <div className="flex gap-2 flex-wrap">
          {[['all', 'All tasks'], ['walkin', 'Walk-in queue'], ['aging', 'Aging / overdue']].map(([k, label]) => (
            <button key={k} onClick={() => setFilter(k)}
              className={`px-3 py-1.5 rounded-xl text-sm font-semibold transition ${filter === k ? 'bg-navy-800 text-white' : 'bg-slate-100 hover:bg-slate-200 text-slate-600'}`}>
              {label}
            </button>
          ))}
        </div>

        {loading ? (
          <div className="text-sm text-slate-400">Loading…</div>
        ) : rows.length === 0 ? (
          <div className="text-sm text-slate-400">No tasks in this view.</div>
        ) : (
          <div className="overflow-x-auto -mx-5 px-5">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-wider text-slate-400 border-b border-slate-100">
                  <th className="py-2 pr-3">Reference</th>
                  <th className="py-2 pr-3">Type</th>
                  <th className="py-2 pr-3">Status</th>
                  <th className="py-2 pr-3">Priority</th>
                  <th className="py-2 pr-3">Due</th>
                  <th className="py-2 pr-3">Officer</th>
                  <th className="py-2 pr-3">Bill</th>
                  <th className="py-2 pr-3">Balance</th>
                  <th className="py-2">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((t) => (
                  <Fragment key={t.id}>
                    <tr className="border-b border-slate-100 align-top">
                      <td className="py-2.5 pr-3 font-mono text-xs font-semibold">{t.task_reference}</td>
                      <td className="py-2.5 pr-3">{t.task_type}</td>
                      <td className="py-2.5 pr-3"><Badge tone={statusTone(t.status)}>{t.status}</Badge></td>
                      <td className="py-2.5 pr-3"><Badge tone={statusTone(t.priority)}>{t.priority}</Badge></td>
                      <td className="py-2.5 pr-3">{t.due_date || '—'}</td>
                      <td className="py-2.5 pr-3">{t.assigned_to || '—'}</td>
                      <td className="py-2.5 pr-3">
                        <div className="font-mono text-xs">{t.bill?.document_number || '—'}</div>
                        <div className="text-xs text-slate-500">{t.bill?.taxpayer_name || ''}</div>
                        {t.bill?.recipient_type === 'Walk-in Taxpayer' && <span className="text-[10px] text-amber-700">walk-in</span>}
                      </td>
                      <td className="py-2.5 pr-3 text-xs">{money(t.bill?.outstanding_balance)}</td>
                      <td className="py-2.5">
                        <div className="flex gap-1.5 flex-wrap">
                          {canAssign && t.bill?.recipient_type === 'Walk-in Taxpayer' && (
                            <button onClick={() => open(t, 'assign')} className="text-xs px-2.5 py-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-semibold">Assign</button>
                          )}
                          {canReassign && (t.task_type !== 'Bill Delivery' || isEnforcementAssignmentManager) && (
                            <button onClick={() => open(t, 'reassign')} className="text-xs px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold">Reassign</button>
                          )}
                          {canRevise && (
                            <button onClick={() => open(t, 'revise')} className="text-xs px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold">Revise</button>
                          )}
                        </div>
                      </td>
                    </tr>
                    {openTask?.id === t.id && (
                      <tr className="border-b border-slate-100 bg-slate-50/60">
                        <td colSpan={9} className="py-3 px-4">
                          <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-2 items-end">
                            {openTask.action !== 'revise' && (
                              <div>
                                <div className="text-[11px] text-slate-500 mb-1">Target officer</div>
                                <OfficerSelect officers={officers} value={form.officer_id} onChange={(v) => setForm((f) => ({ ...f, officer_id: v }))} />
                              </div>
                            )}
                            {openTask.action === 'revise' && (
                              <>
                                <div>
                                  <div className="text-[11px] text-slate-500 mb-1">Priority</div>
                                  <select value={form.priority} onChange={(e) => setForm((f) => ({ ...f, priority: e.target.value }))} className={inputCls}>
                                    <option value="">Keep current</option>
                                    {PRIORITIES.map((p) => <option key={p} value={p}>{p}</option>)}
                                  </select>
                                </div>
                                <div>
                                  <div className="text-[11px] text-slate-500 mb-1">New due date</div>
                                  <input type="date" value={form.due_date} onChange={(e) => setForm((f) => ({ ...f, due_date: e.target.value }))} className={inputCls} />
                                </div>
                                <div>
                                  <div className="text-[11px] text-slate-500 mb-1">Notes</div>
                                  <input value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} placeholder="Revision reason" className={inputCls} />
                                </div>
                              </>
                            )}
                            <div className={`${openTask.action === 'revise' ? '' : 'sm:col-span-1'}`}>
                              <button onClick={() => submit(openTask.action)} disabled={saving} className={`${btnCls('w-full')} disabled:opacity-50`}>
                                {saving ? 'Saving…' : openTask.action === 'assign' ? 'Assign walk-in' : openTask.action === 'reassign' ? 'Reassign' : 'Apply revision'}
                              </button>
                            </div>
                          </div>
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Section>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Queries                                                             */
/* ------------------------------------------------------------------ */

function QueriesTab() {
  const { can } = useAuth()
  const [rows, setRows] = useState([])
  const [status, setStatus] = useState('')
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [notice, setNotice] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const [respondTo, setRespondTo] = useState(null)
  const [response, setResponse] = useState('')
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({ title: '', description: '', priority: 'Normal', assigned_to: '' })

  const canCreate = can('me.create')
  const canRespond = can('me.respond')
  const canClose = can('me.close')

  const load = useCallback(async () => {
    setLoading(true)
    setErr('')
    try {
      const r = await api.get('/me/queries', { params: { status: status || undefined, per_page: 50 } })
      setRows(r.data.data?.data || r.data.data || [])
    } catch (e) {
      setErr(errMsg(e, 'Could not load queries.'))
    } finally {
      setLoading(false)
    }
  }, [status])

  useEffect(() => { load() }, [load])

  const create = async (e) => {
    e.preventDefault()
    setSaving(true)
    setErr('')
    try {
      const body = { ...form }
      if (body.assigned_to) body.assigned_to = Number(body.assigned_to)
      else delete body.assigned_to
      await api.post('/me/queries', body)
      setNotice('Query raised.')
      setShowCreate(false)
      setForm({ title: '', description: '', priority: 'Normal', assigned_to: '' })
      await load()
    } catch (e2) {
      setErr(errMsg(e2))
    } finally {
      setSaving(false)
    }
  }

  const respond = async (q) => {
    setSaving(true)
    setErr('')
    try {
      await api.post(`/me/queries/${q.id}/respond`, { response })
      setNotice(`Query ${q.query_reference} answered.`)
      setRespondTo(null)
      setResponse('')
      await load()
    } catch (e2) {
      setErr(errMsg(e2))
    } finally {
      setSaving(false)
    }
  }

  const close = async (q) => {
    setSaving(true)
    setErr('')
    try {
      await api.post(`/me/queries/${q.id}/close`, {})
      setNotice(`Query ${q.query_reference} closed.`)
      await load()
    } catch (e2) {
      setErr(errMsg(e2))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-5">
      <Notice kind="error" msg={err} onClose={() => setErr('')} />
      <Notice kind="ok" msg={notice} onClose={() => setNotice('')} />

      {canCreate && (
        <Section title="Raise a new query">
          {showCreate ? (
            <form onSubmit={create} className="space-y-3">
              <input required value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} placeholder="Title" className={inputCls} />
              <textarea required value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} rows={3} placeholder="Describe the issue" className={inputCls} />
              <div className="grid sm:grid-cols-2 gap-2">
                <select value={form.priority} onChange={(e) => setForm((f) => ({ ...f, priority: e.target.value }))} className={inputCls}>
                  {PRIORITIES.map((p) => <option key={p} value={p}>{p}</option>)}
                </select>
                <input value={form.assigned_to} onChange={(e) => setForm((f) => ({ ...f, assigned_to: e.target.value }))} placeholder="Assignee user ID (optional)" className={inputCls} />
              </div>
              <div className="flex gap-2">
                <button type="submit" disabled={saving} className={`${btnCls()} disabled:opacity-50`}>{saving ? 'Raising…' : 'Raise query'}</button>
                <button type="button" onClick={() => setShowCreate(false)} className="px-4 py-2 rounded-xl text-sm font-semibold text-slate-500 hover:bg-slate-100">Cancel</button>
              </div>
            </form>
          ) : (
            <button onClick={() => setShowCreate(true)} className={btnCls()}>+ New query…</button>
          )}
        </Section>
      )}

      <Section title="Queries" sub="M&E raises, answers and closes monitoring queries.">
        <div className="flex gap-2 flex-wrap">
          {['', ...QUERY_STATUSES].map((s) => (
            <button key={s || 'all'} onClick={() => setStatus(s)}
              className={`px-3 py-1.5 rounded-xl text-sm font-semibold transition ${status === s ? 'bg-navy-800 text-white' : 'bg-slate-100 hover:bg-slate-200 text-slate-600'}`}>
              {s || 'All'}
            </button>
          ))}
        </div>

        {loading ? (
          <div className="text-sm text-slate-400">Loading…</div>
        ) : rows.length === 0 ? (
          <div className="text-sm text-slate-400">No queries.</div>
        ) : (
          <div className="space-y-3">
            {rows.map((q) => (
              <div key={q.id} className="border border-slate-200 rounded-xl p-3 text-sm">
                <div className="flex items-center justify-between flex-wrap gap-2">
                  <div className="font-mono font-semibold text-xs">{q.query_reference}</div>
                  <div className="flex gap-1.5">
                    <Badge tone={statusTone(q.priority)}>{q.priority}</Badge>
                    <Badge tone={statusTone(q.status)}>{q.status}</Badge>
                  </div>
                </div>
                <div className="mt-1 font-semibold">{q.title}</div>
                <div className="text-xs text-slate-500 mt-1 whitespace-pre-wrap">{q.description}</div>
                <div className="text-xs text-slate-400 mt-2">
                  Raised by #{q.raised_by} · Assigned: {q.assigned_to || '—'} · {dt(q.created_at)}
                </div>
                {q.response && (
                  <div className="mt-2 text-xs bg-slate-50 rounded-xl px-3 py-2">
                    <div className="font-semibold text-slate-500 mb-1">Response</div>
                    {q.response}
                  </div>
                )}
                <div className="flex gap-2 mt-3 flex-wrap">
                  {canRespond && q.status !== 'Closed' && (
                    respondTo?.id === q.id ? (
                      <div className="w-full space-y-2">
                        <textarea value={response} onChange={(e) => setResponse(e.target.value)} rows={3} placeholder="Your response…" className={inputCls} />
                        <div className="flex gap-2">
                          <button onClick={() => respond(q)} disabled={saving || !response.trim()} className={`${btnCls()} disabled:opacity-50`}>{saving ? 'Sending…' : 'Submit response'}</button>
                          <button onClick={() => { setRespondTo(null); setResponse('') }} className="px-4 py-2 rounded-xl text-sm font-semibold text-slate-500 hover:bg-slate-100">Cancel</button>
                        </div>
                      </div>
                    ) : (
                      <button onClick={() => { setRespondTo(q); setResponse(''); setErr('') }} className="text-xs px-2.5 py-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-semibold">Respond</button>
                    )
                  )}
                  {canClose && q.status !== 'Closed' && (
                    <button onClick={() => close(q)} disabled={saving} className="text-xs px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold disabled:opacity-50">Close</button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </Section>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Data quality flags                                                  */
/* ------------------------------------------------------------------ */

function FlagsTab() {
  const { can } = useAuth()
  const [rows, setRows] = useState([])
  const [status, setStatus] = useState('')
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [notice, setNotice] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const [bill, setBill] = useState(null)
  const [issue, setIssue] = useState('')
  const [severity, setSeverity] = useState('Moderate')
  const [saving, setSaving] = useState(false)

  const canFlag = can('me.flag_data_quality')
  const canResolve = can('me.flag_data_quality', 'me.responsible')

  const load = useCallback(async () => {
    setLoading(true)
    setErr('')
    try {
      const r = await api.get('/me/flags', { params: { status: status || undefined, per_page: 50 } })
      setRows(r.data.data?.data || r.data.data || [])
    } catch (e) {
      setErr(errMsg(e, 'Could not load flags.'))
    } finally {
      setLoading(false)
    }
  }, [status])

  useEffect(() => { load() }, [load])

  const createFlag = async (e) => {
    e.preventDefault()
    if (!bill) { setErr('Pick a bill to flag.'); return }
    setSaving(true)
    setErr('')
    try {
      await api.post('/me/flags', { bill_id: bill.id, issue: issue.trim(), severity })
      setNotice('Data-quality issue flagged.')
      setShowCreate(false)
      setBill(null)
      setIssue('')
      setSeverity('Moderate')
      await load()
    } catch (e2) {
      setErr(errMsg(e2))
    } finally {
      setSaving(false)
    }
  }

  const resolve = async (f) => {
    setSaving(true)
    setErr('')
    try {
      await api.patch(`/me/flags/${f.id}/resolve`, { remarks: 'Resolved by M&E.' })
      setNotice('Flag resolved.')
      await load()
    } catch (e2) {
      setErr(errMsg(e2))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-5">
      <Notice kind="error" msg={err} onClose={() => setErr('')} />
      <Notice kind="ok" msg={notice} onClose={() => setNotice('')} />

      {canFlag && (
        <Section title="Flag a data-quality issue" sub="Attach the issue to a bill so the responsible section can act.">
          {showCreate ? (
            <form onSubmit={createFlag} className="space-y-3">
              <div className="text-[11px] text-slate-500 uppercase font-semibold">{bill ? `Bill ${bill.document_number} — ${bill.taxpayer_name}` : 'Select a bill'}</div>
              {!bill && <BillPicker onPick={(b) => setBill(b)} />}
              <input required value={issue} onChange={(e) => setIssue(e.target.value)} placeholder="Issue (e.g. taxpayer name mismatch with source record)" className={inputCls} />
              <select value={severity} onChange={(e) => setSeverity(e.target.value)} className={inputCls}>
                {SEVERITIES.map((s) => <option key={s} value={s}>{s}</option>)}
              </select>
              <div className="flex gap-2">
                <button type="submit" disabled={saving} className={`${btnCls()} disabled:opacity-50`}>{saving ? 'Flagging…' : 'Raise flag'}</button>
                <button type="button" onClick={() => { setShowCreate(false); setBill(null) }} className="px-4 py-2 rounded-xl text-sm font-semibold text-slate-500 hover:bg-slate-100">Cancel</button>
              </div>
            </form>
          ) : (
            <button onClick={() => setShowCreate(true)} className={btnCls()}>+ New flag…</button>
          )}
        </Section>
      )}

      <Section title="Flags">
        <div className="flex gap-2 flex-wrap">
          {['', ...FLAG_STATUSES].map((s) => (
            <button key={s || 'all'} onClick={() => setStatus(s)}
              className={`px-3 py-1.5 rounded-xl text-sm font-semibold transition ${status === s ? 'bg-navy-800 text-white' : 'bg-slate-100 hover:bg-slate-200 text-slate-600'}`}>
              {s || 'All'}
            </button>
          ))}
        </div>

        {loading ? (
          <div className="text-sm text-slate-400">Loading…</div>
        ) : rows.length === 0 ? (
          <div className="text-sm text-slate-400">No flags.</div>
        ) : (
          <div className="space-y-3">
            {rows.map((f) => (
              <div key={f.id} className="border border-slate-200 rounded-xl p-3 text-sm">
                <div className="flex items-center justify-between flex-wrap gap-2">
                  <div className="font-mono text-xs font-semibold">{f.bill?.document_number || `Bill #${f.bill_id}`}</div>
                  <div className="flex gap-1.5">
                    <Badge tone={statusTone(f.severity)}>{f.severity}</Badge>
                    <Badge tone={statusTone(f.status)}>{f.status}</Badge>
                  </div>
                </div>
                <div className="mt-1">{f.issue}</div>
                <div className="text-xs text-slate-400 mt-1">
                  {f.bill?.taxpayer_name || ''} · flagged by {f.flagged_by?.full_name || `#${f.flagged_by}`}
                </div>
                {canResolve && f.status === 'Open' && (
                  <button onClick={() => resolve(f)} disabled={saving} className="mt-2 text-xs px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold disabled:opacity-50">Mark resolved</button>
                )}
              </div>
            ))}
          </div>
        )}
      </Section>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Appeals                                                             */
/* ------------------------------------------------------------------ */

function AppealsTab() {
  const { can } = useAuth()
  const [rows, setRows] = useState([])
  const [status, setStatus] = useState('')
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [notice, setNotice] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const [bill, setBill] = useState(null)
  const [reason, setReason] = useState('')
  const [description, setDescription] = useState('')
  const [decideFor, setDecideFor] = useState(null)
  const [decision, setDecision] = useState('adjusted')
  const [notes, setNotes] = useState('')
  const [saving, setSaving] = useState(false)

  const canCreate = can('me.create')
  const canDecide = can('valuation.approve')
  const decided = (s) => ['Upheld', 'Adjusted', 'Dismissed', 'Withdrawn'].includes(s)

  const load = useCallback(async () => {
    setLoading(true)
    setErr('')
    try {
      const r = await api.get('/appeals', { params: { status: status || undefined, per_page: 50 } })
      setRows(r.data.data?.data || r.data.data || [])
    } catch (e) {
      setErr(errMsg(e, 'Could not load appeals.'))
    } finally {
      setLoading(false)
    }
  }, [status])

  useEffect(() => { load() }, [load])

  const createAppeal = async (e) => {
    e.preventDefault()
    if (!bill) { setErr('Pick a bill to appeal.'); return }
    setSaving(true)
    setErr('')
    try {
      await api.post('/appeals', { bill_id: bill.id, reason: reason.trim(), description: description.trim() })
      setNotice('Appeal filed.')
      setShowCreate(false)
      setBill(null)
      setReason('')
      setDescription('')
      await load()
    } catch (e2) {
      setErr(errMsg(e2))
    } finally {
      setSaving(false)
    }
  }

  const decide = async (a) => {
    setSaving(true)
    setErr('')
    try {
      await api.post(`/appeals/${a.id}/decide`, { decision, notes: notes.trim() })
      setNotice(`Appeal ${a.appeal_reference} decided.`)
      setDecideFor(null)
      setNotes('')
      await load()
    } catch (e2) {
      setErr(errMsg(e2))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-5">
      <Notice kind="error" msg={err} onClose={() => setErr('')} />
      <Notice kind="ok" msg={notice} onClose={() => setNotice('')} />

      {canCreate && (
        <Section title="File an appeal" sub="Appeals are recorded against a bill and decided by management.">
          {showCreate ? (
            <form onSubmit={createAppeal} className="space-y-3">
              <div className="text-[11px] text-slate-500 uppercase font-semibold">{bill ? `Bill ${bill.document_number} — ${bill.taxpayer_name}` : 'Select a bill'}</div>
              {!bill && <BillPicker onPick={(b) => setBill(b)} />}
              <input required value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason (e.g. Assessment disputed)" className={inputCls} />
              <textarea required value={description} onChange={(e) => setDescription(e.target.value)} rows={3} placeholder="Details of the appeal" className={inputCls} />
              <div className="flex gap-2">
                <button type="submit" disabled={saving} className={`${btnCls()} disabled:opacity-50`}>{saving ? 'Filing…' : 'File appeal'}</button>
                <button type="button" onClick={() => { setShowCreate(false); setBill(null) }} className="px-4 py-2 rounded-xl text-sm font-semibold text-slate-500 hover:bg-slate-100">Cancel</button>
              </div>
            </form>
          ) : (
            <button onClick={() => setShowCreate(true)} className={btnCls()}>+ New appeal…</button>
          )}
        </Section>
      )}

      <Section title="Appeals">
        <div className="flex gap-2 flex-wrap">
          {['', ...APPEAL_STATUSES].map((s) => (
            <button key={s || 'all'} onClick={() => setStatus(s)}
              className={`px-3 py-1.5 rounded-xl text-sm font-semibold transition ${status === s ? 'bg-navy-800 text-white' : 'bg-slate-100 hover:bg-slate-200 text-slate-600'}`}>
              {s || 'All'}
            </button>
          ))}
        </div>

        {loading ? (
          <div className="text-sm text-slate-400">Loading…</div>
        ) : rows.length === 0 ? (
          <div className="text-sm text-slate-400">No appeals.</div>
        ) : (
          <div className="space-y-3">
            {rows.map((a) => (
              <div key={a.id} className="border border-slate-200 rounded-xl p-3 text-sm">
                <div className="flex items-center justify-between flex-wrap gap-2">
                  <div className="font-mono font-semibold text-xs">{a.appeal_reference}</div>
                  <div className="flex gap-1.5">
                    <Badge tone={statusTone(a.status)}>{a.status}</Badge>
                    {a.decision && <Badge tone="slate">{a.decision}</Badge>}
                  </div>
                </div>
                <div className="mt-1 font-semibold">{a.reason}</div>
                <div className="text-xs text-slate-500 mt-1 whitespace-pre-wrap">{a.description}</div>
                <div className="text-xs text-slate-400 mt-1">
                  Bill <span className="font-mono">{a.document_number}</span> · {a.taxpayer_name} · filed by #{a.raised_by}
                </div>
                {decideFor?.id === a.id ? (
                  <div className="mt-3 space-y-2 bg-slate-50 rounded-xl p-3">
                    <select value={decision} onChange={(e) => setDecision(e.target.value)} className={inputCls}>
                      <option value="upheld">Upheld</option>
                      <option value="adjusted">Adjusted</option>
                      <option value="dismissed">Dismissed</option>
                    </select>
                    <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} placeholder="Decision notes (required)" className={inputCls} />
                    <div className="flex gap-2">
                      <button onClick={() => decide(a)} disabled={saving || !notes.trim()} className={`${btnCls()} disabled:opacity-50`}>{saving ? 'Saving…' : 'Record decision'}</button>
                      <button onClick={() => { setDecideFor(null); setNotes('') }} className="px-4 py-2 rounded-xl text-sm font-semibold text-slate-500 hover:bg-slate-100">Cancel</button>
                    </div>
                  </div>
                ) : (
                  canDecide && !decided(a.status) && (
                    <button onClick={() => { setDecideFor(a); setErr('') }} className="mt-2 text-xs px-2.5 py-1 rounded-lg bg-navy-800 hover:bg-navy-700 text-white font-semibold">
                      Decide appeal
                    </button>
                  )
                )}
              </div>
            ))}
          </div>
        )}
      </Section>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Page                                                                */
/* ------------------------------------------------------------------ */

export default function MonitoringHub() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const request = params.get('tab') || 'review-board'

  const allowed = useMemo(() => TABS.filter((t) => can(...t.need)), [can])
  const active = allowed.some((t) => t.key === request) ? request : (allowed[0]?.key || 'review-board')

  const [officers, setOfficers] = useState([])
  useEffect(() => {
    if (!can('me.assign_walkin', 'me.reassign', 'me.revise')) return
    api.get('/users', { params: { role: 'Enforcement Officer', per_page: 100 } })
      .then((r) => setOfficers(r.data.data?.data || r.data.data || []))
      .catch(() => {})
  }, [can])

  return (
    <div className="max-w-6xl space-y-5">
      <div>
        <h1 className="text-2xl font-bold">Monitoring &amp; Evaluation</h1>
        <p className="text-sm text-slate-500 mt-1">Walk-in case assignment, query management, quality flags and taxpayer appeals.</p>
      </div>

      <div className="flex gap-1.5 flex-wrap border-b border-slate-200 pb-px">
        {allowed.map((t) => (
          <button key={t.key} onClick={() => setParams({ tab: t.key })}
            className={`px-4 py-2 rounded-t-xl text-sm font-semibold transition -mb-px border-b-2 ${active === t.key ? 'text-brand-600 border-brand-500 bg-brand-50' : 'text-slate-500 border-transparent hover:text-navy-800 hover:bg-slate-50'}`}>
            {t.icon} {t.label}
          </button>
        ))}
      </div>

      {active === 'review-board' && <ReviewBoardTab officers={officers} />}
      {active === 'queries' && <QueriesTab />}
      {active === 'flags' && <FlagsTab />}
      {active === 'appeals' && <AppealsTab />}
    </div>
  )
}