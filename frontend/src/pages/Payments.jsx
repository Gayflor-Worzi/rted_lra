import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import api, { unwrap, errMsg } from '../api'
import { Card, Badge, PageTitle, fmtMoney, fmtDate, fmtTime, Btn, Input, Spinner, ErrorBox, SuccessBox } from '../ui'
import { useAuth } from '../auth'

const STATUSES = ['Pending', 'Confirmed', 'Rejected', 'All']

const verifyTone = (s) => ({ Pending: 'amber', Confirmed: 'green', Rejected: 'red', Exception: 'navy' }[s] || 'slate')
const matchTone = (s) => ({ Match: 'green', Mismatch: 'red' }[s] || 'slate')

export default function Payments() {
  const { can } = useAuth()
  const [tab, setTab] = useState('Queue')
  const [status, setStatus] = useState('Pending')
  const [q, setQ] = useState('')
  const [rows, setRows] = useState(null)
  const [err, setErr] = useState(null)
  const [notice, setNotice] = useState('')
  const [target, setTarget] = useState(null) // { kind: 'confirm'|'reject', row }
  const [receipt, setReceipt] = useState(null) // { row }
  const first = useRef(true)

  const load = () => {
    setErr(null)
    const params = { per_page: 100, q: q.trim() || undefined }
    if (tab === 'Queue') params.status = status
    const url = tab === 'Queue' ? '/payments/queue' : '/payments/history'
    return api.get(url, { params }).then((r) => setRows(unwrap(r).data)).catch(setErr)
  }

  useEffect(() => {
    if (!can('payments.view_queue', 'payments.view_history')) { setRows([]); return }
    if (first.current) { first.current = false; load(); return }
    const t = setTimeout(load, 350)
    return () => clearTimeout(t)
  }, [tab, status, q]) // eslint-disable-line

  if (!can('payments.view_queue', 'payments.view_history')) {
    return (
      <PageTitle sub="Payment claims verification queue and history">Payments</PageTitle>
    )
  }

  const saved = (m) => { setTarget(null); setNotice(m); load() }

  return (
    <div className="space-y-4">
      <PageTitle sub="Payment verification — Account & Records reviews field claims, compares receipts, then confirms or rejects.">
        Payments
      </PageTitle>
      <ErrorBox error={err} />
      <SuccessBox>{notice}</SuccessBox>

      <div className="flex flex-wrap items-center gap-2 justify-between">
        <div className="flex flex-wrap gap-2">
          {['Queue', 'History'].map((t) => (
            <button key={t} onClick={() => setTab(t)}
              className={`px-3 py-1.5 rounded-lg text-sm ${tab === t ? 'bg-blue-600 text-white' : 'bg-white border hover:bg-slate-50'}`}>
              {t}
            </button>
          ))}
        </div>
        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search document / receipt / property…" className="w-64" />
      </div>

      {tab === 'Queue' && (
        <div className="flex flex-wrap gap-2">
          {STATUSES.map((s) => (
            <button key={s} onClick={() => setStatus(s)}
              className={`px-3 py-1 rounded-full text-xs font-semibold border ${status === s ? 'bg-navy-800 text-white border-navy-800' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'}`}>
              {s}
            </button>
          ))}
        </div>
      )}

      {!rows ? <Spinner /> : rows.data.length === 0 ? (
        <Card><p className="text-slate-400 text-sm">No payment verifications here.</p></Card>
      ) : (
        <div className="space-y-3">
          {rows.data.map((v) => (
            <Card key={v.id}>
              <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <Link to={v.bill_id ? `/bills/${v.bill_id}` : '/bills'}
                      className="font-mono text-sm font-semibold text-brand-600 hover:underline">{v.document_number}</Link>
                    {v.property_id && <span className="text-xs text-slate-400">Property {v.property_id}</span>}
                    <Badge tone={verifyTone(v.verification_status)}>{v.verification_status}</Badge>
                    <Badge tone={matchTone(v.match_status)}>{v.match_status}</Badge>
                  </div>
                  <div className="flex flex-wrap gap-x-6 gap-y-1 mt-2 text-sm">
                    <span>Receipt <span className="font-mono">{v.receipt_number}</span></span>
                    <span>Claimed <span className="font-bold text-navy-800">{fmtMoney(v.amount_claimed)}</span></span>
                    <span>Verified <span className="font-bold text-navy-800">{v.verified_amount != null ? fmtMoney(v.verified_amount) : '—'}</span></span>
                    {v.payment_period && <span>Period {v.payment_period}</span>}
                    {v.receipt_date && <span>Dated {fmtDate(v.receipt_date)}</span>}
                  </div>
                  <div className="text-xs text-slate-400 mt-1.5">
                    Submitted {fmtTime(v.created_at)}
                    {v.verified_by && <span> · Reviewed by {v.verified_by} {fmtTime(v.verified_at)}</span>}
                  </div>
                  {v.rejection_reason && <div className="text-xs text-red-600 mt-1.5">⛔ {v.rejection_reason}</div>}
                  {v.remarks && <div className="text-xs text-slate-500 mt-1">📝 {v.remarks}</div>}
                </div>
                <div className="shrink-0 flex flex-wrap items-center gap-2">
                  <Btn tone="white" onClick={() => setReceipt({ row: v })}>View receipt</Btn>
                  {v.verification_status === 'Pending' && (
                    <>
                      {can('payments.verify') && <Btn tone="success" onClick={() => setTarget({ kind: 'confirm', row: v })}>Confirm ✓</Btn>}
                      {can('payments.reject') && <Btn tone="danger" onClick={() => setTarget({ kind: 'reject', row: v })}>Reject ✕</Btn>}
                    </>
                  )}
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}

      {target?.kind === 'confirm' && <ConfirmModal row={target.row} onClose={() => setTarget(null)} onSaved={saved} />}
      {target?.kind === 'reject' && <RejectModal row={target.row} onClose={() => setTarget(null)} onSaved={saved} />}
      {receipt && <ReceiptModal row={receipt.row} onClose={() => setReceipt(null)} />}
    </div>
  )
}

function ConfirmModal({ row, onClose, onSaved }) {
  const [form, setForm] = useState({ verified_amount: '', litas_reference: '', remarks: '' })
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    setErr(''); setBusy(true)
    try {
      const r = await api.post(`/payments/verifications/${row.id}/confirm`, {
        verified_amount: form.verified_amount ? Number(form.verified_amount) : undefined,
        litas_reference: form.litas_reference.trim() || undefined,
        remarks: form.remarks.trim() || undefined,
      })
      onSaved(unwrap(r).message)
    } catch (ex) { setErr(errMsg(ex)) }
    setBusy(false)
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/40 grid place-items-center p-4" onClick={onClose}>
      <form onSubmit={submit} className="w-full max-w-md bg-white rounded-2xl shadow-xl p-6 space-y-4" onClick={(e) => e.stopPropagation()}>
        <div>
          <h2 className="font-bold text-navy-800">Confirm payment claim</h2>
          <p className="text-xs text-slate-400 mt-1">Receipt {row.receipt_number} · {fmtMoney(row.amount_claimed)} claimed for {row.document_number}.</p>
        </div>
        {err && <ErrorBox error={{ message: err }} />}
        <Field label="Verified amount (US$) — leave blank to accept the claim">
          <Input className="w-full" type="number" min="0" step="0.01" value={form.verified_amount}
            onChange={(e) => setForm({ ...form, verified_amount: e.target.value })} placeholder={String(row.amount_claimed ?? '')} />
        </Field>
        <Field label="LITAS reference">
          <Input className="w-full" value={form.litas_reference} onChange={(e) => setForm({ ...form, litas_reference: e.target.value })} placeholder="e.g. 4-45720/LRD/2026" />
        </Field>
        <Field label="Remarks">
          <Input className="w-full" value={form.remarks} onChange={(e) => setForm({ ...form, remarks: e.target.value })} placeholder="Optional note" />
        </Field>
        <div className="flex justify-end gap-2 pt-2">
          <Btn type="button" tone="white" onClick={onClose}>Cancel</Btn>
          <Btn type="submit" tone="success" disabled={busy}>{busy ? 'Confirming…' : 'Confirm payment'}</Btn>
        </div>
      </form>
    </div>
  )
}

function RejectModal({ row, onClose, onSaved }) {
  const [form, setForm] = useState({ reason: '', mismatch: false })
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    if (!form.reason.trim()) return setErr('A rejection reason is required.')
    setErr(''); setBusy(true)
    try {
      const r = await api.post(`/payments/verifications/${row.id}/reject`, { reason: form.reason.trim(), mismatch: form.mismatch })
      onSaved(unwrap(r).message)
    } catch (ex) { setErr(errMsg(ex)) }
    setBusy(false)
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/40 grid place-items-center p-4" onClick={onClose}>
      <form onSubmit={submit} className="w-full max-w-md bg-white rounded-2xl shadow-xl p-6 space-y-4" onClick={(e) => e.stopPropagation()}>
        <div>
          <h2 className="font-bold text-navy-800">Reject payment claim</h2>
          <p className="text-xs text-slate-400 mt-1">Receipt {row.receipt_number} · {fmtMoney(row.amount_claimed)} claimed for {row.document_number}. The case returns to Payment Follow-up.</p>
        </div>
        {err && <ErrorBox error={{ message: err }} />}
        <div>
          <label className="block text-xs font-semibold text-slate-600 mb-1.5">Reason <span className="text-red-500">*</span></label>
          <textarea rows={3} value={form.reason}
            onChange={(e) => setForm({ ...form, reason: e.target.value })}
            className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500"
            placeholder="e.g. Receipt does not match LITAS records" />
        </div>
        <label className="flex items-center gap-2 text-sm text-slate-600">
          <input type="checkbox" checked={form.mismatch} onChange={(e) => setForm({ ...form, mismatch: e.target.checked })} />
          Mark as receipt mismatch
        </label>
        <div className="flex justify-end gap-2 pt-2">
          <Btn type="button" tone="white" onClick={onClose}>Cancel</Btn>
          <Btn type="submit" tone="danger" disabled={busy}>{busy ? 'Rejecting…' : 'Reject claim'}</Btn>
        </div>
      </form>
    </div>
  )
}

function Field({ label, children }) {
  return (
    <div>
      <label className="block text-xs font-semibold text-slate-600 mb-1.5">{label}</label>
      {children}
    </div>
  )
}

function ReceiptModal({ row, onClose }) {
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')

  useEffect(() => {
    api.get(`/payments/verifications/${row.id}/receipt`)
      .then((r) => setData(unwrap(r).data))
      .catch((ex) => setErr(errMsg(ex, 'Could not load receipt.')))
  }, [row.id]) // eslint-disable-line

  return (
    <div className="fixed inset-0 z-50 bg-black/40 overflow-y-auto" onClick={onClose}>
      <div className="min-h-full grid place-items-center p-4" onClick={(e) => e.stopPropagation()}>
        <div className="w-full max-w-lg bg-white rounded-2xl shadow-xl">
          <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div>
              <h2 className="font-bold text-navy-800">Payment receipt</h2>
              <p className="text-xs text-slate-400">{row.document_number} · compare against LITAS records before confirming.</p>
            </div>
            <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600 text-2xl leading-none px-2">×</button>
          </div>

          <div className="px-6 py-4 space-y-4 max-h-[72vh] overflow-y-auto">
            {err && <ErrorBox error={{ message: err }} />}
            {!data && !err && <div className="py-8 text-center text-sm text-slate-400">Loading receipt…</div>}
            {data && (
              <>
                {data.receipt_attachment ? (
                  <img src={data.receipt_attachment} alt="Receipt attachment"
                    className="w-full max-h-80 object-contain rounded-xl border border-slate-200 bg-slate-50" />
                ) : (
                  <div className="rounded-xl bg-slate-50 border border-slate-200 px-4 py-6 text-center text-sm text-slate-400">
                    No receipt photo attached to this claim — verify against the fields below.
                  </div>
                )}
                <div className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                  <div>
                    <div className="text-xs text-slate-400">Receipt number</div>
                    <div className="font-semibold font-mono">{data.receipt_number}</div>
                  </div>
                  {data.receipt_bill_number && (
                    <div>
                      <div className="text-xs text-slate-400">Receipt billing #</div>
                      <div className="font-mono">{data.receipt_bill_number}</div>
                    </div>
                  )}
                  {data.property_id && (
                    <div>
                      <div className="text-xs text-slate-400">Property ID</div>
                      <div className="font-mono">{data.property_id}</div>
                    </div>
                  )}
                  {data.tin && (
                    <div>
                      <div className="text-xs text-slate-400">TIN</div>
                      <div className="font-mono">{data.tin}</div>
                    </div>
                  )}
                  <div>
                    <div className="text-xs text-slate-400">Claimed amount</div>
                    <div className="font-semibold text-navy-800">{fmtMoney(data.amount_claimed)}</div>
                  </div>
                  {data.payment_period && (
                    <div>
                      <div className="text-xs text-slate-400">Payment period</div>
                      <div>{data.payment_period}</div>
                    </div>
                  )}
                  {data.tax_due_date && (
                    <div>
                      <div className="text-xs text-slate-400">Tax due date</div>
                      <div>{fmtDate(data.tax_due_date)}</div>
                    </div>
                  )}
                  {data.receipt_date && (
                    <div>
                      <div className="text-xs text-slate-400">Receipt date</div>
                      <div>{fmtDate(data.receipt_date)}</div>
                    </div>
                  )}
                </div>
              </>
            )}
          </div>

          <div className="flex justify-end px-6 py-4 border-t border-slate-100">
            <Btn tone="white" onClick={onClose}>Close</Btn>
          </div>
        </div>
      </div>
    </div>
  )
}