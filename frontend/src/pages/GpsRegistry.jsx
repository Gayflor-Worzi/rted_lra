import { useCallback, useEffect, useRef, useState } from 'react'
import { useAuth } from '../auth'
import api, { errMsg } from '../api'

const toNum = (v) => (v === null || v === undefined || v === '' ? null : Number(v))
const fmt = (v, d = 6) => (v === null || v === undefined ? '—' : Number(v).toFixed(d))
const dt = (v) => (v ? new Date(v).toLocaleString() : '—')

function osmSrc(lat, lng) {
  const d = 0.0025
  const bbox = `${lng - d},${lat - d},${lng + d},${lat + d}`
  return `https://www.openstreetmap.org/export/embed.html?bbox=${encodeURIComponent(bbox)}&layer=mapnik&marker=${lat},${lng}`
}

export default function GpsRegistry() {
  const { can } = useAuth()
  const [propertyId, setPropertyId] = useState('')
  const [loaded, setLoaded] = useState(null)
  const [official, setOfficial] = useState(null)
  const [recent, setRecent] = useState([])
  const [pendingCount, setPendingCount] = useState(0)
  const [history, setHistory] = useState([])
  const [pendlist, setPendlist] = useState([])
  const [visits, setVisits] = useState([])
  const [attachVisit, setAttachVisit] = useState('')
  const [loading, setLoading] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [capturing, setCapturing] = useState(false)
  const [lat, setLat] = useState('')
  const [lng, setLng] = useState('')
  const [acc, setAcc] = useState('')
  const [remarks, setRemarks] = useState('')
  const [corLat, setCorLat] = useState('')
  const [corLng, setCorLng] = useState('')
  const [corAcc, setCorAcc] = useState('')
  const [corRemarks, setCorRemarks] = useState('')

  const canCapture = can('gps.capture') && can('gps.certify')
  const canUpdate = can('gps.update')
  const canReview = can('gps.review') || can('gps.approve')
  const loadedRef = useRef(null)

  const loadPending = useCallback(async () => {
    if (!canReview) return
    try {
      const r = await api.get('/gps/reviews')
      setPendlist(r.data.data)
    } catch {
      // non-fatal
    }
  }, [canReview])

  useEffect(() => {
    loadPending()
  }, [loadPending])

  const loadProperty = useCallback(async (pid) => {
    if (!pid) return
    loadedRef.current = pid
    setLoading(true)
    setError('')
    try {
      const r = await api.get(`/gps/property/${encodeURIComponent(pid)}/current`)
      const d = r.data.data
      setOfficial(d.official)
      setRecent(d.recent_captures || [])
      setPendingCount(d.pending_count || 0)
      setLoaded(pid)
      try {
        const ctx = await api.get(`/gps/capture-context/${encodeURIComponent(pid)}`)
        setVisits(ctx.data.data.open_visits || [])
      } catch {
        setVisits([])
      }
      try {
        const h = await api.get(`/gps/property/${encodeURIComponent(pid)}/history`)
        setHistory(h.data.data || [])
      } catch {
        setHistory([])
      }
    } catch (e) {
      setError(errMsg(e, 'Could not load GPS data for this property.'))
      setLoaded(null)
    } finally {
      setLoading(false)
      setAttachVisit('')
    }
  }, [])

  const grabLocation = () => {
    if (!navigator.geolocation) {
      setError('Geolocation is not available here (needs a secure context / HTTPS).')
      return
    }
    setCapturing(true)
    setError('')
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setCapturing(false)
        setLat(String(parseFloat(pos.coords.latitude.toFixed(7))))
        setLng(String(parseFloat(pos.coords.longitude.toFixed(7))))
        setAcc(String(Math.round(pos.coords.accuracy)))
      },
      (fail) => {
        setCapturing(false)
        setError(`Location unavailable: ${fail.message}`)
      },
      { enableHighAccuracy: true, timeout: 15000 },
    )
  }

  const certify = async (decision, autoRemarks) => {
    if (!loadedRef.current) return
    if (!lat || !lng) {
      setError('A latitude and longitude are required.')
      return
    }
    const note = remarks.trim() || autoRemarks
    if (decision === 'no' && !note.trim()) {
      setError('A remark is required when the captured location is not the property.')
      return
    }
    try {
      const payload = {
        property_id: loadedRef.current,
        certification_decision: decision,
        source: attachVisit ? 'enforcement_visit' : 'other',
        remarks: note,
        latitude: toNum(lat),
        longitude: toNum(lng),
        accuracy: toNum(acc),
        ...(attachVisit ? { visit_id: Number(attachVisit) } : {}),
      }
      const r = await api.post('/gps/certify', payload)
      setNotice(r.data.data.message || 'Location recorded.')
      setError('')
      const pid = loadedRef.current
      await loadProperty(pid)
      await loadPending()
    } catch (e) {
      setError(errMsg(e))
    }
  }

  const correct = () => {
    if (!loadedRef.current) return
    if (!corLat || !corLng) {
      setError('Correction requires a latitude and longitude.')
      return
    }
    if (!corRemarks.trim()) {
      setError('Correction requires a remark stating the reason.')
      return
    }
    api.put(`/gps/property/${encodeURIComponent(loadedRef.current)}/correct`, {
      latitude: toNum(corLat),
      longitude: toNum(corLng),
      accuracy: toNum(corAcc),
      remarks: corRemarks.trim(),
    })
      .then((r) => {
        setNotice(r.data.message || 'Official GPS corrected.')
        setError('')
        setCorRemarks('')
        return loadProperty(loadedRef.current)
      })
      .catch((e) => setError(errMsg(e)))
  }

  const decide = async (historyId, decision) => {
    try {
      const r = await api.post(`/gps/reviews/${historyId}/decision`, { decision })
      setNotice(r.data.data.message || 'Decision recorded.')
      setError('')
      await loadPending()
      if (loadedRef.current) await loadProperty(loadedRef.current)
    } catch (e) {
      setError(errMsg(e))
    }
  }

  const statusDot = (s) => {
    if (s === 'approved') return 'bg-emerald-500'
    if (s === 'rejected') return 'bg-red-400'
    if (s === 'not_property') return 'bg-amber-400'
    return 'bg-slate-400'
  }

  return (
    <div className="max-w-5xl space-y-5">
      <div>
        <h1 className="text-2xl font-bold">📍 GPS Registry</h1>
        <p className="text-sm text-slate-500 mt-1">Official property locations, capture certification and review.</p>
      </div>

      {notice && (
        <div className="rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm px-4 py-3">
          {notice}
        </div>
      )}
      {error && (
        <div className="rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
          {error}
        </div>
      )}

      {/* Property lookup */}
      <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
        <form
          className="flex gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (!propertyId.trim()) return
            setLoaded(null)
            loadProperty(propertyId.trim())
          }}>
          <input
            value={propertyId}
            onChange={(e) => setPropertyId(e.target.value)}
            placeholder="Property ID (e.g. PRP-07000001)"
            className="flex-1 px-4 py-2.5 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
          <button type="submit" className="px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition">
            Load property
          </button>
        </form>
        {loading && <div className="mt-3 text-sm text-slate-400">Loading property GPS…</div>}
      </div>

      {loaded && (
        <>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {/* Official location + map */}
            <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 space-y-4">
              <div className="flex items-center justify-between">
                <h2 className="font-semibold">Official location</h2>
                {official && <span className="text-[11px] px-2 py-1 rounded-full bg-emerald-100 text-emerald-700 font-semibold">verified</span>}
              </div>

              {official ? (
                <div className="space-y-3">
                  <div className="grid grid-cols-3 gap-2 text-sm">
                    <div className="bg-slate-50 rounded-xl p-3">
                      <div className="text-[11px] text-slate-400 uppercase">Latitude</div>
                      <div className="font-mono font-semibold truncate">{fmt(official.latitude)}</div>
                    </div>
                    <div className="bg-slate-50 rounded-xl p-3">
                      <div className="text-[11px] text-slate-400 uppercase">Longitude</div>
                      <div className="font-mono font-semibold truncate">{fmt(official.longitude)}</div>
                    </div>
                    <div className="bg-slate-50 rounded-xl p-3">
                      <div className="text-[11px] text-slate-400 uppercase">Accuracy</div>
                      <div className="font-semibold">{official.accuracy != null ? `${official.accuracy} m` : '—'}</div>
                    </div>
                  </div>
                  <div className="text-xs text-slate-500">
                    Source: <span className="font-mono">{official.source}</span> · captured {dt(official.captured_at)} · updated {dt(official.updated_at)}
                  </div>
                  <iframe title="Property map" src={osmSrc(Number(official.latitude), Number(official.longitude))} className="w-full h-64 rounded-xl border border-slate-200" loading="lazy" />
                  <div className="flex gap-2 flex-wrap">
                    <a
                      href={`https://www.openstreetmap.org/?mlat=${official.latitude}&mlon=${official.longitude}#map=18/${official.latitude}/${official.longitude}`}
                      target="_blank" rel="noreferrer"
                      className="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-xs font-semibold">
                      OpenStreetMap ↗
                    </a>
                    <a
                      href={`https://www.google.com/maps/dir/?api=1&destination=${official.latitude},${official.longitude}`}
                      target="_blank" rel="noreferrer"
                      className="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-xs font-semibold">
                      Directions ↗
                    </a>
                  </div>
                </div>
              ) : (
                <div className="text-sm text-slate-400">No official GPS recorded for this property yet — capture &amp; certify below.</div>
              )}

              {pendingCount > 0 && (
                <div className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2">
                  {pendingCount} certification waiting for approval on this property.
                </div>
              )}

              {recent.length > 0 && (
                <div>
                  <h3 className="text-xs font-semibold text-slate-500 uppercase mb-2">Recent field captures</h3>
                  <div className="space-y-1.5">
                    {recent.map((v) => (
                      <div key={v.id} className="text-xs text-slate-600 border-b border-slate-100 pb-1.5">
                        <span className="font-mono">{v.visit_reference}</span> · {v.gps_coordinate} · {v.gps_accuracy != null ? `${v.gps_accuracy}m` : '—'}
                        <span className="ml-2 text-[10px] px-1.5 py-0.5 rounded-full bg-slate-100">
                          {v.gps_verified ? `certified: ${v.gps_verified}` : 'uncertified'}
                        </span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>

            {/* Capture + certify */}
            {canCapture && (
              <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 space-y-4">
                <h2 className="font-semibold">Capture &amp; certify</h2>

                {visits.length > 0 && (
                  <label className="block text-sm">
                    <span className="text-slate-500">Attach to an open field visit</span>
                    <select
                      value={attachVisit}
                      onChange={(e) => setAttachVisit(e.target.value)}
                      className="mt-1 w-full px-3 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500">
                      <option value="">No visit attached (standalone capture)</option>
                      {visits.map((v) => (
                        <option key={v.visit_id} value={v.visit_id}>
                          {v.visit_reference}{v.gps_coordinate ? ` — ${v.gps_coordinate}` : ''}
                        </option>
                      ))}
                    </select>
                  </label>
                )}

                <button
                  onClick={grabLocation}
                  className="w-full py-2.5 rounded-xl bg-navy-800 hover:bg-navy-700 text-white text-sm font-semibold transition">
                  {capturing ? 'Reading GPS…' : '📍 Get current location'}
                </button>

                {!capturing && !lat && navigator.geolocation && (
                  <p className="text-[11px] text-slate-400">Use the button above (browser geolocation) or enter coordinates manually.</p>
                )}

                <div className="grid grid-cols-2 gap-2">
                  <label className="block text-xs">
                    <span className="text-slate-500">Latitude</span>
                    <input value={lat} onChange={(e) => setLat(e.target.value)} placeholder="-0.5158889" className="mt-1 w-full px-3 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500" />
                  </label>
                  <label className="block text-xs">
                    <span className="text-slate-500">Longitude</span>
                    <input value={lng} onChange={(e) => setLng(e.target.value)} placeholder="36.0901234" className="mt-1 w-full px-3 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500" />
                  </label>
                </div>
                <label className="block text-xs">
                  <span className="text-slate-500">Accuracy (m)</span>
                  <input value={acc} onChange={(e) => setAcc(e.target.value)} placeholder="8" className="mt-1 w-full px-3 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500" />
                </label>
                <label className="block text-xs">
                  <span className="text-slate-500">Remarks</span>
                  <textarea value={remarks} onChange={(e) => setRemarks(e.target.value)} rows={2} className="mt-1 w-full px-3 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500" placeholder="e.g. White gate, Survey Road" />
                </label>

                <div className="grid grid-cols-2 gap-2">
                  <button
                    onClick={() => certify('yes')}
                    className="px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-semibold transition">
                    Certify — actual location
                  </button>
                  <button
                    onClick={() => certify('no', `Coordinates ${lat},${lng} do not represent this property.`)}
                    className="px-4 py-2.5 rounded-xl bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 text-sm font-semibold transition">
                    Not the property
                  </button>
                </div>
                <p className="text-[11px] text-slate-400">
                  {canUpdate
                    ? 'You have update rights — a “yes” certification applies the official location immediately.'
                    : 'Your “yes” certification queues for supervisory approval before it becomes official.'}
                </p>
              </div>
            )}
          </div>

          {/* Authorized correction */}
          {canUpdate && (
            <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 space-y-3">
              <h2 className="font-semibold">Authorized correction</h2>
              <div className="grid grid-cols-2 gap-2">
                <label className="block text-xs">
                  <span className="text-slate-500">Latitude</span>
                  <input value={corLat} onChange={(e) => setCorLat(e.target.value)} placeholder="-0.5160000" className="mt-1 w-full px-3 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500" />
                </label>
                <label className="block text-xs">
                  <span className="text-slate-500">Longitude</span>
                  <input value={corLng} onChange={(e) => setCorLng(e.target.value)} placeholder="36.0903000" className="mt-1 w-full px-3 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500" />
                </label>
              </div>
              <div className="grid grid-cols-2 gap-2 items-end">
                <label className="block text-xs">
                  <span className="text-slate-500">Accuracy (m)</span>
                  <input value={corAcc} onChange={(e) => setCorAcc(e.target.value)} placeholder="6" className="mt-1 w-full px-3 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500" />
                </label>
                <button
                  onClick={correct}
                  className="px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold transition">
                  Apply correction
                </button>
              </div>
              <label className="block text-xs">
                <span className="text-slate-500">Remarks (required)</span>
                <textarea value={corRemarks} onChange={(e) => setCorRemarks(e.target.value)} rows={2} className="mt-1 w-full px-3 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500" placeholder="Reason for the correction" />
              </label>
            </div>
          )}

          {/* History */}
          <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
            <h2 className="font-semibold mb-3">Location history</h2>
            {history.length === 0 ? (
              <div className="text-sm text-slate-400">No recorded history.</div>
            ) : (
              <div className="space-y-2">
                {history.map((h) => (
                  <div key={h.id} className="flex items-start gap-3 text-sm border-b border-slate-100 pb-2">
                    <span className={`mt-1.5 shrink-0 w-2 h-2 rounded-full ${statusDot(h.verification_status)}`} />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap gap-x-3 gap-y-0.5">
                        <span className="font-mono">{fmt(h.new_latitude)}, {fmt(h.new_longitude)}</span>
                        <span className="text-slate-400 font-mono text-xs">{h.source}</span>
                        <span className="text-slate-400 text-xs">{dt(h.captured_at)}</span>
                      </div>
                      <div className="text-xs text-slate-500">
                        {h.verification_status.replace('_', ' ')}
                        {h.previous_latitude != null && ` · previous ${fmt(h.previous_latitude)}, ${fmt(h.previous_longitude)}`}
                        {h.remarks ? ` · “${h.remarks}”` : ''}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </>
      )}

      {/* Pending certification queue */}
      {canReview && (
        <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
          <h2 className="font-semibold mb-3">Pending certifications ({pendlist.length})</h2>
          {pendlist.length === 0 ? (
            <div className="text-sm text-slate-400">No pending certifications.</div>
          ) : (
            <div className="space-y-3">
              {pendlist.map((p) => (
                <div key={p.id} className="border border-slate-200 rounded-xl p-3 text-sm">
                  <div className="flex items-center justify-between flex-wrap gap-2">
                    <div>
                      <span className="font-mono font-semibold">{p.property_id}</span>{' '}
                      <span className="text-slate-500">— {fmt(p.new_latitude)}, {fmt(p.new_longitude)}</span>
                    </div>
                    <div className="text-xs text-slate-400">
                      by {p.captured_by?.full_name || `user #${p.captured_by}`} · {dt(p.captured_at)}
                    </div>
                  </div>
                  <div className="text-xs text-slate-500 mt-1">
                    source {p.source} · decision “{p.certification_decision}”{p.remarks ? ` · ${p.remarks}` : ''}
                  </div>
                  <div className="flex gap-2 mt-2">
                    <button
                      onClick={() => decide(p.id, 'approve')}
                      className="px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-semibold">
                      Approve
                    </button>
                    <button
                      onClick={() => decide(p.id, 'reject')}
                      className="px-3 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 border border-red-200 text-red-700 text-xs font-semibold">
                      Reject
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  )
}