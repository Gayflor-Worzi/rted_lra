import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import api, { unwrap, errMsg } from '../api'
import { Card, Spinner, ErrorBox, Badge, statusTone, PageTitle, fmtMoney, fmtDate, Btn, Input, SuccessBox } from '../ui'
import { PROPERTY_CLASSIFICATIONS, discoveryStatusInfo } from '../lib/constants'
import { useAuth } from '../auth'
import PropertyDetail from '../components/PropertyDetail'

const TABS = ['Draft', 'Submitted', 'AC Approval', 'Approved', 'Rejected', 'Returned']
const tabFor = (st) => (st === 'Manager Review' ? 'Submitted' : st)

function gps() {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) return reject(new Error('Geolocation not available in this browser.'))
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy, at: new Date().toISOString() }),
      reject,
      { enableHighAccuracy: true, timeout: 15000 },
    )
  })
}

function toDataUri(file) {
  return new Promise((resolve, reject) => {
    const fr = new FileReader()
    fr.onload = () => resolve(fr.result)
    fr.onerror = reject
    fr.readAsDataURL(file)
  })
}

/** RETD formula: Value = Amount × Quantity × (1 − Depreciation%). */
const rowValue = (r) => (Number(r.amount) || 0) * (Number(r.quantity) || 0) * (1 - (Number(r.depreciation_pct) || 0) / 100)

export default function Valuations() {
  const { can } = useAuth()
  const [sp] = useSearchParams()
  const focusId = sp.get('focus') ? Number(sp.get('focus')) : null
  const focusTab = sp.get('status') ? tabFor(sp.get('status')) : null
  const [tab, setTab] = useState(focusTab || 'Submitted')
  const [rows, setRows] = useState(null)
  const [err, setErr] = useState(null)
  const [notice, setNotice] = useState('')
  const [busyId, setBusyId] = useState(null)
  const [remarks, setRemarks] = useState({})
  const [submitBox, setSubmitBox] = useState({})
  const [processId, setProcessId] = useState(null)
  const [showForm, setShowForm] = useState(false)
  const [editVal, setEditVal] = useState(null)
  const [pathV, setPathV] = useState(false)
  const [disc, setDisc] = useState(null)
  const [detail, setDetail] = useState(null)
  const [users, setUsers] = useState([])
  const navigate = useNavigate()
  const focusDone = useRef(false)

  const canCreate = can('valuation.create')
  const canEdit = can('valuation.edit')
  const canReview = can('valuation.review')
  const canDecide = can('valuation.approve')
  const canSubmit = can('valuation.submit')
  const canProcess = can('valuation.litas_processing')

  const load = () => api.get('/valuations', { params: { status: tab, per_page: 50 } })
    .then((r) => setRows(unwrap(r).data)).catch(setErr)
  useEffect(() => { setErr(null); load() }, [tab]) // eslint-disable-line

  // Path V — discoveries classified to the valuation workflow, surfaced automatically.
  const loadPV = () => api.get('/discoveries', { params: { path: 'valuation', per_page: 50 } })
    .then((r) => setDisc(unwrap(r).data)).catch(setErr)

  useEffect(() => { if (pathV) { setErr(null); loadPV() } }, [pathV]) // eslint-disable-line

  useEffect(() => {
    api.get('/users', { params: { per_page: 200 } })
      .then((r) => setUsers(unwrap(r).data.data))
      .catch(() => {})
  }, [])

  // Placeholder spinner while the SSOT modal hydrates both sides.
  const openDetail = async ({ discovery, valuation }) => {
    setDetail({ discovery, valuation })
    if (!discovery?.valuation?.id && !valuation?.discovery_id) return
    let d = discovery
    let v = valuation
    try {
      if (d?.valuation?.id) {
        const r = await api.get(`/valuations/${d.valuation.id}`)
        v = unwrap(r).data
      }
      if (v?.discovery_id) {
        const r = await api.get(`/discoveries/${v.discovery_id}`)
        d = unwrap(r).data
      }
    } catch (ex) { setErr(ex) }
    setDetail({ discovery: d, valuation: v })
  }

  // Discovery workflow verbs (Path V list / linked discovery in the modal).
  const runDiscoveryAction = async (verb, payload = {}) => {
    const d = detail?.discovery
    if (!d) return
    setErr(null); setNotice('')
    try {
      await api.post(`/discoveries/${d.id}/${verb}`, payload)
      setNotice(`Discovery action «${verb}» applied.`)
      if (pathV) loadPV()
      const r = await api.get(`/discoveries/${d.id}`)
      openDetail({ discovery: unwrap(r).data })
    } catch (ex) { setErr(ex) }
  }

  // Valuation workflow verbs bound to the modal (submit/assign/review/decide/processing).
  const runValuationAction = async (verb, payload = {}) => {
    const v = detail?.valuation
    if (!v) return
    setErr(null); setNotice('')
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
      if (pathV) loadPV()
      openDetail({ discovery: detail.discovery, valuation: v })
    } catch (ex) { setErr(ex) }
  }

  useEffect(() => {
    if (!focusId || focusDone.current) return
    const el = document.getElementById(`val-${focusId}`)
    if (!el) return
    focusDone.current = true
    el.scrollIntoView({ behavior: 'smooth', block: 'center' })
    el.classList.add('ring-2', 'ring-brand-400', 'shadow-lg')
    const t = setTimeout(() => el.classList.remove('ring-2', 'ring-brand-400', 'shadow-lg'), 2600)
    return () => clearTimeout(t)
  }, [rows, focusId])

  const act = async (id, endpoint, decision, remark) => {
    setErr(null); setBusyId(id)
    try {
      await api.post(`/valuations/${id}/${endpoint}`, { decision, remarks: remark || undefined })
      setNotice(`Valuation ${endpoint === 'review' ? 'reviewed' : 'decided'}.`)
      load()
    } catch (ex) { setErr(ex) } finally { setBusyId(null) }
  }

  const submitValuation = async (v) => {
    const b = submitBox[v.id] || {}
    if (!(Number(b.assessed_value) > 0) || !(Number(b.annual_tax) > 0)) return
    setErr(null); setBusyId(v.id)
    try {
      await api.post(`/valuations/${v.id}/submit`, {
        assessed_value: Number(b.assessed_value),
        annual_tax: Number(b.annual_tax),
        remarks: b.remarks || undefined,
      })
      setSubmitBox({ ...submitBox, [v.id]: {} })
      setNotice(`Valuation ${v.valuation_reference} submitted for review.`)
      load()
    } catch (ex) { setErr(ex) } finally { setBusyId(null) }
  }

  const markProcessed = async (v) => {
    setErr(null); setProcessId(v.id)
    try {
      await api.post(`/valuations/${v.id}/processing`)
      setNotice('Valuation result confirmed as processed in the source system.')
      load()
    } catch (ex) { setErr(ex) } finally { setProcessId(null) }
  }

  return (
    <div className="space-y-4">
      <PageTitle sub="Two-stage review — Valuation Manager recommends, Assistant Commissioner approves"
        right={canCreate && (
          <Btn onClick={() => setShowForm(true)}>+ New Valuation</Btn>
        )}>
        Valuations
      </PageTitle>
      <ErrorBox error={err} />
      <SuccessBox>{notice}</SuccessBox>

      <div className="flex flex-wrap items-center gap-2">
        <button onClick={() => { setPathV((v) => !v); setTab(focusTab || 'Submitted') }}
          className={`px-3 py-1.5 rounded-lg text-sm font-semibold ${pathV ? 'bg-emerald-600 text-white' : 'bg-white border hover:bg-slate-50'}`}>
          Discovered Properties · Path V
        </button>
        {TABS.map((t) => (
          <button key={t} onClick={() => { setTab(t); setPathV(false) }}
            className={`px-3 py-1.5 rounded-lg text-sm ${tab === t && !pathV ? 'bg-blue-600 text-white' : 'bg-white border hover:bg-slate-50'}`}>
            {t}
          </button>
        ))}
      </div>

      {pathV && (
        <div className="space-y-3">
          <div className="rounded-xl bg-emerald-50 border border-emerald-100 px-4 py-3 text-sm text-emerald-700">
            Discoveries classified to <b>Path V</b> appear here automatically — the valuation is the same record the discovery links to (single source of truth), no manual recreation. Assign an officer on any classified property to route it onward.
          </div>
          {!disc ? <Spinner label="Loading discovered properties…" /> : (
            <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-[11px] uppercase tracking-wider text-slate-400 border-b border-slate-100">
                      <th className="px-3 py-3">Discovery</th>
                      <th className="px-3 py-3">Owner / Occupant</th>
                      <th className="px-3 py-3">Location</th>
                      <th className="px-3 py-3">Status</th>
                      <th className="px-3 py-3">Valuation</th>
                      <th className="px-3 py-3">Officer</th>
                      <th className="px-3 py-3 text-right">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-50">
                    {disc.data.length === 0 && (
                      <tr><td colSpan={7} className="px-3 py-8 text-center text-slate-400">No Path V discoveries yet — classify a discovery to valuation from Enforcement.</td></tr>
                    )}
                    {disc.data.map((d) => {
                      const info = discoveryStatusInfo(d.status)
                      return (
                        <tr key={d.id} className="hover:bg-slate-50">
                          <td className="px-3 py-2.5">
                            <button className="font-semibold text-navy-800 hover:text-brand-600"
                              onClick={() => navigate(`/enforcement?tab=discoveries&focus=${d.id}`)}>
                              {d.discovery_reference}
                            </button>
                          </td>
                          <td className="px-3 py-2.5 text-slate-600">{d.owner_name || '—'}</td>
                          <td className="px-3 py-2.5 text-slate-600 max-w-[220px] truncate block">{d.property_address || '—'}</td>
                          <td className="px-3 py-2.5"><Badge tone={info.tone}>{info.label}</Badge></td>
                          <td className="px-3 py-2.5">
                            {d.valuation ? (
                              <Badge tone="blue">{d.valuation.valuation_reference} · {d.valuation.status || '—'}</Badge>
                            ) : <span className="text-slate-300">—</span>}
                          </td>
                          <td className="px-3 py-2.5 text-xs text-slate-500">{d.valuation?.valuation_officer || '—'}</td>
                          <td className="px-3 py-2.5 text-right">
                            <Btn tone="white" className="!px-3 !py-1.5" onClick={() => openDetail({ discovery: d })}>View details</Btn>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      )}

      {!rows ? <Spinner /> : rows.data.length === 0
        ? <Card><p className="text-slate-400 text-sm">No valuations in this bucket.</p></Card> : (
        <div className="space-y-3">
          {rows.data.map((v) => {
            const awaitingManager = (v.status === 'Submitted' || v.status === 'Manager Review') && canReview
            const awaitingAC = v.status === 'AC Approval' && canDecide
            const needsSubmit = (v.status === 'Draft' || v.status === 'Returned') && canSubmit
            const canMarkProcess = v.status === 'Approved' && canProcess && !v.litas_processing_status
            const busy = busyId === v.id

            let action = null
            if (needsSubmit) {
              const b = submitBox[v.id] || {}
              action = (
                <>
                  {canEdit && (
                    <Btn tone="white" className="w-full text-xs" onClick={() => setEditVal(v)}>
                      ✏️ Complete / edit valuation form
                    </Btn>
                  )}
                  <div className="text-[11px] text-slate-400 font-semibold uppercase tracking-wide">Or quick-submit:</div>
                  <input placeholder="Assessed value (US$)" type="number" min="0" value={b.assessed_value || ''}
                    onChange={(e) => setSubmitBox({ ...submitBox, [v.id]: { ...b, assessed_value: e.target.value } })}
                    className="w-full px-2 py-1.5 border rounded-lg text-xs" />
                  <input placeholder="Recommended annual tax (US$)" type="number" min="0" value={b.annual_tax || ''}
                    onChange={(e) => setSubmitBox({ ...submitBox, [v.id]: { ...b, annual_tax: e.target.value } })}
                    className="w-full px-2 py-1.5 border rounded-lg text-xs" />
                  <input placeholder="Note to manager…" value={b.remarks || ''}
                    onChange={(e) => setSubmitBox({ ...submitBox, [v.id]: { ...b, remarks: e.target.value } })}
                    className="w-full px-2 py-1.5 border rounded-lg text-xs" />
                  <Btn tone="blue" disabled={busy || !(Number(b.assessed_value) > 0) || !(Number(b.annual_tax) > 0)}
                    onClick={() => submitValuation(v)} className="w-full text-xs">
                    {busy ? 'Submitting…' : 'Submit for review →'}
                  </Btn>
                </>
              )
            } else if (awaitingManager) {
              action = (
                <>
                  <input placeholder="Note to AC / officer…" value={remarks[v.id] || ''}
                    onChange={(e) => setRemarks({ ...remarks, [v.id]: e.target.value })}
                    className="w-full px-2 py-1.5 border rounded-lg text-xs" />
                  <div className="flex gap-2">
                    <Btn tone="blue" disabled={busy} onClick={() => act(v.id, 'review', 'forward_ac')}
                      className="flex-1 text-xs">Recommend to AC →</Btn>
                    <Btn tone="white" disabled={busy} onClick={() => act(v.id, 'review', 'return', remarks[v.id])}
                      className="flex-1 text-xs">Return</Btn>
                  </div>
                </>
              )
            } else if (awaitingAC) {
              action = (
                <>
                  <input placeholder="Decision note…" value={remarks[v.id] || ''}
                    onChange={(e) => setRemarks({ ...remarks, [v.id]: e.target.value })}
                    className="w-full px-2 py-1.5 border rounded-lg text-xs" />
                  <div className="flex gap-2">
                    <Btn tone="success" disabled={busy} onClick={() => act(v.id, 'decide', 'approve', remarks[v.id])}
                      className="flex-1 text-xs">Approve</Btn>
                    <Btn tone="danger" disabled={busy} onClick={() => act(v.id, 'decide', 'reject', remarks[v.id])}
                      className="flex-1 text-xs">Reject</Btn>
                  </div>
                </>
              )
            } else if (canMarkProcess) {
              action = (
                <Btn tone="success" disabled={processId === v.id} onClick={() => markProcessed(v)}
                  className="w-full text-xs">
                  {processId === v.id ? 'Confirming…' : 'Mark processed in LITAS'}
                </Btn>
              )
            }

            return (
              <div key={v.id} id={`val-${v.id}`}>
              <Card key={v.id}>
                <div className="flex flex-col md:flex-row justify-between items-start gap-4">
                  <div>
                    <div className="font-semibold">{v.valuation_reference}
                      <span className="ml-2"><Badge tone={statusTone(v.status)}>{v.status}</Badge></span>
                      {v.litas_processing_status && <span className="ml-2"><Badge tone="green">{v.litas_processing_status}</Badge></span>}
                    </div>
                    <div className="text-sm text-slate-500 mt-1">
                      {v.owner_name || '—'} · {v.property_address || '—'}
                    </div>
                    <div className="flex flex-wrap items-center gap-2 mt-1">
                      {v.discovery_reference && (
                        <button onClick={() => navigate(`/enforcement?tab=discoveries&focus=${v.discovery_id}`)}
                          className="text-left">
                          <Badge tone="navy">↳ {v.discovery_reference}</Badge>
                        </button>
                      )}
                      <Btn tone="white" className="!px-2.5 !py-1 !text-xs" onClick={() => openDetail({ valuation: v })}>View details</Btn>
                      {v.document_number ? <span className="text-xs text-slate-400">Bill {v.document_number}</span> : null}
                      {v.property_id ? <span className="text-xs text-slate-400">Property {v.property_id}</span> : null}
                      {v.valuation_officer ? <span className="text-xs text-slate-400">Valuation by {v.valuation_officer}</span> : null}
                    </div>
                    <div className="flex flex-wrap gap-x-6 gap-y-1 mt-2 text-sm">
                      <span>Assessed value <span className="font-bold text-navy-800">{fmtMoney(v.assessed_value)}</span></span>
                      <span>Annual tax <span className="font-bold text-navy-800">{v.annual_tax != null ? fmtMoney(v.annual_tax) : '—'}</span></span>
                      {(v.total_property_value != null || v.total_tax_payable != null) && (
                        <span>Property value <span className="font-bold text-navy-800">{fmtMoney(v.total_property_value)}</span> · Tax payable <span className="font-bold text-brand-500">{fmtMoney(v.total_tax_payable)}</span></span>
                      )}
                    </div>
                    <div className="flex flex-wrap gap-x-4 gap-y-1 mt-1.5 text-xs text-slate-400">
                      {v.assessment_date && <span>Assessed {fmtDate(v.assessment_date)}</span>}
                      {v.declared_value != null && <span>Declared {fmtMoney(v.declared_value)}</span>}
                      {v.gps_coordinate && <span>📍 {v.gps_coordinate}</span>}
                      {v.owner_contact && <span>📞 {v.owner_contact}</span>}
                      {v.descriptions?.length > 0 && <span>🏠 {v.descriptions.length} description row{v.descriptions.length > 1 ? 's' : ''}</span>}
                      {v.photos_count != null && <span>📷 {v.photos_count} photo{v.photos_count === 1 ? '' : 's'}</span>}
                    </div>
                    {(v.manager_remarks || v.ac_remarks) && (
                      <div className="text-xs text-slate-500 mt-2 pt-2 border-t border-slate-100">
                        {v.manager_remarks && <div>Manager: {v.manager_remarks}</div>}
                        {v.ac_remarks && <div>AC: {v.ac_remarks}</div>}
                      </div>
                    )}
                  </div>

                  {action && <div className="w-full md:w-72 shrink-0 space-y-2">{action}</div>}
                </div>
              </Card>
              </div>
            )
          })}
        </div>
      )}

      {showForm && canCreate && (
        <ValuationForm onClose={() => setShowForm(false)} onSaved={() => { setShowForm(false); setTab('Draft'); setNotice('Valuation draft created.') }} />
      )}
      {editVal && canEdit && (
        <ValuationForm initial={editVal} onClose={() => setEditVal(null)}
          onSaved={() => { setEditVal(null); setNotice(`Valuation ${editVal.valuation_reference} updated.`); load() }} />
      )}

      {detail && (
        <PropertyDetail
          discovery={detail.discovery}
          valuation={detail.valuation}
          runAction={detail.discovery ? runDiscoveryAction : undefined}
          runValuationAction={detail.valuation ? runValuationAction : undefined}
          assignmentUsers={users}
          onClose={() => setDetail(null)}
        />
      )}
    </div>
  )
}

function ValuationForm({ onClose, onSaved, initial }) {
  const isEdit = !!initial
  const [form, setForm] = useState(initial ? {
    valuation_type: initial.valuation_type || 'new_property',
    bill_q: initial.document_number || '',
    document_number: initial.document_number || '',
    property_id: initial.property_id || '',
    owner_name: initial.owner_name || '',
    owner_contact: initial.owner_contact || '',
    tin: initial.tin || '',
    property_classification: initial.property_classification || '',
    property_address: initial.property_address || '',
    land_dimensions: initial.land_dimensions || '',
    building_specs: initial.building_specs || '',
    construction_year: initial.construction_year || '',
    condition: initial.condition || '',
    assessment_date: (initial.assessment_date || '').slice(0, 10),
    declared_value: initial.declared_value ?? '',
    applicable_tax_rate: initial.applicable_tax_rate ?? '',
    reassessed_value: initial.reassessed_value ?? '',
    other_amounts: initial.other_amounts ?? '',
    gps_coordinate: initial.gps_coordinate || '',
    gps_accuracy: initial.gps_accuracy ?? '',
    remarks: initial.remarks || '',
  } : {
    valuation_type: 'new_property',
    bill_q: '',
    document_number: '',
    property_id: '',
    owner_name: '',
    owner_contact: '',
    tin: '',
    property_classification: '',
    property_address: '',
    land_dimensions: '',
    building_specs: '',
    construction_year: '',
    condition: '',
    assessment_date: '',
    declared_value: '',
    applicable_tax_rate: '',
    reassessed_value: '',
    other_amounts: '',
    gps_coordinate: '',
    gps_accuracy: '',
    remarks: '',
  })
  const [desc, setDesc] = useState(initial?.descriptions?.length
    ? initial.descriptions.map((r) => ({
        description: r.description || '', level: r.level || 'Ground Floor', area_sqft: r.area_sqft ?? '',
        tar: r.tar ?? '', quantity: r.quantity ?? '1', amount: r.amount ?? '',
        building_age: r.building_age ?? '', depreciation_pct: r.depreciation_pct ?? '',
      }))
    : [
        { description: '', level: 'Ground Floor', area_sqft: '', tar: '', quantity: '1', amount: '', building_age: '', depreciation_pct: '' },
      ])
  const [photo, setPhoto] = useState(null)
  const [photoName, setPhotoName] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [hits, setHits] = useState([])
  const [billId, setBillId] = useState(initial?.bill_id || null)

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const setRow = (i) => (k) => (e) => {
    const next = desc.map((r, idx) => (idx === i ? { ...r, [k]: e.target.value } : r))
    setDesc(next)
  }
  const addRow = () => setDesc([...desc, { description: '', level: 'Other', area_sqft: '', tar: '', quantity: '1', amount: '', building_age: '', depreciation_pct: '' }])
  const delRow = (i) => setDesc(desc.filter((_, idx) => idx !== i))

  const totalValue = desc.reduce((s, r) => s + rowValue(r), 0)
  const annualTaxPreview = Number(form.reassessed_value || 0) && Number(form.applicable_tax_rate || 0) > 0
    ? Number(form.reassessed_value) * Number(form.applicable_tax_rate) / 100
    : 0
  const taxPayablePreview = annualTaxPreview + (Number(form.other_amounts) || 0)

  const captureGps = async () => {
    setErr('')
    try {
      const g = await gps()
      setForm((f) => ({
        ...f,
        gps_coordinate: `${g.lat.toFixed(6)},${g.lng.toFixed(6)}`,
        gps_accuracy: Math.round(g.accuracy).toString(),
      }))
    } catch (ex) { setErr(errMsg(ex, 'Could not capture location — enter GPS manually.')) }
  }

  const pickPhoto = async (e) => {
    const file = e.target.files?.[0]
    if (!file) return
    setErr('')
    if (!file.type.startsWith('image/')) return setErr('Only image files are accepted for evidence.')
    try {
      setPhoto(await toDataUri(file))
      setPhotoName(file.name)
    } catch { /* ignored */ }
  }

  const searchBills = async () => {
    setErr('')
    const q = form.bill_q.trim()
    if (q.length < 2) return setHits([])
    try {
      const r = await api.get('/search', { params: { q } })
      setHits(unwrap(r).data)
    } catch (ex) { setErr(errMsg(ex)) }
  }

  const pickBill = (b) => {
    setBillId(b.id)
    setForm((f) => ({
      ...f,
      bill_q: b.document_number,
      document_number: b.document_number,
      property_id: b.property_id,
      owner_name: b.taxpayer_name,
      owner_contact: b.recipient_contact || '',
      tin: b.tin || '',
      property_classification: b.property_classification || '',
      property_address: b.property_address,
    }))
    setHits([])
  }

  const submit = async (e) => {
    e.preventDefault()
    setErr(''); setBusy(true)
    try {
      const rows = desc.filter((r) => r.description || r.amount)
      const descriptionRows = rows.map((r) => ({
        description: r.description,
        level: r.level,
        area_sqft: r.area_sqft || undefined,
        tar: r.tar || undefined,
        quantity: r.quantity || undefined,
        amount: r.amount || undefined,
        building_age: r.building_age || undefined,
        depreciation_pct: r.depreciation_pct || undefined,
      }))

      let valuation
      if (isEdit) {
        const v = await api.put(`/valuations/${initial.id}`, {
          owner_name: form.owner_name,
          owner_contact: form.owner_contact || undefined,
          tin: form.tin || undefined,
          property_classification: form.property_classification || undefined,
          property_address: form.property_address,
          land_dimensions: form.land_dimensions || undefined,
          building_specs: form.building_specs || undefined,
          construction_year: form.construction_year || undefined,
          condition: form.condition || undefined,
          assessment_date: form.assessment_date || undefined,
          declared_value: form.declared_value || undefined,
          reassessed_value: form.reassessed_value || undefined,
          applicable_tax_rate: form.applicable_tax_rate || undefined,
          other_amounts: form.other_amounts || undefined,
          gps_coordinate: form.gps_coordinate || undefined,
          remarks: form.remarks || undefined,
          descriptions: descriptionRows,
        })
        valuation = unwrap(v).data
      } else {
        const payload = {
          bill_id: billId || undefined,
          valuation_type: form.valuation_type,
          document_number: form.document_number || undefined,
          property_id: form.property_id || undefined,
          owner_name: form.owner_name,
          owner_contact: form.owner_contact || undefined,
          tin: form.tin || undefined,
          property_classification: form.property_classification || undefined,
          property_address: form.property_address,
          land_dimensions: form.land_dimensions || undefined,
          building_specs: form.building_specs || undefined,
          construction_year: form.construction_year || undefined,
          condition: form.condition || undefined,
          assessment_date: form.assessment_date || undefined,
          declared_value: form.declared_value || undefined,
          reassessed_value: form.reassessed_value || undefined,
          applicable_tax_rate: form.applicable_tax_rate || undefined,
          other_amounts: form.other_amounts || undefined,
          gps_coordinate: form.gps_coordinate || undefined,
          remarks: form.remarks || undefined,
          descriptions: descriptionRows,
        }
        const v = await api.post('/valuations', payload)
        valuation = unwrap(v).data
      }

      if (photo && valuation.id) {
        await api.post('/evidence/photos', {
          photo_type: 'PROPERTY_FULL_VIEW',
          valuation_id: valuation.id,
          property_id: valuation.property_id || undefined,
          data_uri: photo,
          gps_coordinate: form.gps_coordinate || undefined,
        })
      }
      setNotice(isEdit
        ? `Valuation ${valuation.valuation_reference} saved.${photo ? ' Photo evidence attached.' : ''}`
        : `Valuation ${valuation.valuation_reference} drafted.${photo ? ' Photo evidence attached.' : ''}`)
      setTimeout(onSaved, 700)
    } catch (ex) {
      const msg = errMsg(ex)
      const details = ex.response?.data?.errors
      setErr(details ? (Array.isArray(Object.values(details)[0]) ? Object.values(details)[0][0] : Object.values(details)[0]) : msg)
    }
    setBusy(false)
  }

  const F = ({ label, required, children }) => (
    <div>
      <label className="block text-xs font-semibold text-slate-600 mb-1.5">{label}{required && <span className="text-red-500"> *</span>}</label>
      {children}
    </div>
  )

  return (
    <div className="fixed inset-0 z-50 bg-black/40 overflow-y-auto" onClick={onClose}>
      <div className="min-h-full grid place-items-center p-4" onClick={(e) => e.stopPropagation()}>
        <form onSubmit={submit} className="w-full max-w-2xl bg-white rounded-2xl shadow-xl">
          <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div>
              <h2 className="font-bold text-navy-800">{isEdit ? 'Edit valuation' : 'New valuation'}</h2>
              <p className="text-xs text-slate-400">{isEdit ? `${initial.valuation_reference} · ${initial.status}` : 'Field assessment for a new property or reassessment.'}</p>
            </div>
            <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600 text-2xl leading-none px-2">×</button>
          </div>

          <div className="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto">
            {err && <ErrorBox error={{ message: err }} />}

            <Card className="!p-0 shadow-none border border-slate-100">
              <div className="text-xs font-semibold text-slate-500 px-4 pt-4 uppercase tracking-wider">Assessment</div>
              <div className="grid sm:grid-cols-2 gap-4 p-4">
                <F label="Valuation type" required>
                  <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={form.valuation_type} onChange={set('valuation_type')} disabled={isEdit}>
                    <option value="new_property">New property</option>
                    <option value="reassessment">Reassessment</option>
                  </select>
                </F>
                {isEdit ? (
                  <F label="Linked bill">
                    {initial?.document_number
                      ? <Badge tone="green">Bill {initial.document_number}</Badge>
                      : <span className="text-sm text-slate-400">None</span>}
                  </F>
                ) : (
                <F label="Link to an existing bill (optional)">
                  <div className="flex gap-2">
                    <Input className="flex-1" value={form.bill_q} onChange={set('bill_q')} placeholder="Search document # / taxpayer…" />
                    <Btn type="button" tone="white" onClick={searchBills}>Find</Btn>
                  </div>
                </F>
                )}
                {hits.length > 0 && (
                  <div className="sm:col-span-2 space-y-1">
                    {hits.map((b) => (
                      <button key={b.id} type="button" onClick={() => pickBill(b)}
                        className="w-full text-left px-3 py-2 border rounded-xl text-sm hover:bg-slate-50">
                        <span className="font-semibold">{b.document_number}</span> · {b.taxpayer_name} · {b.property_address}
                      </button>
                    ))}
                  </div>
                )}
                {billId && !isEdit && <div className="sm:col-span-2"><Badge tone="green">Linked to bill {form.document_number}</Badge></div>}
              </div>
            </Card>

            <Card className="!p-0 shadow-none border border-slate-100">
              <div className="text-xs font-semibold text-slate-500 px-4 pt-4 uppercase tracking-wider">Property</div>
              <div className="grid sm:grid-cols-2 gap-4 p-4">
                <F label="Owner name" required><Input className="w-full" value={form.owner_name} onChange={set('owner_name')} required /></F>
                <F label="Property address" required><Input className="w-full" value={form.property_address} onChange={set('property_address')} required /></F>
                <F label="Classification"><select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={form.property_classification} onChange={set('property_classification')}>
                  <option value="">Select classification…</option>
                  {PROPERTY_CLASSIFICATIONS.map((c) => <option key={c} value={c}>{c}</option>)}
                </select></F>
                <F label="Document # (if any)"><Input className="w-full" value={form.document_number} onChange={set('document_number')} /></F>
                <F label="Property ID (if any)"><Input className="w-full" value={form.property_id} onChange={set('property_id')} /></F>
                <F label="TIN"><Input className="w-full" value={form.tin} onChange={set('tin')} /></F>
                <F label="Land dimensions"><Input className="w-full" value={form.land_dimensions} onChange={set('land_dimensions')} placeholder="e.g. 45ft × 60ft" /></F>
                <F label="Building specs"><Input className="w-full" value={form.building_specs} onChange={set('building_specs')} placeholder="e.g. 2-storey concrete" /></F>
                <F label="Construction year"><Input className="w-full" value={form.construction_year} onChange={set('construction_year')} /></F>
                <F label="Condition"><Input className="w-full" value={form.condition} onChange={set('condition')} /></F>
                <F label="Owner contact"><Input className="w-full" value={form.owner_contact} onChange={set('owner_contact')} placeholder="Phone / email" /></F>
                <F label="Assessment date"><Input className="w-full" type="date" value={form.assessment_date} onChange={set('assessment_date')} /></F>
                <F label="Declared value (US$)"><Input className="w-full" type="number" min="0" value={form.declared_value} onChange={set('declared_value')} /></F>
                <F label="Reassessed value (US$)"><Input className="w-full" type="number" min="0" value={form.reassessed_value} onChange={set('reassessed_value')} /></F>
                <F label="Applicable tax rate (%)"><Input className="w-full" type="number" min="0" value={form.applicable_tax_rate} onChange={set('applicable_tax_rate')} /></F>
                <F label="Other amounts (US$)"><Input className="w-full" type="number" min="0" value={form.other_amounts} onChange={set('other_amounts')} /></F>
                <F label="GPS coordinate">
                  <div className="flex gap-2">
                    <Input className="flex-1" value={form.gps_coordinate} onChange={set('gps_coordinate')} placeholder="lat,lng" />
                    <Btn type="button" tone="white" onClick={captureGps}>📍 Capture</Btn>
                  </div>
                </F>
                {form.gps_accuracy && <div className="sm:col-span-2"><Badge tone="blue">GPS accuracy {form.gps_accuracy} m</Badge></div>}
                <div className="sm:col-span-2">
                  <F label="Evidence photo (property exterior)">
                    <label className="flex items-center gap-3 cursor-pointer">
                      <span className="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">📷 Capture / upload</span>
                      <input type="file" accept="image/*" className="hidden" onChange={pickPhoto} />
                      {photoName && <span className="text-xs text-slate-500">{photoName}</span>}
                    </label>
                  </F>
                </div>
                <div className="sm:col-span-2">
                  <F label="Remarks"><Input className="w-full" value={form.remarks} onChange={set('remarks')} /></F>
                </div>
              </div>
            </Card>

            <Card className="!p-0 shadow-none border border-slate-100">
              <div className="flex items-center justify-between px-4 pt-4">
                <div>
                  <div className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Property descriptions</div>
                  <div className="text-[11px] text-slate-400 mt-0.5">Row value = Amount × Quantity × (1 − Depreciation%)</div>
                </div>
                <Btn type="button" tone="white" onClick={addRow} className="text-xs px-3 py-1.5">+ Add row</Btn>
              </div>
              <div className="p-4 space-y-3">
                {desc.map((r, i) => (
                  <div key={i} className="border border-slate-200 rounded-xl p-3 grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                    <div className="col-span-2">
                      <F label={`Description · row ${i + 1}`}><Input className="w-full" value={r.description} onChange={setRow(i)('description')} placeholder="e.g. 3-bedroom house" /></F>
                    </div>
                    <div>
                      <F label="Level"><select className="w-full px-2 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={r.level} onChange={setRow(i)('level')}>
                        {['Ground Floor', '1st Floor', '2nd Floor', '3rd Floor', 'Basement', 'Exterior', 'Other'].map((l) => <option key={l}>{l}</option>)}
                      </select></F>
                    </div>
                    <div>
                      <F label="Area (sq ft)"><Input className="w-full" type="number" min="0" value={r.area_sqft} onChange={setRow(i)('area_sqft')} /></F>
                    </div>
                    <div>
                      <F label="TAR rate (%)"><Input className="w-full" type="number" min="0" value={r.tar} onChange={setRow(i)('tar')} /></F>
                    </div>
                    <div>
                      <F label="Quantity"><Input className="w-full" type="number" min="1" value={r.quantity} onChange={setRow(i)('quantity')} /></F>
                    </div>
                    <div>
                      <F label="Amount (US$)" required><Input className="w-full" type="number" min="0" value={r.amount} onChange={setRow(i)('amount')} /></F>
                    </div>
                    <div>
                      <F label="Building age (yrs)"><Input className="w-full" type="number" min="0" value={r.building_age} onChange={setRow(i)('building_age')} /></F>
                    </div>
                    <div>
                      <F label="Depreciation (%)"><Input className="w-full" type="number" min="0" max="100" value={r.depreciation_pct} onChange={setRow(i)('depreciation_pct')} /></F>
                    </div>
                    <div className="col-span-2 md:col-span-3 flex items-end gap-2">
                      <span className="text-[11px] text-slate-400">Row value</span>
                      <span className="font-bold text-navy-800">{fmtMoney(rowValue(r))}</span>
                    </div>
                    {desc.length > 1 && (
                      <div className="flex justify-end">
                        <button type="button" onClick={() => delRow(i)} className="text-red-500 hover:text-red-700 text-[11px] font-semibold px-2 py-1.5 border border-red-200 rounded-lg">Remove</button>
                      </div>
                    )}
                  </div>
                ))}

                <div className="flex flex-wrap gap-x-8 gap-y-1 bg-slate-50 border border-slate-100 rounded-xl px-4 py-3 text-sm">
                  <span>Sub-total property value <span className="font-bold text-navy-800">{fmtMoney(totalValue)}</span></span>
                  {Number(form.reassessed_value) > 0 && (
                    <span>Reassessed {fmtMoney(form.reassessed_value)} @ {form.applicable_tax_rate || 0}% <span className="font-bold text-navy-800">{fmtMoney(annualTaxPreview)}</span></span>
                  )}
                  <span>Total tax payable preview <span className="font-bold text-brand-500">{fmtMoney(taxPayablePreview)}</span></span>
                </div>
              </div>
            </Card>
          </div>

          <div className="flex items-center justify-end gap-2 px-6 py-4 border-t border-slate-100 bg-slate-50/60 rounded-b-2xl">
            <Btn type="button" tone="white" onClick={onClose}>Cancel</Btn>
            <Btn type="submit" disabled={busy}>{busy ? (isEdit ? 'Saving…' : 'Creating…') : (isEdit ? 'Save changes' : 'Create draft')}</Btn>
          </div>
        </form>
      </div>
    </div>
  )
}