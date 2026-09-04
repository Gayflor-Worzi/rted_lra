import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import api, { unwrap, errMsg } from '../api'
import { useAuth } from '../auth'
import { Badge, Btn, Input } from '../ui'
import { DISCOVERY_STATUSES, DISCOVERY_ROUTE_LABEL, discoveryStatusInfo, DISCOVERY_PATHS } from '../lib/constants'

/**
 * Single shared "property" detail view for the Discovery ⇄ Valuation SSOT loop.
 *
 * Renders the complete authorized picture of one underlying property — discovery
 * record, location/GPS, photos, valuation (financials + repeatable description
 * table) and the route-driven workflow — plus an RBAC action panel. Reviewers
 * can inspect every required fact before approving/rejecting/returning without
 * leaving their queue.
 *
 * Props:
 *   discovery            present() discovery snapshot (may be null for standalone valuations)
 *   valuation            present() valuation snapshot (may be null pre-route)
 *   runAction(verb,payload)      discovery workflow verbs (bound to id by the parent)
 *   runValuationAction(verb,payload) valuation workflow verbs (submit/review/decide/assign/processing)
 *   assignmentUsers      user list for officer/assignor pickers
 *   onClose              close handler
 */
export default function PropertyDetail({ discovery, valuation, onClose, runAction, runValuationAction, assignmentUsers = [] }) {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [photos, setPhotos] = useState(null)

  const d = discovery
  const v = valuation

  const discoveryId = d?.id
  const valuationId = v?.id

  useEffect(() => {
    if (!discoveryId && !valuationId) return
    api.get('/evidence/photos', { params: { per_page: 100, ...(discoveryId ? { discovery_id: discoveryId } : {}), ...(valuationId ? { valuation_id: valuationId } : {}) } })
      .then((r) => setPhotos(unwrap(r).data.data))
      .catch(() => setPhotos([]))
  }, [discoveryId, valuationId])

  if (!d && !v) return null

  const info = d ? discoveryStatusInfo(d.status) : null
  const flow = (d?.workflow && d.workflow.length ? d.workflow : null) || (d ? DISCOVERY_STATUSES : VALUATION_LADDER)
  const curIdx = d ? Math.max(0, flow.lastIndexOf(d.status)) : flow.indexOf(v.status)
  const prev = curIdx > 0 ? flow[curIdx - 1] : null

  const owner = d?.owner_name || v?.owner_name
  const address = d?.property_address || v?.property_address
  const gps = d?.gps_coordinate || v?.gps_coordinate
  const mapLink = gps
    ? `https://www.google.com/maps?q=${encodeURIComponent(gps)}`
    : address ? `https://www.google.com/maps?q=${encodeURIComponent(address)}` : null

  const goValuation = () => {
    if (!v) return
    navigate(`/valuations?focus=${v.id}&status=${encodeURIComponent(v.status)}`)
    onClose?.()
  }
  const goDiscovery = () => {
    if (!d) return
    navigate(`/enforcement?tab=discoveries&focus=${d.id}`)
    onClose?.()
  }

  const ident = [
    ['Discovery ID', d?.discovery_reference],
    ['Valuation Ref', v?.valuation_reference],
    ['Property ID (LITAS)', d?.property_id || v?.property_id],
    ['Document # (LITAS)', d?.document_number || v?.document_number],
    ['TIN', d?.tin || v?.tin],
    ['Classification', d?.property_classification || v?.property_classification],
    ['Property type', d?.property_type],
    ['Occupancy / use', d?.occupancy_use],
  ].filter(([, x]) => x !== null && x !== undefined && x !== '')

  const ownerRows = [
    ['Owner / occupant', owner],
    ['Contact', d?.owner_contact || v?.owner_contact],
    ['Discovery date', d?.discovery_date],
    ['Discovered by', d?.discovered_by],
    ['Classified by', d?.classified_by],
    ['Classification decision', d?.classification_decision],
    ['Manager remarks', d?.manager_remarks],
    ['Remarks', d?.remarks],
    ['Processed (LITAS)', d?.processed_at],
    ['Completed', d?.completed_at],
  ].filter(([, x]) => x !== null && x !== undefined && x !== '')

  const valRows = [
    ['Valuation status', v?.status],
    ['Valuation officer', v?.valuation_officer],
    ['Assessment date', v?.assessment_date],
    ['Declared value', v?.declared_value],
    ['Reassessed value', v?.reassessed_value],
    ['Assessed value', v?.assessed_value],
    ['Annual tax', v?.annual_tax],
    ['Tax rate (%)', v?.applicable_tax_rate],
    ['Other amounts', v?.other_amounts],
    ['Total property value', v?.total_property_value],
    ['Total tax payable', v?.total_tax_payable],
    ['Manager reviewed', v?.manager_reviewed_at],
    ['Manager remarks', v?.manager_remarks],
    ['AC reviewed', v?.ac_reviewed_at],
    ['AC remarks', v?.ac_remarks],
    ['LITAS status', v?.litas_processing_status],
  ].filter(([, x]) => x !== null && x !== undefined && x !== '')

  const descriptions = v?.descriptions || []

  return (
    <div className="fixed inset-0 z-50 bg-navy-950/60 backdrop-blur-sm grid place-items-center p-4 overflow-y-auto">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[92vh] overflow-y-auto">
        {/* Header */}
        <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white z-10">
          <div className="flex items-center gap-2 flex-wrap">
            <h3 className="font-bold text-navy-800">{d?.discovery_reference || v?.valuation_reference}</h3>
            {info && <Badge tone={info.tone}>{info.label}</Badge>}
            {d?.route && <Badge tone="navy">{DISCOVERY_ROUTE_LABEL[d.route] || `Path ${d.route.slice(0, 1).toUpperCase()}`}</Badge>}
            {v && <Badge tone="blue">{v.valuation_reference} · {v.status}</Badge>}
            {v && <Btn tone="blue" className="!px-2.5 !py-1 !text-xs" onClick={goValuation}>Open valuation →</Btn>}
            {d && <Btn tone="white" className="!px-2.5 !py-1 !text-xs" onClick={goDiscovery}>↳ View discovery</Btn>}
          </div>
          <button onClick={onClose} className="w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-500 font-bold">✕</button>
        </div>

        <div className="p-6 space-y-6">
          {/* Workflow ladder */}
          <Section title="Workflow">
            <div className="flex items-center justify-between mb-2">
              <span className="text-xs font-bold uppercase tracking-wider text-slate-400">Route-driven stages</span>
              {prev && <span className="text-[11px] text-slate-400">Previous: <Badge tone={info?.tone || 'slate'}>{d ? discoveryStatusInfo(prev).label : prev}</Badge></span>}
            </div>
            <div className="flex flex-wrap gap-1.5">
              {flow.map((s, i) => {
                const isCurrent = i === curIdx
                const fired = !!d?.ac_decision && ['AC_REJECTED', 'RETURNED_FOR_CORRECTION', 'RESUBMITTED'].includes(s)
                const entered = i < curIdx || fired
                const tone = isCurrent ? 'bg-brand-500 text-white ring-2 ring-brand-200' : entered ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400'
                const label = d ? discoveryStatusInfo(s).label : s
                return (
                  <span key={i} className={`px-2.5 py-1 rounded-lg text-[10px] font-semibold whitespace-nowrap ${tone}`}>
                    {isCurrent ? '● ' : entered ? '✓ ' : '○ '}{label}
                  </span>
                )
              })}
            </div>
          </Section>

          {/* Identification */}
          {ident.length > 0 && (
            <Section title="Identification">
              <InfoGrid rows={ident} />
            </Section>
          )}

          {/* Owner */}
          <Section title="Owner information">
            <InfoGrid rows={ownerRows} />
          </Section>

          {/* Location */}
          <Section title="Location">
            <div className="text-sm text-navy-800">{address || '—'}</div>
            <div className="text-xs text-slate-500 mt-0.5">
              {[d?.county, d?.district, d?.city_town, d?.community, [d?.street, d?.house_number].filter(Boolean).join(' / ')].filter(Boolean).join(' · ') || 'No location details'}
            </div>
            {gps && <div className="text-xs text-slate-500 mt-1">GPS: {gps}{d?.gps_accuracy != null ? ` (accuracy ${Math.round(d.gps_accuracy)}m)` : ''}</div>}
            {mapLink && <a href={mapLink} target="_blank" rel="noreferrer" className="text-xs font-semibold text-brand-600 hover:underline">Open in Maps ↗</a>}
          </Section>

          {/* Valuation info */}
          {v && (
            <Section title="Valuation information">
              <InfoGrid rows={valRows} />
            </Section>
          )}

          {/* Property descriptions */}
          {v && (
            <Section title={`Property description ${descriptions.length ? `(${descriptions.length})` : ''}`}>
              {descriptions.length === 0 ? (
                <p className="text-sm text-slate-400">No property-description rows recorded.</p>
              ) : (
                <div className="overflow-x-auto border border-slate-200 rounded-xl">
                  <table className="w-full text-xs">
                    <thead>
                      <tr className="text-left text-[11px] uppercase tracking-wider text-slate-400 bg-slate-50">
                        {['No.', 'Property Description', 'Level', 'Area (sq. ft.)', 'TAR', 'Qty', 'Amount', 'Age', 'Depr. %', 'Value'].map((h) => (
                          <th key={h} className="px-2.5 py-2">{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {descriptions.map((r, i) => (
                        <tr key={r.id ?? i}>
                          <td className="px-2.5 py-2">{i + 1}</td>
                          <td className="px-2.5 py-2 text-slate-700">{r.description || '—'}</td>
                          <td className="px-2.5 py-2">{r.level || '—'}</td>
                          <td className="px-2.5 py-2">{r.area_sqft ?? '—'}</td>
                          <td className="px-2.5 py-2">{r.tar ?? '—'}</td>
                          <td className="px-2.5 py-2">{r.quantity ?? '—'}</td>
                          <td className="px-2.5 py-2">{r.amount ?? '—'}</td>
                          <td className="px-2.5 py-2">{r.building_age ?? '—'}</td>
                          <td className="px-2.5 py-2">{r.depreciation_pct ?? '—'}</td>
                          <td className="px-2.5 py-2 font-semibold">{r.value ?? '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Section>
          )}

          {/* Photos */}
          <Section title={`Field photos ${photos ? `(${photos.length})` : ''}`}>
            {photos === null ? (
              <p className="text-sm text-slate-400">Loading photos…</p>
            ) : photos.length === 0 ? (
              <p className="text-sm text-slate-400">No photos on file.</p>
            ) : (
              <div className="grid grid-cols-3 sm:grid-cols-4 gap-2">
                {photos.slice(0, 12).map((p) => <Photo key={p.id} id={p.id} />)}
              </div>
            )}
          </Section>

          {/* Valuation action panel (review-before-action) */}
          {v && runValuationAction && <ValuationActionPanel valuation={v} runValuationAction={runValuationAction} can={can} users={assignmentUsers} />}

          {/* Discovery action panel */}
          {d && runAction && <DiscoveryActionPanel d={d} runAction={runAction} can={can} users={assignmentUsers} />}
        </div>
      </div>
    </div>
  )
}

const VALUATION_LADDER = ['Draft', 'Submitted', 'Manager Review', 'Returned', 'AC Approval', 'Approved', 'Rejected']

function Section({ title, children }) {
  return (
    <div>
      <div className="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">{title}</div>
      {children}
    </div>
  )
}

function InfoGrid({ rows }) {
  return (
    <div className="grid sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
      {rows.map(([k, v]) => (
        <div key={k} className="flex justify-between gap-3 border-b border-slate-50 pb-1">
          <span className="text-slate-400 text-xs uppercase tracking-wide">{k}</span>
          <span className="font-medium text-navy-800 text-right">{v}</span>
        </div>
      ))}
    </div>
  )
}

/* ------------------------------------------------------------------ */
/*  Valuation actions — stage driven, gated by individual permissions  */
/* ------------------------------------------------------------------ */

function ValuationActionPanel({ valuation, runValuationAction, can, users }) {
  const [mode, setMode] = useState(null)
  const [remarks, setRemarks] = useState('')
  const [officer, setOfficer] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const st = valuation.status
  const worth = can('valuation.submit')
  const manager = can('valuation.review', 'valuation.forward_ac', 'valuation.return')
  const assigner = can('valuation.review', 'valuation.forward_ac', 'valuation.approve')
  const ac = can('valuation.approve', 'valuation.reject')
  const litas = can('valuation.litas_processing')

  const officers = users.filter((u) => /valuation/i.test(u.role?.name || ''))
  const configured = [
    ...(worth && ['Draft', 'Returned'].includes(st) ? [{ key: 'submit', label: 'Submit valuation' }] : []),
    ...(assigner && ['Draft', 'Returned'].includes(st) ? [{ key: 'assign', label: 'Assign to officer' }] : []),
    ...(manager && ['Submitted', 'Manager Review'].includes(st) ? [{ key: 'forward', label: 'Recommend to AC' }, { key: 'return', label: 'Return for correction' }] : []),
    ...(ac && st === 'AC Approval' ? [{ key: 'approve', label: 'Approve', tone: 'success' }, { key: 'reject', label: 'Reject', tone: 'danger' }] : []),
    ...(litas && st === 'Approved' && valuation.litas_processing_status !== 'Processed in source system' ? [{ key: 'processing', label: 'Mark processed in LITAS' }] : []),
  ]

  if (configured.length === 0) return null

  const run = async () => {
    setErr(''); setBusy(true)
    try {
      const payload = {}
      if (mode === 'submit') {
        if (valuation.assessed_value == null && valuation.annual_tax == null) {
          throw new Error('Enter an assessed value and annual tax on the valuation form before submitting.')
        }
        payload.assessed_value = valuation.assessed_value
        payload.annual_tax = valuation.annual_tax
        payload.applicable_tax_rate = valuation.applicable_tax_rate ?? undefined
        payload.other_amounts = valuation.other_amounts ?? undefined
      } else if (mode === 'assign') {
        if (!officer) throw new Error('Choose a valuation officer.')
        payload.officer_id = Number(officer)
      } else if (['forward', 'return', 'approve', 'reject'].includes(mode)) {
        payload.decision = mode
        payload.remarks = remarks || undefined
      }
      await runValuationAction(mode, payload)
      setMode(null); setRemarks(''); setOfficer('')
    } catch (ex) { setErr(errMsg(ex)) }
    setBusy(false)
  }

  return (
    <Section title="Valuation actions for this stage">
      <div className="rounded-xl border border-slate-200 p-4 space-y-3">
        {err && <div className="text-sm text-red-600">{err}</div>}
        {!mode ? (
          <div className="flex gap-2 flex-wrap">
            {configured.map((a) => (
              <Btn key={a.key} tone={a.tone === 'success' ? 'success' : a.tone === 'danger' ? 'danger' : 'primary'}
                onClick={() => setMode(a.key)}>{a.label}</Btn>
            ))}
          </div>
        ) : (
          <div className="space-y-3">
            {mode === 'assign' && (
              <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={officer}
                onChange={(e) => setOfficer(e.target.value)}>
                <option value="">Valuation officer…</option>
                {officers.map((u) => <option key={u.id} value={u.id}>{u.full_name} ({u.role?.name})</option>)}
                {users.filter((u) => !/valuation/i.test(u.role?.name || '')).map((u) => <option key={u.id} value={u.id}>{u.full_name} ({u.role?.name})</option>)}
              </select>
            )}
            {['forward', 'return', 'approve', 'reject'].includes(mode) && (
              <textarea className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm" rows={2}
                placeholder={mode === 'reject' ? 'Rejection reason' : mode === 'return' ? 'Correction instructions' : 'Remarks (optional)'}
                value={remarks} onChange={(e) => setRemarks(e.target.value)} />
            )}
            {mode === 'submit' && <p className="text-xs text-slate-500">Submitting locks the assessment for manager review.</p>}
            <div className="flex gap-2">
              <Btn onClick={run} disabled={busy}>{busy ? 'Working…' : 'Confirm'}</Btn>
              <Btn tone="white" onClick={() => { setMode(null); setRemarks(''); setOfficer('') }}>Cancel</Btn>
            </div>
          </div>
        )}
      </div>
    </Section>
  )
}

/* ------------------------------------------------------------------ */
/*  Discovery actions — the discovery workflow verbs for its status     */
/* ------------------------------------------------------------------ */

const ACTIONS_CONFIG = [
  { verb: 'submit', label: 'Submit', from: ['DISCOVERED'], perm: 'canCreate', prompt: null },
  { verb: 'reopen', label: 'Reopen', from: ['AC_REJECTED', 'RETURNED_FOR_CORRECTION'], perm: 'canReopen', confirm: true },
  { verb: 'review', label: 'Review', from: ['SUBMITTED', 'RESUBMITTED'], perm: 'canReview', prompt: { key: 'manager_remarks', place: 'Manager remarks' } },
  { verb: 'classify', label: 'Classify', from: ['UNDER_MANAGER_REVIEW', 'SUBMITTED'], perm: 'canClassify', pathChoose: true },
  { verb: 'route-to-account', label: 'Route to Account', from: ['CLASSIFIED'], need: 'account', perm: 'canRouteAccount' },
  { verb: 'route-to-valuation', label: 'Route to Valuation', from: ['CLASSIFIED'], need: 'valuation', perm: 'canRouteValuation', officerChoose: true },
  { verb: 'account-processing', label: 'Record LITAS ids', from: ['SENT_TO_ACCOUNT', 'SENT_TO_ACCOUNT_MANAGER'], perm: 'canLitas', litasPrompt: true },
  { verb: 'approve', label: 'Approve', from: ['PENDING_AC_APPROVAL'], perm: 'canApprove', prompt: { key: 'remarks', place: 'Approval remarks' } },
  { verb: 'reject', label: 'Reject', from: ['PENDING_AC_APPROVAL', 'VALUATION_MANAGER_REVIEW'], perm: 'canReject', prompt: { key: 'remarks', place: 'Rejection reason' } },
  { verb: 'complete', label: 'Complete', from: ['PROCESSED_IN_LITAS'], perm: 'canComplete' },
]

function DiscoveryActionPanel({ d, runAction, can, users }) {
  const [action, setAction] = useState(null)
  const [inputs, setInputs] = useState({})
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const canActions = {
    canCreate: can('discovery.create'),
    canReview: can('discovery.review'),
    canClassify: can('discovery.classify'),
    canRouteAccount: can('discovery.route_to_account'),
    canRouteValuation: can('discovery.route_to_valuation'),
    canApprove: can('discovery.approve'),
    canReject: can('discovery.reject'),
    canReopen: can('discovery.reopen'),
    canLitas: can('discovery.litas_processing') || can('discovery.route_to_account'),
    canComplete: can('discovery.litas_processing') || can('discovery.review') || can('discovery.approve'),
  }

  const configured = ACTIONS_CONFIG.filter((a) =>
    a.from.includes(d.status) && canActions[a.perm] && (!a.need || d.decision_path === a.need))

  if (configured.length === 0) return null

  const doAction = async (a) => {
    setErr('')
    if (a.confirm && !window.confirm(`Confirm ${a.label.toLowerCase()} ${d.discovery_reference}?`)) return
    if (a.prompt || a.pathChoose || a.officerChoose || a.litasPrompt) return setAction({ verb: a.verb, label: a.label, needs: a })
    setBusy(true)
    try { await runAction(a.verb, {}) } catch (ex) { setErr(errMsg(ex)) }
    setBusy(false)
  }

  const confirmComplex = async () => {
    const a = action.needs
    const payload = {}
    if (action.prompt) payload[action.prompt.key] = inputs[action.prompt.key] || undefined
    if (a?.verb === 'classify') {
      if (!inputs.path) return alert('Choose a path.')
      payload.decision_path = inputs.path
      payload.classification_decision = inputs.decision || undefined
    }
    if (a?.verb === 'route-to-valuation') {
      if (!inputs.officer) return alert('Choose a valuation officer.')
      payload.officer_id = inputs.officer
    }
    if (a?.verb === 'account-processing') {
      payload.property_id = inputs.pid || undefined
      payload.document_number = inputs.doc || undefined
    }
    setBusy(true)
    try {
      await runAction(action.verb, payload)
      setAction(null); setInputs({})
    } catch (ex) { setErr(errMsg(ex)) }
    setBusy(false)
  }

  return (
    <Section title="Discovery actions for the current status">
      <div className="rounded-xl border border-slate-200 p-4 space-y-3">
        {err && <div className="text-sm text-red-600">{err}</div>}
        {!action ? (
          <div className="flex gap-2 flex-wrap">
            {configured.map((a) => (
              <Btn key={a.verb} tone={a.verb === 'approve' ? 'success' : a.verb === 'reject' ? 'danger' : 'primary'}
                onClick={() => doAction(a)}>{a.label}</Btn>
            ))}
          </div>
        ) : (
          <div className="space-y-3">
            {action.prompt && (
              <textarea className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm" rows={2}
                placeholder={action.prompt.place}
                value={inputs[action.prompt.key] || ''}
                onChange={(e) => setInputs({ ...inputs, [action.prompt.key]: e.target.value })} />
            )}
            {action.needs?.verb === 'classify' && (
              <div className="grid sm:grid-cols-2 gap-2">
                <select className="px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={inputs.path || ''}
                  onChange={(e) => setInputs({ ...inputs, path: e.target.value })}>
                  <option value="">Path…</option>
                  {DISCOVERY_PATHS.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
                </select>
                <Input placeholder="Decision note (opt.)" value={inputs.decision || ''} onChange={(e) => setInputs({ ...inputs, decision: e.target.value })} />
              </div>
            )}
            {action.needs?.verb === 'route-to-valuation' && (
              <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={inputs.officer || ''}
                onChange={(e) => setInputs({ ...inputs, officer: e.target.value })}>
                <option value="">Valuation officer…</option>
                {users.filter((u) => /valuation/i.test(u.role?.name || '')).map((u) => <option key={u.id} value={u.id}>{u.full_name} ({u.role?.name})</option>)}
                {users.filter((u) => !/valuation/i.test(u.role?.name || '')).map((u) => <option key={u.id} value={u.id}>{u.full_name} ({u.role?.name})</option>)}
              </select>
            )}
            {action.needs?.verb === 'account-processing' && (
              <div className="grid sm:grid-cols-2 gap-2">
                <Input placeholder="Property ID (LITAS)" value={inputs.pid || ''} onChange={(e) => setInputs({ ...inputs, pid: e.target.value })} />
                <Input placeholder="Document # (LITAS)" value={inputs.doc || ''} onChange={(e) => setInputs({ ...inputs, doc: e.target.value })} />
              </div>
            )}
            <div className="flex gap-2">
              <Btn onClick={confirmComplex} disabled={busy}>{busy ? 'Working…' : 'Confirm'}</Btn>
              <Btn tone="white" onClick={() => { setAction(null); setInputs({}) }}>Cancel</Btn>
            </div>
          </div>
        )}
      </div>
    </Section>
  )
}

/** Photo thumbnail — fetches through the axios client so the bearer token rides along. */
function Photo({ id }) {
  const [src, setSrc] = useState(null)
  const [failed, setFailed] = useState(false)
  useEffect(() => {
    let active = true
    let blobUrl = null
    api.get(`/evidence/photos/${id}/download`, { responseType: 'blob' })
      .then((r) => {
        if (!active) return
        blobUrl = URL.createObjectURL(r.data)
        setSrc(blobUrl)
      })
      .catch(() => { if (active) setFailed(true) })
    return () => { active = false; if (blobUrl) URL.revokeObjectURL(blobUrl) }
  }, [id])
  if (failed) return <div className="aspect-square rounded-lg bg-slate-100 grid place-items-center text-slate-400 text-[10px]">n/a</div>
  if (!src) return <div className="aspect-square rounded-lg bg-slate-50 animate-pulse" />
  return (
    <a href={src} target="_blank" rel="noreferrer" className="aspect-square rounded-lg overflow-hidden border border-slate-200 block">
      <img src={src} alt="field evidence" className="w-full h-full object-cover hover:scale-105 transition" />
    </a>
  )
}