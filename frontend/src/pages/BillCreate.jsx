import { useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import { Card, Btn, Input, ErrorBox } from '../ui'
import { useAuth } from '../auth'
import { PROPERTY_CLASSIFICATIONS, PROPERTY_TYPES } from '../lib/constants'

const F = ({ label, required, children }) => (
  <div>
    <label className="block text-xs font-semibold text-slate-600 mb-1.5">{label}{required && <span className="text-red-500"> *</span>}</label>
    {children}
  </div>
)

export default function BillCreate({ onClose, onCreated }) {
  const { user } = useAuth()
  const canAssignBill = ['Enforcement Manager', 'System Administrator'].includes(user?.role)
  const [users, setUsers] = useState([])
  const [err, setErr] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)

  const [form, setForm] = useState({
    document_number: '',
    property_id: '',
    taxpayer_name: '',
    tin: '',
    property_classification: '',
    property_address: '',
    gps_coordinate: '',
    gps_accuracy: '',
    assessed_value: '',
    tax_amount: '',
    interest_charged: '0',
    penalty_charged: '0',
    tax_period: new Date().getFullYear(),
    property_type: '',
    recipient_name: '',
    recipient_contact: '',
    assigned_enforcement_officer_id: '',
    remarks: '',
  })

  useEffect(() => {
    if (!canAssignBill) return
    api.get('/lookup/enforcement-officers')
      .then((r) => setUsers(unwrap(r).data || []))
      .catch(() => { setUsers([]) })
  }, [canAssignBill])

  const set = (k) => (e) => {
    let v = e.target.value
    if (['assessed_value', 'tax_amount', 'interest_charged', 'penalty_charged', 'gps_accuracy'].includes(k)) v = e.target.value === '' ? '' : Number(e.target.value)
    if (k === 'assigned_enforcement_officer_id') v = e.target.value === '' ? '' : Number(e.target.value)
    setForm({ ...form, [k]: v })
  }

  const setOfficer = (e) => {
    const id = e.target.value === '' ? '' : Number(e.target.value)
    setForm((f) => {
      const officer = users.find((u) => u.id === id)
      const next = { ...f, assigned_enforcement_officer_id: id }
      if (officer) {
        next.recipient_name = officer.full_name
        next.recipient_contact = officer.phone || officer.email
      }
      return next
    })
  }

  const pickRecipient = (e) => {
    const name = e.target.value
    const officer = users.find((u) => u.full_name === name)
    setForm({ ...form, recipient_name: name, recipient_contact: officer ? (officer.phone || officer.email) : form.recipient_contact })
  }

  const officerAssigned = !!form.assigned_enforcement_officer_id
  const officerContact = (() => {
    const officer = users.find((u) => u.full_name === form.recipient_name)
    return form.recipient_contact || (officer ? (officer.phone || officer.email) : '')
  })()

  const total = (Number(form.tax_amount) || 0) + (Number(form.interest_charged) || 0) + (Number(form.penalty_charged) || 0)

  const submit = async (e) => {
    e.preventDefault()
    setErr(''); setNotice(''); setBusy(true)
    try {
      const r = await api.post('/property-bills', form)
      const bill = unwrap(r).data
      setNotice(`Bill ${bill.document_number} logged with ${bill.case_status} status. Task created.`)
      setTimeout(() => { onCreated && onCreated(bill) }, 700)
    } catch (ex) {
      const msg = errMsg(ex)
      const details = ex.response?.data?.errors
      if (details) {
        const first = Object.values(details)[0]
        setErr((Array.isArray(first) ? first[0] : first) || msg)
      } else {
        setErr(msg)
      }
    }
    setBusy(false)
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/40 overflow-y-auto" onClick={onClose}>
      <div className="min-h-full grid place-items-center p-4" onClick={(e) => e.stopPropagation()}>
        <form onSubmit={submit} className="w-full max-w-2xl bg-white rounded-2xl shadow-xl">
          <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div>
              <h2 className="font-bold text-navy-800">Log a bill</h2>
              <p className="text-xs text-slate-400">Log Printed Bills Here.</p>
            </div>
            <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600 text-2xl leading-none px-2">×</button>
          </div>

          <div className="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto">
            {notice && <div className="rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100 px-4 py-3 text-sm">{notice}</div>}
            {err && <ErrorBox error={{ message: err }} />}

            <Card className="!p-0 shadow-none border border-slate-100">
              <div className="text-xs font-semibold text-slate-500 px-4 pt-4 uppercase tracking-wider">Source identifiers</div>
              <div className="grid sm:grid-cols-2 gap-4 p-4">
                <F label="Document #" required><Input className="w-full" value={form.document_number} onChange={set('document_number')} placeholder="e.g. 2026/12345" required /></F>
                <F label="Property ID" required><Input className="w-full" value={form.property_id} onChange={set('property_id')} placeholder="e.g. 103458" required /></F>
                <F label="TIN" required><Input className="w-full" value={form.tin} onChange={set('tin')} required /></F>
                {canAssignBill && <F label="Direct assignment to officer"><select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={form.assigned_enforcement_officer_id} onChange={setOfficer}>
                  <option value="">Walk-in / leave for enforcement queue</option>
                  {users.map((u) => <option key={u.id} value={u.id}>{u.full_name} ({u.staff_id})</option>)}
                </select></F>}
              </div>
            </Card>

            <Card className="!p-0 shadow-none border border-slate-100">
              <div className="text-xs font-semibold text-slate-500 px-4 pt-4 uppercase tracking-wider">Property &amp; taxpayer</div>
              <div className="grid sm:grid-cols-2 gap-4 p-4">
                <F label="Taxpayer name" required><Input className="w-full" value={form.taxpayer_name} onChange={set('taxpayer_name')} required /></F>
                <F label="Property address" required><Input className="w-full" value={form.property_address} onChange={set('property_address')} required /></F>
                <F label="Classification"><select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={form.property_classification} onChange={set('property_classification')}>
                  <option value="">Select classification…</option>
                  {PROPERTY_CLASSIFICATIONS.map((c) => <option key={c} value={c}>{c}</option>)}
                </select></F>
                <F label="Property type"><select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={form.property_type} onChange={set('property_type')}>
                  <option value="">Select property type…</option>
                  {PROPERTY_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                </select></F>
                <F label="Tax period"><Input className="w-full" value={form.tax_period} onChange={set('tax_period')} /></F>
                <F label="Assessed value (US$)"><Input className="w-full" type="number" step="any" min="0" value={form.assessed_value} onChange={set('assessed_value')} /></F>
              </div>
            </Card>

            <Card className="!p-0 shadow-none border border-slate-100">
              <div className="text-xs font-semibold text-slate-500 px-4 pt-4 uppercase tracking-wider">Property location (GPS)</div>
              <div className="grid sm:grid-cols-2 gap-4 p-4">
                <F label="GPS coordinates"><Input className="w-full" value={form.gps_coordinate} onChange={set('gps_coordinate')} placeholder="lat,lng" /></F>
                <F label="Accuracy (m)"><Input className="w-full" type="number" step="any" min="0" value={form.gps_accuracy} onChange={set('gps_accuracy')} placeholder="e.g. 12" /></F>
              </div>
            </Card>

            <Card className="!p-0 shadow-none border border-slate-100">
              <div className="text-xs font-semibold text-slate-500 px-4 pt-4 uppercase tracking-wider">Amounts</div>
              <div className="grid sm:grid-cols-3 gap-4 p-4">
                <F label="Tax amount (US$)" required><Input className="w-full" type="number" min="0" step="0.01" value={form.tax_amount} onChange={set('tax_amount')} required /></F>
                <F label="Interest (US$)"><Input className="w-full" type="number" min="0" step="0.01" value={form.interest_charged} onChange={set('interest_charged')} /></F>
                <F label="Penalty (US$)"><Input className="w-full" type="number" min="0" step="0.01" value={form.penalty_charged} onChange={set('penalty_charged')} /></F>
              </div>
            </Card>

            <Card className="!p-0 shadow-none border border-slate-100">
              <div className="text-xs font-semibold text-slate-500 px-4 pt-4 uppercase tracking-wider">Recipient &amp; remarks (optional)</div>
              <div className="grid sm:grid-cols-2 gap-4 p-4">
                <F label="Recipient name">
                  {officerAssigned ? (
                    <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={form.recipient_name} onChange={pickRecipient}>
                      <option value="">Select officer…</option>
                      {users.map((u) => <option key={u.id} value={u.full_name}>{u.full_name} ({u.staff_id})</option>)}
                    </select>
                  ) : (
                    <Input className="w-full" value={form.recipient_name} onChange={set('recipient_name')} />
                  )}
                </F>
                <F label="Recipient contact">
                  {officerAssigned ? (
                    <div className="px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-500">{officerContact || '—'}</div>
                  ) : (
                    <Input className="w-full" value={form.recipient_contact} onChange={set('recipient_contact')} />
                  )}
                </F>
                <div className="sm:col-span-2">
                  <F label="Remarks"><Input className="w-full" value={form.remarks} onChange={set('remarks')} /></F>
                </div>
              </div>
            </Card>
          </div>

          <div className="flex items-center justify-between px-6 py-4 border-t border-slate-100 bg-slate-50/60 rounded-b-2xl">
            <div className="text-sm text-slate-500">Total due: <span className="font-bold text-navy-800">US$ {total.toLocaleString()}</span></div>
            <div className="flex gap-2">
              <Btn type="button" tone="white" onClick={onClose}>Cancel</Btn>
              <Btn type="submit" disabled={busy}>{busy ? 'Logging…' : 'Log bill'}</Btn>
            </div>
          </div>
        </form>
      </div>
    </div>
  )
}
