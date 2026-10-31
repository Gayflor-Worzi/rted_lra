import { useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import { Card, Stat, Badge, Btn, Input, Spinner, ErrorBox, PageTitle, statusTone } from '../ui'
import BillDrawer from '../components/BillDrawer'
import PropertyRecordModal from '../components/PropertyRecordModal'

/**
 * Reusable section-workspace task page (Assistant Commissioner / Valuation).
 * Feeds the section workspace API — every surface is scoped server-side by the
 * workspace registry, so this page never decides which tasks it may show.
 */
const WS = {
  ac: {
    title: 'My Tasks',
    sub: 'Assistant Commissioner — your approvals, decisions and reassessments.',
    personal: 'Your approvals',
    queue: 'Decision queue',
    empty: 'No approvals are assigned to you.',
    emptyQueue: 'No accommodations awaiting assignment.',
    hint: 'Valuation approvals are actioned from the Valuation module.',
  },
  valuation: {
    title: 'My Tasks',
    sub: 'Valuation — your assessments, reviews and manager desk work.',
    personal: 'Your tasks',
    queue: 'Manager review queue',
    empty: 'No valuation tasks are assigned to you.',
    emptyQueue: 'No valuation work awaiting assignment.',
    hint: 'Manager decisions are actioned from the Valuation module.',
  },
}

const PER_PAGE = 20

function value(v, fb = '—') {
  if (v === null || v === undefined || v === '') return fb
  // Descriptor fields (next_action, deadline_status) arrive as objects. Rendering
  // one raw throws "Objects are not valid as a React child", so unwrap the known
  // label-bearing keys rather than trusting the field to be scalar.
  if (typeof v === 'object') return v.label || v.verb || v.notes || v.name || fb
  return v
}

/**
 * presentDeadline() exposes flags, not a ready-made label, so the drawer builds
 * its own sentence. It previously read `deadline_status.label`, which does not
 * exist, so this field silently rendered the em-dash fallback forever.
 */
function deadlineText(ds) {
  if (!ds) return '—'
  if (ds.is_completed_late) return `Completed ${ds.overdue_days || ''} day(s) late`.replace('  ', ' ')
  if (ds.is_overdue) return `Overdue by ${ds.overdue_days} day(s)`
  if (ds.is_due_today) return 'Due today'
  if (ds.due_date) return `${ds.deadline_type || 'DATE'} · due ${ds.due_date}`
  return '—'
}

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

function TaskModal({ task, hint, onClose }) {
  // Discovery and valuation references are routed to the full Property View by
  // openTask(), so this fallback only ever shows for other reference kinds.
  const openAction = task.reference_type === 'property_bill'
    ? (
      <div className="rounded-lg bg-brand-50 border border-brand-200 text-brand-600 text-xs px-3 py-2">
        Reference: Bill #{task.reference_id} — open it from the bill register.
      </div>
    ) : null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <div className="relative w-full max-w-lg bg-white rounded-2xl shadow-xl p-5 space-y-3">
        <div className="flex items-center justify-between">
          <div className="font-bold text-navy-800">{task.task_reference}</div>
          <Btn tone="white" onClick={onClose}>✕</Btn>
        </div>
        <StatusBadge status={task.status} />
        {hint && <div className="text-xs text-slate-400">{hint}</div>}
        {openAction}
        <div className="grid grid-cols-2 gap-3">
          <Field l="Type" v={task.task_type} />
          <Field l="Section" v={task.section} />
          <Field l="Property / record" v={value(task.label)} />
          <Field l="Address" v={value(task.property_address)} />
          <Field l="Stage" v={value(task.stage)} />
          <Field l="Next action" v={value(task.next_action?.verb || task.next_action?.notes)} />
          <Field l="Due" v={value(task.due_date)} />
          <Field l="Assignee" v={value(task.assignee)} />
          <Field l="Deadline" v={deadlineText(task.deadline_status)} />
        </div>
      </div>
    </div>
  )
}

export default function SectionWorkspaceTasks({ workspace }) {
  const cfg = WS[workspace] || WS.valuation
  const [tab, setTab] = useState('personal')
  const [summary, setSummary] = useState(null)
  const [rows, setRows] = useState(null)
  const [queue, setQueue] = useState(null)
  const [err, setErr] = useState(null)
  const [loading, setLoading] = useState(false)
  const [group, setGroup] = useState('active')
  const [search, setSearch] = useState('')
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [taskOpen, setTaskOpen] = useState(null)
  const [recordOpen, setRecordOpen] = useState(null)
  const [billDrawer, setBillDrawer] = useState(null)

  const loadSummary = () =>
    api.get(`/${workspace}/tasks/summary`).then((r) => setSummary(unwrap(r).data)).catch(() => {})

  useEffect(() => {
    api.post(`/${workspace}/tasks/read`).catch(() => {})
    loadSummary()
  }, [workspace])

  useEffect(() => {
    if (tab !== 'personal') return
    setLoading(true)
    const params = { per_page: PER_PAGE, page }
    if (group !== 'all') params.status_group = group
    if (q) params.q = q
    api.get(`/${workspace}/tasks`, { params })
      .then((r) => { setRows(unwrap(r).data); setErr(null) })
      .catch((e) => setErr(e))
      .finally(() => setLoading(false))
  }, [workspace, tab, group, q, page])

  useEffect(() => {
    if (tab !== 'queue') return
    setLoading(true)
    const params = { per_page: PER_PAGE, page }
    if (q) params.q = q
    api.get(`/${workspace}/tasks/queue`, { params })
      .then((r) => { setQueue(unwrap(r).data); setErr(null) })
      .catch((e) => setErr(e))
      .finally(() => setLoading(false))
  }, [workspace, tab, q, page])

  const afterChange = () => {
    loadSummary()
    setRows(null)
    setQueue(null)
    setTaskOpen(null)
    setRecordOpen(null)
  }

  // A discovery or valuation reference opens the same full Property View the
  // Account Manager and Enforcement desks get, not a bare field summary. The
  // action panels inside it gate every button on the signed-in user's own
  // permissions, so this desk sees only the actions it may actually perform.
  const PROPERTY_REFS = ['property_discovery', 'valuation']

  const openTask = (t) => {
    if (t.reference_type === 'property_bill') {
      setBillDrawer({ billId: t.reference_id, taskId: t.id })
    } else if (PROPERTY_REFS.includes(t.reference_type)) {
      setRecordOpen(t)
    } else {
      setTaskOpen(t)
    }
  }

  const s = (k) => Number(summary?.[k] ?? 0)
  const list = tab === 'personal' ? rows : queue
  const groups = [
    { key: 'active', label: 'Active' },
    { key: 'completed', label: 'Completed' },
    { key: 'overdue', label: 'Overdue' },
    { key: 'all', label: 'All' },
  ]

  return (
    <div className="space-y-5">
      <PageTitle sub={cfg.sub}>{cfg.title}</PageTitle>

      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
          <Stat label={cfg.personal} value={s('personal').toLocaleString()} />
          <Stat label="Completed" value={s('completed').toLocaleString()} tone="green" />
          <Stat label="Desk queue" value={s('queue').toLocaleString()} tone="blue" />
          <Stat label="Overdue" value={s('overdue').toLocaleString()} tone="amber" />
          <Stat label="Due today" value={s('due_today').toLocaleString()} tone="amber" />
        </div>
      )}

      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex gap-1">
            {[
              ['personal', cfg.personal],
              ['queue', cfg.queue],
            ].map(([k, label]) => (
              <button key={k} onClick={() => { setTab(k); setPage(1) }}
                className={`px-4 py-2 rounded-xl text-sm font-semibold transition ${tab === k ? 'bg-brand-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>
                {label}{k === 'personal' && s('personal') ? ` (${s('personal')})` : ''}
              </button>
            ))}
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {tab === 'personal' ? (
              <div className="flex rounded-xl bg-slate-100 p-1">
                {groups.map((g) => (
                  <button key={g.key} onClick={() => { setGroup(g.key); setPage(1) }}
                    className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition ${group === g.key ? 'bg-white shadow text-brand-600' : 'text-slate-500 hover:text-slate-700'}`}>
                    {g.label}
                  </button>
                ))}
              </div>
            ) : null}
            <div className="flex gap-2">
              <Input className="w-48" placeholder="Search reference…" value={search}
                onChange={(e) => setSearch(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') { setQ(search); setPage(1) } }} />
              <Btn tone="white" onClick={() => { setQ(search); setPage(1) }}>Search</Btn>
            </div>
          </div>
        </div>

        {err && <div className="mt-3"><ErrorBox error={errMsg(err, 'Unable to load this information.')} /></div>}
        {loading && !rows && !queue && <div className="py-10"><Spinner label="Loading…" /></div>}

        {list && (
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
                  {(list.data || []).length === 0 ? (
                    <tr><td colSpan={6} className="px-5 py-8 text-center text-slate-400">
                      {tab === 'personal' ? cfg.empty : cfg.emptyQueue}
                    </td></tr>
                  ) : list.data.map((t) => (
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
            {list.total > 0 && (
              <div className="flex items-center justify-between mt-4 text-sm">
                <span className="text-slate-500">
                  Showing {(list.current_page - 1) * list.per_page + 1}–{Math.min(list.current_page * list.per_page, list.total)} of {list.total}
                </span>
                <div className="flex gap-2">
                  <Btn tone="white" disabled={list.current_page <= 1} onClick={() => setPage((p) => p - 1)}>Prev</Btn>
                  <span className="self-center text-xs text-slate-400">Page {list.current_page} / {list.last_page}</span>
                  <Btn tone="white" disabled={list.current_page >= list.last_page} onClick={() => setPage((p) => p + 1)}>Next</Btn>
                </div>
              </div>
            )}
          </>
        )}
      </Card>

      {taskOpen && <TaskModal task={taskOpen} hint={cfg.hint} onClose={() => setTaskOpen(null)} />}
      {recordOpen && (
        <PropertyRecordModal
          referenceType={recordOpen.reference_type}
          referenceId={recordOpen.reference_id}
          title={`${recordOpen.task_reference} · ${recordOpen.label || 'Property'}`}
          onClose={() => setRecordOpen(null)}
          onChanged={afterChange}
        />
      )}
      {billDrawer && <BillDrawer billId={billDrawer.billId} taskId={billDrawer.taskId} onClose={() => setBillDrawer(null)} onChanged={afterChange} />}
    </div>
  )
}