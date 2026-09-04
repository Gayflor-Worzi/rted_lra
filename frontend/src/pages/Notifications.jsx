import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import api, { unwrap, errMsg } from '../api'
import { Card, Spinner, Badge, PageTitle, Btn } from '../ui'

const KIND_BADGE = {
  query: { tone: 'sky', label: 'Query' },
  appeal: { tone: 'amber', label: 'Appeal' },
  task: { tone: 'navy', label: 'Task' },
  valuation: { tone: 'purple', label: 'Valuation' },
  discovery: { tone: 'indigo', label: 'Discovery' },
  visit: { tone: 'teal', label: 'Visit' },
  broadcast: { tone: 'rose', label: 'Broadcast' },
  info: { tone: 'slate', label: 'Notice' },
}

export default function Notifications() {
  const navigate = useNavigate()
  const [rows, setRows] = useState(null)
  const [failed, setFailed] = useState(false)
  const [openId, setOpenId] = useState(null)
  const [busy, setBusy] = useState(null)
  const [texts, setTexts] = useState({})
  const [choices, setChoices] = useState({})
  const [actionErr, setActionErr] = useState('')
  const [flash, setFlash] = useState('')

  const load = () => api.get('/notifications', { params: { per_page: 50 } })
    .then((r) => setRows(unwrap(r).data))
    .catch(() => { setFailed(true); if (!rows) setRows({ data: [] }) })
  useEffect(() => { load() }, []) // eslint-disable-line

  const markAll = async () => { await api.post('/notifications/read-all'); load() }

  const openOne = (n) => {
    setOpenId(n.id)
    setActionErr('')
    if (!n.read_at) {
      api.post(`/notifications/${n.id}/read`).then(() => {
        setRows((r) => r && ({ ...r, data: r.data.map((x) => x.id === n.id ? { ...x, read_at: new Date().toISOString() } : x) }))
      }).catch(() => {})
    }
  }

  const save = (value, key) => { setTexts((t) => ({ ...t, [key]: value })) }

  const run = async (n, a, payload) => {
    setBusy(a.id); setActionErr(''); setFlash('')
    try {
      await api.post(a.endpoint, payload || {})
      setFlash(`${n.title} — ${a.label} done.`)
      await load()
      setTimeout(() => setFlash(''), 2500)
    } catch (e) { setActionErr(errMsg(e, `Could not ${a.label.toLowerCase()}.`)) }
    setBusy(null)
  }

  const confirmAction = (n, a) => () => {
    if (window.confirm(`${a.label} — are you sure?`)) run(n, a)
  }

  if (!rows) return <Spinner label="Loading notifications…" />

  const list = rows.data || []
  const unread = list.filter((n) => !n.read_at).length

  return (
    <div className="space-y-4 max-w-3xl">
      <PageTitle
        sub={unread > 0 ? `${unread} unread notification${unread === 1 ? '' : 's'}` : 'You’re all caught up.'}
        right={<Btn tone="white" onClick={markAll}>Mark all read</Btn>}>
        Notifications
      </PageTitle>
      {flash && <div className="rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100 px-4 py-3 text-sm">{flash}</div>}
      {actionErr && <div className="rounded-xl bg-red-50 text-red-700 border border-red-100 px-4 py-3 text-sm">⚠️ {actionErr}</div>}
      {failed && <div className="rounded-xl bg-amber-50 text-amber-700 border border-amber-100 px-4 py-3 text-sm">Could not load notifications — refresh to retry.</div>}

      {list.length === 0 && <Card><p className="text-slate-400 text-sm">Nothing here yet.</p></Card>}

      <div className="space-y-2">
        {list.map((n) => {
          const d = n.detail || {}
          const badge = KIND_BADGE[d.kind] || KIND_BADGE.info
          const open = openId === n.id
          return (
            <Card key={n.id} className={n.read_at ? 'opacity-75' : ''}>
              <button onClick={() => openOne(n)} className="w-full text-left">
                <div className="flex items-center gap-3">
                  <Badge tone={badge.tone}>{badge.label}</Badge>
                  {!n.read_at && <span className="w-2 h-2 rounded-full bg-brand-500 shrink-0" title="New" />}
                  <span className="font-semibold text-navy-800 flex-1">{n.title}</span>
                  <span className="text-xs text-slate-400 whitespace-nowrap">{(n.created_at || '').replace('T', ' ').slice(0, 16)}</span>
                  <span className={`text-slate-300 transition-transform ${open ? 'rotate-180' : ''}`}>▾</span>
                </div>
                <p className="text-sm text-slate-500 mt-1.5 line-clamp-2">{n.message}</p>
              </button>

              {open && (
                <div className="mt-3 pt-3 border-t border-slate-100">
                  {d.ref && (
                    <div className="text-xs font-semibold text-brand-600 mb-2">
                      {d.ref}
                      {d.link && (
                        <button onClick={() => navigate(d.link)}
                          className="ml-3 text-brand-500 hover:text-brand-700 underline">
                          Open page →
                        </button>
                      )}
                    </div>
                  )}
                  {d.fields?.length > 0 && (
                    <dl className="grid sm:grid-cols-2 gap-x-6 gap-y-1.5 mb-3">
                      {d.fields.map((f, i) => (
                        <div key={i} className="text-sm">
                          <dt className="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">{f.l}</dt>
                          <dd className="text-slate-700 whitespace-pre-wrap break-words">{f.v}</dd>
                        </div>
                      ))}
                    </dl>
                  )}

                  {d.actions?.length > 0 ? (
                    <div className="space-y-3">
                      {d.actions.map((a) => {
                        const k = `${n.id}.${a.id}`
                        return (
                          <div key={a.id} className="flex flex-wrap items-center gap-3 bg-slate-50 rounded-xl px-3 py-2.5">
                            {a.kind === 'appeal' && (
                              <>
                                <select
                                  value={choices[k] || ''}
                                  onChange={(e) => setChoices((c) => ({ ...c, [k]: e.target.value }))}
                                  className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white">
                                  <option value="">Decision…</option>
                                  {a.options.map((o) => <option key={o.v} value={o.v}>{o.l}</option>)}
                                </select>
                                <input
                                  placeholder="Notes (required)"
                                  value={texts[k] || ''}
                                  onChange={(e) => save(e.target.value, k)}
                                  className="flex-1 min-w-[200px] px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" />
                                <Btn disabled={busy === a.id || !choices[k] || !(texts[k] || '')}
                                  onClick={() => run(n, a, { decision: choices[k], notes: texts[k] || '' })}>
                                  {busy === a.id ? 'Saving…' : a.label}
                                </Btn>
                              </>
                            )}
                            {a.kind === 'text' && (
                              <>
                                <input
                                  placeholder={a.fieldLabel || 'Text'}
                                  value={texts[k] || ''}
                                  onChange={(e) => save(e.target.value, k)}
                                  className="flex-1 min-w-[200px] px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" />
                                <Btn disabled={busy === a.id || (texts[k] || '') === ''}
                                  onClick={() => run(n, a, { [a.field]: texts[k] || '' })}>
                                  {busy === a.id ? 'Saving…' : a.label}
                                </Btn>
                              </>
                            )}
                            {a.kind === 'confirm' && (
                              <Btn tone={a.id === 'close' ? 'navy' : 'danger'} disabled={busy === a.id} onClick={confirmAction(n, a)}>
                                {busy === a.id ? 'Working…' : a.label}
                              </Btn>
                            )}
                          </div>
                        )
                      })}
                    </div>
                  ) : d.kind === 'info' ? null : (
                    <p className="text-xs text-slate-400">No further action needed — this notification is for information.</p>
                  )}
                </div>
              )}
            </Card>
          )
        })}
      </div>
    </div>
  )
}