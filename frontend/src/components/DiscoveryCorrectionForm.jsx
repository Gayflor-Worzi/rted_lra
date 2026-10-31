import { useMemo, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import GatedImage from './GatedImage'
import { VALUE_DECLARATION_TYPES } from '../lib/constants'

/**
 * Correction form for a discovery that a reviewer returned for correction.
 *
 * The backend has always accepted `PUT /discoveries/{id}` on a returned record
 * (`discovery.create` + recorder of the record + status Draft/Returned), but no
 * screen called it, so the officer held a record they could see was wrong and
 * could not touch. This form is that missing screen.
 *
 * Evidence can be added and removed on a returned record as well, which is
 * usually the actual reason a record comes back.
 */
export default function DiscoveryCorrectionForm({ discovery, onClose, onSaved }) {
  const d = discovery
  // the discovery stores one "lat,lng" string; evidence carries its own coordinate,
  // which is a different thing and must not be edited as the record's location
  const [startLat, startLng] = String(d.gps_coordinate || '').split(',')
  const [form, setForm] = useState(() => ({
    owner_name: d.owner_name || '',
    owner_contact: d.owner_contact || '',
    tin: d.tin || '',
    property_address: d.property_address || '',
    county: d.county || '',
    district: d.district || '',
    city_town: d.city_town || '',
    community: d.community || '',
    street: d.street || '',
    house_number: d.house_number || '',
    property_classification: d.property_classification || '',
    property_type: d.property_type || '',
    occupancy_use: d.occupancy_use || '',
    description: d.description || '',
    value_declaration_type: d.value_declaration_type || '',
    declared_assessed_value: d.declared_assessed_value ?? '',
    documentary_value: d.documentary_value ?? '',
    gps_lat: startLat ?? '',
    gps_lng: startLng ?? '',
    gps_accuracy: d.gps_accuracy ?? '',
    remarks: d.remarks || '',
  }))
  const [files, setFiles] = useState([])
  const [docFile, setDocFile] = useState(null)
  const [evidence, setEvidence] = useState(d.evidence || [])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [saved, setSaved] = useState(false)

  const set = (k) => (e) => {
    const v = e?.target ? e.target.value : e
    setForm((f) => ({ ...f, [k]: v }))
  }

  const reason = useMemo(
    () => d.aarm_remarks || d.manager_remarks || d.ac_remarks || d.remarks || '',
    [d]
  )

  const payload = () => {
    const out = {}
    Object.entries(form).forEach(([k, v]) => {
      if (v === '' || v === null || v === undefined) return
      out[k] = v
    })
    // An emptied value must clear the field rather than be silently skipped, and
    // the API treats a missing key as "leave unchanged", so blanks are sent as null.
    if (!out.declared_assessed_value && form.declared_assessed_value === '') out.declared_assessed_value = null
    if (!out.documentary_value && form.documentary_value === '') out.documentary_value = null
    if (!out.gps_lat) { delete out.gps_lat; delete out.gps_lng; delete out.gps_accuracy }
    return out
  }

  const uploadNew = async (id) => {
    // sequence numbers continue after what is already on the record, otherwise the
    // new photos collide with the existing ones
    let seq = evidence.length
    for (let i = 0; i < files.length; i++) {
      const fd = new FormData()
      fd.append('evidence_type', 'PROPERTY_PHOTO')
      fd.append('sequence_no', String(seq + i + 1))
      fd.append('capture_source', 'UPLOAD')
      fd.append('file', files[i])
      await api.post(`/discoveries/${id}/evidence`, fd)
    }
    if (docFile) {
      const fd = new FormData()
      fd.append('evidence_type', 'OWNERSHIP_DOCUMENT')
      fd.append('capture_source', 'UPLOAD')
      fd.append('file', docFile)
      await api.post(`/discoveries/${id}/evidence`, fd)
    }
    setFiles([]); setDocFile(null)
  }

  const reloadEvidence = async (id) => {
    const r = await api.get(`/discoveries/${id}`)
    setEvidence(unwrap(r).data.evidence || [])
  }

  const save = async (thenResubmit) => {
    setErr(''); setBusy(true)
    try {
      const r = await api.put(`/discoveries/${d.id}`, payload())
      await uploadNew(d.id)
      await reloadEvidence(d.id)
      setSaved(true)
      if (thenResubmit) {
        await api.post(`/discoveries/${d.id}/resubmit`, {
          value_declaration_type: form.value_declaration_type || undefined,
          declared_assessed_value: form.declared_assessed_value || undefined,
          documentary_value: form.documentary_value || undefined,
        })
        onSaved?.(true)
        return
      }
      onSaved?.(false, unwrap(r).data)
    } catch (ex) {
      setErr(errMsg(ex))
    }
    setBusy(false)
  }

  const removeEvidence = async (ev) => {
    setErr(''); setBusy(true)
    try {
      await api.delete(`/discoveries/${d.id}/evidence/${ev.id}`)
      await reloadEvidence(d.id)
    } catch (ex) { setErr(errMsg(ex)) }
    setBusy(false)
  }

  const declaration = form.value_declaration_type

  return (
    <div className="fixed inset-0 z-[60] bg-navy-950/60 backdrop-blur-sm grid place-items-center p-4 overflow-y-auto">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
        <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white z-10">
          <div>
            <h3 className="font-bold text-navy-800">Correct {d.discovery_reference}</h3>
            <p className="text-xs text-slate-500">Returned for correction — amend the record, then resubmit.</p>
          </div>
          <button onClick={onClose} className="w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-500 font-bold">✕</button>
        </div>

        <div className="p-6 space-y-6">
          {err && <div className="rounded-xl bg-red-50 text-red-700 px-4 py-3 text-sm">{err}</div>}

          {reason && (
            <div className="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900">
              <span className="font-semibold">What the reviewer asked for: </span>{reason}
            </div>
          )}

          <section className="space-y-3">
            <h4 className="text-xs font-bold uppercase tracking-wide text-slate-500">Owner information</h4>
            <div className="grid sm:grid-cols-2 gap-3">
              <Text label="Owner / occupant name" value={form.owner_name} onChange={set('owner_name')} />
              <Text label="Contact" value={form.owner_contact} onChange={set('owner_contact')} />
              <Text label="TIN" value={form.tin} onChange={set('tin')} />
            </div>
          </section>

          <section className="space-y-3">
            <h4 className="text-xs font-bold uppercase tracking-wide text-slate-500">Address</h4>
            <Text label="Property address" value={form.property_address} onChange={set('property_address')} />
            <div className="grid sm:grid-cols-2 gap-3">
              <Text label="County" value={form.county} onChange={set('county')} />
              <Text label="District" value={form.district} onChange={set('district')} />
              <Text label="City / town" value={form.city_town} onChange={set('city_town')} />
              <Text label="Community" value={form.community} onChange={set('community')} />
              <Text label="Street" value={form.street} onChange={set('street')} />
              <Text label="House number" value={form.house_number} onChange={set('house_number')} />
            </div>
          </section>

          <section className="space-y-3">
            <h4 className="text-xs font-bold uppercase tracking-wide text-slate-500">Property</h4>
            <div className="grid sm:grid-cols-3 gap-3">
              <Text label="Classification" value={form.property_classification} onChange={set('property_classification')} />
              <Text label="Property type" value={form.property_type} onChange={set('property_type')} />
              <Text label="Occupancy / use" value={form.occupancy_use} onChange={set('occupancy_use')} />
            </div>
            <label className="block">
              <span className="text-xs font-semibold text-slate-600">Description</span>
              <textarea rows={3} value={form.description} onChange={set('description')} className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
            </label>
          </section>

          <section className="space-y-3">
            <h4 className="text-xs font-bold uppercase tracking-wide text-slate-500">Value declaration</h4>
            <div className="grid sm:grid-cols-3 gap-3">
              <Select label="Basis" value={form.value_declaration_type} onChange={set('value_declaration_type')}
                options={[
                  { v: '', t: '— none —' },
                  ...VALUE_DECLARATION_TYPES.map((t) => ({ v: t.value, t: t.label })),
                ]} />
              {declaration !== 'VALUATION_VALUE' && (
                <>
                  <Text label="Declared value" type="number" step="0.01" value={form.declared_assessed_value} onChange={set('declared_assessed_value')} />
                  <Text label="Documentary value" type="number" step="0.01" value={form.documentary_value} onChange={set('documentary_value')} />
                </>
              )}
            </div>
            {declaration && (
              <p className="text-xs text-slate-500">
                {VALUE_DECLARATION_TYPES.find((t) => t.value === declaration)?.hint}
              </p>
            )}
          </section>

          <section className="space-y-3">
            <h4 className="text-xs font-bold uppercase tracking-wide text-slate-500">GPS / property location</h4>
            <div className="grid sm:grid-cols-3 gap-3">
              <Text label="Latitude" value={form.gps_lat} onChange={set('gps_lat')} />
              <Text label="Longitude" value={form.gps_lng} onChange={set('gps_lng')} />
              <Text label="Accuracy (m)" value={form.gps_accuracy} onChange={set('gps_accuracy')} />
            </div>
          </section>

          <section className="space-y-3">
            <h4 className="text-xs font-bold uppercase tracking-wide text-slate-500">Supporting evidence</h4>
            {evidence.length > 0 && (
              <ul className="space-y-2">
                {evidence.map((ev) => (
                  <li key={ev.id} className="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2">
                    {ev.photo_url && (
                      <GatedImage src={ev.photo_url} alt={ev.original_name} className="w-10 h-10 rounded object-cover shrink-0" />
                    )}
                    <span className="text-sm text-slate-700 truncate flex-1">
                      {ev.original_name} <span className="text-xs text-slate-400">· {String(ev.evidence_type || '').replace(/_/g, ' ')}</span>
                    </span>
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => removeEvidence(ev)}
                      className="text-xs font-semibold text-red-600 hover:text-red-700 disabled:opacity-40"
                    >
                      Remove
                    </button>
                  </li>
                ))}
              </ul>
            )}
            <div className="grid sm:grid-cols-2 gap-3">
              <label className="block">
                <span className="text-xs font-semibold text-slate-600">Add property photos</span>
                <input
                  type="file" multiple accept="image/*" capture="environment"
                  onChange={(e) => setFiles(Array.from(e.target.files || []))}
                  className="mt-1 w-full text-xs text-slate-600"
                />
              </label>
              <label className="block">
                <span className="text-xs font-semibold text-slate-600">Add ownership / deed document</span>
                <input
                  type="file" accept="image/*,application/pdf"
                  onChange={(e) => setDocFile(e.target.files?.[0] || null)}
                  className="mt-1 w-full text-xs text-slate-600"
                />
              </label>
            </div>
            {(files.length > 0 || docFile) && (
              <p className="text-xs text-slate-500">
                {files.length} photo(s){docFile ? ' + 1 document' : ''} will be uploaded when you save.
              </p>
            )}
          </section>

          <section className="space-y-3">
            <h4 className="text-xs font-bold uppercase tracking-wide text-slate-500">Officer remarks</h4>
            <textarea rows={2} value={form.remarks} onChange={set('remarks')} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
          </section>
        </div>

        <div className="px-6 py-4 border-t border-slate-100 flex items-center justify-between gap-3 sticky bottom-0 bg-white">
          <span className="text-xs text-slate-500">
            {saved ? 'Saved. The record can now be resubmitted.' : 'Not saved yet.'}
          </span>
          <div className="flex items-center gap-2">
            <button onClick={onClose} disabled={busy} className="px-4 py-2 rounded-lg text-sm font-semibold text-slate-600 hover:bg-slate-100 disabled:opacity-40">
              Cancel
            </button>
            <button onClick={() => save(false)} disabled={busy} className="px-4 py-2 rounded-lg text-sm font-semibold border border-navy-800 text-navy-800 hover:bg-slate-50 disabled:opacity-40">
              {busy ? 'Saving…' : 'Save changes'}
            </button>
            <button onClick={() => save(true)} disabled={busy} className="px-4 py-2 rounded-lg text-sm font-semibold bg-navy-800 text-white hover:bg-navy-700 disabled:opacity-40">
              {busy ? 'Working…' : 'Save & resubmit'}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

function Text({ label, value, onChange, type = 'text', step }) {
  return (
    <label className="block">
      <span className="text-xs font-semibold text-slate-600">{label}</span>
      <input type={type} step={step} value={value ?? ''} onChange={onChange} className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
    </label>
  )
}

function Select({ label, value, onChange, options }) {
  return (
    <label className="block">
      <span className="text-xs font-semibold text-slate-600">{label}</span>
      <select value={value ?? ''} onChange={onChange} className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm bg-white">
        {options.map((o) => <option key={o.v} value={o.v}>{o.t}</option>)}
      </select>
    </label>
  )
}
