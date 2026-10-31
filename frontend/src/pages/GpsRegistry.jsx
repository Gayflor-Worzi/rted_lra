import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import api, { errMsg } from '../api'

const toNum = (value) => (value === null || value === undefined || value === '' ? null : Number(value))
const fmt = (value, digits = 6) => (value === null || value === undefined ? '—' : Number(value).toFixed(digits))
const dt = (value) => (value ? new Date(value).toLocaleString() : '—')

const STATUS_LABELS = {
  pending_review: 'Pending review',
  approved: 'Certified',
  rejected: 'Rejected',
  not_property: 'Not the property',
}

const STATUS_STYLES = {
  pending_review: 'bg-amber-100 text-amber-800',
  approved: 'bg-emerald-100 text-emerald-800',
  rejected: 'bg-red-100 text-red-800',
  not_property: 'bg-slate-100 text-slate-700',
}

function osmSrc(lat, lng) {
  const delta = 0.003
  const bbox = `${lng - delta},${lat - delta},${lng + delta},${lat + delta}`
  return `https://www.openstreetmap.org/export/embed.html?bbox=${encodeURIComponent(bbox)}&layer=mapnik&marker=${lat},${lng}`
}

function MapPreview({ latitude, longitude, label = 'Captured location map' }) {
  if (latitude === null || latitude === undefined || longitude === null || longitude === undefined) {
    return <div className="flex h-56 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-sm text-slate-500">Map unavailable — coordinates were not recorded.</div>
  }
  const lat = Number(latitude)
  const lng = Number(longitude)
  if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
    return <div className="flex h-56 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-sm text-slate-500">Map unavailable — recorded coordinates are invalid.</div>
  }
  return <iframe title={label} src={osmSrc(lat, lng)} className="h-56 w-full rounded-xl border border-slate-200" loading="lazy" />
}

function StatusBadge({ status }) {
  return <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-bold ${STATUS_STYLES[status] || 'bg-slate-100 text-slate-700'}`}>{STATUS_LABELS[status] || status || 'Unknown'}</span>
}

export default function GpsRegistry() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const [tab, setTab] = useState(params.get('tab') || 'registry')
  const [propertyId, setPropertyId] = useState('')
  const [loaded, setLoaded] = useState(null)
  const [official, setOfficial] = useState(null)
  const [recent, setRecent] = useState([])
  const [pendingCount, setPendingCount] = useState(0)
  const [history, setHistory] = useState([])
  const [historyError, setHistoryError] = useState('')
  const [visits, setVisits] = useState([])
  const [attachVisit, setAttachVisit] = useState('')
  const [capture, setCapture] = useState({ latitude: '', longitude: '', accuracy: '', remarks: '' })
  const [correction, setCorrection] = useState({ latitude: '', longitude: '', accuracy: '', remarks: '' })
  const [reviewRows, setReviewRows] = useState([])
  const [records, setRecords] = useState([])
  const [counts, setCounts] = useState({ total: 0, pending_review: 0, approved: 0, rejected: 0 })
  const [selected, setSelected] = useState(null)
  const [decision, setDecision] = useState(null)
  const [reviewRemarks, setReviewRemarks] = useState('')
  const [search, setSearch] = useState('')
  const [district, setDistrict] = useState('')
  const [officerId, setOfficerId] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [loadingList, setLoadingList] = useState(false)
  const [listError, setListError] = useState('')
  const [propertyLoading, setPropertyLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [capturing, setCapturing] = useState(false)

  const canCapture = can('gps.capture') && can('gps.certify')
  const canUpdate = can('gps.update')
  const canReview = can('gps.review') || can('gps.approve')
  const canApprove = can('gps.approve')
  const canHistory = can('gps.view_history')
  const loadedRef = useRef(null)
  const reviewsRef = useRef(null)

  const tabs = useMemo(() => [
    { key: 'registry', label: 'GPS Registry', show: true },
    { key: 'reviews', label: 'Certification Reviews', show: canReview },
    { key: 'certified', label: 'Certified Records', show: canHistory },
    { key: 'rejected', label: 'Rejected Records', show: canHistory },
    { key: 'history', label: 'GPS History & Audit', show: canHistory },
    { key: 'property', label: 'Property Lookup', show: true },
  ].filter((item) => item.show), [canHistory, canReview])

  useEffect(() => {
    const requested = params.get('tab')
    if (requested && tabs.some((item) => item.key === requested)) setTab(requested)
  }, [params, tabs])

  useEffect(() => {
    if (tab === 'reviews') reviewsRef.current?.scrollIntoView?.({ behavior: 'smooth', block: 'start' })
  }, [tab])

  const loadList = useCallback(async () => {
    if (tab === 'property') return
    setLoadingList(true)
    setListError('')
    try {
      if (tab === 'reviews') {
        const response = await api.get('/gps/reviews', { params: { page, per_page: 20 } })
        const result = response.data?.data
        setReviewRows(result?.data || [])
        setLastPage(result?.last_page || 1)
        setCounts((current) => ({ ...current, pending_review: result?.total || 0 }))
      } else {
        const status = tab === 'certified' ? 'approved' : tab === 'rejected' ? 'rejected' : undefined
        const response = await api.get('/gps/registry', {
          params: {
            page,
            per_page: 20,
            ...(status ? { status } : {}),
            ...(search.trim() ? { q: search.trim() } : {}),
            ...(district.trim() ? { tax_district: district.trim() } : {}),
            ...(officerId.trim() ? { officer_id: officerId.trim() } : {}),
            ...(dateFrom ? { date_from: dateFrom } : {}),
            ...(dateTo ? { date_to: dateTo } : {}),
          },
        })
        const result = response.data?.data?.records
        setRecords(result?.data || [])
        setLastPage(result?.last_page || 1)
        setCounts(response.data?.data?.counts || { total: 0, pending_review: 0, approved: 0, rejected: 0 })
      }
    } catch (requestError) {
      setListError(errMsg(requestError, 'Could not load GPS records.'))
    } finally {
      setLoadingList(false)
    }
  }, [tab, page, search, district, officerId, dateFrom, dateTo])

  useEffect(() => {
    const timer = setTimeout(loadList, 250)
    return () => clearTimeout(timer)
  }, [loadList])

  const changeTab = (next) => {
    setTab(next)
    setPage(1)
    setSelected(null)
    setParams(next === 'registry' ? {} : { tab: next })
  }

  const loadProperty = async (id = propertyId.trim()) => {
    const pid = id.trim()
    if (!pid) return
    loadedRef.current = null
    setLoaded(null)
    setOfficial(null)
    setRecent([])
    setHistory([])
    setHistoryError('')
    setVisits([])
    setAttachVisit('')
    setCapture({ latitude: '', longitude: '', accuracy: '', remarks: '' })
    setCorrection({ latitude: '', longitude: '', accuracy: '', remarks: '' })
    setPropertyLoading(true)
    setError('')
    setNotice('')
    try {
      const currentResponse = await api.get(`/gps/property/${encodeURIComponent(pid)}/current`)
      const current = currentResponse.data.data
      setOfficial(current.official)
      setRecent(current.recent_captures || [])
      setPendingCount(current.pending_count || 0)
      setLoaded(pid)
      loadedRef.current = pid

      if (canCapture) {
        try {
          const context = await api.get(`/gps/capture-context/${encodeURIComponent(pid)}`)
          setVisits(context.data.data.open_visits || [])
        } catch (contextError) {
          setError(errMsg(contextError, 'Property loaded, but field visit context is unavailable.'))
        }
      }
      if (canHistory) {
        try {
          const response = await api.get(`/gps/property/${encodeURIComponent(pid)}/history`)
          setHistory(response.data.data || [])
        } catch (historyRequestError) {
          setHistoryError(errMsg(historyRequestError, 'Location history could not be loaded.'))
        }
      } else {
        setHistoryError('Your role can view the current GPS record but does not have GPS history permission.')
      }
    } catch (requestError) {
      setError(errMsg(requestError, 'Could not load GPS data for this property.'))
    } finally {
      setPropertyLoading(false)
    }
  }

  const updatePropertyId = (value) => {
    setPropertyId(value)
    if (loadedRef.current && value.trim() !== loadedRef.current) {
      loadedRef.current = null
      setLoaded(null)
      setOfficial(null)
      setRecent([])
      setPendingCount(0)
      setHistory([])
      setVisits([])
      setCapture({ latitude: '', longitude: '', accuracy: '', remarks: '' })
      setCorrection({ latitude: '', longitude: '', accuracy: '', remarks: '' })
    }
  }

  const grabLocation = () => {
    if (!navigator.geolocation) {
      setError('Geolocation is unavailable. Use a secure browser context or a supported device.')
      return
    }
    setCapturing(true)
    setError('')
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setCapturing(false)
        setCapture((value) => ({
          ...value,
          latitude: String(parseFloat(position.coords.latitude.toFixed(7))),
          longitude: String(parseFloat(position.coords.longitude.toFixed(7))),
          accuracy: position.coords.accuracy == null ? '' : String(position.coords.accuracy),
        }))
      },
      (failure) => {
        setCapturing(false)
        setError(`Location unavailable: ${failure.message}`)
      },
      { enableHighAccuracy: true, timeout: 15000 },
    )
  }

  const certify = async (certificationDecision) => {
    if (!loadedRef.current || !capture.latitude || !capture.longitude) {
      setError('Load a property and provide latitude and longitude before submitting.')
      return
    }
    if (certificationDecision === 'no' && !capture.remarks.trim()) {
      setError('A remark is required when the captured location is not the property.')
      return
    }
    setBusy(true)
    try {
      const response = await api.post('/gps/certify', {
        property_id: loadedRef.current,
        certification_decision: certificationDecision,
        source: attachVisit ? 'enforcement_visit' : 'other',
        remarks: capture.remarks.trim() || (certificationDecision === 'no' ? 'Captured coordinates do not represent this property.' : undefined),
        latitude: toNum(capture.latitude),
        longitude: toNum(capture.longitude),
        accuracy: toNum(capture.accuracy),
        ...(attachVisit ? { visit_id: Number(attachVisit) } : {}),
      })
      setNotice(response.data.data.message || 'Location recorded.')
      setError('')
      await loadProperty(loadedRef.current)
      await loadList()
    } catch (requestError) {
      setError(errMsg(requestError))
    } finally {
      setBusy(false)
    }
  }

  const correct = async () => {
    if (!loadedRef.current) return
    if (!correction.latitude || !correction.longitude || !correction.remarks.trim()) {
      setError('Correction requires latitude, longitude, and a reason.')
      return
    }
    setBusy(true)
    try {
      const response = await api.put(`/gps/property/${encodeURIComponent(loadedRef.current)}/correct`, {
        latitude: toNum(correction.latitude),
        longitude: toNum(correction.longitude),
        accuracy: toNum(correction.accuracy),
        remarks: correction.remarks.trim(),
      })
      setNotice(response.data.data.message || 'Official GPS corrected.')
      setError('')
      await loadProperty(loadedRef.current)
    } catch (requestError) {
      setError(errMsg(requestError))
    } finally {
      setBusy(false)
    }
  }

  const openDecision = (record, nextDecision) => {
    if (nextDecision === 'approve') {
      setDecision({ record, action: nextDecision })
      setReviewRemarks('')
      return
    }
    setDecision({ record, action: nextDecision })
    setReviewRemarks('')
  }

  const submitDecision = async () => {
    if (!decision) return
    if (decision.action === 'reject' && !reviewRemarks.trim()) {
      setError('A rejection reason is required.')
      return
    }
    setBusy(true)
    try {
      const response = await api.post(`/gps/reviews/${decision.record.id}/decision`, {
        decision: decision.action,
        ...(reviewRemarks.trim() ? { remarks: reviewRemarks.trim() } : {}),
      })
      setNotice(response.data.data.message || 'Decision recorded.')
      setError('')
      setDecision(null)
      setSelected(null)
      await loadList()
      if (loadedRef.current) await loadProperty(loadedRef.current)
    } catch (requestError) {
      setError(errMsg(requestError))
    } finally {
      setBusy(false)
    }
  }

  const applyFilters = (event) => {
    event.preventDefault()
    setPage(1)
  }

  const tabLabel = tabs.find((item) => item.key === tab)?.label || 'GPS Registry'

  return (
    <div className="mx-auto max-w-7xl space-y-5 pb-8">
      <header className="rounded-2xl bg-gradient-to-r from-navy-900 to-navy-700 p-6 text-white shadow-sm">
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-slate-300">Location operations</p>
            <h1 className="mt-1 text-2xl font-bold">GPS Registry</h1>
            <p className="mt-2 max-w-2xl text-sm text-slate-200">Official property locations, field capture certification, and review decisions.</p>
          </div>
          <span className="rounded-full border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-semibold">{tabLabel}</span>
        </div>
      </header>

      {notice && <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</div>}
      {error && <div role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>}

      <nav aria-label="GPS workspaces" className="flex gap-2 overflow-x-auto rounded-xl border border-slate-200 bg-white p-2 shadow-sm">
        {tabs.map((item) => (
          <button key={item.key} type="button" onClick={() => changeTab(item.key)} aria-current={tab === item.key ? 'page' : undefined}
            className={`shrink-0 rounded-lg px-4 py-2 text-sm font-semibold transition ${tab === item.key ? 'bg-navy-800 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100'}`}>
            {item.label}{item.key === 'reviews' && counts.pending_review > 0 ? ` (${counts.pending_review})` : ''}
          </button>
        ))}
      </nav>

      {tab !== 'property' && tab !== 'reviews' && (
        <section aria-label="GPS record summary" className="grid grid-cols-2 gap-3 md:grid-cols-4">
          {[
            ['GPS records', counts.total, 'text-navy-800'],
            ['Pending review', counts.pending_review, 'text-amber-700'],
            ['Certified', counts.approved, 'text-emerald-700'],
            ['Rejected', counts.rejected, 'text-red-700'],
          ].map(([label, value, tone]) => (
            <div key={label} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p>
              <p className={`mt-1 text-2xl font-extrabold ${tone}`}>{value}</p>
            </div>
          ))}
        </section>
      )}

      {(tab === 'registry' || tab === 'certified' || tab === 'rejected' || tab === 'history') && (
        <section className="space-y-4">
          <form onSubmit={applyFilters} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-2 xl:grid-cols-6">
            <label className="xl:col-span-2">
              <span className="mb-1 block text-xs font-semibold text-slate-600">Property, address, or officer</span>
              <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search GPS records" className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            </label>
            <label>
              <span className="mb-1 block text-xs font-semibold text-slate-600">Tax district</span>
              <input value={district} onChange={(event) => setDistrict(event.target.value)} placeholder="District name" className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            </label>
            {(canApprove || can('gps.view_history')) && <label>
              <span className="mb-1 block text-xs font-semibold text-slate-600">Officer ID</span>
              <input value={officerId} onChange={(event) => setOfficerId(event.target.value)} inputMode="numeric" placeholder="Optional" className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            </label>}
            <label>
              <span className="mb-1 block text-xs font-semibold text-slate-600">Captured from</span>
              <input value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} type="date" className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            </label>
            <label>
              <span className="mb-1 block text-xs font-semibold text-slate-600">Captured to</span>
              <input value={dateTo} onChange={(event) => setDateTo(event.target.value)} type="date" className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            </label>
            <div className="flex items-end gap-2 xl:col-span-6">
              <button type="submit" className="rounded-lg bg-navy-800 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">Apply filters</button>
              <button type="button" onClick={() => { setSearch(''); setDistrict(''); setOfficerId(''); setDateFrom(''); setDateTo(''); setPage(1) }} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</button>
            </div>
          </form>

          {listError && <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><span>{listError}</span><button type="button" onClick={loadList} className="font-bold underline">Retry</button></div>}
          {loadingList ? <div className="rounded-xl border border-slate-200 bg-white px-5 py-12 text-center text-sm text-slate-500">Loading GPS records…</div> : (
            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
              <div className="overflow-x-auto">
                <table className="min-w-[900px] w-full text-left text-sm">
                  <thead className="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                    <tr><th className="px-4 py-3">Property</th><th className="px-4 py-3">Address / district</th><th className="px-4 py-3">Coordinates</th><th className="px-4 py-3">Accuracy</th><th className="px-4 py-3">Captured</th><th className="px-4 py-3">Submitted by</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Details</th></tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {records.map((record) => (
                      <tr key={record.id} className="hover:bg-slate-50">
                        <td className="px-4 py-3 font-mono font-semibold text-navy-800">{record.property_id}</td>
                        <td className="px-4 py-3"><div>{record.property?.address || 'Address unavailable'}</div><div className="text-xs text-slate-500">{record.property?.tax_district || 'District unavailable'}</div></td>
                        <td className="px-4 py-3 font-mono text-xs">{fmt(record.new_latitude)}, {fmt(record.new_longitude)}</td>
                        <td className="px-4 py-3">{record.accuracy == null ? 'Not reported' : `${record.accuracy} m`}</td>
                        <td className="px-4 py-3 text-xs">{dt(record.captured_at)}</td>
                        <td className="px-4 py-3">{record.captured_by || 'Not available'}</td>
                        <td className="px-4 py-3"><StatusBadge status={record.verification_status} /></td>
                        <td className="px-4 py-3"><button type="button" onClick={() => setSelected(record)} className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold hover:bg-slate-100">View</button></td>
                      </tr>
                    ))}
                    {!records.length && <tr><td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-500">{tab === 'certified' ? 'No certified GPS records match these filters.' : tab === 'rejected' ? 'No rejected records match these filters.' : 'No GPS records match these filters.'}</td></tr>}
                  </tbody>
                </table>
              </div>
              {lastPage > 1 && <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-xs text-slate-500"><span>Page {page} of {lastPage}</span><div className="flex gap-2"><button type="button" disabled={page <= 1} onClick={() => setPage((value) => value - 1)} className="rounded-md border px-3 py-1.5 disabled:opacity-40">Previous</button><button type="button" disabled={page >= lastPage} onClick={() => setPage((value) => value + 1)} className="rounded-md border px-3 py-1.5 disabled:opacity-40">Next</button></div></div>}
            </div>
          )}
        </section>
      )}

      {tab === 'reviews' && (
        <section ref={reviewsRef} className="space-y-4">
          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-2"><div><h2 className="text-lg font-bold text-navy-800">Certification review queue</h2><p className="mt-1 text-sm text-slate-500">Inspect the property identity, captured point, accuracy, and visit context before deciding.</p></div><span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800">{reviewRows.length} loaded</span></div>
          </div>
          {listError && <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><span>{listError}</span><button type="button" onClick={loadList} className="font-bold underline">Retry</button></div>}
          {loadingList ? <div className="rounded-xl border bg-white px-5 py-12 text-center text-sm text-slate-500">Loading certification reviews…</div> : reviewRows.length === 0 ? <div className="rounded-2xl border border-slate-200 bg-white px-5 py-12 text-center"><div className="text-lg font-bold text-slate-700">No pending reviews</div><p className="mt-1 text-sm text-slate-500">New field certifications will appear here when they require review.</p></div> : (
            <div className="grid gap-4 lg:grid-cols-2">
              {reviewRows.map((record) => (
                <article key={record.id} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div><h3 className="font-mono font-bold text-navy-800">{record.property_id}</h3><p className="mt-1 text-sm text-slate-600">{record.property?.address || 'Property address unavailable'}</p></div>
                    <StatusBadge status={record.verification_status} />
                  </div>
                  <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                    <Info label="Tax district" value={record.property?.tax_district || 'Unavailable'} />
                    <Info label="Taxpayer" value={record.property?.taxpayer_name || 'Unavailable'} />
                    <Info label="Captured point" value={`${fmt(record.new_latitude)}, ${fmt(record.new_longitude)}`} />
                    <Info label="Reported accuracy" value={record.accuracy == null ? 'Not reported' : `${record.accuracy} m`} />
                    <Info label="Captured at" value={dt(record.captured_at)} />
                    <Info label="Submitting officer" value={record.captured_by || 'Unavailable'} />
                  </div>
                  {record.visit && <p className="mt-3 rounded-lg bg-slate-50 p-2 text-xs text-slate-600">Visit {record.visit.reference || '—'} · {record.visit.date || 'date unavailable'} · officer {record.visit.officer || 'unavailable'}</p>}
                  {record.remarks && <p className="mt-3 text-xs text-slate-600"><strong>Capture remarks:</strong> {record.remarks}</p>}
                  <div className="mt-3"><MapPreview latitude={record.new_latitude} longitude={record.new_longitude} label={`Captured GPS for ${record.property_id}`} /></div>
                  {record.previous_latitude != null
                    ? <p className="mt-2 text-xs text-slate-500">Prior official/reference point: {fmt(record.previous_latitude)}, {fmt(record.previous_longitude)}. Compare visually; no distance is inferred.</p>
                    : <p className="mt-2 text-xs text-slate-500">No prior official reference coordinate is available; only the captured point can be inspected.</p>}
                  <div className="mt-4 flex flex-wrap justify-end gap-2">
                    <button type="button" onClick={() => setSelected(record)} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold hover:bg-slate-50">Review details</button>
                    {canApprove && <>
                      <button type="button" onClick={() => openDecision(record, 'approve')} className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700">Approve</button>
                      <button type="button" onClick={() => openDecision(record, 'reject')} className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-bold text-red-700 hover:bg-red-100">Reject</button>
                    </>}
                  </div>
                  {!canApprove && <p className="mt-2 text-right text-xs text-slate-500">Read-only review access; approval authority is not assigned to your role.</p>}
                </article>
              ))}
            </div>
          )}
          {lastPage > 1 && <div className="flex justify-end gap-2"><button type="button" disabled={page <= 1} onClick={() => setPage((value) => value - 1)} className="rounded-md border bg-white px-3 py-2 text-xs disabled:opacity-40">Previous</button><button type="button" disabled={page >= lastPage} onClick={() => setPage((value) => value + 1)} className="rounded-md border bg-white px-3 py-2 text-xs disabled:opacity-40">Next</button></div>}
        </section>
      )}

      {tab === 'property' && (
        <section className="space-y-4">
          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 className="font-bold text-navy-800">Property GPS lookup</h2>
            <p className="mt-1 text-sm text-slate-500">Load a source-system property ID to inspect its official location, captures, and authorized actions.</p>
            <form className="mt-4 flex flex-col gap-2 sm:flex-row" onSubmit={(event) => { event.preventDefault(); loadProperty() }}>
              <input value={propertyId} onChange={(event) => updatePropertyId(event.target.value)} placeholder="Property ID (e.g. PRP-07000001)" aria-label="Property ID" className="min-w-0 flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
              <button type="submit" disabled={propertyLoading} className="rounded-xl bg-brand-500 px-5 py-2.5 text-sm font-bold text-white hover:bg-brand-600 disabled:opacity-60">{propertyLoading ? 'Searching…' : 'Load property'}</button>
            </form>
          </div>

          {propertyLoading && <div className="rounded-xl bg-white px-5 py-8 text-center text-sm text-slate-500">Loading property GPS…</div>}
          {loaded && (
            <>
              <div className="grid gap-4 lg:grid-cols-2">
                <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                  <div className="flex items-center justify-between"><h3 className="font-bold">Official location · {loaded}</h3>{official && <StatusBadge status="approved" />}</div>
                  {official ? <>
                    <div className="grid grid-cols-3 gap-2"><Info label="Latitude" value={fmt(official.latitude)} /><Info label="Longitude" value={fmt(official.longitude)} /><Info label="Accuracy" value={official.accuracy == null ? 'Not reported' : `${official.accuracy} m`} /></div>
                    <p className="text-xs text-slate-500">Source {official.source || 'unavailable'} · captured {dt(official.captured_at)} · updated {dt(official.updated_at)}</p>
                    <MapPreview latitude={official.latitude} longitude={official.longitude} label={`Official GPS for ${loaded}`} />
                    <div className="flex flex-wrap gap-2"><a href={`https://www.openstreetmap.org/?mlat=${official.latitude}&mlon=${official.longitude}#map=18/${official.latitude}/${official.longitude}`} target="_blank" rel="noreferrer" className="rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold hover:bg-slate-200">OpenStreetMap ↗</a><a href={`https://www.google.com/maps/dir/?api=1&destination=${official.latitude},${official.longitude}`} target="_blank" rel="noreferrer" className="rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold hover:bg-slate-200">Directions ↗</a></div>
                  </> : <p className="text-sm text-slate-500">No official GPS recorded for this property.</p>}
                  {pendingCount > 0 && <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">{pendingCount} certification(s) waiting for approval.</p>}
                  {!!recent.length && <div><h4 className="mb-2 text-xs font-bold uppercase text-slate-500">Recent field captures</h4>{recent.map((item) => <p key={item.id} className="border-b border-slate-100 py-2 text-xs text-slate-600">{item.visit_reference} · {item.gps_coordinate} · {item.gps_accuracy == null ? 'accuracy unavailable' : `${item.gps_accuracy} m`} · {item.gps_verified || 'uncertified'}</p>)}</div>}
                </section>

                {canCapture && (
                  <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 className="font-bold">Capture and certify</h3>
                    {visits.length > 0 && <label className="block text-xs font-semibold text-slate-600">Attach to an open field visit (optional)<select value={attachVisit} onChange={(event) => setAttachVisit(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">Standalone capture</option>{visits.map((visit) => <option key={visit.visit_id} value={visit.visit_id}>{visit.visit_reference}{visit.gps_coordinate ? ` — ${visit.gps_coordinate}` : ''}</option>)}</select></label>}
                    <button type="button" disabled={capturing || busy} onClick={grabLocation} className="w-full rounded-xl bg-navy-800 px-4 py-3 text-sm font-bold text-white hover:bg-navy-700 disabled:opacity-60">{capturing ? 'Acquiring GPS…' : '📍 Get current location'}</button>
                    <div className="grid grid-cols-2 gap-3"><CoordinateInput label="Latitude" value={capture.latitude} onChange={(value) => setCapture((state) => ({ ...state, latitude: value }))} placeholder="-0.5158889" /><CoordinateInput label="Longitude" value={capture.longitude} onChange={(value) => setCapture((state) => ({ ...state, longitude: value }))} placeholder="36.0901234" /></div>
                    <CoordinateInput label="Reported accuracy (m)" value={capture.accuracy} onChange={(value) => setCapture((state) => ({ ...state, accuracy: value }))} placeholder="Device reported value" />
                    <p className="text-xs text-slate-500">Accuracy is recorded as reported; no agency acceptance threshold is configured.</p>
                    <label className="block text-xs font-semibold text-slate-600">Capture remarks<textarea value={capture.remarks} onChange={(event) => setCapture((state) => ({ ...state, remarks: event.target.value }))} rows={2} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Optional observation; required for ‘not the property’." /></label>
                    <div className="grid gap-2 sm:grid-cols-2"><button type="button" disabled={busy || !capture.latitude || !capture.longitude} onClick={() => certify('yes')} className="rounded-xl bg-emerald-600 px-3 py-2.5 text-sm font-bold text-white disabled:opacity-50">{busy ? 'Submitting…' : 'Certify actual location'}</button><button type="button" disabled={busy || !capture.latitude || !capture.longitude || !capture.remarks.trim()} onClick={() => certify('no')} className="rounded-xl border border-red-200 bg-red-50 px-3 py-2.5 text-sm font-bold text-red-700 disabled:opacity-50">Not the property</button></div>
                    <p className="text-xs text-slate-500">{canUpdate ? 'You have update rights; “yes” applies the official location immediately.' : 'A “yes” certification waits for authorized supervisory approval.'}</p>
                  </section>
                )}
              </div>

              {canUpdate && <section className="space-y-3 rounded-2xl border border-amber-200 bg-amber-50/60 p-5">
                <h3 className="font-bold text-amber-900">Authorized correction</h3>
                <div className="grid gap-3 sm:grid-cols-3"><CoordinateInput label="Latitude" value={correction.latitude} onChange={(value) => setCorrection((state) => ({ ...state, latitude: value }))} placeholder="-0.5160000" /><CoordinateInput label="Longitude" value={correction.longitude} onChange={(value) => setCorrection((state) => ({ ...state, longitude: value }))} placeholder="36.0903000" /><CoordinateInput label="Accuracy (m)" value={correction.accuracy} onChange={(value) => setCorrection((state) => ({ ...state, accuracy: value }))} placeholder="Reported value" /></div>
                <label className="block text-xs font-semibold text-slate-600">Reason for correction<textarea value={correction.remarks} onChange={(event) => setCorrection((state) => ({ ...state, remarks: event.target.value }))} rows={2} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" /></label>
                <button type="button" disabled={busy} onClick={correct} className="rounded-lg bg-amber-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-60">{busy ? 'Applying…' : 'Apply correction'}</button>
              </section>}

              <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="font-bold">Location history</h3>{!canHistory && <span className="text-xs text-amber-700">History permission required</span>}</div>
                {historyError && <p role="alert" className="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{historyError}</p>}
                {canHistory && !historyError && !history.length && <p className="mt-3 text-sm text-slate-500">No recorded history.</p>}
                {canHistory && history.map((item) => <div key={item.id} className="flex gap-3 border-b border-slate-100 py-3 text-sm"><span className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${item.verification_status === 'approved' ? 'bg-emerald-500' : item.verification_status === 'rejected' ? 'bg-red-500' : item.verification_status === 'not_property' ? 'bg-amber-500' : 'bg-slate-400'}`} /><div className="min-w-0 flex-1"><div className="flex flex-wrap gap-x-3 text-xs"><span className="font-mono">{fmt(item.new_latitude)}, {fmt(item.new_longitude)}</span><span className="text-slate-400">{item.source}</span><span className="text-slate-400">{dt(item.captured_at)}</span><StatusBadge status={item.verification_status} /></div><p className="mt-1 text-xs text-slate-600">{item.remarks || 'No capture remarks'}{item.review_remarks ? ` · Reviewer: ${item.review_remarks}` : ''}</p></div></div>)}
              </section>
            </>
          )}
        </section>
      )}

      {selected && (
        <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/50 p-3 sm:p-6" onMouseDown={(event) => { if (event.target === event.currentTarget) setSelected(null) }}>
          <section role="dialog" aria-modal="true" aria-labelledby="gps-detail-title" className="my-auto max-h-[92vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl">
            <div className="flex items-start justify-between gap-3"><div><h2 id="gps-detail-title" className="text-lg font-bold text-navy-800">GPS certification details</h2><p className="mt-1 font-mono text-sm">{selected.property_id}</p></div><button type="button" onClick={() => setSelected(null)} aria-label="Close details" className="rounded-lg px-3 py-1 text-xl text-slate-500 hover:bg-slate-100">×</button></div>
            <div className="mt-4 grid gap-4 lg:grid-cols-2">
              <div className="space-y-3">
                <Info label="Property address" value={selected.property?.address || 'Unavailable'} />
                <Info label="Tax district" value={selected.property?.tax_district || 'Unavailable'} />
                <Info label="Taxpayer/property context" value={selected.property?.taxpayer_name || 'Unavailable'} />
                <Info label="Coordinates" value={`${fmt(selected.new_latitude)}, ${fmt(selected.new_longitude)}`} />
                <Info label="Reported accuracy" value={selected.accuracy == null ? 'Not reported' : `${selected.accuracy} m`} />
                <Info label="Captured" value={dt(selected.captured_at)} />
                <Info label="Submitted by" value={selected.captured_by || 'Unavailable'} />
                <Info label="Source" value={selected.source || 'Unavailable'} />
                <Info label="Capture remarks" value={selected.remarks || 'None provided'} />
                <Info label="Visit" value={selected.visit?.reference || 'No linked visit information'} />
                <Info label="Prior official point" value={selected.previous_latitude == null ? 'Unavailable' : `${fmt(selected.previous_latitude)}, ${fmt(selected.previous_longitude)}`} />
                {selected.review_remarks && <Info label="Reviewer remarks" value={selected.review_remarks} />}
              </div>
              <MapPreview latitude={selected.new_latitude} longitude={selected.new_longitude} label={`GPS details for ${selected.property_id}`} />
            </div>
            <div className="mt-5 flex flex-wrap justify-end gap-2"><button type="button" onClick={() => setSelected(null)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">Close</button>{tab === 'reviews' && canApprove && selected.verification_status === 'pending_review' && <><button type="button" onClick={() => openDecision(selected, 'approve')} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white">Approve</button><button type="button" onClick={() => openDecision(selected, 'reject')} className="rounded-lg bg-red-50 px-4 py-2 text-sm font-bold text-red-700">Reject</button></>}</div>
          </section>
        </div>
      )}

      {decision && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/50 p-4">
          <section role="dialog" aria-modal="true" aria-labelledby="gps-decision-title" className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl">
            <h2 id="gps-decision-title" className="text-lg font-bold text-navy-800">{decision.action === 'approve' ? 'Approve GPS certification?' : 'Reject GPS certification?'}</h2>
            <p className="mt-2 text-sm text-slate-600">Property <strong>{decision.record.property_id}</strong> · {fmt(decision.record.new_latitude)}, {fmt(decision.record.new_longitude)} · accuracy {decision.record.accuracy == null ? 'not reported' : `${decision.record.accuracy} m`}</p>
            <p className="mt-2 text-xs text-slate-500">{decision.record.property?.address || 'Property address unavailable'}</p>
            {decision.action === 'reject' && <label className="mt-4 block text-sm font-semibold text-slate-700">Rejection reason <textarea autoFocus value={reviewRemarks} onChange={(event) => setReviewRemarks(event.target.value)} rows={4} maxLength={2000} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal focus:outline-none focus:ring-2 focus:ring-brand-500" placeholder="Explain what needs to be corrected." /></label>}
            {decision.action === 'approve' && <p className="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">Approval will make this captured coordinate the official GPS location for this property.</p>}
            <div className="mt-5 flex justify-end gap-2"><button type="button" disabled={busy} onClick={() => setDecision(null)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">Cancel</button><button type="button" disabled={busy || (decision.action === 'reject' && !reviewRemarks.trim())} onClick={submitDecision} className={`rounded-lg px-4 py-2 text-sm font-bold text-white disabled:opacity-50 ${decision.action === 'approve' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-red-600 hover:bg-red-700'}`}>{busy ? 'Saving…' : decision.action === 'approve' ? 'Confirm approval' : 'Confirm rejection'}</button></div>
          </section>
        </div>
      )}
    </div>
  )
}

function Info({ label, value }) {
  return <div className="rounded-lg bg-slate-50 px-3 py-2"><div className="text-[10px] font-bold uppercase tracking-wide text-slate-500">{label}</div><div className="mt-0.5 break-words text-xs font-medium text-slate-800">{value}</div></div>
}

function CoordinateInput({ label, value, onChange, placeholder }) {
  return <label className="block text-xs font-semibold text-slate-600">{label}<input type="number" step="any" value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal focus:outline-none focus:ring-2 focus:ring-brand-500" /></label>
}
