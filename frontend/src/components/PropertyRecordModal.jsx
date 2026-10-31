import { useCallback, useEffect, useState } from 'react'
import api, { unwrap, errMsg } from '../api'
import PropertyDetail from './PropertyDetail'

/**
 * One Property View for every desk.
 *
 * A task queue only knows a reference (type + id). This resolves that reference
 * into the discovery and/or valuation behind it, hydrates the other side of the
 * relationship so the view can never show a half-record, and hands both to the
 * shared PropertyDetail. The action panels inside PropertyDetail gate every
 * button on the signed-in user's own permissions, so the Valuation Manager, the
 * Account Manager and an Enforcement Officer each get the same view with a
 * different, correct set of actions.
 *
 * Discovery workflow verbs are one route segment each; valuation verbs are
 * mapped explicitly because they are not.
 */
const VALUATION_CALLS = {
  begin: (v) => api.post(`/valuations/${v.id}/begin`),
  submit: (v, p) => api.post(`/valuations/${v.id}/submit`, p),
  assign: (v, p) => api.post(`/valuations/${v.id}/assign`, p),
  forward: (v, p) => api.post(`/valuations/${v.id}/review`, { decision: 'confirm_ac', remarks: p.remarks }),
  return: (v, p) => api.post(`/valuations/${v.id}/review`, { decision: 'return', remarks: p.remarks }),
  approve: (v, p) => api.post(`/valuations/${v.id}/decide`, { decision: 'approve', remarks: p.remarks }),
  reject: (v, p) => api.post(`/valuations/${v.id}/decide`, { decision: 'reject', remarks: p.remarks }),
  'decide-return': (v, p) => api.post(`/valuations/${v.id}/decide`, { decision: 'return', remarks: p.remarks }),
  'account-approve': (v, p) => api.post(`/valuations/${v.id}/account-review`, { decision: 'approve', officer_id: p.officer_id, remarks: p.remarks }),
  'account-return': (v, p) => api.post(`/valuations/${v.id}/account-review`, { decision: 'return', remarks: p.remarks }),
  processing: (v, p) => api.post(`/valuations/${v.id}/processing`, p),
  complete: (v) => api.post(`/valuations/${v.id}/complete`),
}

export default function PropertyRecordModal({
  referenceType, referenceId, title, assignmentUsers = [], onClose, onChanged,
}) {
  const [discovery, setDiscovery] = useState(null)
  const [valuation, setValuation] = useState(null)
  const [err, setErr] = useState('')
  const [loaded, setLoaded] = useState(false)

  const load = useCallback(async () => {
    if (!referenceId) return { discovery: null, valuation: null }

    if (referenceType === 'property_discovery') {
      const d = unwrap(await api.get(`/discoveries/${referenceId}`)).data
      let v = d?.valuation ?? null
      // A discovery that already carries a valuation must open on both sides,
      // otherwise the manager sees the record without its assessment.
      if (!v && d?.valuation_id) {
        v = unwrap(await api.get(`/valuations/${d.valuation_id}`)).data
      }
      return { discovery: d, valuation: v }
    }

    if (referenceType === 'valuation') {
      const v = unwrap(await api.get(`/valuations/${referenceId}`)).data
      let d = v?.discovery ?? null
      if (!d && v?.discovery_id) {
        d = unwrap(await api.get(`/discoveries/${v.discovery_id}`)).data
      }
      return { discovery: d, valuation: v }
    }

    return { discovery: null, valuation: null }
  }, [referenceType, referenceId])

  useEffect(() => {
    let active = true
    setErr('')
    setLoaded(false)
    load()
      .then(({ discovery: d, valuation: v }) => {
        if (!active) return
        setDiscovery(d)
        setValuation(v)
        setLoaded(true)
      })
      .catch((e) => { if (active) { setErr(errMsg(e, 'Unable to load this property record.')); setLoaded(true) } })
    return () => { active = false }
  }, [load])

  const refresh = useCallback(async () => {
    const { discovery: d, valuation: v } = await load()
    setDiscovery(d)
    setValuation(v)
    onChanged?.()
  }, [load, onChanged])

  const runAction = useCallback(async (verb, payload = {}) => {
    if (!discovery) return undefined
    return unwrap(await api.post(`/discoveries/${discovery.id}/${verb}`, payload))
  }, [discovery])

  const runValuationAction = useCallback(async (verb, payload = {}) => {
    if (!valuation) return undefined
    const call = VALUATION_CALLS[verb]
    if (!call) throw new Error(`Unsupported valuation action: ${verb}`)
    return unwrap(await call(valuation, payload))
  }, [valuation])

  const onActionDone = useCallback(async () => { await refresh() }, [refresh])

  if (err) {
    return (
      <div className="fixed inset-0 z-50 grid place-items-center p-4">
        <div className="bg-white rounded-2xl shadow-xl p-6 text-sm text-red-600 space-y-3">
          <div>{err}</div>
          <button type="button" onClick={onClose} className="text-xs font-semibold text-brand-700 underline">Close</button>
        </div>
      </div>
    )
  }

  if (!loaded || (!discovery && !valuation)) return null

  return (
    <PropertyDetail
      discovery={discovery}
      valuation={valuation}
      title={title}
      assignmentUsers={assignmentUsers}
      onClose={onClose}
      runAction={discovery ? runAction : undefined}
      runValuationAction={valuation ? runValuationAction : undefined}
      onActionDone={onActionDone}
    />
  )
}
