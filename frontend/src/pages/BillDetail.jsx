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
    remarks: bill.remarks || '',
  })
  const [busy, setBusy] = useState(false)

  if (!can('bills.edit')) return null

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    try {
      await api.put(`/property-bills/${bill.id}`, form)
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
      <div className="sm:col-span-2">
        <label className="block text-xs font-semibold text-slate-600 mb-1.5">Remarks</label>
        <textarea className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm" rows={2} value={form.remarks} onChange={set('remarks')} />
      </div>
      <div className="sm:col-span-2">
        <Btn type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save changes'}</Btn>
        <span className="ml-3 text-[11px] text-slate-400">Document #, Property ID and tax amounts originate from the source tax system. Tax / interest / penalty amounts are changed through the Amendment action below, never here.</span>
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
  const [saveErr, setSaveErr] = useState(null)
  const [notice, setNotice] = useState('')

  const load = () => api.get(`/property-bills/${id}`).then((r) => setBill(unwrap(r).data)).catch((e) => {
    if (e.response?.status === 403 || e.response?.status === 404) nav('/bills')
    else setErr(e)
  })

  const refresh = async () => { await load(); setNotice('') }
  const retry = async () => { setErr(null); await load() }

  useEffect(() => { load() }, [id]) // eslint-disable-line

  if (!bill && !err) return <Spinner label="Loading bill…" />
  // Load failed and there is no prior content to fall back on.
  if (!bill) return (
    <div className="space-y-4">
      <PageTitle right={<Link to="/bills"><Btn tone="white">← Register</Btn></Link>}>Bill {id}</PageTitle>
      <ErrorBox error={{ message: err.message || 'Unable to load bill.' }} />
      <Btn tone="white" onClick={retry}>⟳ Retry</Btn>
    </div>
  )

  return (
    <div className="space-y-5">
      <PageTitle
        sub={`Property ID ${bill.property_id} · ${bill.document_number} · ${bill.taxpayer_name || ''}`}
        right={<Link to="/bills"><Btn tone="white">← Register</Btn></Link>}>
        Bill {bill.document_number}
      </PageTitle>

      {/* Stale refresh failure — keep the loaded content visible, offer retry. */}
      {err && (
        <div className="flex items-center gap-2">
          <div className="flex-1"><ErrorBox error={{ message: err.message || 'Unable to refresh the bill.' }} /></div>
          <Btn tone="white" onClick={retry}>⟳ Retry</Btn>
        </div>
      )}

      {notice && <SuccessBox>{notice}</SuccessBox>}

      {/* The unified task + property/bill workspace (same component as the drawer). */}
      <BillDrawer billId={bill.id} embedded onChanged={load} />

      {can('bills.edit') && (
        <Card title="Edit bill details">
          {saveErr && (
            <div className="flex items-center gap-2 mb-3">
              <div className="flex-1"><ErrorBox error={{ message: saveErr }} /></div>
              <Btn tone="white" onClick={() => setSaveErr(null)}>Dismiss</Btn>
            </div>
          )}
          <EditForm bill={bill} onDone={() => { refresh(); setSaveErr(null); setNotice('Bill updated.') }} onError={setSaveErr} />
        </Card>
      )}
    </div>
  )
}