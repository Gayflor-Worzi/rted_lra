import { useEffect, useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import api, { unwrap, errMsg } from '../api'
import { Spinner, ErrorBox, PageTitle, Card, Btn, SuccessBox, Input } from '../ui'
import { useAuth } from '../auth'
import { PROPERTY_CLASSIFICATIONS } from '../lib/constants'
import BillDrawer from '../components/BillDrawer'

function EditForm({ bill, onDone, onError }) {
  const { can } = useAuth()
  const [form, setForm] = useState({
    taxpayer_name: bill.taxpayer_name,
    property_address: bill.property_address,
    property_classification: bill.property_classification || '',
    tax_period: bill.tax_period || '',
    tax_amount: bill.tax_amount,
    interest_charged: bill.interest_charged,
    penalty_charged: bill.penalty_charged,
    remarks: bill.remarks || '',
  })
  const [busy, setBusy] = useState(false)

  if (!can('bills.edit')) return null

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    try {
      await api.put(`/property-bills/${bill.id}`, {
        ...form,
        tax_amount: Number(form.tax_amount) || 0,
        interest_charged: Number(form.interest_charged) || 0,
        penalty_charged: Number(form.penalty_charged) || 0,
      })
      onDone()
    } catch (ex) { onError(errMsg(ex)) }
    setBusy(false)
  }

  return (
    <form onSubmit={submit} className="grid sm:grid-cols-2 gap-4">
      <FIELD_form label="Taxpayer" value={form.taxpayer_name} onChange={set('taxpayer_name')} />
      <FIELD_form label="Property address" value={form.property_address} onChange={set('property_address')} />
      <div>
        <label className="block text-xs font-semibold text-slate-600 mb-1.5">Classification</label>
        <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={form.property_classification} onChange={set('property_classification')}>
          <option value="">Select classification…</option>
          {PROPERTY_CLASSIFICATIONS.map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
      </div>
      <FIELD_form label="Tax period" value={form.tax_period} onChange={set('tax_period')} />
      <FIELD_form label="Tax amount (US$)" type="number" value={form.tax_amount} onChange={set('tax_amount')} />
      <FIELD_form label="Interest (US$)" type="number" value={form.interest_charged} onChange={set('interest_charged')} />
      <FIELD_form label="Penalty (US$)" type="number" value={form.penalty_charged} onChange={set('penalty_charged')} />
      <div className="sm:col-span-2">
        <label className="block text-xs font-semibold text-slate-600 mb-1.5">Remarks</label>
        <textarea className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm" rows={2} value={form.remarks} onChange={set('remarks')} />
      </div>
      <div className="sm:col-span-2">
        <Btn type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save changes'}</Btn>
        <span className="ml-3 text-[11px] text-slate-400">Document # and Property ID originate from the source tax system and cannot be edited here.</span>
      </div>
    </form>
  )
}

function FIELD_form({ label, value, onChange, type = 'text' }) {
  return (
    <div>
      <label className="block text-xs font-semibold text-slate-600 mb-1.5">{label}</label>
      <Input type={type} value={value} onChange={onChange} className="w-full" />
    </div>
  )
}

/**
 * /bills/:id deep link — renders the SAME unified workspace as the task
 * drawer (so the two screens never diverge), with the bill-maintenance
 * form below it.
 */
export default function BillDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const { can } = useAuth()
  const [bill, setBill] = useState(null)
  const [err, setErr] = useState(null)
  const [notice, setNotice] = useState('')

  const load = () => api.get(`/property-bills/${id}`).then((r) => setBill(unwrap(r).data)).catch((e) => {
    if (e.response?.status === 403 || e.response?.status === 404) nav('/bills')
    else setErr(e)
  })

  const refresh = async () => { await load(); setNotice('') }
  const onError = (m) => setErr({ message: m })

  useEffect(() => { load() }, [id]) // eslint-disable-line

  if (err) return <ErrorBox error={{ message: err.message || 'Unable to load bill.' }} />
  if (!bill) return <Spinner label="Loading bill…" />

  return (
    <div className="space-y-5">
      <PageTitle
        sub={`Property ID ${bill.property_id} · ${bill.document_number} · ${bill.taxpayer_name || ''}`}
        right={<Link to="/bills"><Btn tone="white">← Register</Btn></Link>}>
        Bill {bill.document_number}
      </PageTitle>

      {notice && <SuccessBox>{notice}</SuccessBox>}
      <ErrorBox error={err?.response ? { message: errMsg(err) } : null} />

      {/* The unified task + property/bill workspace (same component as the drawer). */}
      <BillDrawer billId={bill.id} embedded onChanged={load} />

      {can('bills.edit') && (
        <Card title="Edit bill (totals recompute automatically)">
          <EditForm bill={bill} onDone={() => { refresh(); setNotice('Bill updated.') }} onError={onError} />
        </Card>
      )}
    </div>
  )
}