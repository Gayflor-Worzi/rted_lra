import { useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import { useAuth } from '../auth'
import { Card, Stat, Badge, Btn, Input, Spinner, ErrorBox, PageTitle, statusTone } from '../ui'
import BillDrawer from '../components/BillDrawer'
import PropertyDetail from '../components/PropertyDetail'

const ACCOUNT_TYPES = ['Account & Record Processing', 'Account & Record Manager Review', 'Discovery Review']
const PER_PAGE = 20

const DISCOVERY_STATUSES = [
  'Draft', 'Submitted', 'Classified',
  'Awaiting Account Manager Review', 'Awaiting Valuation', 'Under Valuation',
  'Awaiting AC Approval', 'Approved',
  'Assigned to Account & Record', 'Referred to Valuation',
  'Registered', 'Completed',
  'Returned for Correction', 'Rejected',
]

const value = (v, fb = '—') => (v === null || v === undefined || v === '' ? fb : v)

function StatusBadge({ status }) {
  return <Badge tone={statusTone(status)}>{status}</Badge>
}

function Field({ l, v }) {
  return (
    <div className="min-w-0">
      <div className="text-[10px] uppercase tracking-wide text-slate-400">{l}</div>
      <div className="text-sm text-navy-800 break-words">{value(v)}</div>
    </div>
  )
}

/* Discovery detail — one shared Property View for the record plus the A&R
   workflow actions. The view itself (workflow ladder, identity, value
   declaration with its sources, location/GPS, evidence, property history) is
   rendered by PropertyDetail so this screen can never drift from the one the
   bill, valuation and enforcement screens show. */
function DiscoveryModal({ id, onClose, onChanged, users }) {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const [billResult, setBillResult] = useState(null)

  const load = () => api.get(`/discoveries/${id}`).then((r) => unwrap(r).data)

  useEffect(() => {
    load().then(setD).catch((e) => setErr(errMsg(e, 'Unable to load this discovery.')))
  }, [id])

  // Records the bill the processing result wrote (or updated), so the officer
  // sees the outcome of the upsert rather than just a success toast.
  const onActionDone = async (verb, result) => {
    if (verb === 'account-processing') {
      const payload = result?.data || result
      if (payload?.bill) setBillResult({ bill: payload.bill, action: payload.bill_action || payload.bill?.outcome })
    }
    setD(await load())
    onChanged?.()
  }

  // every discovery workflow verb is its own route segment
  const runAction = async (verb, payload) => unwrap(await api.post(`/discoveries/${id}/${verb}`, payload))

  if (err && !d) {
    return (
      <div className="fixed inset-0 z-50 grid place-items-center p-4">
        <div className="bg-white rounded-2xl shadow-xl p-6 text-sm text-red-600">{err}</div>
      </div>
    )
  }
  if (!d) return null

  return (
    <>
      {billResult && (
        <div className="fixed top-4 left-1/2 -translate-x-1/2 z-[70] rounded-xl bg-emerald-50 text-emerald-700 text-sm px-4 py-2 shadow-lg border border-emerald-200">
          <div>Processing result recorded — the discovery task was completed automatically.</div>
          <div>
            Bill <span className="font-semibold">{billResult.bill.document_number}</span>{' '}
            {billResult.action === 'created' ? 'created' : 'updated'} for Tax Period {billResult.bill.tax_period || '—'}{' '}
            (US$ {Number(billResult.bill.total_tax_due || 0).toLocaleString()}) by {billResult.bill.logged_by}.
          </div>
        </div>
      )}
      <PropertyDetail
        discovery={d}
        title="My Discovery"
        assignmentUsers={users}
        onClose={onClose}
        runAction={runAction}
        onActionDone={onActionDone}
      />
    </>
  )
}

/* Valuation detail — the same shared Property View, reached when a property arrived at
   this desk through the Valuation workflow instead of Discovery. PropertyDetail renders
   its VALUATION ASSESSMENT view, so both arrival routes show one approved design rather
   than this desk falling back to a bare field summary. The linked discovery is loaded
   alongside so the record keeps its full identity and history, and the action panel gates
   every control on the signed-in user's own permissions. */
function ValuationRecordModal({ id, onClose, onChanged, users }) {
  const [v, setV] = useState(null)
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')

  const load = async () => {
    const valuation = unwrap(await api.get(`/valuations/${id}`)).data
    let discovery = valuation?.discovery ?? null
    if (!discovery && valuation?.discovery_id) {
      discovery = unwrap(await api.get(`/discoveries/${valuation.discovery_id}`)).data
    }
    return { valuation, discovery }
  }

  useEffect(() => {
    let active = true
    load()
      .then((r) => {
        if (!active) return
        setV(r.valuation)
        setD(r.discovery)
      })
      .catch((e) => {
        if (active) setErr(errMsg(e, 'Unable to load this valuation.'))
      })
    return () => {
      active = false
    }
  }, [id])

  // Every valuation workflow verb is its own route segment. The verbs this desk cannot
  // perform are never offered by the action panel, so the mapping mirrors the valuation
  // register rather than duplicating any authorisation decision here.
  const runValuationAction = async (verb, payload = {}) => {
    if (verb === 'begin') return unwrap(await api.post(`/valuations/${id}/begin`))
    if (verb === 'submit') return unwrap(await api.post(`/valuations/${id}/submit`, payload))
    if (verb === 'assign') return unwrap(await api.post(`/valuations/${id}/assign`, payload))
    if (verb === 'forward') return unwrap(await api.post(`/valuations/${id}/review`, { decision: 'confirm_ac', remarks: payload.remarks }))
    if (verb === 'return') return unwrap(await api.post(`/valuations/${id}/review`, { decision: 'return', remarks: payload.remarks }))
    if (verb === 'approve') return unwrap(await api.post(`/valuations/${id}/decide`, { decision: 'approve', remarks: payload.remarks }))
    if (verb === 'reject') return unwrap(await api.post(`/valuations/${id}/decide`, { decision: 'reject', remarks: payload.remarks }))
    if (verb === 'decide-return') return unwrap(await api.post(`/valuations/${id}/decide`, { decision: 'return', remarks: payload.remarks }))
    if (verb === 'account-approve') return unwrap(await api.post(`/valuations/${id}/account-review`, { decision: 'approve', officer_id: payload.officer_id, remarks: payload.remarks }))
    if (verb === 'account-return') return unwrap(await api.post(`/valuations/${id}/account-review`, { decision: 'return', remarks: payload.remarks }))
    if (verb === 'processing') return unwrap(await api.post(`/valuations/${id}/processing`, payload))
    if (verb === 'complete') return unwrap(await api.post(`/valuations/${id}/complete`))
    throw new Error(`Unsupported valuation action: ${verb}`)
  }

  const onActionDone = async () => {
    const r = await load()
    setV(r.valuation)
    setD(r.discovery)
    onChanged?.()
  }

  if (err && !v) {
    return (
      <div className="fixed inset-0 z-50 grid place-items-center p-4">
        <div className="bg-white rounded-2xl shadow-xl p-6 text-sm text-red-600">{err}</div>
      </div>
    )
  }
  if (!v) return null

  return (
    <PropertyDetail
      valuation={v}
      discovery={d}
      title={`Valuation ${v.valuation_reference}`}
      assignmentUsers={users}
      onClose={onClose}
      runValuationAction={runValuationAction}
      onActionDone={onActionDone}
    />
  )
}

function TaskModal({ task, onClose }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <div className="relative w-full max-w-lg bg-white rounded-2xl shadow-xl p-5 space-y-3">
        <div className="flex items-center justify-between">
          <div className="font-bold text-navy-800">{task.task_reference}</div>
          <Btn tone="white" onClick={onClose}>✕</Btn>
        </div>
        <StatusBadge status={task.status} />
        <div className="grid grid-cols-2 gap-3">
          <Field l="Type" v={task.task_type} />
          <Field l="Section" v={task.section} />
          <Field l="Stage" v={task.stage} />
          <Field l="Next action" v={task.next_action?.verb || task.next_action?.notes} />
          <Field l="Due" v={task.due_date} />
          <Field l="Assignee" v={task.assignee} />
        </div>
      </div>
    </div>
  )
}

export default function AccountMyTasks() {
  const { user } = useAuth()
  const [tab, setTab] = useState('tasks')
  const [summary, setSummary] = useState(null)
  const [tasks, setTasks] = useState(null)
  const [discoveries, setDiscoveries] = useState(null)
  const [err, setErr] = useState(null)
  const [loading, setLoading] = useState(false)

  const [group, setGroup] = useState('active')
  const [search, setSearch] = useState('')
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [dStatus, setDStatus] = useState('')
  const [dPage, setDPage] = useState(1)

  const [taskOpen, setTaskOpen] = useState(null)
  const [billDrawer, setBillDrawer] = useState(null)
  const [discoveryId, setDiscoveryId] = useState(null)
  const [valuationId, setValuationId] = useState(null)
  const [users, setUsers] = useState([])

  useEffect(() => {
    // /users is gated on staff.view, which an Account & Records Officer does
    // not hold, so the assignment picker silently came back empty. The
    // assignable lookup is open to any signed-in staff member and only returns
    // people the server will actually accept for the task.
    Promise.all([
      api.get('/lookup/assignable', { params: { task_type: 'Account & Record Processing' } }),
      api.get('/lookup/assignable', { params: { task_type: 'Enforcement Visit' } }),
    ])
      .then(([acct, enf]) => {
        const seen = new Set()
        const merged = []
        for (const r of [...unwrap(acct).data, ...unwrap(enf).data]) {
          if (seen.has(r.id)) continue
          seen.add(r.id)
          merged.push(r)
        }
        setUsers(merged)
      })
      .catch((e) => console.warn('[AccountMyTasks] staff lookup failed', e))
  }, [])

  const loadSummary = () =>
    api.get('/account/my-tasks/summary').then((r) => setSummary(unwrap(r).data)).catch(() => {})

  useEffect(() => {
    api.post('/account/my-tasks/read').catch(() => {})
    loadSummary()
  }, [])

  useEffect(() => {
    if (tab !== 'tasks') return
    setLoading(true)
    const params = { view: 'mine', task_types: ACCOUNT_TYPES, per_page: PER_PAGE, page }
    if (group !== 'all') params.status_group = group
    if (q) params.q = q
    api.get('/tasks', { params })
      .then((r) => { setTasks(unwrap(r).data); setErr(null) })
      .catch((e) => setErr(e))
      .finally(() => setLoading(false))
  }, [tab, group, q, page])

  useEffect(() => {
    if (tab !== 'discoveries') return
    setLoading(true)
    const params = { officer_id: user?.id, per_page: PER_PAGE, page: dPage }
    if (dStatus) params.status = dStatus
    api.get('/discoveries', { params })
      .then((r) => { setDiscoveries(unwrap(r).data); setErr(null) })
      .catch((e) => setErr(e))
      .finally(() => setLoading(false))
  }, [tab, dStatus, dPage, user?.id])

  const afterChange = () => {
    loadSummary()
    if (tab === 'tasks') {
      setPage((p) => (p === 1 ? 1 : p))
      setTasks(null)
    }
    setDiscoveryId(null)
    setValuationId(null)
    setTaskOpen(null)
  }

  const openTask = (t) => {
    if (t.reference_type === 'property_bill') {
      setBillDrawer({ billId: t.reference_id, taskId: t.id })
    } else if (t.reference_type === 'property_discovery') {
      setDiscoveryId(t.reference_id)
    } else if (t.reference_type === 'valuation') {
      // A property that reached this desk through valuation gets the same Property View
      // as one that arrived by discovery, not a bare field summary.
      setValuationId(t.reference_id)
    } else {
      setTaskOpen(t)
    }
  }

  const groups = [
    { key: 'active', label: 'Active' },
    { key: 'completed', label: 'Completed' },
    { key: 'overdue', label: 'Overdue' },
    { key: 'all', label: 'All' },
  ]

  const s = (k) => Number(summary?.[k] ?? 0)

  return (
    <div className="space-y-5">
      <PageTitle
        sub="Account &amp; Record Officer — your assigned processing work and property discoveries.">
        My Tasks
      </PageTitle>

      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
          <Stat label="Assigned" value={s('assigned').toLocaleString()} />
          <Stat label="Completed" value={s('completed').toLocaleString()} tone="green" />
          <Stat label="My Discoveries" value={s('discoveries').toLocaleString()} tone="blue" />
          <Stat label="Overdue" value={s('overdue').toLocaleString()} tone="amber" />
          <Stat label="Due today" value={s('due_today').toLocaleString()} tone="amber" />
        </div>
      )}

      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex gap-1">
            {['tasks', 'discoveries'].map((t) => (
              <button key={t} onClick={() => setTab(t)}
                className={`px-4 py-2 rounded-xl text-sm font-semibold transition ${tab === t ? 'bg-brand-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>
                {t === 'tasks' ? `Assigned Tasks${s('assigned') ? ` (${s('assigned')})` : ''}` : `My Discoveries${s('discoveries') ? ` (${s('discoveries')})` : ''}`}
              </button>
            ))}
          </div>

          {tab === 'tasks' ? (
            <div className="flex flex-wrap items-center gap-2">
              <div className="flex rounded-xl bg-slate-100 p-1">
                {groups.map((g) => (
                  <button key={g.key} onClick={() => { setGroup(g.key); setPage(1) }}
                    className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition ${group === g.key ? 'bg-white shadow text-brand-600' : 'text-slate-500 hover:text-slate-700'}`}>
                    {g.label}
                  </button>
                ))}
              </div>
              <div className="flex gap-2">
                <Input className="w-48" placeholder="Search reference, property…" value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') { setQ(search); setPage(1) } }} />
                <Btn tone="white" onClick={() => { setQ(search); setPage(1) }}>Search</Btn>
              </div>
            </div>
          ) : (
            <select className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={dStatus}
              onChange={(e) => { setDStatus(e.target.value); setDPage(1) }}>
              <option value="">All statuses</option>
              {DISCOVERY_STATUSES.map((s2) => <option key={s2} value={s2}>{s2}</option>)}
            </select>
          )}
        </div>

        {err && <div className="mt-3"><ErrorBox error={errMsg(err, 'Unable to load this information.')} /></div>}
        {loading && !tasks && !discoveries && <div className="py-10"><Spinner label="Loading…" /></div>}

        {tab === 'tasks' && tasks && (
          <>
            <div className="-mx-5 mt-4 overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                    <th className="px-3 py-2 first:pl-5 whitespace-nowrap">Reference</th>
                    <th className="px-3 py-2 whitespace-nowrap">Type</th>
                    <th className="px-3 py-2 whitespace-nowrap">Property / record</th>
                    <th className="px-3 py-2 whitespace-nowrap">Status</th>
                    <th className="px-3 py-2 whitespace-nowrap">Due</th>
                    <th className="px-3 py-2 pr-5 text-right whitespace-nowrap">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {(tasks.data || []).length === 0 ? (
                    <tr><td colSpan={6} className="px-5 py-8 text-center text-slate-400">No {group === 'all' ? '' : `${group} `}tasks assigned to you.</td></tr>
                  ) : tasks.data.map((t) => (
                    <tr key={t.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                      <td className="px-3 py-2.5 first:pl-5 whitespace-nowrap">
                        <span className="font-semibold text-navy-800">{t.task_reference}</span>
                      </td>
                      <td className="px-3 py-2.5 whitespace-nowrap text-slate-600">{t.task_type}</td>
                      <td className="px-3 py-2.5 whitespace-nowrap text-slate-600">
                        {value(t.label)}
                        {t.property_address ? <span className="block text-[11px] text-slate-400">{t.property_address}</span> : null}
                      </td>
                      <td className="px-3 py-2.5 whitespace-nowrap"><StatusBadge status={t.status} /></td>
                      <td className="px-3 py-2.5 whitespace-nowrap text-slate-600">{value(t.due_date)}</td>
                      <td className="px-3 py-2.5 pr-5 text-right whitespace-nowrap">
                        <Btn tone="white" className="!px-3 !py-1.5" onClick={() => openTask(t)}>Open →</Btn>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {tasks.total > 0 && (
              <div className="flex items-center justify-between mt-4 text-sm">
                <span className="text-slate-500">
                  Showing {(tasks.current_page - 1) * tasks.per_page + 1}–{Math.min(tasks.current_page * tasks.per_page, tasks.total)} of {tasks.total}
                </span>
                <div className="flex gap-2">
                  <Btn tone="white" disabled={tasks.current_page <= 1} onClick={() => setPage((p) => p - 1)}>Prev</Btn>
                  <span className="self-center text-xs text-slate-400">Page {tasks.current_page} / {tasks.last_page}</span>
                  <Btn tone="white" disabled={tasks.current_page >= tasks.last_page} onClick={() => setPage((p) => p + 1)}>Next</Btn>
                </div>
              </div>
            )}
          </>
        )}

        {tab === 'discoveries' && discoveries && (
          <>
            <div className="-mx-5 mt-4 overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                    <th className="px-3 py-2 first:pl-5 whitespace-nowrap">Reference</th>
                    <th className="px-3 py-2 whitespace-nowrap">Owner</th>
                    <th className="px-3 py-2 whitespace-nowrap">Address</th>
                    <th className="px-3 py-2 whitespace-nowrap">Status</th>
                    <th className="px-3 py-2 whitespace-nowrap">Route</th>
                    <th className="px-3 py-2 pr-5 text-right whitespace-nowrap">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {(discoveries.data || []).length === 0 ? (
                    <tr><td colSpan={6} className="px-5 py-8 text-center text-slate-400">No discoveries recorded by you.</td></tr>
                  ) : discoveries.data.map((d) => (
                    <tr key={d.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                      <td className="px-3 py-2.5 first:pl-5 whitespace-nowrap">
                        <span className="font-semibold text-navy-800">{d.discovery_reference}</span>
                      </td>
                      <td className="px-3 py-2.5 whitespace-nowrap text-slate-600">{value(d.owner_name)}</td>
                      <td className="px-3 py-2.5 whitespace-nowrap text-slate-600">{value(d.property_address)}</td>
                      <td className="px-3 py-2.5 whitespace-nowrap"><StatusBadge status={d.status} /></td>
                      <td className="px-3 py-2.5 whitespace-nowrap text-slate-600">{value(d.route, d.decision_path)}</td>
                      <td className="px-3 py-2.5 pr-5 text-right whitespace-nowrap">
                        <Btn tone="white" className="!px-3 !py-1.5" onClick={() => setDiscoveryId(d.id)}>Open →</Btn>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {discoveries.total > 0 && (
              <div className="flex items-center justify-between mt-4 text-sm">
                <span className="text-slate-500">
                  Showing {(discoveries.current_page - 1) * discoveries.per_page + 1}–{Math.min(discoveries.current_page * discoveries.per_page, discoveries.total)} of {discoveries.total}
                </span>
                <div className="flex gap-2">
                  <Btn tone="white" disabled={discoveries.current_page <= 1} onClick={() => setDPage((p) => p - 1)}>Prev</Btn>
                  <span className="self-center text-xs text-slate-400">Page {discoveries.current_page} / {discoveries.last_page}</span>
                  <Btn tone="white" disabled={discoveries.current_page >= discoveries.last_page} onClick={() => setDPage((p) => p + 1)}>Next</Btn>
                </div>
              </div>
            )}
          </>
        )}
      </Card>

      {taskOpen && <TaskModal task={taskOpen} onClose={() => setTaskOpen(null)} />}
      {discoveryId && <DiscoveryModal id={discoveryId} users={users} onClose={() => setDiscoveryId(null)} onChanged={afterChange} />}
      {valuationId && <ValuationRecordModal id={valuationId} users={users} onClose={() => setValuationId(null)} onChanged={afterChange} />}
      {billDrawer && <BillDrawer billId={billDrawer.billId} taskId={billDrawer.taskId} onClose={() => setBillDrawer(null)} onChanged={afterChange} />}
    </div>
  )
}