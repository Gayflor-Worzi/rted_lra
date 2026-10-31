import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import api, { unwrap, errMsg } from '../../api'
import { useAuth } from '../../auth'
import { Badge, Btn, Input, Spinner, ErrorBox, PageTitle } from '../../ui'
import PropertyDetail from '../../components/PropertyDetail'
import {
  DISCOVERY_STATUSES, discoveryStatusInfo,
  PROPERTY_CLASSIFICATIONS, PROPERTY_TYPES,
  VALUE_DECLARATION_TYPES, valueDeclarationInfo, fmtMoney,
} from '../../lib/constants'

/**
 * Preview of a just-selected local File.
 *
 * The object URL has to be minted inside an effect and revoked on cleanup.
 * Calling URL.createObjectURL() inline in JSX minted a fresh URL on every render
 * of the upload form, leaking the previous one and forcing the <img> to reload
 * the image on each keystroke elsewhere in the form.
 */
function LocalPreview({ file, alt }) {
  const [url, setUrl] = useState(null)

  useEffect(() => {
    if (!file) { setUrl(null); return undefined }
    const objectUrl = URL.createObjectURL(file)
    setUrl(objectUrl)
    return () => URL.revokeObjectURL(objectUrl)
  }, [file])

  if (!url) return <div className="w-full h-full bg-slate-100" />
  return <img src={url} alt={alt || file.name || 'photo'} className="w-full h-full object-cover" />
}

const EMPTY = {
  owner_name: '', owner_contact: '', tin: '',
  property_address: '', county: '', district: '', city_town: '', community: '', street: '', house_number: '',
  property_classification: '', property_type: '', occupancy_use: '', description: '',
  value_declaration_type: '', declared_assessed_value: '', documentary_value: '',
  gps_lat: '', gps_lng: '', gps_accuracy: '', discovery_date: '', remarks: '',
}

export default function DiscoveriesTab() {
  const { can } = useAuth()
  const [sp] = useSearchParams()
  const [rows, setRows] = useState(null)
  const [err, setErr] = useState('')
  const [notice, setNotice] = useState('')
  const [filters, setFilters] = useState({ q: '', status: '', classification: '', declaration: '', value_min: '', value_max: '', from: '', to: '' })
  const [page, setPage] = useState(1)
  const [users, setUsers] = useState([])
  const [openNew, setOpenNew] = useState(false)
  const [detail, setDetail] = useState(null)

  const canCreate = can('discovery.create')

  useEffect(() => {
    // The assignable lookup, not /users: /users needs staff.view, which an
    // Account & Records Officer lacks, so the officer picker came back empty.
    Promise.all([
      api.get('/lookup/assignable', { params: { task_type: 'Account & Record Processing' } }),
      api.get('/lookup/assignable', { params: { task_type: 'Enforcement Visit' } }),
    ])
      .then(([acct, enf]) => {
        const seen = new Set()
        // unwrap() already yields the response BODY, so the rows sit at
        // unwrap(r).data — one level, not two. Reading .data.data here threw
        // "undefined is not iterable" inside this handler, the rejection was
        // swallowed by the catch below, and `users` silently stayed empty for
        // the whole page.
        setUsers([...unwrap(acct).data, ...unwrap(enf).data].filter((u) => {
          if (seen.has(u.id)) return false
          seen.add(u.id)
          return true
        }))
      })
      .catch((e) => console.warn('[DiscoveriesTab] staff lookup failed', e))
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
      ...(filters.declaration ? { value_declaration_type: filters.declaration } : {}),
      ...(filters.value_min ? { value_min: filters.value_min } : {}),
      ...(filters.value_max ? { value_max: filters.value_max } : {}),
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
        if (verb === 'begin') return api.post(`/valuations/${v.id}/begin`)
        if (verb === 'submit') return api.post(`/valuations/${v.id}/submit`, payload)
        if (verb === 'assign') return api.post(`/valuations/${v.id}/assign`, payload)
        if (verb === 'forward') return api.post(`/valuations/${v.id}/review`, { decision: 'confirm_ac', remarks: payload.remarks })
        if (verb === 'return') return api.post(`/valuations/${v.id}/review`, { decision: 'return', remarks: payload.remarks })
        if (verb === 'approve') return api.post(`/valuations/${v.id}/decide`, { decision: 'approve', remarks: payload.remarks })
        if (verb === 'reject') return api.post(`/valuations/${v.id}/decide`, { decision: 'reject', remarks: payload.remarks })
        if (verb === 'decide-return') return api.post(`/valuations/${v.id}/decide`, { decision: 'return', remarks: payload.remarks })
        if (verb === 'account-approve') return api.post(`/valuations/${v.id}/account-review`, { decision: 'approve', officer_id: payload.officer_id, remarks: payload.remarks })
        if (verb === 'account-return') return api.post(`/valuations/${v.id}/account-review`, { decision: 'return', remarks: payload.remarks })
        if (verb === 'processing') return api.post(`/valuations/${v.id}/processing`, payload)
        if (verb === 'complete') return api.post(`/valuations/${v.id}/complete`)
      }
      await call()
      setNotice(`Valuation action «${verb}» applied.`)
      load()
      refreshDetail(detail.id, true)
    } catch (ex) {
      setErr(errMsg(ex))
    }
  }

  const refreshDetail = (id) => {
    api.get(`/discoveries/${id}`)
      .then((r) => {
        const d = unwrap(r).data
        if (d?.valuation?.id && (d.valuation.assessed_value == null)) {
          api.get(`/valuations/${d.valuation.id}`)
            .then((vr) => setDetail({ ...d, valuation: unwrap(vr).data }))
            .catch(() => setDetail(d))
        } else {
          setDetail(d)
        }
      })
      .catch((ex) => setErr(errMsg(ex)))
  }

  const openDetail = (id) => refreshDetail(id, false)

  return (
    <div className="space-y-3">
      <PageTitle
        sub="Register of unregistered property finds — review, classify, then route (Path A: Account & Records · Path V: Valuation → AC)."
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
        <select className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={filters.declaration}
          onChange={(e) => setFilters({ ...filters, declaration: e.target.value })}>
          <option value="">All declarations</option>
          {VALUE_DECLARATION_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
        </select>
        <div className="flex items-center gap-2">
          <Input className="w-full" type="number" step="any" min="0" placeholder="Value ≥ $…" value={filters.value_min} onChange={(e) => setFilters({ ...filters, value_min: e.target.value })} />
          <Input className="w-full" type="number" step="any" min="0" placeholder="Value ≤ $…" value={filters.value_max} onChange={(e) => setFilters({ ...filters, value_max: e.target.value })} />
        </div>
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
                  <th className="px-3 py-3">Value Declaration</th>
                  <th className="px-3 py-3">Path</th>
                  <th className="px-3 py-3">Status</th>
                  <th className="px-3 py-3">Assigned To</th>
                  <th className="px-3 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {list.length === 0 && (
                  <tr><td colSpan={9} className="px-3 py-8 text-center text-slate-400">No discoveries match.</td></tr>
                )}
                {list.map((d) => {
                  const info = discoveryStatusInfo(d.status)
                  const decl = valueDeclarationInfo(d.value_declaration_type)
                  return (
                    <tr key={d.id} className="hover:bg-slate-50">
                      <td className="px-3 py-2.5"><span className="font-semibold text-navy-800">{d.discovery_reference}</span></td>
                      <td className="px-3 py-2.5 text-slate-600">{d.owner_name || '—'}</td>
                      <td className="px-3 py-2.5 text-slate-600 max-w-[220px] truncate block">{d.property_address || [d.city_town, d.community, d.county].filter(Boolean).join(', ') || '—'}</td>
                      <td className="px-3 py-2.5"><Badge tone="slate">{d.property_classification || '—'}</Badge></td>
                      <td className="px-3 py-2.5">
                        {d.value_declaration_type
                          ? <span className="flex flex-col gap-0.5"><Badge tone={decl.tone}>{decl.label}</Badge><span className="text-[11px] text-slate-500">{fmtMoney(d.declared_assessed_value ?? d.declared_value)}</span></span>
                          : <span className="text-slate-300">—</span>}
                      </td>
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
  const [deed, setDeed] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const cameraRef = useRef(null)
  const fileRef = useRef(null)
  const deedRef = useRef(null)

  const declaration = form.value_declaration_type

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const locate = () => {
    if (!navigator.geolocation) return alert('Browser geolocation unavailable — enter GPS manually.')
    navigator.geolocation.getCurrentPosition(
      (p) => setForm((f) => ({ ...f, gps_lat: p.coords.latitude.toFixed(6), gps_lng: p.coords.longitude.toFixed(6), gps_accuracy: Math.round(p.coords.accuracy) })),
      () => alert('Could not read location — enter GPS manually.'),
    )
  }
  const addFiles = (files) => {
    const next = Array.from(files).slice(0, 3 - photos.length)
    setPhotos((p) => [...p, ...next].slice(0, 3))
  }
  const pickDeed = (files) => {
    if (files?.[0]) setDeed(files[0])
  }

  const uploadEvidence = async (d) => {
    for (let i = 0; i < photos.length; i++) {
      const fd = new FormData()
      fd.append('evidence_type', 'PROPERTY_PHOTO')
      fd.append('sequence_no', String(i + 1))
      fd.append('capture_source', 'CAMERA')
      fd.append('file', photos[i])
      if (form.gps_lat) fd.append('gps_latitude', form.gps_lat)
      if (form.gps_lng) fd.append('gps_longitude', form.gps_lng)
      await api.post(`/discoveries/${d.id}/evidence`, fd)
    }
    if (deed) {
      const fd = new FormData()
      fd.append('evidence_type', 'OWNERSHIP_DOCUMENT')
      fd.append('capture_source', 'UPLOAD')
      fd.append('file', deed)
      await api.post(`/discoveries/${d.id}/evidence`, fd)
    }
  }

  const save = async (mode) => {
    setErr(''); setBusy(true)
    try {
      if (mode === 'submit' && (!form.owner_name.trim() || !form.property_classification)) {
        setErr('Owner / occupant name and Property classification are required before a discovery can be routed.')
        setBusy(false)
        return
      }
      if (mode === 'submit' && declaration === 'DOCUMENTARY_VALUE' && !deed) {
        setErr('Value from Documentation requires the ownership/deed document — attach it in the Evidence section.')
        setBusy(false)
        return
      }
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))
      if (declaration === 'VALUATION_VALUE') {
        delete payload.declared_assessed_value
        delete payload.declared_value
      }
      const r = await api.post('/discoveries', payload)
      const d = unwrap(r).data
      await uploadEvidence(d)
      if (mode === 'submit') {
        await api.post(`/discoveries/${d.id}/submit`, {
          value_declaration_type: d.value_declaration_type || declaration,
          declared_assessed_value: form.declared_assessed_value || undefined,
          documentary_value: form.documentary_value || undefined,
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
        <div className="p-6 space-y-6">
          <ErrorBox error={err} />

          <section>
            <SectionTitle>1 · Property &amp; owner</SectionTitle>
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
          </section>

          <section>
            <SectionTitle>2 · Location &amp; GPS</SectionTitle>
            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <Field label="GPS latitude" value={form.gps_lat} readOnly ph="Not captured yet" />
              <Field label="GPS longitude" value={form.gps_lng} readOnly ph="Not captured yet" />
              <Field label="GPS accuracy (m)" value={form.gps_accuracy} readOnly ph="—" />
              <Field label="Discovery date" type="date" value={form.discovery_date} onChange={set('discovery_date')} />
            </div>
            <div className="flex flex-wrap items-center gap-3 mt-3">
              <Btn tone="navy" onClick={locate} type="button">📡 Use my location</Btn>
              <span className="text-xs text-slate-500">Cptured the Property location.</span>
            </div>
          </section>

          <section>
            <SectionTitle>3 · Value declaration</SectionTitle>
            <div className="grid sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1.5">Value declaration type</label>
                <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={declaration}
                  onChange={(e) => setForm({ ...form, value_declaration_type: e.target.value })}>
                  <option value="">Select declaration type…</option>
                  {VALUE_DECLARATION_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
                {declaration && (
                  <p className="text-xs text-slate-500 mt-1.5">{VALUE_DECLARATION_TYPES.find((t) => t.value === declaration)?.hint}</p>
                )}
              </div>
              {declaration && declaration !== 'VALUATION_VALUE' && (
                <div className="grid sm:grid-cols-2 gap-4 content-start">
                  <div>
                    <label className="block text-xs font-semibold text-slate-600 mb-1.5">Declared / assessed value (USD)</label>
                    <Input className="w-full" type="number" min="0" step="0.01" value={form.declared_assessed_value} onChange={set('declared_assessed_value')} placeholder="e.g. 25000.00" />
                  </div>
                  {declaration === 'DOCUMENTARY_VALUE' && (
                    <div>
                      <label className="block text-xs font-semibold text-slate-600 mb-1.5">Value per documentation (USD)</label>
                      <Input className="w-full" type="number" min="0" step="0.01" value={form.documentary_value} onChange={set('documentary_value')} placeholder="Amount per deed/documents" />
                    </div>
                  )}
                </div>
              )}
            </div>
          </section>

          <section>
            <SectionTitle>4 · Evidence</SectionTitle>
            <div className="grid sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1.5">Ownership / deed document {declaration === 'DOCUMENTARY_VALUE' && <span className="text-red-500">*</span>}</label>
                <input ref={deedRef} type="file" accept="image/*,.pdf" className="hidden" onChange={(e) => pickDeed(e.target.files)} />
                <Btn tone="white" type="button" className="w-full !justify-between" onClick={() => deedRef.current?.click()}>
                  <span>{deed ? deed.name : '⬆ Attach ownership / deed document'}</span>
                </Btn>
                {deed && <button type="button" className="text-xs text-red-600 mt-1" onClick={() => { setDeed(null); deedRef.current.value = '' }}>Remove document</button>}
              </div>
              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1.5">Property photographs ({photos.length} of 3)</label>
                <div className="flex flex-wrap gap-2">
                  <input ref={cameraRef} type="file" accept="image/*" capture="environment" className="hidden" onChange={(e) => { addFiles(e.target.files); e.target.value = '' }} />
                  <Btn tone="white" type="button" onClick={() => cameraRef.current?.click()} disabled={photos.length >= 3}>📷 Take photo</Btn>
                  <input ref={fileRef} type="file" accept="image/*" multiple className="hidden" onChange={(e) => { addFiles(e.target.files); e.target.value = '' }} />
                  <Btn tone="white" type="button" onClick={() => fileRef.current?.click()} disabled={photos.length >= 3}>⬆ Upload</Btn>
                </div>
                {photos.length > 0 && (
                  <div className="flex gap-2 mt-2 flex-wrap">
                    {photos.map((p, i) => (
                      <div key={i} className="relative w-20 h-20 rounded-lg overflow-hidden border border-slate-200">
                        <LocalPreview file={p} alt={p.name} />
                        <button type="button" onClick={() => setPhotos(photos.filter((_, x) => x !== i))}
                          className="absolute top-0 right-0 bg-red-600 text-white text-[10px] w-5 h-5 rounded-bl-lg">✕</button>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </section>

          <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
            <Btn tone="white" type="button" onClick={onClose}>Cancel</Btn>
            <Btn tone="white" type="button" disabled={busy} onClick={() => save('draft')}>{busy ? 'Saving…' : 'Record Draft'}</Btn>
            <Btn type="button" disabled={busy} onClick={() => save('submit')}>{busy ? 'Submitting…' : 'Record &amp; Submit'}</Btn>
          </div>
        </div>
      </div>
    </div>
  )
}

function SectionTitle({ children }) {
  return <h4 className="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2">{children}</h4>
}

/* ------------------------------------------------------------------ */
/*  Field / Select form helpers                                        */
/* ------------------------------------------------------------------ */

function Field({ label, value, onChange, ph, type = 'text', readOnly = false }) {
  return (
    <div>
      <label className="block text-xs font-semibold text-slate-600 mb-1.5">{label}</label>
      <Input className="w-full" type={type} value={value} onChange={onChange} readOnly={readOnly} placeholder={ph} />
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