import { Fragment, useEffect, useMemo, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import { Spinner, ErrorBox, PageTitle, Card, Badge, Btn, Input } from '../ui'
import { useAuth } from '../auth'

export default function RolesAdmin() {
  const { can } = useAuth()
  const [roles, setRoles] = useState(null)
  const [catalog, setCatalog] = useState(null)
  const [sections, setSections] = useState([])
  const [err, setErr] = useState(null)
  const [flash, setFlash] = useState('')
  const [selected, setSelected] = useState(null)
  const [showCreate, setShowCreate] = useState(false)
  const [draft, setDraft] = useState(null)
  const [saving, setSaving] = useState(false)
  const [filter, setFilter] = useState('')
  const [view, setView] = useState('checklist')
  const [cloneDraft, setCloneDraft] = useState(null)
  const canCreate = can('rbac.create_role')
  const canEdit = can('rbac.assign_permissions')

  const loadRoles = async () => {
    try { setRoles(unwrap(await api.get('/admin/roles')).data) } catch (e) { setErr({ message: errMsg(e) }) }
  }

  const selectRole = (id) => {
    const role = roles.find((r) => r.id === id)
    setSelected(id)
    setDraft(role ? { roleId: role.id, perms: [...(role.permissions || [])] } : null)
  }

  useEffect(() => {
    loadRoles()
    api.get('/admin/permission-catalog').then((r) => setCatalog(unwrap(r).data)).catch(() => {})
    api.get('/sections').then((r) => setSections(unwrap(r).data || [])).catch(() => {})
  }, []) // eslint-disable-line

  const live = selected ? roles.find((r) => r.id === selected) : null
  const current = useMemo(() => {
    if (draft && draft.roleId === live?.id) return draft.perms
    return live?.permissions || []
  }, [draft, live])

  const dirty = useMemo(() => {
    if (!live) return false
    const a = [...(live.permissions || [])].sort()
    const b = [...current].sort()
    return JSON.stringify(a) !== JSON.stringify(b)
  }, [current, live])

  if (err) return <ErrorBox error={err} />
  if (!roles || !catalog) return <Spinner label="Loading roles…" />

  const togglePerm = (perm) => {
    const has = new Set(current)
    if (has.has(perm)) has.delete(perm)
    else has.add(perm)
    setDraft({ roleId: live.id, perms: [...has] })
  }

  const toggleActive = async () => {
    try {
      await api.put(`/admin/roles/${live.id}`, { is_active: !live.is_active })
      await loadRoles()
      if (draft && draft.roleId === live.id) setDraft({ ...draft, roleId: live.id })
      setFlash(`${live.name} is now ${live.is_active ? 'inactive' : 'active'}.`)
      setTimeout(() => setFlash(''), 2500)
    } catch (e) { setErr({ message: errMsg(e) }) }
  }

  const save = async () => {
    setErr(null); setSaving(true)
    try {
      await api.put(`/admin/roles/${live.id}`, { permissions: current })
      await loadRoles()
      setFlash(`Saved ${live.name} permissions.`)
      setDraft(null)
      setTimeout(() => setFlash(''), 2000)
    } catch (e) { setErr({ message: errMsg(e) }) }
    setSaving(false)
  }

  const discard = () => {
    setDraft(null)
    setFlash('Changes discarded.')
    setTimeout(() => setFlash(''), 1800)
  }

  const grantedCount = new Set(current).size

  return (
    <div className="space-y-4">
      <PageTitle sub="Tick the permission checklist per role, then press Save. Scope controls record visibility."
        right={
          <div className="flex items-center gap-2">
            <div className="flex rounded-xl border border-slate-200 overflow-hidden text-xs font-semibold">
              <button onClick={() => setView('checklist')} className={`px-3 py-1.5 transition ${view === 'checklist' ? 'bg-brand-600 text-white' : 'text-slate-500 hover:bg-slate-50'}`}>Checklist</button>
              <button onClick={() => setView('matrix')} className={`px-3 py-1.5 transition ${view === 'matrix' ? 'bg-brand-600 text-white' : 'text-slate-500 hover:bg-slate-50'}`}>Matrix</button>
            </div>
            {canCreate && <Btn onClick={() => setShowCreate((s) => !s)}>{showCreate ? 'Cancel' : '+ New role'}</Btn>}
          </div>
        }>
        Roles & Permissions
      </PageTitle>

      {flash && <div className="rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100 px-4 py-3 text-sm">{flash}</div>}

      {showCreate && (
        <CreateForm sections={sections}
          onDone={(id) => { setShowCreate(false); setSelected(null); setFlash('Role created — select it and tick permissions, then Save.'); setTimeout(() => setFlash(''), 3500); loadRoles().then(() => selectRole(id)) }} />
      )}

      {cloneDraft && (
        <CloneForm roleId={cloneDraft.roleId} initialName={cloneDraft.name}
          onDone={() => { setCloneDraft(null); setFlash('Role cloned — select it to review permissions.'); setTimeout(() => setFlash(''), 3000); loadRoles() }} />
      )}

      <div className="grid lg:grid-cols-[300px_1fr] gap-4">
        <Card>
          <ul className="space-y-1">
            {roles.map((r) => (
              <li key={r.id}>
                <button onClick={() => { selectRole(r.id); setErr(null) }}
                  className={`w-full text-left px-3 py-2.5 rounded-xl text-sm font-medium transition ${selected === r.id ? 'bg-brand-50 text-brand-700 border border-brand-100' : 'hover:bg-slate-50'}`}>
                  <div className="flex items-center justify-between gap-2">
                    <span className="truncate">{r.name}</span>
                    <span className="flex items-center gap-1.5 shrink-0">
                      {!r.is_active && <Badge tone="slate">Inactive</Badge>}
                      <Badge tone={r.default_scope === 'system' ? 'navy' : 'slate'}>{r.default_scope}</Badge>
                    </span>
                  </div>
                </button>
              </li>
            ))}
          </ul>
        </Card>

        <Card title={live ? live.name : 'Select a role'} right={live && (
          <div className="flex flex-wrap items-center gap-2">
            <div className="text-xs text-slate-400">
              Scope: <Badge tone="navy">{live.default_scope}</Badge> · {grantedCount} permissions {dirty && <span className="text-amber-600 font-semibold">· unsaved</span>}
            </div>
            {canCreate && !live.is_system_role && (
              <Btn tone="white" onClick={() => setCloneDraft({ roleId: live.id, name: `${live.name} (copy)` })}>Clone</Btn>
            )}
            {canEdit && !live.is_system_role && (
              <Btn tone={live.is_active ? 'danger' : 'success'} onClick={toggleActive}>{live.is_active ? 'Deactivate' : 'Activate'}</Btn>
            )}
          </div>
        )}>
          {view === 'matrix' ? (
            <RoleMatrix roles={roles} catalog={catalog} selected={live?.id} onSelect={(id) => { selectRole(id); setErr(null) }} />
          ) : !live ? (
            <p className="text-sm text-slate-400">Select a role to edit its permission checklist.</p>
          ) : live.is_system_role ? (
            <div className="rounded-xl bg-amber-50 text-amber-700 border border-amber-100 px-4 py-3 text-sm">
              The System Administrator role has full access and is protected from edits.
            </div>
          ) : (
            <div className="space-y-4">
              {!canEdit && (
                <div className="rounded-xl bg-slate-50 text-slate-500 border border-slate-100 px-4 py-3 text-sm">
                  Read-only — you need <b>rbac.assign_permissions</b> to modify this checklist.
                </div>
              )}

              {canEdit && (
                <div className="sticky top-0 z-10 -mx-1 px-1 py-2 bg-white/95 backdrop-blur border-b border-slate-100 flex items-center gap-3">
                  <Btn onClick={save} disabled={!dirty || saving} className="px-6">
                    {saving ? 'Saving…' : dirty ? 'Save permissions' : 'Saved ✓'}
                  </Btn>
                  <Btn tone="white" onClick={discard} disabled={!dirty}>Discard</Btn>
                  <span className="text-[11px] text-slate-400">
                    {dirty ? `${Math.abs((live.permissions || []).length - current.length)} permission changes pending` : 'No unsaved changes'}
                  </span>
                </div>
              )}

              <Input placeholder="Search permissions… (module, action or description)" value={filter}
                onChange={(e) => setFilter(e.target.value)} className="w-full sm:w-80" />

              {Object.keys(catalog).map((module) => {
                const perms = catalog[module].filter((p) => {
                  if (!filter.trim()) return true
                  const terms = [p.name, p.action, p.description].filter(Boolean).join(' ').toLowerCase()
                  return terms.includes(filter.toLowerCase())
                })
                if (!perms.length) return null
                const grantedHere = perms.filter((p) => current.includes(p.name)).length
                return (
                <div key={module}>
                  <div className="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                    {module}
                    <span className="normal-case font-normal text-slate-300">
                      {grantedHere}/{perms.length}
                    </span>
                  </div>
                  <div className="grid sm:grid-cols-2 gap-x-6 gap-y-1">
                    {perms.map((p) => {
                      const on = current.includes(p.name)
                      return (
                        <label key={p.name}
                          className={`flex items-start gap-2.5 px-2 py-1.5 rounded-lg cursor-pointer text-sm transition ${on ? 'bg-brand-50/60 text-brand-800' : 'text-slate-600 hover:bg-slate-50'}`} title={p.description || p.name}>
                          <input type="checkbox" className="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-400"
                            checked={on} disabled={!canEdit} onChange={() => togglePerm(p.name)} />
                          <span>
                            <span className="font-medium">{p.action}</span>
                            <span className="block text-[11px] text-slate-400">{p.description || p.name}</span>
                          </span>
                        </label>
                      )
                    })}
                  </div>
                </div>
                )
              })}
            </div>
          )}
        </Card>
      </div>
    </div>
  )
}

function CreateForm({ sections, onDone }) {
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState({ name: '', description: '', default_scope: 'own', section_id: '' })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const submit = async (e) => {
    e.preventDefault()
    setErr(''); setBusy(true)
    try {
      const r = unwrap(await api.post('/admin/roles', { ...form, section_id: form.section_id || null }))
      onDone(r.data.id)
    } catch (ex) { setErr(errMsg(ex)) }
    setBusy(false)
  }

  return (
    <Card title="New role">
      {err && <div className="mb-3 rounded-xl bg-red-50 text-red-700 border border-red-100 px-4 py-3 text-sm">⚠️ {err}</div>}
      <form onSubmit={submit} className="grid sm:grid-cols-2 gap-4">
        <L label="Role name"><Input className="w-full" value={form.name} onChange={set('name')} required /></L>
        <L label="Data scope">
          <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={form.default_scope} onChange={set('default_scope')}>
            <option value="own">own — own records only</option>
            <option value="team">team — self + supervisees</option>
            <option value="section">section — entire section</option>
            <option value="division">division — all sections</option>
            <option value="system">system — unrestricted</option>
          </select>
        </L>
        <L label="Section (optional)">
          <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={form.section_id} onChange={set('section_id')}>
            <option value="">— No default section —</option>
            {sections.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </L>
        <L label="Description"><Input className="w-full" value={form.description} onChange={set('description')} placeholder="What this role is for" /></L>
        <div className="sm:col-span-2 flex items-end">
          <Btn type="submit" disabled={busy} className="px-6">{busy ? 'Creating…' : 'Create role'}</Btn>
          <span className="ml-3 text-[11px] text-slate-400">New roles start active with no permissions — tick the checklist and Save.</span>
        </div>
      </form>
    </Card>
  )
}

function L({ label, children }) {
  return <div><label className="block text-xs font-semibold text-slate-600 mb-1.5">{label}</label>{children}</div>
}

function RoleMatrix({ roles, catalog, selected, onSelect }) {
  const full = (r) => r.is_system_role || (r.permissions || []).includes('*')
  return (
    <div className="space-y-4">
      <div className="text-xs text-slate-400">Read-only permission matrix — one row per function, one column per role. The System Administrator column shows full access.</div>
      <div className="overflow-x-auto rounded-xl border border-slate-100">
        <table className="w-full text-xs">
          <thead>
            <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-400">
              <th className="py-2 px-3 sticky left-0 bg-slate-50 min-w-[220px] border-r border-slate-100">Function</th>
              {roles.map((r) => (
                <th key={r.id} className={`py-2 px-2 text-center min-w-[120px] ${selected === r.id ? 'text-brand-700 font-bold' : ''}`}>
                  <button onClick={() => onSelect(r.id)} className="hover:underline" disabled={!r.is_active}>{r.name}</button>
                  {!r.is_active && <span className="block text-[9px] text-slate-400 font-normal">inactive</span>}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {Object.keys(catalog).map((module) => {
              const perms = catalog[module]
              return (
                <Fragment key={module}>
                  <tr className="bg-brand-50/40">
                    <td colSpan={roles.length + 1} className="py-1.5 px-3 font-bold uppercase tracking-wider text-[10px] text-brand-700">{module}</td>
                  </tr>
                  {perms.map((p) => (
                    <tr key={p.name} className="border-b border-slate-50 hover:bg-slate-50/60">
                      <td className="py-1.5 px-3 sticky left-0 bg-white border-r border-slate-100">
                        <span className="font-medium text-slate-700">{p.action}</span>
                        {p.description && <span className="block text-[10px] text-slate-400">{p.description}</span>}
                      </td>
                      {roles.map((r) => {
                        const granted = full(r) || (r.permissions || []).includes(p.name)
                        return (
                          <td key={r.id} className="py-1.5 px-2 text-center">
                            <span className={`inline-flex h-5 w-5 items-center justify-center rounded-full text-[11px] ${granted ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-300'}`}>{granted ? '✓' : '—'}</span>
                          </td>
                        )
                      })}
                    </tr>
                  ))}
                </Fragment>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function CloneForm({ roleId, initialName, onDone }) {
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [name, setName] = useState(initialName)

  const submit = async (e) => {
    e.preventDefault()
    setErr(''); setBusy(true)
    try {
      await api.post(`/admin/roles/${roleId}/clone`, { name, description: '' })
      onDone()
    } catch (ex) { setErr(errMsg(ex)) }
    setBusy(false)
  }

  return (
    <Card title={`Clone role — ${initialName}`}>
      {err && <div className="mb-3 rounded-xl bg-red-50 text-red-700 border border-red-100 px-4 py-3 text-sm">⚠️ {err}</div>}
      <form onSubmit={submit} className="grid sm:grid-cols-2 gap-4">
        <L label="New role name"><Input className="w-full" value={name} onChange={(e) => setName(e.target.value)} required /></L>
        <div className="sm:col-span-2 flex items-end">
          <Btn type="submit" disabled={busy} className="px-6">{busy ? 'Cloning…' : 'Create clone'}</Btn>
          <Btn tone="white" type="button" onClick={onDone} className="ml-2">Cancel</Btn>
          <span className="ml-3 text-[11px] text-slate-400">Copies the permission checklist, data scope and active state. The clone is never a system role.</span>
        </div>
      </form>
    </Card>
  )
}