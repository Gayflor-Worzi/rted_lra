import { useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import { Card, Stat, Badge, Spinner, ErrorBox, Btn, Input, PageTitle, fmtDate, fmtMoney, fmtTime } from '../ui'
import { useAuth } from '../auth'
import Analytics from '../components/Analytics'
import { CASE_STATUSES, PAYMENT_STATUSES, TASK_STATUSES, targetMetricLabel } from '../lib/constants'

const KINDS = [
  { id: 'bills', label: 'Property Bills', icon: '🧾', viewPermissions: ['reports.view'], exportPermissions: ['reports.view', 'reports.export'] },
  { id: 'collections', label: 'Collections', icon: '💰', viewPermissions: ['reports.view'], exportPermissions: ['reports.view', 'reports.export'] },
  { id: 'enforcement', label: 'Enforcement', icon: '👮', viewPermissions: ['reports.view', 'enforcement.view_assignments'], exportPermissions: ['reports.view', 'reports.export', 'enforcement.view_assignments'] },
  { id: 'valuations', label: 'Valuations', icon: '🏢', viewPermissions: ['reports.view', 'valuation.view_history'], exportPermissions: ['reports.view', 'reports.export', 'valuation.view_history'] },
  { id: 'discoveries', label: 'Discoveries', icon: '📡', viewPermissions: ['reports.view', 'discovery.view', 'discovery.create'], exportPermissions: ['reports.view', 'reports.export', 'discovery.view', 'discovery.create'] },
  { id: 'payment-queue', label: 'Payment Queue', icon: '🔁', viewPermissions: ['reports.view', 'payments.view_queue', 'payments.view_history'], exportPermissions: ['reports.view', 'reports.export', 'payments.view_queue', 'payments.view_history'] },
]

// Extra filters beyond the date range — applied to the on-screen list AND to
// CSV/PDF exports. `options` renders a select of fixed values, `staff` a
// select of users, otherwise a free-text input.
const EXTRA = {
  bills: [
    { param: 'case_status', label: 'Status', options: CASE_STATUSES },
    { param: 'payment_status', label: 'Payment status', options: PAYMENT_STATUSES },
    { param: 'recipient_type', label: 'Recipient type' },
    { param: 'logged_by', label: 'Logged by', staff: true },
    { param: 'assigned_to', label: 'Assigned to', staff: true },
  ],
  collections: [
    { param: 'verified_by', label: 'Verified by', staff: true },
  ],
  enforcement: [
    { param: 'status', label: 'Status', options: TASK_STATUSES },
    { param: 'assigned_to', label: 'Assigned to', staff: true },
  ],
  valuations: [
    { param: 'status', label: 'Status' },
    { param: 'valuation_officer', label: 'Officer', staff: true },
  ],
  discoveries: [
    { param: 'status', label: 'Status' },
    { param: 'decision_path', label: 'Decision path' },
    { param: 'discovered_by', label: 'Discovered by', staff: true },
    { param: 'property_classification', label: 'Classification' },
    { param: 'value_declaration_type', label: 'Value declaration' },
  ],
  'payment-queue': [
    { param: 'verification_status', label: 'Verification', options: ['Pending', 'Confirmed', 'Rejected'] },
    { param: 'match_status', label: 'Match', options: ['Pending', 'Match'] },
  ],
}

const QUICK = [
  { id: '', label: 'All time' },
  { id: 'today', label: 'Today' },
  { id: 'this_week', label: 'This week' },
  { id: 'this_month', label: 'This month' },
  { id: 'quarter', label: 'Quarter' },
  { id: 'yearly', label: 'Year' },
]

const TONE = { Approved: 'green', Successful: 'green', Paid: 'green', Closed: 'green', 'Fully Verified': 'green', 'Match Found': 'green' }

export default function Reports() {
  const { can, user } = useAuth()
  const availableKinds = KINDS.filter((item) =>
    item.viewPermissions.some((permission) => can(permission)) ||
    item.exportPermissions.some((permission) => can(permission)))
  const canViewTasks = can('tasks.view_own')
  const canViewAnalytics = can('dashboard.view_own', 'dashboard.view_section', 'dashboard.view_division')
  const isManagementPerformance = [
    'Account Manager', 'Valuation Manager', 'Enforcement Manager',
    'Account Supervisor', 'Valuation Supervisor', 'Enforcement Supervisor',
    'Assistant Commissioner',
  ].includes(user?.role)
  const [view, setView] = useState(() => canViewTasks ? 'my-tasks' : 'my-performance')
  const [kind, setKind] = useState('bills')
  const [res, setRes] = useState(null)
  const [err, setErr] = useState('')
  const [quick, setQuick] = useState('')
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [filters, setF] = useState({})
  const [users, setUsers] = useState([])
  const [dl, setDl] = useState(null)
  const [personalTasks, setPersonalTasks] = useState(null)
  const [performance, setPerformance] = useState(null)
  const [personalLoading, setPersonalLoading] = useState(false)
  const [taskStatus, setTaskStatus] = useState('')

  const setFilter = (key, value) => setF((f) => ({ ...f, [key]: value }))
  const resetAll = () => { setQuick(''); setStartDate(''); setEndDate(''); setF({}) }

  useEffect(() => {
    if (!can('staff.view')) return
    api.get('/users', { params: { per_page: 200 } })
      .then((r) => setUsers(unwrap(r).data.data))
      .catch((ex) => setErr(errMsg(ex, 'Unable to load staff filter options.')))
  }, [])

  const params = () => {
    const p = {}
    if (quick) p.filter = quick
    else if (startDate || endDate) {
      if (startDate) p.start_date = startDate
      if (endDate) p.end_date = endDate
    }
    Object.entries(filters).forEach(([k, v]) => { if (v !== '' && v != null) p[k] = v })
    return p
  }

  const load = () => {
    setErr('')
    api.get(`/reports/${kind}`, { params: params() })
      .then((r) => setRes(unwrap(r).data))
      .catch((ex) => setErr(errMsg(ex)))
  }
  useEffect(() => {
    const report = KINDS.find((item) => item.id === kind)
    if (view !== 'registers' || !report || !report.viewPermissions.some((permission) => can(permission))) return
    setRes(null)
    load()
  }, [view, kind, quick, startDate, endDate, filters]) // eslint-disable-line react-hooks/exhaustive-deps

  const taskParams = () => {
    const p = {}
    if (quick) p.filter = quick
    else if (startDate || endDate) {
      if (startDate) p.start_date = startDate
      if (endDate) p.end_date = endDate
    }
    if (taskStatus) p.status = taskStatus
    return p
  }

  useEffect(() => {
    if (!['my-tasks', 'my-performance'].includes(view)) return
    setPersonalLoading(true)
    setErr('')
    const request = view === 'my-tasks'
      ? api.get('/reports/my/tasks', { params: taskParams() })
      : api.get(isManagementPerformance ? '/reports/management/performance' : '/reports/my/performance')
    request
      .then((r) => {
        const data = unwrap(r).data
        if (view === 'my-tasks') setPersonalTasks(data.rows)
        else setPerformance(data)
      })
      .catch((ex) => setErr(errMsg(ex, 'Unable to load this report.')))
      .finally(() => setPersonalLoading(false))
  }, [view, quick, startDate, endDate, taskStatus, isManagementPerformance]) // eslint-disable-line react-hooks/exhaustive-deps

  const download = async (format) => {
    setErr(''); setDl(format)
    try {
      const endpoint = view === 'my-tasks'
        ? '/reports/my/tasks/export'
        : isManagementPerformance ? '/reports/management/performance/export' : '/reports/my/performance/export'
      const reportParams = view === 'registers'
        ? { ...params(), format }
        : view === 'my-tasks'
          ? { ...taskParams(), format }
          : { format }
      const r = await api.get(view === 'registers' ? `/reports/${kind}/export` : endpoint, { params: reportParams, responseType: 'blob' })
      const blob = r.data
      const cd = (r.headers['content-disposition'] || '').match(/filename="?([^"]+)"?/)
      const name = cd ? cd[1] : `retd_${kind}.${format}`
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = name
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } catch (ex) { setErr(errMsg(ex, 'Export failed.')) }
    setDl(null)
  }

  const rows = res?.rows?.data || []
  const canPreviewKind = KINDS.find((item) => item.id === kind)?.viewPermissions.some((permission) => can(permission))
  const meta = { cols: COLS[kind] || [] }

  return (
    <div className="space-y-4">
      <PageTitle sub={isManagementPerformance ? 'Management performance, operational registers, and analytical reports.' : 'Personal work, operational registers, and analytical reports.'}>Reports</PageTitle>
      <ErrorBox error={err} />

      <div className="flex flex-wrap gap-2">
        {canViewTasks && <ReportTab active={view === 'my-tasks'} onClick={() => setView('my-tasks')}>My Tasks</ReportTab>}
        <ReportTab active={view === 'my-performance'} onClick={() => setView('my-performance')}>
          {isManagementPerformance ? 'Management Performance' : 'My Performance'}
        </ReportTab>
        {availableKinds.length > 0 && <ReportTab active={view === 'registers'} onClick={() => { setView('registers'); setKind(availableKinds[0].id) }}>Registers</ReportTab>}
        {canViewAnalytics && <ReportTab active={view === 'analytics'} onClick={() => setView('analytics')}>Analytics Exports</ReportTab>}
      </div>

      {view === 'my-tasks' && canViewTasks && (
        <PersonalTaskReport
          loading={personalLoading}
          rows={personalTasks?.data || []}
          total={personalTasks?.total || 0}
          quick={quick}
          setQuick={setQuick}
          startDate={startDate}
          setStartDate={setStartDate}
          endDate={endDate}
          setEndDate={setEndDate}
          taskStatus={taskStatus}
          setTaskStatus={setTaskStatus}
          onReset={() => { resetAll(); setTaskStatus('') }}
          onDownload={download}
          downloading={dl}
        />
      )}
      {view === 'my-performance' && (
        isManagementPerformance ? (
          <ManagementPerformanceReport data={performance} loading={personalLoading} onDownload={download} downloading={dl} />
        ) : (
          <PersonalPerformanceReport
            data={performance}
            loading={personalLoading}
            onDownload={download}
            downloading={dl}
          />
        )
      )}
      {view === 'analytics' && canViewAnalytics && <Analytics heading="Dashboard analytics report" />}
      {view === 'registers' && availableKinds.length === 0 && <Card>No operational registers are available for this account.</Card>}
      {view === 'registers' && availableKinds.length > 0 && (
        <>
      {/* Register tabs remain permission-filtered; each endpoint enforces the same permission server-side. */}
      <div className="flex flex-wrap gap-2">
        {availableKinds.map((k) => (
          <button key={k.id} onClick={() => { setKind(k.id); setRes(null); setF({}) }} type="button"
            className={`px-4 py-2 rounded-xl text-sm font-semibold transition ${kind === k.id ? 'bg-navy-800 text-white shadow' : 'bg-white text-slate-600 border border-slate-200 hover:border-slate-300'}`}>
            {k.icon} {k.label}
          </button>
        ))}
      </div>

      {/* Summary cards */}
      {res?.summary && (
        <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <Stat label="Total tasks" value={res.summary.total} />
          <Stat label="Assigned" value={res.summary.assigned} />
          <Stat label="Delivered / resolved" value={res.summary.delivered} />
          <Stat label="Escalated" value={res.summary.escalated} />
        </div>
      )}
      {res?.totals && (
        <div className="grid sm:grid-cols-2 gap-3">
          <Stat label="Verified collections" value={fmtMoney(res.totals.verified_amount)} />
          <Stat label="Verifications" value={res.totals.count} />
        </div>
      )}

      {/* Filters + export */}
      <Card title="Date filter & export">
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex flex-wrap gap-1.5">
            {QUICK.map((q) => (
              <button key={q.id} onClick={() => { setQuick(q.id); setStartDate(''); setEndDate('') }}
                className={`px-3 py-1.5 rounded-full text-xs font-semibold transition ${quick === q.id ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>
                {q.label}
              </button>
            ))}
          </div>
          <div className="flex items-center gap-2 ml-auto">
            <div className="flex items-center gap-1.5 text-xs text-slate-500 font-semibold">
              From <Input type="date" className="w-auto" value={startDate} onChange={(e) => { setStartDate(e.target.value); setQuick('') }} />
              To <Input type="date" className="w-auto" value={endDate} onChange={(e) => { setEndDate(e.target.value); setQuick('') }} />
            </div>
          </div>
        </div>
        <div className="flex flex-wrap items-end gap-3 mt-3 pt-3 border-t border-slate-100">
          {(EXTRA[kind] || []).filter((f) => !f.staff || can('staff.view')).map((f) => (
            <div key={f.param}>
              <label className="block text-xs font-semibold text-slate-600 mb-1">{f.label}</label>
              {f.staff ? (
                <select value={filters[f.param] || ''} onChange={(e) => setFilter(f.param, e.target.value)}
                  className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white min-w-[180px]">
                  <option value="">All {f.label}</option>
                  {users.map((u) => <option key={u.id} value={u.id}>{u.full_name}</option>)}
                </select>
              ) : f.options ? (
                <select value={filters[f.param] || ''} onChange={(e) => setFilter(f.param, e.target.value)}
                  className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white min-w-[180px]">
                  <option value="">All {f.label}</option>
                  {f.options.map((o) => <option key={o} value={o}>{o}</option>)}
                </select>
              ) : (
                <Input placeholder={`Filter ${f.label}…`} value={filters[f.param] || ''}
                  onChange={(e) => setFilter(f.param, e.target.value)} />
              )}
            </div>
          ))}
          <button onClick={resetAll}
            className="px-3 py-2 rounded-xl text-sm font-semibold text-slate-500 border border-slate-200 hover:bg-slate-50">
            Reset
          </button>
        </div>
        <div className="flex items-center gap-2 mt-3 pt-3 border-t border-slate-100">
          <span className="text-sm font-semibold text-navy-800">{rows.length} record(s)</span>
          <div className="flex-1" />
          {KINDS.find((item) => item.id === kind)?.exportPermissions.some((permission) => can(permission)) && (
            <>
              <Btn tone="navy" onClick={() => download('csv')} disabled={dl === 'csv'}>
                {dl === 'csv' ? 'Downloading…' : '⬇ CSV'}
              </Btn>
              <Btn onClick={() => download('pdf')} disabled={dl === 'pdf'}>
                {dl === 'pdf' ? 'Downloading…' : '⬇ A4 PDF'}
              </Btn>
            </>
          )}
        </div>
      </Card>

      {/* Table */}
      {!canPreviewKind ? <Card>You can export this report, but your permissions do not allow previewing its records.</Card> : !res ? <Spinner label="Loading report…" /> : (
        <Card title={`${KINDS.find((k) => k.id === kind)?.label} register`}>
          <div className="-mx-5 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                  {meta.cols.map((c) => <th key={c.key} className={c.align === 'r' ? 'px-2 py-2 text-right' : 'px-2 py-2'}>{c.label}</th>)}
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 ? (
                  <tr><td colSpan={meta.cols.length} className="px-5 py-8 text-center text-sm text-slate-400">No records for this filter.</td></tr>
                ) : rows.map((row, i) => (
                  <tr key={row.id ?? i} className="border-b border-slate-50 hover:bg-slate-50/60">
                    {meta.cols.map((c) => {
                      const v = c.render ? c.render(row) : row[c.key]
                      const badged = c.badge && v
                      const val = c.money ? fmtMoney(v) : c.date ? fmtDate(v) : c.time ? fmtTime(v) : v
                      return (
                        <td key={c.key} className={c.align === 'r' ? 'px-2 py-2.5 text-right' : 'px-2 py-2.5'}>
                          {badged ? <Badge tone={TONE[v] || 'slate'}>{val}</Badge> : (<span className={c.money ? 'font-semibold' : ''}>{val ?? '—'}</span>)}
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
        </>
      )}
    </div>
  )
}

function ReportTab({ active, onClick, children }) {
  return (
    <button type="button" onClick={onClick}
      className={`px-4 py-2 rounded-xl text-sm font-semibold transition ${active ? 'bg-navy-800 text-white shadow' : 'bg-white text-slate-600 border border-slate-200 hover:border-slate-300'}`}>
      {children}
    </button>
  )
}

function PersonalTaskReport({ loading, rows, total, quick, setQuick, startDate, setStartDate, endDate, setEndDate, taskStatus, setTaskStatus, onReset, onDownload, downloading }) {
  return (
    <Card title="My authorized tasks" right={<span className="text-sm text-slate-500">{total} record(s)</span>}>
      <div className="flex flex-wrap items-end gap-3 mb-4">
        <div className="flex flex-wrap gap-1.5">
          {QUICK.map((option) => (
            <button type="button" key={option.id} onClick={() => { setQuick(option.id); setStartDate(''); setEndDate('') }}
              className={`px-3 py-1.5 rounded-full text-xs font-semibold ${quick === option.id ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>
              {option.label}
            </button>
          ))}
        </div>
        <label className="text-xs font-semibold text-slate-500">From <Input type="date" className="w-auto mt-1" value={startDate} onChange={(e) => { setStartDate(e.target.value); setQuick('') }} /></label>
        <label className="text-xs font-semibold text-slate-500">To <Input type="date" className="w-auto mt-1" value={endDate} onChange={(e) => { setEndDate(e.target.value); setQuick('') }} /></label>
        <label className="text-xs font-semibold text-slate-500">Status
          <select value={taskStatus} onChange={(e) => setTaskStatus(e.target.value)} className="block mt-1 px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white">
            <option value="">All statuses</option>
            {TASK_STATUSES.map((status) => <option key={status} value={status}>{status}</option>)}
          </select>
        </label>
        <Btn tone="white" onClick={onReset}>Reset</Btn>
        <div className="flex-1" />
        <Btn tone="navy" onClick={() => onDownload('csv')} disabled={downloading === 'csv'}>{downloading === 'csv' ? 'Preparing…' : 'CSV'}</Btn>
        <Btn onClick={() => onDownload('pdf')} disabled={downloading === 'pdf'}>{downloading === 'pdf' ? 'Preparing…' : 'A4 PDF'}</Btn>
      </div>
      {loading ? <Spinner label="Loading your tasks…" /> : (
        <div className="-mx-5 overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              {['Task reference', 'Type', 'Status', 'Due date', 'Created', 'Completed'].map((label) => <th key={label} className="px-2 py-2">{label}</th>)}
            </tr></thead>
            <tbody>{rows.length === 0 ? (
              <tr><td colSpan={6} className="px-5 py-8 text-center text-sm text-slate-400">No tasks assigned to you for this filter.</td></tr>
            ) : rows.map((row) => (
              <tr key={row.id} className="border-b border-slate-50">
                <td className="px-2 py-2.5">{row.task_reference}</td>
                <td className="px-2 py-2.5">{row.task_type}</td>
                <td className="px-2 py-2.5"><Badge tone={TONE[row.status] || 'slate'}>{row.status}</Badge></td>
                <td className="px-2 py-2.5">{fmtDate(row.due_date) || '—'}</td>
                <td className="px-2 py-2.5">{fmtTime(row.created_at) || '—'}</td>
                <td className="px-2 py-2.5">{fmtTime(row.completed_at) || '—'}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      )}
      <p className="text-xs text-slate-400 mt-3">Task rows are restricted to tasks assigned to your account. PDF exports use A4 landscape pages.</p>
    </Card>
  )
}

function PersonalPerformanceReport({ data, loading, onDownload, downloading }) {
  const targets = data?.targets || []
  const overall = data?.overall
  return (
    <Card title="My live performance targets" right={
      <div className="flex gap-2">
        <Btn tone="navy" onClick={() => onDownload('csv')} disabled={downloading === 'csv'}>{downloading === 'csv' ? 'Preparing…' : 'CSV'}</Btn>
        <Btn onClick={() => onDownload('pdf')} disabled={downloading === 'pdf'}>{downloading === 'pdf' ? 'Preparing…' : 'A4 PDF'}</Btn>
      </div>
    }>
      {loading ? <Spinner label="Loading your performance…" /> : (
        <>
          {overall && (
            <div className="grid sm:grid-cols-3 gap-3 mb-4">
              <Stat label="Achieved" value={Number(overall.achieved_value).toLocaleString('en-US', { maximumFractionDigits: 2 })} />
              <Stat label="Approved target" value={Number(overall.target_value).toLocaleString('en-US', { maximumFractionDigits: 2 })} />
              <Stat label="Overall progress" value={overall.completion_rate == null ? 'N/A' : `${overall.completion_rate}%`} />
            </div>
          )}
          {targets.length === 0 ? <p className="text-sm text-slate-400">No approved performance targets for your account.</p> : (
            <div className="-mx-5 overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                  {['Metric', 'Target', 'Achieved', 'Progress', 'Frequency', 'Period'].map((label) => <th key={label} className="px-2 py-2">{label}</th>)}
                </tr></thead>
                <tbody>{targets.map((target) => (
                  <tr key={target.id} className="border-b border-slate-50">
                    <td className="px-2 py-2.5">{targetMetricLabel(target.metric)}{target.measurement_unit ? ` (${target.measurement_unit})` : ''}</td>
                    <td className="px-2 py-2.5">{target.target_value}</td>
                    <td className="px-2 py-2.5">{target.achieved_value}</td>
                    <td className="px-2 py-2.5">{target.completion_rate}%</td>
                    <td className="px-2 py-2.5">{target.frequency}</td>
                    <td className="px-2 py-2.5">{target.start_date} – {target.end_date}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
          <p className="text-xs text-slate-400 mt-3">Achievement values are recomputed from operational records using the authoritative target calculations.</p>
        </>
      )}
    </Card>
  )
}

function ManagementPerformanceReport({ data, loading, onDownload, downloading }) {
  const periods = data?.periods || []

  return (
    <Card title="Aggregated Management Performance" right={
      <div className="flex gap-2">
        <Btn tone="navy" onClick={() => onDownload('csv')} disabled={downloading === 'csv'}>{downloading === 'csv' ? 'Preparing…' : 'CSV'}</Btn>
        <Btn onClick={() => onDownload('pdf')} disabled={downloading === 'pdf'}>{downloading === 'pdf' ? 'Preparing…' : 'A4 PDF'}</Btn>
      </div>
    }>
      {loading ? <Spinner label="Loading management performance…" /> : (
        <>
          <p className="mb-3 text-xs text-slate-500">{data?.scope_label || 'Management scope'} · Grouped by metric, measurement unit, and approved target window.</p>
          {data?.attribution_limited && (
            <p className="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
              Historical attribution is limited for {data.unattributed_targets_count} target window(s); only recorded assignment periods are included.
            </p>
          )}
          {periods.length === 0 ? (
            <p className="text-sm text-slate-400">No attributable approved operational targets are available for this management scope.</p>
          ) : (
            <div className="-mx-5 overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-400">
                  {['Metric', 'Target window', 'Eligible target', 'Achievement', 'Progress', 'Staff'].map((label) => <th key={label} className="px-2 py-2">{label}</th>)}
                </tr></thead>
                <tbody>{periods.map((period) => (
                  <tr key={`${period.metric}|${period.measurement_unit}|${period.start_date}|${period.end_date}`} className="border-b border-slate-50">
                    <td className="px-2 py-2.5">{targetMetricLabel(period.metric)}{period.measurement_unit ? ` (${period.measurement_unit})` : ''}</td>
                    <td className="px-2 py-2.5">{period.start_date} – {period.end_date}</td>
                    <td className="px-2 py-2.5">{period.target_value}</td>
                    <td className="px-2 py-2.5">{period.achieved_value}</td>
                    <td className="px-2 py-2.5">{period.completion_rate == null ? 'Unavailable' : `${period.completion_rate}%`}</td>
                    <td className="px-2 py-2.5">{period.contributing_staff_count}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
          {data?.unsupported_metrics?.length > 0 && (
            <p className="mt-3 text-xs text-slate-500">
              Not calculated because no live target rule exists: {data.unsupported_metrics.map(targetMetricLabel).join(', ')}.
            </p>
          )}
        </>
      )}
    </Card>
  )
}

const COLS = {
  bills: [
    { key: 'property_id', label: 'Property ID' },
    { key: 'tin', label: 'TIN' },
    { key: 'document_number', label: 'Reference' },
    { key: 'taxpayer_name', label: 'Taxpayer' },
    { key: 'total_tax_due', label: 'Total due', align: 'r', money: true },
    { key: 'payment_status', label: 'Payment status', badge: true },
    { key: 'recipient_type', label: 'Recipient type' },
    { key: 'case_status', label: 'Status', badge: true },
    { key: 'date_logged', label: 'Log date', date: true },
  ],
  collections: [
    { key: 'property_id', label: 'Property ID' },
    { key: 'tin', label: 'TIN' },
    { key: 'document_number', label: 'Document #' },
    { key: 'amount', label: 'Amount', align: 'r', money: true },
    { key: 'payment_period', label: 'Period' },
    { key: 'receipt_number', label: 'Receipt #' },
    { key: 'verified_by', label: 'Verified by' },
    { key: 'verified_at', label: 'Verified at', time: true },
  ],
  enforcement: [
    { key: 'property_id', label: 'Property ID' },
    { key: 'tin', label: 'TIN' },
    { key: 'task_reference', label: 'Reference' },
    { key: 'task_type', label: 'Type' },
    { key: 'status', label: 'Status', badge: true },
    { key: 'assigned_to', label: 'Assigned to' },
    { key: 'due_date', label: 'Due', date: true },
    { key: 'created_at', label: 'Created', time: true },
  ],
  valuations: [
    { key: 'valuation_reference', label: 'Reference' },
    { key: 'property_id', label: 'Property' },
    { key: 'owner_name', label: 'Owner' },
    { key: 'status', label: 'Status', badge: true },
    { key: 'assessed_value', label: 'Assessed', align: 'r', money: true },
    { key: 'annual_tax', label: 'Annual tax', align: 'r', money: true },
    { key: 'valuation_officer', label: 'Officer' },
  ],
  discoveries: [
    { key: 'discovery_reference', label: 'Reference' },
    { key: 'property_id', label: 'Property ID' },
    { key: 'tin', label: 'TIN' },
    { key: 'owner_name', label: 'Owner' },
    { key: 'property_classification', label: 'Classification' },
    { key: 'decision_path', label: 'Path' },
    { key: 'status', label: 'Status', badge: true },
    { key: 'discovered_by', label: 'Discovered by' },
    { key: 'discovery_date', label: 'Date', date: true },
  ],
  'payment-queue': [
    { key: 'property_id', label: 'Property ID' },
    { key: 'tin', label: 'TIN' },
    { key: 'document_number', label: 'Document #' },
    { key: 'receipt_number', label: 'Receipt #' },
    { key: 'amount_claimed', label: 'Amount', align: 'r', money: true },
    { key: 'match_status', label: 'Match', badge: true },
    { key: 'verification_status', label: 'Verification', badge: true },
    { key: 'created_at', label: 'Created', time: true },
  ],
}