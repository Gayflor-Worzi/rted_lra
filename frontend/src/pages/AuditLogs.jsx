import { useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import { Spinner, ErrorBox, PageTitle, Card, Btn, Badge, Input } from '../ui'
import { useAuth } from '../auth'

export default function AuditLogs() {
  const { can } = useAuth()
  const [rows, setRows] = useState(null)
  const [page, setPage] = useState(1)
  const [action, setAction] = useState('')
  const [err, setErr] = useState(null)
  const [dl, setDl] = useState(false)

  const load = () => {
    setErr(null)
    api.get('/audit-logs', { params: { per_page: 50, page, action: action || undefined } })
      .then((r) => setRows(unwrap(r).data))
      .catch((e) => setErr({ message: errMsg(e) }))
  }
  useEffect(() => { load() }, [page]) // eslint-disable-line react-hooks/exhaustive-deps

  const search = () => { setPage(1); load() }
  const clear = () => { setAction(''); setPage(1); setTimeout(load, 0) }

  const download = async () => {
    setErr(null); setDl(true)
    try {
      const r = await api.get('/audit-logs/export', { params: { action: action || undefined }, responseType: 'blob' })
      const cd = (r.headers['content-disposition'] || '').match(/filename="?([^"]+)"?/)
      const name = cd ? cd[1] : 'retd_audit.csv'
      const url = URL.createObjectURL(r.data)
      const a = document.createElement('a')
      a.href = url
      a.download = name
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } catch (ex) { setErr({ message: errMsg(ex, 'Export failed.') }) }
    setDl(false)
  }

  if (err) return <ErrorBox error={err} />
  if (!rows) return <Spinner label="Loading audit trail…" />

  const list = rows.data || []

  return (
    <div className="space-y-4">
      <PageTitle sub="Immutable, hash-chained trail of every recorded action."
        right={can('audit.export') && <Btn tone="navy" onClick={download} disabled={dl}>{dl ? 'Exporting…' : 'Export CSV'}</Btn>}>
        Audit Log
      </PageTitle>

      <Card>
        <div className="flex flex-wrap gap-3 mb-4">
          <Input placeholder="Filter by action (e.g. valuation.submit)…" value={action}
            onChange={(e) => setAction(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && search()} className="flex-1 min-w-[240px]" />
          <Btn onClick={search}>Search</Btn>
          <Btn tone="white" onClick={clear}>Clear</Btn>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs uppercase tracking-wider text-slate-400 border-b border-slate-100">
                <th className="py-2 pr-3">Action</th>
                <th className="py-2 pr-3">Actor</th>
                <th className="py-2 pr-3">Record</th>
                <th className="py-2 pr-3">IP</th>
                <th className="py-2 pr-3">Hash</th>
                <th className="py-2 pr-3">When</th>
              </tr>
            </thead>
            <tbody>
              {list.map((a) => (
                <tr key={a.id} className="border-b border-slate-50 hover:bg-slate-50/60 align-top">
                  <td className="py-2.5 pr-3"><Badge tone="slate">{a.action}</Badge></td>
                  <td className="py-2.5 pr-3">{a.actor || 'System'}</td>
                  <td className="py-2.5 pr-3 text-slate-500 text-xs">
                    {a.auditable_type || '—'} #{a.auditable_id ?? '—'}
                    {a.new_values && <span className="block text-slate-400 max-w-[280px] truncate">{JSON.stringify(a.new_values)}</span>}
                  </td>
                  <td className="py-2.5 pr-3 text-slate-400 text-xs">{a.ip_address || '—'}</td>
                  <td className="py-2.5 pr-3 font-mono text-[11px] text-slate-400">{a.hash}</td>
                  <td className="py-2.5 pr-3 text-slate-500 text-xs">{String(a.created_at).replace('T', ' ').slice(0, 16)}</td>
                </tr>
              ))}
              {list.length === 0 && <tr><td colSpan={6} className="py-8 text-center text-slate-400">No audit records match.</td></tr>}
            </tbody>
          </table>
        </div>

        <div className="flex items-center justify-between pt-3 text-sm">
          <span className="text-xs text-slate-400">Page {rows.current_page} of {rows.last_page} · {rows.total} records</span>
          <div className="flex gap-2">
            <Btn tone="white" disabled={!rows.prev_page_url} onClick={() => setPage(rows.current_page - 1)}>← Prev</Btn>
            <Btn tone="white" disabled={!rows.next_page_url} onClick={() => setPage(rows.current_page + 1)}>Next →</Btn>
          </div>
        </div>
      </Card>
    </div>
  )
}