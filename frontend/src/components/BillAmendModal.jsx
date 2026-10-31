import { useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import { Btn, Input, ErrorBox, Badge, fmtMoney, fmtDate, billCaseTone } from '../ui'
import { useAuth } from '../auth'

const L = ({ label, children }) => (
  <div>
    <label className="block text-xs font-semibold text-slate-600 mb-1.5">{label}</label>
    {children}
  </div>
)

const RO = ({ label, value }) => (
  <L label={label}><div className="px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-500">{value || '—'}</div></L>
)

/**
 * Bill Amendment — a controlled, audit-trailed edit applied in place from the
 * Bill Register. Authorized Account & Records staff see ONLY the bills they
 * logged in their register; a bill logged by another staff member is reachable
 * solely through the targeted lookup by Property ID + Tax Period. They are
 * ONLY able to change:
 *
 *   - Bill Document #       (requires bills.amend_document)
 *   - Penalty Charge        (requires bills.amend_penalty_interest)
 *   - Interest Charge       (requires bills.amend_penalty_interest)
 *
 * Everything else is shown read-only (Property ID / TIN / taxpayer / property /
 * original logging officer / dates are protected). Totals recompute live per
 * the logging rules, a reason is recorded, and the full amendment history is
 * shown below the editor. Backed by POST /property-bills/{id}/amend.
 */
export default function BillAmendModal({ initialBillId, onClose, onChanged }) {
  const { can } = useAuth()
  const canDoc = can('bills.amend_document')
  const canFin = can('bills.amend_penalty_interest')

  const [step, setStep] = useState(initialBillId ? 'edit' : 'pick')
  const [query, setQuery] = useState('')
  const [results, setResults] = useState(null)
  const [searched, setSearched] = useState(false)
  const [lookupPid, setLookupPid] = useState('')
  const [lookupPeriod, setLookupPeriod] = useState('')
  const [lookupBusy, setLookupBusy] = useState(false)
  const [lookupErr, setLookupErr] = useState('')
  const [bill, setBill] = useState(null)
  const [history, setHistory] = useState([])
  const [docNum, setDocNum] = useState('')
  const [interest, setInterest] = useState('')
  const [penalty, setPenalty] = useState('')
  const [reason, setReason] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(null)

  const doSearch = async (e) => {
    e && e.preventDefault()
    if (query.trim().length < 2) return
    setSearched(true); setErr('')
    try {
      const r = await api.get('/property-bills', { params: { q: query.trim(), per_page: 20 } })
      setResults(unwrap(r).data.data)
    } catch (ex) { setErr(errMsg(ex, 'Search failed.')) }
  }

  // Targeted lookup — the ONLY path to a bill logged by another staff member.
  // Exact Property ID + Tax Period; requires an amendment permission already.
  const doLookup = async (e) => {
    e && e.preventDefault()
    setLookupBusy(true); setLookupErr(''); setErr('')
    try {
      const r = await api.get('/property-bills/lookup', { params: { property_id: lookupPid.trim(), tax_period: lookupPeriod.trim() } })
      open(unwrap(r).data)
    } catch (ex) { setLookupErr(errMsg(ex, 'No bill found for that Property ID + Tax Period.')) }
    setLookupBusy(false)
  }

  const open = async (b) => {
    setBill(b)
    setDocNum(b.document_number || '')
    setInterest(String(b.interest_charged ?? ''))
    setPenalty(String(b.penalty_charged ?? ''))
    setReason(''); setErr(''); setDone(null); setStep('edit')
    try {
      const h = await api.get(`/property-bills/${b.id}/amendments`)
      setHistory((unwrap(h).data.data || []))
    } catch { setHistory([]) }
  }

  useEffect(() => {
    if (initialBillId) api.get(`/property-bills/${initialBillId}`).then((r) => open(unwrap(r).data)).catch(() => {})
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialBillId])

  const newTotal = (Number(bill?.tax_amount) || 0) + (Number(interest) || 0) + (Number(penalty) || 0)
  const changed = (canDoc && docNum.trim() !== bill?.document_number) ||
    (canFin && Number(interest) !== Number(bill?.interest_charged)) ||
    (canFin && Number(penalty) !== Number(bill?.penalty_charged))
  const canSubmit = bill && !busy && changed

  const submit = async (e) => {
    e.preventDefault()
    setErr(''); setDone(null); setBusy(true)
    try {
      const payload = { reason: reason.trim() || null }
      if (canDoc && docNum.trim() !== bill.document_number) payload.document_number = docNum.trim()
      if (canFin && Number(interest) !== Number(bill.interest_charged)) payload.interest_charged = Number(interest)
      if (canFin && Number(penalty) !== Number(bill.penalty_charged)) payload.penalty_charged = Number(penalty)
      const r = await api.post(`/property-bills/${bill.id}/amend`, payload)
      const res = unwrap(r).data.bill
      setBill(res)
      setDone(`Bill ${res.document_number} amended. Total due recomputed from ${fmtMoney(res.total_tax_due)} based on your approved changes.`)
      try {
        const h = await api.get(`/property-bills/${res.id}/amendments`)
        setHistory((unwrap(h).data.data || []))
      } catch { /* history best-effort */ }
      onChanged && onChanged(res)
    } catch (ex) { setErr(errMsg(ex, 'Amendment failed.')) }
    setBusy(false)
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/40 overflow-y-auto" onClick={onClose}>
      <div className="min-h-full grid place-items-center p-4" onClick={(e) => e.stopPropagation()}>
        <form onSubmit={submit} className="w-full max-w-2xl bg-white rounded-2xl shadow-xl">
          <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div>
              <h2 className="font-bold text-navy-800">Amend a bill</h2>
              <p className="text-xs text-slate-400">Controlled, audit-trailed corrections — Document #, Penalty &amp; Interest only.</p>
            </div>
            <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600 text-2xl leading-none px-2">×</button>
          </div>

          <div className="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto">
            {err && <ErrorBox error={{ message: err }} />}
            {done && <div className="rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100 px-4 py-3 text-sm">{done}</div>}

            {step === 'pick' && (
              <>
                <div className="rounded-xl border border-brand-200 bg-brand-50/60 px-4 py-3">
                  <div className="text-xs font-semibold text-brand-700 mb-1">Call a specific record — even one logged by another staff member</div>
                  <div className="text-[11px] text-slate-400 mb-2">Enter the exact Property ID and Tax Period to bring that bill up for amendment.</div>
                  <form onSubmit={doLookup} className="flex flex-wrap gap-2">
                    <Input className="flex-1 min-w-[140px]" placeholder="Property ID" value={lookupPid}
                      onChange={(e) => { setLookupPid(e.target.value); setLookupErr('') }} />
                    <Input className="flex-1 min-w-[120px]" placeholder="Tax Period" value={lookupPeriod}
                      onChange={(e) => { setLookupPeriod(e.target.value); setLookupErr('') }} />
                    <Btn type="button" onClick={doLookup} disabled={lookupBusy || !lookupPid.trim() || !lookupPeriod.trim()}>
                      {lookupBusy ? 'Finding…' : 'Find'}
                    </Btn>
                  </form>
                  {lookupErr && <p className="text-xs text-red-600 mt-1.5">{lookupErr}</p>}
                </div>

                <div className="text-center text-[11px] text-slate-300 select-none">or</div>

                <div>
                  <label className="block text-xs font-semibold text-slate-600 mb-1.5">Search bills you logged (Document #, Property ID, TIN, taxpayer)</label>
                  <div className="flex gap-2">
                    <Input className="w-full" placeholder="Search…" value={query}
                      onChange={(e) => { setQuery(e.target.value); setSearched(false) }} />
                    <Btn type="button" onClick={doSearch} disabled={query.trim().length < 2}>Search</Btn>
                  </div>
                </div>
                {searched && results && results.length === 0 && (
                  <p className="text-sm text-slate-400">No bills match that search.</p>
                )}
                {results && results.length > 0 && (
                  <ul className="divide-y divide-slate-100 border border-slate-200 rounded-xl overflow-hidden">
                    {results.map((b) => (
                      <li key={b.id}>
                        <button type="button" onClick={() => open(b)}
                          className="w-full text-left px-4 py-3 hover:bg-slate-50 flex items-center justify-between gap-3">
                          <div className="min-w-0">
                            <div className="text-sm font-semibold text-navy-800">{b.document_number}</div>
                            <div className="text-xs text-slate-500 truncate">{b.taxpayer_name} · {b.property_id} · {fmtDate(b.date_logged)}</div>
                          </div>
                          <div className="flex items-center gap-2 shrink-0">
                            <Badge tone={billCaseTone(b.case_status)}>{b.case_status}</Badge>
                            <span className="text-xs text-slate-400">{fmtMoney(b.total_tax_due)}</span>
                          </div>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </>
            )}

            {step === 'edit' && bill && (
              <>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <button type="button" onClick={() => { setBill(null); setStep('pick'); setResults(null); setSearched(false) }}
                    className="text-xs text-brand-500 font-semibold hover:underline">← Search another bill</button>
                  <div className="flex items-center gap-2">
                    <Badge tone={billCaseTone(bill.case_status)}>{bill.case_status}</Badge>
                    <Badge>{bill.payment_status}</Badge>
                  </div>
                </div>

                <div className="grid sm:grid-cols-2 gap-4">
                  <L label="Document #" >
                    {canDoc ? (
                      <Input className="w-full" value={docNum} onChange={(e) => setDocNum(e.target.value)} />
                    ) : (
                      <div className="px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-500">{bill.document_number}</div>
                    )}
                  </L>
                  <RO label="Property ID" value={bill.property_id} />
                  <RO label="TIN" value={bill.tin} />
                  <RO label="Taxpayer" value={bill.taxpayer_name} />
                  <RO label="Property address" value={bill.property_address} />
                  <div>
                    <label className="block text-xs font-semibold text-slate-600 mb-1.5">Classification</label>
                    <div className="px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-500">{bill.property_classification || '—'}</div>
                  </div>
                  <RO label="Assessed value (US$)" value={fmtMoney(bill.assessed_value)} />
                  <RO label="Tax amount (US$)" value={fmtMoney(bill.tax_amount)} />
                  <L label="Interest charge (US$)">
                    {canFin ? (
                      <Input className="w-full" type="number" min="0" step="0.01" value={interest} onChange={(e) => setInterest(e.target.value)} />
                    ) : (
                      <div className="px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-500">{fmtMoney(bill.interest_charged)}</div>
                    )}
                  </L>
                  <L label="Penalty charge (US$)">
                    {canFin ? (
                      <Input className="w-full" type="number" min="0" step="0.01" value={penalty} onChange={(e) => setPenalty(e.target.value)} />
                    ) : (
                      <div className="px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-500">{fmtMoney(bill.penalty_charged)}</div>
                    )}
                  </L>
                  <div className="sm:col-span-2">
                    <L label="Reason for amendment">
                      <textarea rows={2} className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm" value={reason}
                        onChange={(e) => setReason(e.target.value)} placeholder="Why is this being changed? (recorded for audit)" />
                    </L>
                  </div>
                </div>

                <div className="rounded-xl bg-slate-50 border border-slate-200 px-4 py-3 text-sm flex items-center justify-between">
                  <span className="text-slate-500">Total due (recomputed):</span>
                  <span className="font-bold text-navy-800">{fmtMoney(newTotal)}</span>
                </div>
                {Number(bill.total_tax_due) !== newTotal && (
                  <div className="text-[11px] text-slate-400">was {fmtMoney(bill.total_tax_due)} · outstanding balance and payment status will be recalculated.</div>
                )}

                <div>
                  <div className="text-xs font-semibold text-slate-600 mb-2">Amendment history</div>
                  {history.length === 0 ? (
                    <p className="text-sm text-slate-400">No amendments yet for this bill.</p>
                  ) : (
                    <ul className="space-y-2">
                      {history.map((h) => {
                        const c = h.document_number === h.new_document_number ? (h.previous_document_number ?? h.document_number) : null
                        return (
                          <li key={h.id} className="text-xs border border-slate-200 rounded-xl px-3 py-2 bg-white">
                            <div className="flex items-center justify-between">
                              <span className="font-semibold text-navy-800">{fmtDate(h.amended_at)} · {h.amender?.full_name || 'Unknown'}</span>
                              <Badge tone="brand">Amended #{h.id}</Badge>
                            </div>
                            {h.reason && <p className="text-slate-500 mt-1">“{h.reason}”</p>}
                            <div className="text-slate-500 mt-1">
                              {h.previous_document_number || h.new_document_number
                                ? <span>Doc# {h.previous_document_number ?? '—'} → {h.new_document_number ?? '—'}</span> : null}
                              {h.previous_document_number || h.new_document_number
                                ? <span className="mx-2 text-slate-300">·</span> : null}
                              {h.previous_interest_charge != null && h.new_interest_charge != null
                                ? <span>Interest {fmtMoney(h.previous_interest_charge)} → {fmtMoney(h.new_interest_charge)}</span> : null}
                              {h.previous_penalty_charge != null && h.new_penalty_charge != null
                                ? <span className="mx-2 text-slate-300">·</span> : null}
                              {h.previous_penalty_charge != null && h.new_penalty_charge != null
                                ? <span>Penalty {fmtMoney(h.previous_penalty_charge)} → {fmtMoney(h.new_penalty_charge)}</span> : null}
                            </div>
                            <div className="text-slate-400 mt-0.5">Total {fmtMoney(h.previous_total_tax_due)} → {fmtMoney(h.new_total_tax_due)}</div>
                          </li>
                        )
                      })}
                    </ul>
                  )}
                </div>
              </>
            )}
          </div>

          <div className="flex items-center justify-between px-6 py-4 border-t border-slate-100 bg-slate-50/60 rounded-b-2xl">
            <div className="text-[11px] text-slate-400">
              {step === 'edit' && bill
                ? 'Only fields you are authorized to change are editable.'
                : 'Search bills you logged — or call a record by Property ID + Tax Period.'}
            </div>
            <div className="flex gap-2">
              <Btn type="button" tone="white" onClick={onClose}>Cancel</Btn>
              {step === 'edit' && bill && (
                <Btn type="submit" disabled={!canSubmit}>
                  {busy ? 'Amending…' : changed ? 'Apply amendment' : 'Nothing to change'}
                </Btn>
              )}
            </div>
          </div>
        </form>
      </div>
    </div>
  )
}