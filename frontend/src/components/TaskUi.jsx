import { Fragment, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import api, { unwrap, errMsg } from '../api'
import { Btn, Input, Badge, fmtDate, fmtTime } from '../ui'
import { useAuth } from '../auth'
import { VISIT_STATUSES, BILL_DELIVERY_STATUSES, ENGAGEMENT_TYPES, engagementInfo } from '../lib/constants'

export const selectCls = 'w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500'

export function engagementOutcomeTone(o) {
  const ok = ['delivered', 'handed_over', 'received', 'notice_issued', 'notice_served', 'paid', 'confirmed', 'claim_submitted', 'contact_made']
  const bad = ['rejected', 'refused', 'no_answer', 'no_access', 'vacant', 'closed']
  if (ok.includes(o)) return 'green'
  if (bad.includes(o)) return 'red'
  if (o === 'promised_payment') return 'brand'
  return 'slate'
}

export function fmtWhen(d) { return d ? fmtTime(d) : '' }

function T({ children, label }) {
  return (
    <div>
      <label className="block text-xs font-semibold text-slate-600 mb-1.5">{label}</label>
      {children}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Record a field visit
// ---------------------------------------------------------------------------
export function RecordVisitModal({ task, onClose, onSaved }) {
  const bill = task.bill
  const cameraRef = useRef(null)
  const uploadRef = useRef(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [locating, setLocating] = useState(false)
  const [form, setForm] = useState({
    status: 'Visited - Delivered',
    bill_delivery_status: bill ? 'Delivered' : '',
    notes: '',
    recipient_name: '',
    recipient_contact: '',
    gps_coordinate: '',
    gps_accuracy: '',
    photo: '',
    photo_name: '',
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const useMyLocation = () => {
    if (!navigator.geolocation) return setErr('Geolocation is not supported in this browser.')
    setErr(''); setLocating(true)
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setForm((f) => ({
          ...f,
          gps_coordinate: `${pos.coords.latitude.toFixed(6)},${pos.coords.longitude.toFixed(6)}`,
          gps_accuracy: Math.round(pos.coords.accuracy || 0),
        }))
        setLocating(false)
      },
      (e) => { setErr(`Location unavailable: ${e.message}`); setLocating(false) },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 },
    )
  }

  const pickPhoto = (e) => {
    const file = e.target.files?.[0]
    setErr('')
    if (!file) return
    if (!file.type.startsWith('image/')) return setErr('Only image files are accepted as the property photo.')
    const fr = new FileReader()
    fr.onload = () => setForm((f) => ({ ...f, photo: String(fr.result), photo_name: file.name }))
    fr.onerror = () => setErr('Could not read the image file.')
    fr.readAsDataURL(file)
  }

  const submit = async (e) => {
    e.preventDefault()
    setErr(''); setBusy(true)
    try {
      await api.post('/enforcement-visits', {
        task_id: task.id,
        assignment_id: task.id,
        property_bill_id: bill?.id,
        bill_id: bill?.id,
        status: form.status,
        visit_status: form.status,
        bill_delivery_status: form.bill_delivery_status || undefined,
        delivery_status: form.bill_delivery_status || undefined,
        notes: form.notes || undefined,
        recipient_name: form.recipient_name || undefined,
        recipient_contact: form.recipient_contact || undefined,
        gps_coordinate: form.gps_coordinate || undefined,
        gps_accuracy: form.gps_accuracy ? Number(form.gps_accuracy) : undefined,
        gps_captured_at: form.gps_coordinate ? new Date().toISOString() : undefined,
        visit_photo: form.photo || undefined,
        photo_type: 'PROPERTY_FULL_VIEW',
      })
      onSaved()
    } catch (ex) { setErr(errMsg(ex, 'Could not record visit.')) }
    setBusy(false)
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/40 overflow-y-auto" onClick={onClose}>
      <div className="min-h-full grid place-items-center p-4" onClick={(e) => e.stopPropagation()}>
        <form onSubmit={submit} className="w-full max-w-lg bg-white rounded-2xl shadow-xl">
          <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div>
              <h2 className="font-bold text-navy-800">Record field visit</h2>
              <p className="text-xs text-slate-400">{task.task_reference}{bill ? ` · ${bill.document_number}` : ''}</p>
            </div>
            <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600 text-2xl leading-none px-2">×</button>
          </div>

          <div className="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto">
            {err && <div className="rounded-xl bg-red-50 text-red-700 border border-red-100 px-4 py-3 text-sm">{err}</div>}

            <T label="Visit outcome"><select className={selectCls} value={form.status} onChange={set('status')}>
              {VISIT_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
            </select></T>

            <T label="Bill delivery status"><select className={selectCls} value={form.bill_delivery_status} onChange={set('bill_delivery_status')}>
              <option value="">Not recorded</option>
              {BILL_DELIVERY_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
            </select></T>

            <T label="Recipient name"><Input className="w-full" value={form.recipient_name} onChange={set('recipient_name')} placeholder="Who received the bill (if delivered)" /></T>
            <T label="Recipient contact"><Input className="w-full" value={form.recipient_contact} onChange={set('recipient_contact')} /></T>

            <T label="GPS location (optional)">
              <div className="flex items-center gap-2">
                <Input className="w-full" value={form.gps_coordinate} onChange={set('gps_coordinate')} placeholder="e.g. 6.3156,-10.8074" />
                <Btn type="button" tone="white" onClick={useMyLocation} disabled={locating}>{locating ? 'Locating…' : '📍 Use my location'}</Btn>
              </div>
              {form.gps_accuracy > 0 && <p className="text-[10px] text-emerald-600 mt-1">Captured · accuracy ±{form.gps_accuracy}m</p>}
            </T>

            <T label="Property photo (capture or upload)">
              <input ref={cameraRef} type="file" accept="image/*" capture="environment" onChange={pickPhoto} className="hidden" />
              <input ref={uploadRef} type="file" accept="image/*" onChange={pickPhoto} className="hidden" />
              {form.photo ? (
                <div className="flex items-center gap-3">
                  <img src={form.photo} alt="Property" className="h-24 w-40 object-cover rounded-xl border border-slate-200" />
                  <div className="space-y-1.5">
                    <p className="text-xs text-emerald-600">✓ {form.photo_name || 'Property photo attached'}</p>
                    <button type="button" onClick={() => setForm({ ...form, photo: '', photo_name: '' })} className="text-xs font-semibold text-red-500 hover:text-red-700">Remove & retake</button>
                  </div>
                </div>
              ) : (
                <div className="flex items-center gap-2">
                  <Btn type="button" tone="white" onClick={() => cameraRef.current?.click()}>📷 Take photo</Btn>
                  <Btn type="button" tone="white" onClick={() => uploadRef.current?.click()}>⬆ Upload</Btn>
                </div>
              )}
            </T>

            <T label="Notes"><textarea className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm" rows={3} value={form.notes} onChange={set('notes')} /></T>
          </div>

          <div className="flex items-center justify-end gap-2 px-6 py-4 border-t border-slate-100 bg-slate-50/60 rounded-b-2xl">
            <Btn type="button" tone="white" onClick={onClose}>Cancel</Btn>
            <Btn type="submit" disabled={busy}>{busy ? 'Recording…' : 'Record visit'}</Btn>
          </div>
        </form>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Submit a payment receipt (payment claim)
// ---------------------------------------------------------------------------
export function SubmitReceiptModal({ task, onClose, onSaved }) {
  const bill = task.bill
  const cameraRef = useRef(null)
  const uploadRef = useRef(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [form, setForm] = useState({
    billing_number: bill?.document_number || task.bill_name || '',
    property_id: bill?.property_id || '',
    tin: bill?.tin || '',
    tax_due_date: bill?.date_logged || '',
    amount: '',
    receipt_number: '',
    period: new Date().getFullYear(),
    photo: '',
    photo_name: '',
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const pickPhoto = (e) => {
    const file = e.target.files?.[0]
    setErr('')
    if (!file) return
    if (!file.type.startsWith('image/')) return setErr('Only image files are accepted as the receipt photo.')
    const fr = new FileReader()
    fr.onload = () => setForm((f) => ({ ...f, photo: String(fr.result), photo_name: file.name }))
    fr.onerror = () => setErr('Could not read the image file.')
    fr.readAsDataURL(file)
  }

  const submit = async (e) => {
    e.preventDefault()
    setErr(''); setBusy(true)
    try {
      await api.post('/enforcement/submit-receipt', {
        billing_number: form.billing_number,
        property_id: form.property_id,
        tin: form.tin,
        tax_due_date: form.tax_due_date || undefined,
        amount: Number(form.amount) || 0,
        receipt_number: form.receipt_number,
        payment_period: String(form.period),
        receipt_attachment: form.photo || undefined,
      })
      onSaved()
    } catch (ex) { setErr(errMsg(ex, 'Could not submit receipt.')) }
    setBusy(false)
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/40 overflow-y-auto" onClick={onClose}>
      <div className="min-h-full grid place-items-center p-4" onClick={(e) => e.stopPropagation()}>
        <form onSubmit={submit} className="w-full max-w-xl bg-white rounded-2xl shadow-xl">
          <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div>
              <h2 className="font-bold text-navy-800">Submit payment receipt</h2>
              <p className="text-xs text-slate-400">Creates a payment claim for Accounts to verify.</p>
            </div>
            <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600 text-2xl leading-none px-2">×</button>
          </div>

          <div className="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto">
            {err && <div className="rounded-xl bg-red-50 text-red-700 border border-red-100 px-4 py-3 text-sm">{err}</div>}

            <div className="grid sm:grid-cols-2 gap-4">
              <T label="Bill number" required><Input className="w-full" value={form.billing_number} onChange={set('billing_number')} required /></T>
              <T label="Receipt number" required><Input className="w-full" value={form.receipt_number} onChange={set('receipt_number')} required /></T>
              <T label="Property ID" required><Input className="w-full" value={form.property_id} onChange={set('property_id')} required /></T>
              <T label="Tax Identification Number (TIN)" required><Input className="w-full" value={form.tin} onChange={set('tin')} required /></T>
              <T label="Amount paid (US$)" required><Input className="w-full" type="number" min="0" step="0.01" value={form.amount} onChange={set('amount')} required /></T>
              <T label="Tax period"><Input className="w-full" value={form.period} onChange={set('period')} /></T>
            </div>
            <T label="Tax due year" required><Input className="w-full" type="number" min={2000} max={2100} placeholder="e.g. 2024" value={form.tax_due_date} onChange={set('tax_due_date')} required /></T>

            <div>
              <label className="block text-xs font-semibold text-slate-600 mb-1.5">Receipt photo (capture or upload) <span className="text-red-500">*</span></label>
              <input ref={cameraRef} type="file" accept="image/*" capture="environment" onChange={pickPhoto} className="hidden" />
              <input ref={uploadRef} type="file" accept="image/*" onChange={pickPhoto} className="hidden" />
              {form.photo ? (
                <div className="flex items-center gap-3">
                  <img src={form.photo} alt="Receipt" className="h-24 w-40 object-cover rounded-xl border border-slate-200" />
                  <div className="space-y-1.5">
                    <p className="text-xs text-emerald-600">✓ {form.photo_name || 'Receipt photo attached'}</p>
                    <button type="button" onClick={() => setForm({ ...form, photo: '', photo_name: '' })} className="text-xs font-semibold text-red-500 hover:text-red-700">Remove & retake</button>
                  </div>
                </div>
              ) : (
                <div className="flex items-center gap-2">
                  <Btn type="button" tone="white" onClick={() => cameraRef.current?.click()}>📷 Take photo</Btn>
                  <Btn type="button" tone="white" onClick={() => uploadRef.current?.click()}>⬆ Upload</Btn>
                </div>
              )}
            </div>
          </div>

          <div className="flex items-center justify-end gap-2 px-6 py-4 border-t border-slate-100 bg-slate-50/60 rounded-b-2xl">
            <Btn type="button" tone="white" onClick={onClose}>Cancel</Btn>
            <Btn type="submit" disabled={busy || !form.photo}>{busy ? 'Submitting…' : 'Submit receipt'}</Btn>
          </div>
        </form>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Record a manual engagement (follow-up, note, attempt)
// ---------------------------------------------------------------------------
export function EngagementModal({ task, onClose, onSaved }) {
  const bill = task.bill
  const cameraRef = useRef(null)
  const uploadRef = useRef(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [locating, setLocating] = useState(false)
  const [form, setForm] = useState({
    engagement_type: 'follow_up',
    outcome: '',
    notes: '',
    occurred_at: '',
    gps_coordinate: '',
    gps_accuracy: '',
    photo: '',
    photo_name: '',
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const useMyLocation = () => {
    if (!navigator.geolocation) return setErr('Geolocation is not supported in this browser.')
    setErr(''); setLocating(true)
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setForm((f) => ({
          ...f,
          gps_coordinate: `${pos.coords.latitude.toFixed(6)},${pos.coords.longitude.toFixed(6)}`,
          gps_accuracy: Math.round(pos.coords.accuracy || 0),
        }))
        setLocating(false)
      },
      (e) => { setErr(`Location unavailable: ${e.message}`); setLocating(false) },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 },
    )
  }

  const pickPhoto = (e) => {
    const file = e.target.files?.[0]
    setErr('')
    if (!file) return
    if (!file.type.startsWith('image/')) return setErr('Only image files are accepted as the property photo.')
    const fr = new FileReader()
    fr.onload = () => setForm((f) => ({ ...f, photo: String(fr.result), photo_name: file.name }))
    fr.onerror = () => setErr('Could not read the image file.')
    fr.readAsDataURL(file)
  }

  const submit = async (e) => {
    e.preventDefault()
    setErr(''); setBusy(true)
    try {
      await api.post(`/tasks/${task.id}/engagements`, {
        engagement_type: form.engagement_type,
        outcome: form.outcome || undefined,
        notes: form.notes || undefined,
        occurred_at: form.occurred_at || undefined,
      })
      if (form.photo) {
        await api.post('/evidence/photos', {
          photo_type: 'PROPERTY_FULL_VIEW',
          task_id: task.id,
          bill_id: bill?.id,
          property_id: bill?.property_id,
          gps_coordinate: form.gps_coordinate || undefined,
          captured_at: new Date().toISOString(),
          data_uri: form.photo,
          remarks: `Captured with ${form.engagement_type} engagement.`,
        })
      }
      onSaved()
    } catch (ex) { setErr(errMsg(ex, 'Could not record engagement.')) }
    setBusy(false)
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/40 overflow-y-auto" onClick={onClose}>
      <div className="min-h-full grid place-items-center p-4" onClick={(e) => e.stopPropagation()}>
        <form onSubmit={submit} className="w-full max-w-lg bg-white rounded-2xl shadow-xl">
          <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div>
              <h2 className="font-bold text-navy-800">Record engagement</h2>
              <p className="text-xs text-slate-400">{task.task_reference} · timeline entry</p>
            </div>
            <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600 text-2xl leading-none px-2">×</button>
          </div>

          <div className="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto">
            {err && <div className="rounded-xl bg-red-50 text-red-700 border border-red-100 px-4 py-3 text-sm">{err}</div>}

            <T label="Engagement type"><select className={selectCls} value={form.engagement_type} onChange={set('engagement_type')}>
              {ENGAGEMENT_TYPES.map((t) => <option key={t} value={t}>{engagementInfo(t).label}</option>)}
            </select></T>
            <T label="Outcome"><Input className="w-full" value={form.outcome} onChange={set('outcome')} placeholder="e.g. contact_made, promised_payment, no_answer" /></T>
            <T label="Notes"><textarea className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm" rows={3} value={form.notes} onChange={set('notes')} /></T>
            <T label="Date occurred"><Input className="w-full" type="date" value={form.occurred_at} onChange={set('occurred_at')} /></T>

            <T label="GPS location (optional)">
              <div className="flex items-center gap-2">
                <Input className="w-full" value={form.gps_coordinate} onChange={set('gps_coordinate')} placeholder="e.g. 6.3156,-10.8074" />
                <Btn type="button" tone="white" onClick={useMyLocation} disabled={locating}>{locating ? 'Locating…' : '📍 Use my location'}</Btn>
              </div>
              {form.gps_accuracy > 0 && <p className="text-[10px] text-emerald-600 mt-1">Captured · accuracy ±{form.gps_accuracy}m</p>}
            </T>

            <T label="Property photo (capture or upload)">
              <input ref={cameraRef} type="file" accept="image/*" capture="environment" onChange={pickPhoto} className="hidden" />
              <input ref={uploadRef} type="file" accept="image/*" onChange={pickPhoto} className="hidden" />
              {form.photo ? (
                <div className="flex items-center gap-3">
                  <img src={form.photo} alt="Property" className="h-24 w-40 object-cover rounded-xl border border-slate-200" />
                  <div className="space-y-1.5">
                    <p className="text-xs text-emerald-600">✓ {form.photo_name || 'Property photo attached'}</p>
                    <button type="button" onClick={() => setForm({ ...form, photo: '', photo_name: '' })} className="text-xs font-semibold text-red-500 hover:text-red-700">Remove & retake</button>
                  </div>
                </div>
              ) : (
                <div className="flex items-center gap-2">
                  <Btn type="button" tone="white" onClick={() => cameraRef.current?.click()}>📷 Take photo</Btn>
                  <Btn type="button" tone="white" onClick={() => uploadRef.current?.click()}>⬆ Upload</Btn>
                </div>
              )}
            </T>
          </div>

          <div className="flex items-center justify-end gap-2 px-6 py-4 border-t border-slate-100 bg-slate-50/60 rounded-b-2xl">
            <Btn type="button" tone="white" onClick={onClose}>Cancel</Btn>
            <Btn type="submit" disabled={busy}>{busy ? 'Recording…' : 'Record engagement'}</Btn>
          </div>
        </form>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Ladder — compact progress strip (actual state + next step only)
// ---------------------------------------------------------------------------
export function TaskLadder({ timeline }) {
  if (!timeline || !timeline.length) return null
  const next = timeline.find((s) => s.state === 'upcoming')
  const relevant = timeline.filter((s) => s.state === 'done' || s.state === 'current' || (next && s.key === next.key))
  const doneCount = relevant.filter((s) => s.state === 'done').length

  return (
    <div className="flex items-center px-5 py-2.5 bg-slate-50/60 border-b border-slate-100 overflow-x-auto">
      {relevant.map((s, i) => {
        const isNext = next && s.key === next.key
        const cls = s.state === 'done'
          ? 'bg-emerald-500 border-emerald-500'
          : s.state === 'current'
            ? 'bg-brand-500 border-brand-500 ring-2 ring-brand-200'
            : 'border-brand-400 bg-white'
        const showLabel = s.state === 'current' || isNext || (!next && i === relevant.length - 1)
        return (
          <Fragment key={s.key}>
            <div className="flex items-center gap-1.5 shrink-0">
              <span className={`w-2.5 h-2.5 rounded-full border-2 ${cls}`} />
              {showLabel && (
                <span className={`text-[10px] font-bold whitespace-nowrap ${s.state === 'current' ? 'text-brand-700' : 'text-brand-500'}`}>
                  {s.state === 'current' ? 'CURRENT · ' : isNext ? 'NEXT · ' : ''}{s.label}
                </span>
              )}
            </div>
            {i < relevant.length - 1 && <span className="w-3 h-px bg-slate-200 shrink-0" />}
          </Fragment>
        )
      })}
      <span className="ml-auto text-[10px] text-slate-400 shrink-0">{doneCount}/{relevant.length} stages done</span>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Next-action button / chip (the single required action on the card)
// ---------------------------------------------------------------------------
export function TaskNextAction({ task, canVisit, busyId, onRecordVisit, onAdvance, onClaim }) {
  const { can } = useAuth()
  const na = task.next_action || {}
  const kind = na.kind
  const verb = na.verb || ''

  if (kind === 'none' || !verb) {
    return <span className="text-xs text-slate-400">{na.notes || 'No further action required.'}</span>
  }

  if (/claim payment|claim|submit receipt/i.test(verb) && can('payments.claim')) {
    return <button onClick={() => onClaim?.(task)} disabled={busyId === task.id} className="px-4 py-2 rounded-xl bg-brand-500 text-white hover:bg-brand-600 text-sm font-semibold transition">🧾 Submit payment claim</button>
  }

  if (kind === 'auto') {
    return (
      <span className="inline-flex items-center gap-1.5 text-xs">
        <span className="rounded-lg bg-slate-100 text-slate-600 px-3 py-1.5 font-medium">{na.verb}</span>
        {na.auto_at && <span className="text-slate-400">auto-runs {fmtDate(na.auto_at)}</span>}
      </span>
    )
  }

  if (kind === 'verify' && can('payments.verify')) {
    return <Link to="/payments" className="inline-flex items-center gap-2 rounded-xl bg-navy-50 text-navy-800 hover:bg-navy-100 px-4 py-2 text-sm font-semibold transition">Verify pending claim →</Link>
  }

  if (kind === 'advance' && can('tasks.complete', 'tasks.escalate', 'enforcement.escalate', 'enforcement.record_visit')) {
    return (
      <button
        onClick={(e) => { e.preventDefault(); onAdvance(task) }}
        disabled={busyId === task.id}
        className="px-4 py-2 rounded-xl bg-amber-100 text-amber-800 hover:bg-amber-200 text-sm font-semibold transition disabled:opacity-50">
        {busyId === task.id ? 'Running…' : 'Run pending step'}
      </button>
    )
  }

  if (kind === 'manual') {
    if (/deliver/i.test(verb) && canVisit) {
      return <button onClick={() => onRecordVisit(task)} disabled={busyId === task.id} className="px-4 py-2 rounded-xl bg-brand-500 text-white hover:bg-brand-600 text-sm font-semibold transition">Record field delivery</button>
    }
    if (/assign/i.test(verb) && can('tasks.assign', 'me.assign_walkin') && task.reference_id) {
      return <Link to={`/bills/${task.reference_id}`} className="inline-flex items-center gap-2 rounded-xl bg-navy-50 text-navy-800 hover:bg-navy-100 px-4 py-2 text-sm font-semibold transition">Assign an officer →</Link>
    }
    if (canVisit) {
      return <button onClick={() => onRecordVisit(task)} disabled={busyId === task.id} className="px-4 py-2 rounded-xl bg-brand-50 text-brand-700 hover:bg-brand-100 text-sm font-semibold transition">Record visit</button>
    }
    return <span className="text-xs text-slate-500">{verb}{na.notes ? ` — ${na.notes}` : ''}</span>
  }

  return <span className="text-xs text-slate-500">{verb}</span>
}