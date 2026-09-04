import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import api, { unwrap, errMsg } from '../../api'
import { useAuth } from '../../auth'
import { Badge, Btn, Input, Spinner, ErrorBox, PageTitle } from '../../ui'
import PropertyDetail from '../../components/PropertyDetail'
import {
  DISCOVERY_STATUSES, DISCOVERY_PATHS, discoveryStatusInfo,
  PROPERTY_CLASSIFICATIONS, PROPERTY_TYPES,
} from '../../lib/constants'

const EMPTY = {
  owner_name: '', owner_contact: '', tin: '',
  property_address: '', county: '', district: '', city_town: '', community: '', street: '', house_number: '',
  property_classification: '', property_type: '', occupancy_use: '', description: '',
  gps_lat: '', gps_lng: '', gps_accuracy: '', discovery_date: '', remarks: '',
}

export default function DiscoveriesTab() {
  const { can } = useAuth()
  const [sp] = useSearchParams()
  const [rows, setRows] = useState(null)
  const [err, setErr] = useState('')
  const [notice, setNotice] = useState('')
  const [filters, setFilters] = useState({ q: '', status: '', classification: '', path: '', officer: '', from: '', to: '' })
  const [page, setPage] = useState(1)
  const [users, setUsers] = useState([])
  const [openNew, setOpenNew] = useState(false)
  const [detail, setDetail] = useState(null)

  const canCreate = can('discovery.create')

  useEffect(() => {
    api.get('/users', { params: { per_page: 200 } })
      .then((r) => setUsers(unwrap(r).data.data))
      .catch(() => {})
  }, [])

  // Cross-module navigation — /enforcement?tab=discoveries&focus=<id> opens the detail.
  useEffect(() => {
    const focus = sp.get('focus')
    if (focus && can('discovery.view', 'discovery.review')) openDetail(Number(focus))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const load = (extra = {}) => {
    const params = {
      per_page: 25, page: extra.page ?? page,
      ...(filters.q ? { q: filters.q } : {}),
      ...(filters.status ? { status: filters.status } : {}),
      ...(filters.classification ? { classification: filters.classification } : {}),
      ...(filters.path ? { path: filters.path } : {}),
      ...(filters.officer ? { officer_id: filters.officer } : {}),
      ...(filters.from ? { date_from: filters.from } : {}),
      ...(filters.to ? { date_to: filters.to } : {}),
      ...extra,
    }
    api.get('/discoveries', { params })
      .then((r) => setRows(unwrap(r).data))
      .catch((ex) => setErr(errMsg(ex)))
  }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load() }, [page])

  const list = rows?.data || []
  const runAction = async (verb, payload = {}) => {
    if (!detail) return
    setErr(''); setNotice('')
    try {
      const r = await api.post(`/discoveries/${detail.id}/${verb}`, payload)
      setNotice(unwrap(r).message || 'Done.')
      load()
      refreshDetail(detail.id, true)
    } catch (ex) {
      setErr(errMsg(ex))
    }
  }

  // Allow stage-appropriate valuation actions on the linked record (RBAC-gated in the modal).
  const runValuationAction = async (verb, payload = {}) => {
    const v = detail?.valuation
    if (!v) { setErr('No linked valuation for this discovery yet — route to valuation first.'); return }
    setErr(''); setNotice('')
    try {
      const call = async () => {
        if (verb === 'submit') return api.post(`/valuations/${v.id}/submit`, payload)
        if (verb === 'assign') return api.post(`/valuations/${v.id}/assign`, payload)
        if (verb === 'forward') return api.post(`/valuations/${v.id}/review`, { decision: 'forward_ac', remarks: payload.remarks })
        if (verb === 'return') return api.post(`/valuations/${v.id}/review`, { decision: 'return', remarks: payload.remarks })
        if (verb === 'approve') return api.post(`/valuations/${v.id}/decide`, { decision: 'approve', remarks: payload.remarks })
        if (verb === 'reject') return api.post(`/valuations/${v.id}/decide`, { decision: 'reject', remarks: payload.remarks })
        if (verb === 'processing') return api.post(`/valuations/${v.id}/processing`)
      }
      await call()
      setNotice(`Valuation action «${verb}» applied.`)
      load()
      refreshDetail(detail.id, true)
    } catch (ex) {
      setErr(errMsg(ex))
    }
  }

  const refreshDetail = (id, silent) => {
    api.get(`/discoveries/${id}`)
      .then((r) => setDetail(unwrap(r).data))
      .catch((ex) => {
        if (!silent) { setErr(errMsg(ex)); setDetail(null) }
      })
  }

  const openDetail = (id) => refreshDetail(id, false)

  return (
    <div className="space-y-3">
      <PageTitle
        sub="Register of unregistered property finds — review, classify, then route (Path A: Account & Records → LITAS · Path V: Valuation → AC → LITAS)."
        right={canCreate && <Btn onClick={() => setOpenNew(true)}>+ New Discovery</Btn>} />

      <ErrorBox error={err} />
      {notice && <div className="rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100 px-4 py-3 text-sm">{notice}</div>}

      {/* Filters */}
      <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-2">
        <Input className="w-full" placeholder="Search ID, owner, address…" value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} />
        <select className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={filters.status}
          onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
          <option value="">All statuses</option>
          {DISCOVERY_STATUSES.map((s) => <option key={s} value={s}>{discoveryStatusInfo(s).label}</option>)}
        </select>
        <select className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={filters.classification}
          onChange={(e) => setFilters({ ...filters, classification: e.target.value })}>
          <option value="">All classifications</option>
          {PROPERTY_CLASSIFICATIONS.map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
        <select className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={filters.path}
          onChange={(e) => setFilters({ ...filters, path: e.target.value })}>
          <option value="">All paths</option>
          {DISCOVERY_PATHS.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
        </select>
        <select className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={filters.officer}
          onChange={(e) => setFilters({ ...filters, officer: e.target.value })}>
          <option value="">All officers</option>
          {users.map((u) => <option key={u.id} value={u.id}>{u.full_name}</option>)}
        </select>
        <Input type="date" className="w-full" title="From discovery date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
        <Input type="date" className="w-full" title="To discovery date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
        <Btn tone="white" onClick={() => { setPage(1); load({ page: 1 }) }}>Apply filters</Btn>
      </div>

      {/* Register table */}
      {!rows ? <Spinner label="Loading discoveries…" /> : (
        <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-wider text-slate-400 border-b border-slate-100">
                  <th className="px-3 py-3">Discovery ID</th>
                  <th className="px-3 py-3">Owner / Occupant</th>
                  <th className="px-3 py-3">Location</th>
                  <th className="px-3 py-3">Classification</th>
                  <th className="px-3 py-3">Path</th>
                  <th className="px-3 py-3">Status</th>
                  <th className="px-3 py-3">Assigned To</th>
                  <th className="px-3 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {list.length === 0 && (
                  <tr><td colSpan={8} className="px-3 py-8 text-center text-slate-400">No discoveries match.</td></tr>
                )}
                {list.map((d) => {
                  const info = discoveryStatusInfo(d.status)
                  return (
                    <tr key={d.id} className="hover:bg-slate-50">
                      <td className="px-3 py-2.5"><span className="font-semibold text-navy-800">{d.discovery_reference}</span></td>
                      <td className="px-3 py-2.5 text-slate-600">{d.owner_name || '—'}</td>
                      <td className="px-3 py-2.5 text-slate-600 max-w-[220px] truncate block">{d.property_address || [d.city_town, d.community, d.county].filter(Boolean).join(', ') || '—'}</td>
                      <td className="px-3 py-2.5"><Badge tone="slate">{d.property_classification || '—'}</Badge></td>
                      <td className="px-3 py-2.5">{d.decision_path ? <Badge tone="navy">Path {d.decision_path.slice(0, 1).toUpperCase()}</Badge> : <span className="text-slate-300">—</span>}</td>
                      <td className="px-3 py-2.5"><Badge tone={info.tone}>{info.label}</Badge></td>
                      <td className="px-3 py-2.5 text-xs text-slate-500">{d.discovered_by}</td>
                      <td className="px-3 py-2.5 text-right">
                        <Btn tone="white" className="!px-3 !py-1.5" onClick={() => openDetail(d.id)}>View</Btn>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          {rows.last_page > 1 && (
            <div className="flex items-center justify-between px-4 py-3 border-t border-slate-100">
              <span className="text-xs text-slate-400">Page {rows.current_page} of {rows.last_page} · {rows.total} discoveries</span>
              <div className="flex gap-2">
                <Btn tone="white" disabled={!rows.prev_page_url} onClick={() => setPage(rows.current_page - 1)}>‹ Prev</Btn>
                <Btn tone="white" disabled={!rows.next_page_url} onClick={() => setPage(rows.current_page + 1)}>Next ›</Btn>
              </div>
            </div>
          )}
        </div>
      )}

      {openNew && <NewDiscoveryModal onClose={() => setOpenNew(false)} onSaved={() => { setOpenNew(false); setNotice('Discovery recorded.'); setPage(1); load({ page: 1 }) }} />}
      {detail && <PropertyDetail discovery={detail} valuation={detail.valuation} onClose={() => setDetail(null)} runAction={runAction} runValuationAction={runValuationAction} assignmentUsers={users} />}
    </div>
  )
}

/* ------------------------------------------------------------------ */
/*  New Discovery modal (record, then submitted via the detail modal)  */
/* ------------------------------------------------------------------ */

function NewDiscoveryModal({ onClose, onSaved }) {
  const [form, setForm] = useState(EMPTY)
  const [photos, setPhotos] = useState([])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const fileRef = useRef(null)
  const cameraRef = useRef(null)

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const locate = () => {
    if (!navigator.geolocation) return alert('Browser geolocation unavailable — enter GPS manually.')
    navigator.geolocation.getCurrentPosition(
      (p) => setForm((f) => ({ ...f, gps_lat: p.coords.latitude.toFixed(6), gps_lng: p.coords.longitude.toFixed(6), gps_accuracy: Math.round(p.coords.accuracy) })),
      () => alert('Could not read location — enter GPS manually.'),
    )
  }
  const addPhotos = (files) => {
    const readers = Array.from(files).map((f) => new Promise((resolve) => {
      const fr = new FileReader()
      fr.onload = () => resolve({ name: f.name, data_uri: fr.result })
      fr.readAsDataURL(f)
    }))
    Promise.all(readers).then((arr) => setPhotos((p) => [...p, ...arr]))
  }

  const submit = async (e) => {
    e.preventDefault()
    setErr(''); setBusy(true)
    try {
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))
      const r = await api.post('/discoveries', payload)
      const d = unwrap(r).data
      for (const ph of photos) {
        await api.post('/evidence/photos', {
          photo_type: 'PROPERTY_FULL_VIEW',
          discovery_id: d.id,
          data_uri: ph.data_uri,
          gps_lat: form.gps_lat || undefined,
          gps_lng: form.gps_lng || undefined,
        })
      }
      onSaved()
    } catch (ex) {
      setErr(errMsg(ex))
    }
    setBusy(false)
  }

  return (
    <div className="fixed inset-0 z-50 bg-navy-950/60 backdrop-blur-sm grid place-items-center p-4 overflow-y-auto">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
        <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white z-10">
          <h3 className="font-bold text-navy-800">Record a new property discovery</h3>
          <button onClick={onClose} className="w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-500 font-bold">✕</button>
        </div>
        <form onSubmit={submit} className="p-6 space-y-5">
          <ErrorBox error={err} />
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <Field label="Owner / occupant name" value={form.owner_name} onChange={set('owner_name')} ph="If known" />
            <Field label="Owner contact" value={form.owner_contact} onChange={set('owner_contact')} ph="Phone / email" />
            <Field label="TIN" value={form.tin} onChange={set('tin')} ph="Taxpayer ID number" />
            <div className="lg:col-span-3">
              <label className="block text-xs font-semibold text-slate-600 mb-1.5">Property address</label>
              <Input className="w-full" value={form.property_address} onChange={set('property_address')} placeholder="Street, city/town, community" />
            </div>
            <Field label="County" value={form.county} onChange={set('county')} ph="e.g. Montserrado" />
            <Field label="District" value={form.district} onChange={set('district')} ph="e.g. Greater Monrovia" />
            <Field label="City / town" value={form.city_town} onChange={set('city_town')} />
            <Field label="Community" value={form.community} onChange={set('community')} />
            <Field label="Street" value={form.street} onChange={set('street')} />
            <Field label="House number" value={form.house_number} onChange={set('house_number')} />
            <Select label="Property classification" value={form.property_classification} onChange={set('property_classification')} options={PROPERTY_CLASSIFICATIONS} />
            <Select label="Property type" value={form.property_type} onChange={set('property_type')} options={PROPERTY_TYPES} />
            <Field label="Occupancy / use" value={form.occupancy_use} onChange={set('occupancy_use')} ph="e.g. Owner-occupied rental" />
            <div className="lg:col-span-3">
              <label className="block text-xs font-semibold text-slate-600 mb-1.5">Description</label>
              <textarea className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm" rows={2} value={form.description} onChange={set('description')} placeholder="Structure, surroundings, notable features" />
            </div>
          </div>

          <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <Field label="GPS latitude" value={form.gps_lat} onChange={set('gps_lat')} ph="e.g. 6.315611" />
            <Field label="GPS longitude" value={form.gps_lng} onChange={set('gps_lng')} ph="e.g. -10.807407" />
            <Field label="GPS accuracy (m)" value={form.gps_accuracy} onChange={set('gps_accuracy')} />
            <Field label="Discovery date" type="date" value={form.discovery_date} onChange={set('discovery_date')} />
          </div>
          <div className="flex flex-wrap gap-3">
            <Btn tone="navy" onClick={locate} type="button">📡 Use my location</Btn>
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-600 mb-1.5">Field photographs ({photos.length} captured)</label>
            <div className="flex flex-wrap gap-2">
              <input ref={cameraRef} type="file" accept="image/*" capture="environment" className="hidden"
                onChange={(e) => addPhotos(e.target.files)} />
              <Btn tone="white" type="button" onClick={() => cameraRef.current?.click()}>📷 Take photo</Btn>
              <input ref={fileRef} type="file" accept="image/*" multiple className="hidden"
                onChange={(e) => addPhotos(e.target.files)} />
              <Btn tone="white" type="button" onClick={() => fileRef.current?.click()}>⬆ Upload</Btn>
            </div>
            {photos.length > 0 && (
              <div className="flex gap-2 mt-2 flex-wrap">
                {photos.map((p, i) => (
                  <div key={i} className="relative w-20 h-20 rounded-lg overflow-hidden border border-slate-200">
                    <img src={p.data_uri} alt={p.name} className="w-full h-full object-cover" />
                    <button type="button" onClick={() => setPhotos(photos.filter((_, x) => x !== i))}
                      className="absolute top-0 right-0 bg-red-600 text-white text-[10px] w-5 h-5 rounded-bl-lg">✕</button>
                  </div>
                ))}
              </div>
            )}
          </div>

          <div className="flex justify-end gap-2">
            <Btn tone="white" type="button" onClick={onClose}>Cancel</Btn>
            <Btn type="submit" disabled={busy}>{busy ? 'Recording…' : 'Record discovery'}</Btn>
          </div>
        </form>
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/*  Field / Select form helpers                                        */
/* ------------------------------------------------------------------ */

function Field({ label, value, onChange, ph, type = 'text' }) {
  return (
    <div>
      <label className="block text-xs font-semibold text-slate-600 mb-1.5">{label}</label>
      <Input className="w-full" type={type} value={value} onChange={onChange} placeholder={ph} />
    </div>
  )
}

function Select({ label, value, onChange, options, ph = 'Select…' }) {
  return (
    <div>
      <label className="block text-xs font-semibold text-slate-600 mb-1.5">{label}</label>
      <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={value} onChange={onChange}>
        <option value="">{ph}</option>
        {options.map((o) => <option key={o} value={o}>{o}</option>)}
      </select>
    </div>
  )
}