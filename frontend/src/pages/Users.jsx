import { useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import { Spinner, ErrorBox, PageTitle, Card, Btn, Input, Badge } from '../ui'
import { useAuth } from '../auth'

export default function Users() {
  const { can } = useAuth()
  const [rows, setRows] = useState(null)
  const [sections, setSections] = useState([])
  const [assignableRoles, setAssignableRoles] = useState([])
  const [supervisors, setSupervisors] = useState([])
  const [err, setErr] = useState(null)
  const [flash, setFlash] = useState('')
  const [q, setQ] = useState('')
  const [section, setSection] = useState('')
  const [role, setRole] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const [permsFor, setPermsFor] = useState(null)

  const canInspect = can('rbac.assign_role_to_user') || can('rbac.assign_permissions') || can('staff.view') || can('staff.edit')

  const loadUsers = () => {
    const params = { per_page: 100 }
    if (q) params.q = q
    if (section) params.section = section
    if (role) params.role = role
    api.get('/users', { params })
      .then((r) => setRows(unwrap(r).data))
      .catch((e) => setErr({ message: errMsg(e) }))
  }

  useEffect(() => {
    api.get('/sections').then((r) => setSections(unwrap(r).data || [])).catch(() => {})
    api.get('/lookup/roles').then((r) => setAssignableRoles(unwrap(r).data || [])).catch(() => {})
    api.get('/lookup/supervisors').then((r) => setSupervisors(unwrap(r).data || [])).catch(() => {})
    loadUsers()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const applyFilter = (k) => (e) => {
    const v = e.target.value
    if (k === 'q') setQ(v)
    if (k === 'section') setSection(v)
    if (k === 'role') setRole(v)
    const params = { per_page: 100 }
    if (k === 'q') params.q = v
    if (k === 'section') params.section = v
    if (k === 'role') params.role = v
    api.get('/users', { params })
      .then((r) => setRows(unwrap(r).data))
      .catch((e) => setErr({ message: errMsg(e) }))
  }

  if (err) return <ErrorBox error={err} />
  if (!rows) return <Spinner label="Loading staff…" />

  const toggleActive = async (u) => {
    try {
      await api.patch(`/users/${u.id}/active`, { is_active: !u.is_active })
      setFlash(`${u.full_name} ${u.is_active ? 'deactivated' : 'activated'}.`)
      setTimeout(() => setFlash(''), 2500)
      loadUsers()
    } catch (ex) { setErr({ message: errMsg(ex) }) }
  }

  return (
    <div className="space-y-4">
      <PageTitle sub="Staff accounts, activation and team wiring."
        right={can('staff.create') && <Btn onClick={() => setShowCreate((s) => !s)}>{showCreate ? 'Cancel' : '+ Add staff'}</Btn>}>
        User Management
      </PageTitle>

      {flash && <div className="rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100 px-4 py-3 text-sm">{flash}</div>}
      <ErrorBox error={err} />

      {showCreate && (
        <CreateForm sections={sections} roles={assignableRoles} supervisors={supervisors}
          onDone={() => { setShowCreate(false); setFlash('Account created (inactive until activated).'); setTimeout(() => setFlash(''), 3000); loadUsers() }} />
      )}

      <Card>
        <div className="flex flex-wrap gap-3 mb-4">
          <Input placeholder="Search name, email, staff id…" value={q} onChange={applyFilter('q')} className="flex-1 min-w-[220px]" />
          <select value={section} onChange={applyFilter('section')} className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white">
            <option value="">All sections</option>
            {sections.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
          <select value={role} onChange={applyFilter('role')} className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white">
            <option value="">All roles</option>
            {assignableRoles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </select>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs uppercase tracking-wider text-slate-400 border-b border-slate-100">
                <th className="py-2 pr-3">Name</th>
                <th className="py-2 pr-3">Email</th>
                <th className="py-2 pr-3">Role</th>
                <th className="py-2 pr-3">Section</th>
                <th className="py-2 pr-3">Staff ID</th>
                <th className="py-2 pr-3">Status</th>
                {canInspect && <th className="py-2 pr-3">Effective permissions</th>}
                {can('staff.activate') && <th className="py-2 pr-3">Action</th>}
              </tr>
            </thead>
            <tbody>
              {(rows.data || []).map((u) => (
                <tr key={u.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                  <td className="py-2.5 pr-3 font-medium">{u.full_name}</td>
                  <td className="py-2.5 pr-3 text-slate-500">{u.email}</td>
                  <td className="py-2.5 pr-3">{u.role}</td>
                  <td className="py-2.5 pr-3 text-slate-500">{u.section}</td>
                  <td className="py-2.5 pr-3 text-slate-400">{u.staff_id}</td>
                  <td className="py-2.5 pr-3"><Badge tone={u.is_active ? 'green' : 'slate'}>{u.is_active ? 'Active' : 'Inactive'}</Badge></td>
                  {canInspect && (
                    <td className="py-2.5 pr-3">
                      <Btn tone="white" onClick={() => setPermsFor(u)}>Perms</Btn>
                    </td>
                  )}
                  {can('staff.activate') && (
                    <td className="py-2.5 pr-3">
                      <Btn tone={u.is_active ? 'white' : 'success'} onClick={() => toggleActive(u)}>
                        {u.is_active ? 'Deactivate' : 'Activate'}
                      </Btn>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      {permsFor && <EffectivePermissionsModal user={permsFor} onClose={() => setPermsFor(null)} />}
    </div>
  )
}

function CreateForm({ sections, roles, supervisors, onDone }) {
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState({ full_name: '', email: '', password: '', staff_id: '', section_id: '', role_id: '', supervisor_id: '' })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const submit = async (e) => {
    e.preventDefault()
    setErr(''); setBusy(true)
    try {
      await api.post('/users', form)
      onDone()
    } catch (ex) { setErr(errMsg(ex)) }
    setBusy(false)
  }

  return (
    <Card title="Add staff member">
      {err && <div className="mb-3 rounded-xl bg-red-50 text-red-700 border border-red-100 px-4 py-3 text-sm">⚠️ {err}</div>}
      <form onSubmit={submit} className="grid sm:grid-cols-2 gap-4">
        <L label="Full name"><Input className="w-full" value={form.full_name} onChange={set('full_name')} required /></L>
        <L label="Email"><Input className="w-full" type="email" value={form.email} onChange={set('email')} required /></L>
        <L label="Temporary password"><Input className="w-full" type="password" value={form.password} onChange={set('password')} required minLength={8} /></L>
        <L label="Staff ID"><Input className="w-full" value={form.staff_id} onChange={set('staff_id')} /></L>
        <L label="Section"><select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={form.section_id} onChange={set('section_id')} required>
          <option value="">Select section…</option>
          {sections.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select></L>
        <L label="Role"><select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={form.role_id} onChange={set('role_id')} required>
          <option value="">Select role…</option>
          {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
        </select></L>
        <L label="Reports to (supervisor)"><select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={form.supervisor_id} onChange={set('supervisor_id')}>
          <option value="">— No supervisor —</option>
          {supervisors.map((s) => <option key={s.id} value={s.id}>{s.full_name} — {s.role} ({s.section})</option>)}
        </select></L>
        <div className="sm:col-span-2 flex items-end">
          <Btn type="submit" disabled={busy} className="px-6">{busy ? 'Creating…' : 'Create account'}</Btn>
          <span className="ml-3 text-[11px] text-slate-400">New accounts start inactive and require a password change on first login.</span>
        </div>
      </form>
    </Card>
  )
}

function L({ label, children }) {
  return <div><label className="block text-xs font-semibold text-slate-600 mb-1.5">{label}</label>{children}</div>
}

function EffectivePermissionsModal({ user, onClose }) {
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')
  const [q, setQ] = useState('')
  const [draft, setDraft] = useState(null)
  const [saving, setSaving] = useState(false)
  const [flash, setFlash] = useState('')

  useEffect(() => {
    api.get(`/users/${user.id}/effective-permissions`)
      .then((r) => setData(unwrap(r).data))
      .catch((e) => setErr(errMsg(e)))
  }, [user.id])

  const current = data
    ? Object.keys(data.permissions).reduce((acc, mod) => {
        for (const p of data.permissions[mod]) if (p.name) acc[p.name] = !!p.granted
        return acc
      }, {})
    : {}

  const overrides = draft !== null ? draft : (data
    ? Object.keys(data.permissions).reduce((acc, mod) => {
        for (const p of data.permissions[mod]) if (p.override) acc[p.name] = p.override === 'allow'
        return acc
      }, {})
    : {})

  const dirty = draft !== null

  const setOverride = (name, val) => {
    const next = { ...overrides }
    if (val === null) delete next[name]
    else next[name] = val
    setDraft(next)
  }

  const resetOverride = (name) => {
    const next = { ...overrides }
    delete next[name]
    setDraft(next)
  }

  const save = async () => {
    setSaving(true); setErr('')
    try {
      await api.put(`/users/${user.id}/permissions`, { overrides })
      const r = unwrap(await api.get(`/users/${user.id}/effective-permissions`))
      setData(r.data)
      setDraft(null)
      setFlash('Permission overrides saved — they take effect on the user’s next request/refresh.')
      setTimeout(() => setFlash(''), 3500)
    } catch (e) { setErr(errMsg(e)) }
    setSaving(false)
  }

  const total = data
    ? Object.keys(data.permissions).reduce((n, k) => n + (data.permissions[k] || []).length, 0)
    : 0

  const editable = !!data?.can_edit

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" onClick={onClose}>
      <div className="flex w-full max-w-4xl max-h-[90vh] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
          <div>
            <div className="text-lg font-bold text-slate-800">Permissions & overrides</div>
            <div className="text-xs text-slate-500">
              {data ? `${data.user.full_name}${data.user.staff_id ? ` · ${data.user.staff_id}` : ''} · ${data.user.email}` : user.full_name}
            </div>
          </div>
          <button onClick={onClose} className="text-xl leading-none text-slate-400 hover:text-slate-600">×</button>
        </div>

        {err && <div className="rounded bg-red-50 px-5 py-3 text-sm text-red-700">⚠️ {err}</div>}
        {flash && <div className="rounded bg-emerald-50 px-5 py-3 text-sm text-emerald-700">✓ {flash}</div>}
        {!data && !err && <div className="p-10 text-center"><Spinner label="Resolving permissions…" /></div>}

        {data && (
          <>
            <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-3 text-xs">
              <Badge tone="navy">{data.role}</Badge>
              <Badge tone="slate">{data.section || 'No section'}</Badge>
              <Badge tone={data.default_scope === 'system' ? 'navy' : 'blue'}>{data.default_scope} scope</Badge>
              <Badge tone={data.user.is_active ? 'green' : 'slate'}>{data.user.is_active ? 'Active' : 'Inactive'}</Badge>
              <span className="ml-auto font-medium text-slate-500">
                {data.is_system_admin ? 'Full access (wildcard)' : `${data.permission_count} of ${total} effective · ${data.overridden_count} overridden`}
              </span>
            </div>

            {editable ? (
              <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50/60 px-5 py-3 text-xs">
                <span className="flex items-center gap-1.5 text-emerald-700"><span className="h-2 w-2 rounded-full bg-emerald-500" /> Granted by role</span>
                <span className="flex items-center gap-1.5 text-sky-700"><span className="h-2 w-2 rounded-full bg-sky-500" /> Granted individually</span>
                <span className="flex items-center gap-1.5 text-amber-700"><span className="h-2 w-2 rounded-full bg-amber-500" /> Denied individually</span>
                <div className="ml-auto flex items-center gap-2">
                  {dirty && <Btn tone="white" onClick={() => setDraft(null)}>Reset</Btn>}
                  <Btn onClick={save} disabled={!dirty || saving}>{saving ? 'Saving…' : dirty ? 'Save overrides' : 'Saved ✓'}</Btn>
                </div>
              </div>
            ) : (
              <div className="border-b border-slate-100 bg-slate-50/60 px-5 py-3 text-xs text-slate-500">
                Read-only — you need <b>rbac.assign_permissions</b> or <b>rbac.assign_role_to_user</b> to edit individual overrides. Override states shown: granted-by-role (green), granted-individually (blue), denied-individually (amber).
              </div>
            )}

            <div className="flex flex-wrap gap-1.5 border-b border-slate-100 px-5 py-3">
              <Chip on={data.capabilities.submission} label="Submit records" />
              <Chip on={data.capabilities.approval} label="Approve / verify" />
              <Chip on={data.capabilities.workflow_management} label="Workflow mgmt" />
              <Chip on={data.capabilities.reporting} label="Reports & audit" />
              <Chip on={data.capabilities.staff_management} label="Staff mgmt" />
              <Chip on={data.capabilities.rbac_management} label="RBAC mgmt" />
            </div>

            <div className="border-b border-slate-100 px-5 py-3">
              <Input placeholder="Filter permissions…" value={q} onChange={(e) => setQ(e.target.value)} className="w-full sm:w-80" />
            </div>

            <div className="flex-1 overflow-y-auto px-5 py-4">
              {Object.keys(data.permissions).map((mod) => {
                const perms = (data.permissions[mod] || []).filter((p) => {
                  if (!q.trim()) return true
                  return [p.name, p.action, p.description].filter(Boolean).join(' ').toLowerCase().includes(q.toLowerCase())
                })
                if (!perms.length) return null
                const grantedHere = perms.filter((p) => p.granted).length
                return (
                  <div key={mod} className="mb-4">
                    <div className="mb-1.5 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
                      {mod}
                      <span className="font-normal normal-case text-slate-300">{grantedHere}/{perms.length}</span>
                    </div>
                    <div className="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                      {perms.map((p) => {
                        const gbr = !!p.granted_by_role
                        const gi = !!p.granted_individually
                        const di = !!p.denied_individually
                        const sweet = overrides[p.name]
                        const state = sweet === undefined ? null : sweet
                        const effective = !!current[p.name]
                        const dot = gi ? 'bg-sky-500' : di ? 'bg-amber-500' : effective ? 'bg-emerald-500' : 'bg-slate-200'
                        return (
                          <div key={p.name}
                            className={`flex items-center gap-2 rounded-lg border px-2 py-1.5 text-sm ${di ? 'border-amber-100 bg-amber-50/50 text-slate-400' : effective ? 'border-transparent bg-transparent text-emerald-800' : 'text-slate-400'}`}>
                            <span className={`h-2 w-2 shrink-0 rounded-full ${dot}`} title={gi ? 'Granted individually' : di ? 'Denied individually' : effective ? 'Granted by role' : 'Not granted'} />
                            <span className="font-medium">{p.action}</span>
                            {editable && (
                              <TriState value={state} onChange={(v) => setOverride(p.name, v)} onReset={() => resetOverride(p.name)} />
                            )}
                            <span className="ml-auto text-[10px] text-slate-400">{p.name}</span>
                          </div>
                        )
                      })}
                    </div>
                  </div>
                )
              })}
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function TriState({ value, onChange, onReset }) {
  const seg = (label, active, click) => (
    <button type="button"
      onClick={click}
      className={`px-1.5 py-0.5 text-[10px] font-semibold leading-none ${active ? 'bg-slate-800 text-white' : 'text-slate-400 hover:text-slate-600'}`}>
      {label}
    </button>
  )
  return (
    <span className="flex items-center overflow-hidden rounded-md border border-slate-200">
      {seg('Allow', value === true, () => onChange(true))}
      {seg('Inherit', value === null, () => onReset())}
      {seg('Deny', value === false, () => onChange(false))}
    </span>
  )
}

function Chip({ on, label }) {
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-medium ${on ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-400'}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${on ? 'bg-emerald-500' : 'bg-slate-300'}`} />
      {label}
    </span>
  )
}