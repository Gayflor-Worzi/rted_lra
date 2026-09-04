import { useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import { useAuth } from '../auth'
import { Card, Badge, Spinner, ErrorBox, Btn, Input, PageTitle, SuccessBox, fmtDate, fmtMoney } from '../ui'
import { TARGET_METRICS, TARGET_FREQUENCIES, targetMetricLabel } from '../lib/constants'

const STATUS_TONE = { Approved: 'green', Draft: 'slate', Archived: 'slate' }

export default function Targets() {
  const { can } = useAuth()
  const [rows, setRows] = useState(null)
  const [users, setUsers] = useState([])
  const [err, setErr] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [openNew, setOpenNew] = useState(false)
  const [filters, setFilters] = useState({ section: '', status: '' })
  const [form, setForm] = useState({
    user_id: '', section: '', metric: '', target_value: '', measurement_unit: '',
    frequency: 'Monthly', start_date: '', end_date: '', period: '',
  })

  const canCreate = can('targets.create')
  const canApprove = can('targets.approve')
  const canRefresh = can('targets.refresh')

  const load = () => {
    const params = {}
    if (filters.section) params.section = filters.section
    if (filters.status) params.status = filters.status
    return api.get('/targets', { params })
      .then((r) => setRows(unwrap(r).data))
      .catch((ex) => setErr(errMsg(ex)))
  }
  useEffect(() => { load() }, [filters]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    api.get('/users', { params: { per_page: 200 } }).then((r) => setUsers(unwrap(r).data.data)).catch(() => {})
  }, [])

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const create = async (e) => {
    e.preventDefault()
    setErr(''); setNotice(''); setBusy(true)
    try {
      await api.post('/targets', {
        user_id: form.user_id,
        section: form.section || undefined,
        metric: form.metric,
        target_value: form.target_value,
        measurement_unit: form.measurement_unit || undefined,
        frequency: form.frequency,
        start_date: form.start_date || undefined,
        end_date: form.end_date || undefined,
        period: form.period || undefined,
      })
      setNotice('Target created and pending approval.')
      setOpenNew(false)
      setForm({ user_id: '', section: '', metric: '', target_value: '', measurement_unit: '', frequency: 'Monthly', start_date: '', end_date: '', period: '' })
      load()
    } catch (ex) { setErr(errMsg(ex)) }
    setBusy(false)
  }

  const act = async (fn, msg) => {
    setErr(''); setNotice('')
    try {
      await fn()
      setNotice(msg)
      load()
    } catch (ex) { setErr(errMsg(ex)) }
  }

  const list = rows?.data || []

  // Metric groups, flattened for the dropdown with optgroup labels.
  const metricGroups = Object.entries(TARGET_METRICS)

  return (
    <div className="space-y-4">
      <PageTitle sub="Staff performance indicators — set targets, then refresh achieved values from the operational records."
        right={canCreate && <Btn onClick={() => setOpenNew((v) => !v)}>{openNew ? 'Close form' : '+ New target'}</Btn>}>
        Staff Targets
      </PageTitle>

      <ErrorBox error={err} />
      <SuccessBox>{notice}</SuccessBox>

      {openNew && canCreate && (
        <Card title="Set a performance target">
          <form onSubmit={create} className="space-y-4">
            <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1.5">Staff member *</label>
                <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" required value={form.user_id} onChange={set('user_id')}>
                  <option value="">Select staff…</option>
                  {users.map((u) => <option key={u.id} value={u.id}>{u.full_name} — {u.role?.name || u.email}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1.5">Metric *</label>
                <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" required value={form.metric} onChange={set('metric')}>
                  <option value="">Select metric…</option>
                  {metricGroups.map(([group, items]) => (
                    <optgroup key={group} label={group}>
                      {items.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                    </optgroup>
                  ))}
                </select>
              </div>
              <Field label="Target value *" value={form.target_value} onChange={set('target_value')} type="number" step="0.01" min="0" ph="e.g. 40" />
              <Field label="Measurement unit" value={form.measurement_unit} onChange={set('measurement_unit')} ph="e.g. bills / visits / USD" />
              <Field label="Frequency" type="select" options={TARGET_FREQUENCIES} value={form.frequency} onChange={set('frequency')} />
              <Field label="Period (e.g. 2026)" value={form.period} onChange={set('period')} ph="e.g. 2026 or 2026-03" />
              <Field label="Start date" type="date" value={form.start_date} onChange={set('start_date')} />
              <Field label="End date" type="date" value={form.end_date} onChange={set('end_date')} />
              <Field label="Section (optional)" value={form.section} onChange={set('section')} ph="e.g. Enforcement" />
            </div>
            <div className="flex justify-end">
              <Btn type="submit" disabled={busy}>{busy ? 'Saving…' : 'Create target'}</Btn>
            </div>
          </form>
        </Card>
      )}

      {/* Filters + bulk refresh */}
      <div className="flex flex-wrap items-end gap-3">
        <div>
          <label className="block text-xs font-semibold text-slate-600 mb-1">Section</label>
          <select className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white"
            value={filters.section} onChange={(e) => setFilters({ ...filters, section: e.target.value })}>
            <option value="">All sections</option>
            {['Enforcement', 'Valuation', 'Account & Record', 'M&E'].map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-slate-600 mb-1">Status</label>
          <select className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white"
            value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
            <option value="">All</option>
            <option>Draft</option>
            <option>Approved</option>
            <option>Archived</option>
          </select>
        </div>
        {canRefresh && (
          <Btn tone="navy" onClick={() => act(async () => { const r = await api.post('/targets/refresh'); setNotice((unwrap(r).data?.refreshed ?? 0) + ' target(s) refreshed.') }, '')}>
            🔄 Refresh achieved values
          </Btn>
        )}
      </div>

      {!rows ? <Spinner label="Loading targets…" /> : list.length === 0 ? (
        <Card title="Targets"><p className="text-sm text-slate-400">No targets found.</p></Card>
      ) : (
        <Card title={`Targets (${rows.total})`}>
          <div className="-mx-5 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                  <th className="px-5 py-2">Staff</th>
                  <th className="px-2 py-2">Metric</th>
                  <th className="px-2 py-2">Period</th>
                  <th className="px-2 py-2 text-right">Target</th>
                  <th className="px-2 py-2 text-right">Achieved</th>
                  <th className="px-2 py-2">Progress</th>
                  <th className="px-2 py-2">Status</th>
                  <th className="px-5 py-2 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {list.map((t) => {
                  const pct = Math.min(t.achievement_pct, 100)
                  const pctTone = t.achievement_pct >= 100 ? 'green' : t.achievement_pct >= 60 ? 'amber' : 'red'
                  return (
                    <tr key={t.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                      <td className="px-5 py-2.5">
                        <div className="font-semibold text-navy-800">{t.staff}</div>
                        <div className="text-[11px] text-slate-400">{t.section || '—'} · {t.frequency}</div>
                      </td>
                      <td className="px-2 py-2.5">{targetMetricLabel(t.metric)}{t.measurement_unit ? <span className="text-[11px] text-slate-400"> ({t.measurement_unit})</span> : null}</td>
                      <td className="px-2 py-2.5 text-slate-500">{t.period}{t.start_date ? <div className="text-[11px] text-slate-400">{fmtDate(t.start_date)} → {fmtDate(t.end_date)}</div> : null}</td>
                      <td className="px-2 py-2.5 text-right font-semibold">{t.metric === 'collections_amount' ? fmtMoney(t.target_value) : t.target_value}</td>
                      <td className="px-2 py-2.5 text-right">{t.metric === 'collections_amount' ? fmtMoney(t.achieved_value) : t.achieved_value}</td>
                      <td className="px-2 py-2.5">
                        <div className="flex items-center gap-2">
                          <div className="w-24 h-2 rounded-full bg-slate-100 overflow-hidden">
                            <div className={`h-full rounded-full ${pctTone === 'green' ? 'bg-emerald-500' : pctTone === 'amber' ? 'bg-amber-400' : 'bg-red-500'}`} style={{ width: `${pct}%` }} />
                          </div>
                          <Badge tone={pctTone}>{t.achievement_pct}%</Badge>
                        </div>
                      </td>
                      <td className="px-2 py-2.5"><Badge tone={STATUS_TONE[t.status] || 'slate'}>{t.status}</Badge></td>
                      <td className="px-5 py-2.5 text-right">
                        <div className="flex justify-end gap-2">
                          {canApprove && t.status === 'Draft' && (
                            <Btn tone="success" onClick={() => act(() => api.post(`/targets/${t.id}/approve`), 'Target approved.')}>Approve</Btn>
                          )}
                          {canRefresh && t.status === 'Approved' && (
                            <Btn tone="white" onClick={() => act(() => api.post(`/targets/refresh/${t.id}`), 'Achieved value refreshed.')}>Refresh</Btn>
                          )}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  )

  function Field({ label, value, onChange, ph, type = 'text', options, step, min }) {
    return (
      <div>
        <label className="block text-xs font-semibold text-slate-600 mb-1.5">{label}</label>
        {type === 'select' ? (
          <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={value} onChange={onChange}>
            {options.map((o) => <option key={o} value={o}>{o}</option>)}
          </select>
        ) : (
          <Input className="w-full" type={type} step={step} min={min} value={value} onChange={onChange} placeholder={ph} />
        )}
      </div>
    )
  }
}