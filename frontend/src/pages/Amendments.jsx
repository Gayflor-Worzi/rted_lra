import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api, { unwrap, errMsg } from '../api'
import { Card, Badge, Input, Spinner, ErrorBox, PageTitle, fmtDate, fmtMoney } from '../ui'

// The sanctioned correction trail. Amendments are the ONLY way a logged
// assessment changes after the fact (Document # / Penalty / Interest), so this
// register is the accountable audit surface for every correction in RETD.
export default function Amendments() {
  const [rows, setRows] = useState(null)
  const [total, setTotal] = useState(0)
  const [err, setErr] = useState(null)
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)

  useEffect(() => {
    setRows(null)
    api.get('/bill-amendments', { params: { per_page: 25, page, q: q.trim() || undefined } })
      .then((r) => {
        const d = unwrap(r).data
        setRows(d.data)
        setTotal(d.total ?? 0)
      })
      .catch((ex) => setErr(errMsg(ex)))
  }, [page, q])

  const changedChip = (label, from, to) => (
    <span className="inline-flex items-center gap-1 rounded-lg bg-navy-50 px-2 py-0.5 text-[11px] whitespace-nowrap">
      <span className="text-slate-400 font-semibold">{label}</span>
      {from !== null && from !== undefined && from !== '' && (
        <><span className="text-slate-400 line-through">{String(from)}</span><span className="text-slate-300">→</span></>
      )}
      <span className="font-bold text-navy-800">{to !== null && to !== undefined && to !== '' ? (label === 'penalty' || label === 'interest' ? fmtMoney(to) : String(to)) : '—'}</span>
    </span>
  )

  const applySearch = (value) => {
    setQ(value)
    setPage(1)
  }

  return (
    <div className="space-y-4">
      <PageTitle sub="Every correction applied to logged bills · amendment is the sanctioned path for Document # / Penalty / Interest">
        Bill Amendments
      </PageTitle>

      <Card title={`Corrections register · ${total.toLocaleString()} total`}
        right={<Input className="max-w-xs" placeholder="Search document / property / taxpayer / reason…" value={q} onChange={(e) => applySearch(e.target.value)} />}>
        {!rows ? (
          <Spinner label="Loading amendments…" />
        ) : err ? (
          <ErrorBox error={err} />
        ) : rows.length === 0 ? (
          <p className="text-sm text-slate-400">No amendments match.</p>
        ) : (
          <div className="-mx-5 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                  <th className="px-5 py-2">Document #</th>
                  <th className="px-5 py-2">Property / Taxpayer</th>
                  <th className="px-5 py-2">Changed</th>
                  <th className="px-5 py-2">Reason</th>
                  <th className="px-5 py-2">Amended by</th>
                  <th className="px-5 py-2">When</th>
                  <th className="px-5 py-2">Bill</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((a) => (
                  <tr key={a.id} className="border-b border-slate-50 hover:bg-slate-50/60 align-top">
                    <td className="px-5 py-2.5 whitespace-nowrap">
                      <div className="font-semibold text-navy-800">{a.changed.document_number.to || a.document_number}</div>
                      {a.changed.document_number.from && (
                        <div className="text-[11px] text-slate-400 line-through">was {a.changed.document_number.from}</div>
                      )}
                    </td>
                    <td className="px-5 py-2.5 min-w-[180px]">
                      <div className="font-medium">{a.property_id}</div>
                      <div className="text-xs text-slate-500">{a.taxpayer_name}</div>
                      <div className="text-[11px] text-slate-400">{a.property_address}</div>
                    </td>
                    <td className="px-5 py-2.5">
                      <div className="flex flex-wrap gap-1">
                        {a.changed.penalty.to !== null && a.changed.penalty.to !== undefined && changedChip('penalty', a.changed.penalty.from, a.changed.penalty.to)}
                        {a.changed.interest.to !== null && a.changed.interest.to !== undefined && changedChip('interest', a.changed.interest.from, a.changed.interest.to)}
                      </div>
                    </td>
                    <td className="px-5 py-2.5 max-w-[240px]">
                      <div className="text-xs text-slate-600 line-clamp-2">{a.reason || '—'}</div>
                      <div className="text-[10px] text-slate-400">TIN {a.tin || '—'}</div>
                    </td>
                    <td className="px-5 py-2.5 whitespace-nowrap text-slate-600">{a.amended_by}</td>
                    <td className="px-5 py-2.5 whitespace-nowrap text-slate-600">{a.amended_at ? fmtDate(a.amended_at) : '—'}</td>
                    <td className="px-5 py-2.5">
                      <Link to={`/bills/${a.bill_id}`} className="text-brand-600 font-semibold text-xs hover:underline">Open bill →</Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {total > 25 && (
          <div className="flex items-center justify-between pt-4 text-sm text-slate-500">
            <button className="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-40" disabled={page <= 1}
              onClick={() => setPage((p) => p - 1)}>
              ← Prev
            </button>
            <span>Page {page} of {Math.ceil(total / 25)}</span>
            <button className="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-40" disabled={page * 25 >= total}
              onClick={() => setPage((p) => p + 1)}>
              Next →
            </button>
          </div>
        )}
      </Card>

      {/* <Card title="Why an amendment register?">
        <div className="text-sm text-slate-600 space-y-1.5">
          <p>RETD treats each (Property ID + TIN + Tax Period) triple as one assessment — logging it twice is blocked with Amend guidance.</p>
          <p>After logging, only <Badge tone="amber">Document #</Badge>, <Badge tone="amber">Penalty</Badge> and <Badge tone="amber">Interest</Badge> may change, and only through an audited amendment.</p>
          <p>Every correction here is a permanent, attributed record — the register above is its audit surface.</p>
        </div>
      </Card> */}
    </div>
  )
}