import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import api, { unwrap, errMsg, apiPath } from '../api'
import { useAuth } from '../auth'
  import { Badge, Btn, Input, billCaseTone, statusTone, fmtMoney, fmtValue, fmtTime, fmtNum, roleName } from '../ui'
import { DISCOVERY_STATUSES, DISCOVERY_ROUTE_LABEL, discoveryStatusInfo, VALUATION_LADDER, valuationStatusInfo, valueDeclarationInfo } from '../lib/constants'
import { isLandRow } from '../lib/valuationRows'
import GatedImage from './GatedImage'
import DiscoveryCorrectionForm from './DiscoveryCorrectionForm'
import PropertyRecordExport from './PropertyRecordExport'
/**
 * Single shared "property" detail view — the one place a property is rendered.
 *
 * Two ways in, one set of rules out:
 *
 * - record mode  — a discovery and/or valuation snapshot is handed in by a
 * queue screen (Valuations, Discoveries) that already holds
 * the row.
 * - property mode — a lookup key is handed in and the whole lifecycle is
 * fetched from GET /property-profile, so a bill, a discovery
 * and a reassessment are presented as one property instead of
 * three unrelated datasets.
 *
 * Either way the same sections render, the same value rules apply, and a
 * candidate GPS coordinate is never shown as the official property location.
 *
 * Props:
 * discovery            present() discovery snapshot (record mode, may be null)
 * valuation            present() valuation snapshot (record mode, may be null)
 * propertyKey          { property_id | document_number | bill_id } (property mode)
 * title                header caption override
 * runAction(verb,payload)      discovery workflow verbs (bound to id by the parent)
 * runValuationAction(verb,payload) valuation workflow verbs
 * onActionDone(verb, result)   notified after an action succeeds
 * assignmentUsers      user list for officer/assignor pickers
 * onClose              close handler
 */
export default function PropertyDetail({
  discovery,
  valuation,
  propertyKey,
  title,
  onClose,
  runAction,
  runValuationAction,
  onActionDone,
  assignmentUsers = []
}) {
  const {
    can,
    user
  } = useAuth()
  const navigate = useNavigate()
  const [photos, setPhotos] = useState(null)
  const [profile, setProfile] = useState(null)
  const [profileErr, setProfileErr] = useState('')
  const propertyMode = !!propertyKey
  const key = propertyKey ? JSON.stringify(propertyKey) : ''
  useEffect(() => {
    if (!propertyMode) return setProfile(null)
    setProfileErr('')
    const params = {}
    if (propertyKey.bill_id) params.bill_id = propertyKey.bill_id
    if (propertyKey.property_id) params.property_id = propertyKey.property_id
    if (propertyKey.document_number) params.document_number = propertyKey.document_number
    if (propertyKey.source_reference) params.source_reference = propertyKey.source_reference
    api.get('/property-profile', {
      params
    }).then((r) => setProfile(unwrap(r).data)).catch((e) => setProfileErr(errMsg(e, 'Unable to load this property.')))
  }, [key])
  // Property mode reads the aggregate; record mode keeps the handed-in rows.
  const agg = profile
  const d = propertyMode ? agg?.discoveries?.[0] ?? null : discovery
  const v = propertyMode ? agg?.valuations?.[0] ?? null : valuation
  const ident0 = agg?.identity
  const discoveryId = d?.id
  const valuationId = v?.id
  const [detail, setDetail] = useState(null)
  useEffect(() => {
    if (propertyMode || !discoveryId) return
    // The discovery list deliberately omits evidence, so the handed-in row has
    // no photographs. The full record is fetched once when the view opens;
    // eager-loading evidence on every list row would tax all list screens to
    // serve one modal.
    api.get(`/discoveries/${discoveryId}`).then((r) => setDetail(unwrap(r).data)).catch(() => setDetail(null))
  }, [propertyMode, discoveryId])
  useEffect(() => {
    if (propertyMode) return
    const src = detail?.evidence ?? d?.evidence
    if (!src) return
    // NOTE: the legacy evidence_photos store is NOT the discovery's property
    // photograph set. Discovery property photographs live in
    // property_discovery_evidence as PROPERTY_PHOTO rows, so counting that
    // table reported "(0)" while three photos existed. Legacy rows are only
    // used when the record genuinely has no evidence photographs, and are never
    // mixed into the property-photo count.
    const fromEvidence = evidencePhotos(src)
    if (fromEvidence.length) {
      setPhotos(fromEvidence)
      return
    }
    const legacy = (detail?.legacy_photos || d?.legacy_photos || []).map((p) => normalizeLegacyPhoto(p, valuationId))
    setPhotos(legacy)
  }, [propertyMode, detail, d?.evidence, d?.legacy_photos, valuationId])
  if (propertyMode && !agg) {
    return <ModalShell onClose={onClose} title={title || 'Property'}>

{profileErr ? <p className="text-sm text-red-600">
{profileErr}
</p> : <p className="text-sm text-slate-400">
Loading property…</p>}
      </ModalShell>
  }
  // A bill-only property has no discovery or valuation, yet the bill is exactly
  // why the drawer is open. In property mode the aggregate stands on its own.
  const hasRecord = !!d || !!v
  if (!hasRecord && !propertyMode) return null
  const info = d ? discoveryStatusInfo(d.status) : v ? valuationStatusInfo(v.status) : agg?.status ? {
    label: String(agg.status).replace(/_/g, ' '),
    tone: billCaseTone(agg.status)
  } : null
  const flow = (d?.workflow && d.workflow.length ? d.workflow : null) || (d ? DISCOVERY_STATUSES : VALUATION_LADDER)
  const curIdx = d ? Math.max(0, flow.lastIndexOf(d.status)) : v ? Math.max(0, flow.indexOf(v.status)) : 0
  const prev = curIdx > 0 ? flow[curIdx - 1] : null
  // Identity resolves across record types, so an unregistered property still
  // shows its owner and address, and a registered one still shows them.
  const owner = d?.owner_name || v?.owner_name || ident0?.owner_name
  const address = d?.property_address || v?.property_address || ident0?.property_address
  const gpsAgg = agg?.gps
  const gps = gpsAgg?.coordinate || d?.gps_coordinate || v?.gps_coordinate
  const mapLink = gps ? `https://www.google.com/maps?q=${encodeURIComponent(gps)}` : address ? `https://www.google.com/maps?q=${encodeURIComponent(address)}` : null
  const goValuation = () => {
    if (!v) return navigate(`/valuations?focus=${v.id}&status=${encodeURIComponent(v.status)}`)
    onClose?.()
  }
  const goDiscovery = () => {
    if (!d) return navigate(`/enforcement?tab=discoveries&focus=${d.id}`)
    onClose?.()
  }
  const ident = [['Source Reference ID', d?.source_reference || v?.source_reference || ident0?.source_reference], ['Discovery ID', d?.discovery_reference], ['Valuation Ref', v?.valuation_reference], ['Property ID (source)', d?.property_id || v?.property_id || ident0?.property_id], ['Document # (source)', d?.document_number || v?.document_number || ident0?.document_number], ['TIN', d?.tin || v?.tin || ident0?.tin], ['Classification', d?.property_classification || v?.property_classification || ident0?.property_classification], ['Property type', d?.property_type || ident0?.property_type], ['Occupancy / use', d?.occupancy_use || ident0?.occupancy_use]].filter(([, x]) => x !== null && x !== undefined && x !== '')
  const ownerRows = [['Owner / occupant', owner], ['Contact', d?.owner_contact || v?.owner_contact || ident0?.owner_contact], ['Discovery date', d?.discovery_date], ['Discovered by', d?.discovered_by], ['Classified by', d?.classified_by], ['Classification decision', d?.classification_decision], ['Manager remarks', d?.manager_remarks], ['Remarks', d?.remarks], ['Processed (source)', d?.processed_at], ['Completed', d?.completed_at]].filter(([, x]) => x !== null && x !== undefined && x !== '')
  const valRows = [['Valuation status', v?.status], ['Valuation officer', v?.valuation_officer], ['Assessment date', v?.assessment_date], ['Declared value', v?.declared_value], ['Reassessed value', v?.reassessed_value], ['Assessed value', v?.assessed_value], ['Annual tax', v?.annual_tax], ['Tax rate (%)', fmtNum(v?.applicable_tax_rate)], ['Other amounts', v?.other_amounts], ['Total property value', v?.total_property_value], ['Total tax payable', v?.total_tax_payable], ['Manager reviewed', v?.manager_reviewed_at], ['Manager remarks', v?.manager_remarks], ['AC reviewed', v?.ac_reviewed_at], ['AC remarks', v?.ac_remarks], ['Registration status', v?.registration_status]].filter(([, x]) => x !== null && x !== undefined && x !== '')
  const descriptions = v?.descriptions || []
  const decl = d?.value_declaration_type ? valueDeclarationInfo(d.value_declaration_type) : null
  const evidenceList = propertyMode ? agg?.evidence || [] : detail?.evidence ?? d?.evidence ?? []
  // Photographs and documentary evidence are different things and are counted
  // from their own rows. A single mixed tally is what let three photographs be
  // reported as zero.
  const documentList = evidenceList.filter((e) => e.evidence_type !== 'PROPERTY_PHOTO')
  const photoList = propertyMode ? (agg?.photos || []).map(normalizeAggregatePhoto) : photos
  // An officer must never confuse a discovery with the valuation or
  // reassessment that followed it, so the record type is stated outright.
  const recordTypeLabel = v ? v.valuation_type === 'reassessment' ? 'REASSESSMENT' : 'VALUATION ASSESSMENT' : d ? 'PROPERTY DISCOVERY' : agg?.record?.record_type === 'bill' ? 'BILL' : 'PROPERTY'
  // Three separate-but-related views over the same lifecycle. A discovery is
  // not a valuation, and neither is a property bill: the authoritative
  // property_id / document_number / bill_id only exist once the source system
  // has registered the property and issued a bill. Conflating the three is how
  // an officer ends up looking for a bill that was never generated.
  const view = propertyMode ? 'registered_property' : v ? 'valuation_assessment' : 'discovery'
  // The identifiers the source system has issued so far, if any. Never
  // derived or inferred — a discovery with no registration simply has none.
  const registered = {
    propertyId: ident0?.property_id || d?.property_id || v?.property_id || null,
    documentNumber: ident0?.document_number || d?.document_number || v?.document_number || null,
    billId: agg?.record?.record_type === 'bill' ? agg?.record?.id ?? null : v?.bill_id ?? d?.bill_id ?? null
  }
  const isRegistered = !!(registered.propertyId || registered.documentNumber || registered.billId)
  // Discovery -> Valuation -> Registration -> Bill. Once registration exists an
  // authorised user moves on to the registered view from the same header, so
  // the chain is navigable without re-searching or re-keying the record.
  const goRegistered = () => {
    const params = new URLSearchParams()
    if (registered.billId) params.set('bill_id', String(registered.billId))
else if (registered.documentNumber) params.set('document_number', registered.documentNumber)
else if (registered.propertyId) params.set('property_id', String(registered.propertyId))
    navigate(`/properties?${params.toString()}`)
    onClose?.()
  }
  const gpsOfficial = gpsAgg ? !!gpsAgg.is_official : d?.gps_verified === 'yes'
  const gpsSource = d?.gps_coordinate ? 'Discovery GPS' : gpsAgg?.source || null
  // Values are never collapsed into one "property value": each figure keeps the
  // source it came from, so a self-declaration is never mistaken for an
  // assessment and an assessment is never mistaken for a registration.
  const val = agg?.value
  const declared = val ? val.declared_value : d?.declared_value
  const declaredAssessed = val ? val.declared_assessed_value : d?.declared_assessed_value
  const documentary = val ? val.documentary_value : d?.documentary_value
  const officialAssessed = val ? val.official_assessed_value : d?.official_assessed_value
  const valHistory = val?.history || []
  return <div className="fixed inset-0 z-50 bg-navy-950/60 backdrop-blur-sm grid place-items-center p-4 overflow-y-auto">

<div className="bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[92vh] overflow-y-auto">

{
        /* Header */
      }
        <div className="px-6 py-4 border-b border-slate-100 flex items-start justify-between gap-3 sticky top-0 bg-white z-10">

<div className="min-w-0">

<div className="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-600">

{view === 'registered_property' ? 'REGISTERED PROPERTY VIEW' : view === 'valuation_assessment' ? 'VALUATION ASSESSMENT VIEW' : 'DISCOVERY PROPERTY VIEW'}
            </div>
<div className="flex items-center gap-2 flex-wrap mt-0.5">

<h3 className="font-bold text-navy-800">

{title || d?.discovery_reference || v?.valuation_reference || ident0?.property_id || 'Property'}
              </h3>
{info && <Badge tone={info.tone}>
{info.label}
</Badge>}
              {d?.route && <Badge tone="navy">
{DISCOVERY_ROUTE_LABEL[d.route] || `Path ${d.route.slice(0, 1).toUpperCase()}`}
</Badge>}
              {v && <Badge tone="blue">
{v.valuation_reference}
 · {v.status}
</Badge>}
            </div>
<div className="flex items-center gap-x-3 gap-y-1 flex-wrap mt-1 text-[11px] text-slate-500">

{ident0?.property_id || d?.property_id || v?.property_id ? <span>
Property ID <span className="font-mono font-semibold text-slate-700">
{ident0?.property_id || d?.property_id || v?.property_id}
</span></span> : null}
{ident0?.source_reference || d?.source_reference || v?.source_reference ? <span>
Source Ref <span className="font-mono font-semibold text-slate-700">
{ident0?.source_reference || d?.source_reference || v?.source_reference}
</span></span> : null}
              {ident0?.tin || d?.tin || v?.tin ? <span>
TIN <span className="font-mono font-semibold text-slate-700">
{ident0?.tin || d?.tin || v?.tin}
</span></span> : null}
              {ident0?.document_number || d?.document_number ? <span>
Registry Document # <span className="font-mono font-semibold text-slate-700">
{ident0?.document_number || d?.document_number}
</span></span> : null}
              {ident0?.property_classification || d?.property_classification ? <span>
{ident0?.property_classification || d?.property_classification}
</span> : null}
              {ident0?.property_type || d?.property_type ? <span>
{ident0?.property_type || d?.property_type}
</span> : null}
            </div>
{
            /* Registration state, stated rather than implied. Before the                source system registers the property there is deliberately no                property_id, document number or bill to show, and saying so                stops a pending record reading as a data gap. */
          }
            {view !== 'registered_property' && <div className="text-[11px] text-slate-500 mt-1">

{isRegistered ? 'Registered with the source system — authoritative identifiers available.' : 'Not yet registered with the source system — no property ID, registry document number or tax bill exists yet.'}
              </div>}
          </div>
<div className="flex items-center gap-2 shrink-0">

{v && <Btn tone="blue" className="!px-2.5 !py-1 !text-xs" onClick={goValuation}>
Open valuation →</Btn>}
            {d && <Btn tone="white" className="!px-2.5 !py-1 !text-xs" onClick={goDiscovery}>
↳ View discovery</Btn>}
            {view !== 'registered_property' && isRegistered && <Btn tone="white" className="!px-2.5 !py-1 !text-xs" onClick={goRegistered}>
→ Registered property</Btn>}
            {/* The official sheet belongs to whichever record is on screen. A
                discovery and a valuation are different documents, so the control
                follows the record rather than offering both. */}
            {v && <PropertyRecordExport recordType="valuation" recordId={v.id} label="property-valuation-record" />}
            {!v && d && <PropertyRecordExport recordType="discovery" recordId={d.id} label="property-discovery-record" />}
            <button onClick={onClose} className="w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-500 font-bold">
✕</button>          </div>        </div>
<div className="p-6 space-y-6">

{
          /* Workflow ladder - only meaningful when a discovery/valuation exists.              A bill-only property has no ladder, and showing an all-greyed              valuation ladder there would assert a progress never recorded. */
        }
          {hasRecord && <Section title="Workflow">

<div className="flex items-center justify-between mb-2">

<span className="text-xs font-bold uppercase tracking-wider text-slate-400">
Route-driven stages</span>
{prev && <span className="text-[11px] text-slate-400">
Previous: <Badge tone={info?.tone || 'slate'}>
{d ? discoveryStatusInfo(prev).label : valuationStatusInfo(prev).label}
</Badge></span>}
              </div>
<div className="flex flex-wrap gap-1.5">

{flow.map((s, i) => {
              const isCurrent = i === curIdx
              const fired = !!d?.ac_decision && ['Rejected', 'AC Rejected', 'Returned for Correction', 'AC Returned'].includes(s)
              const entered = i < curIdx || fired
              const tone = isCurrent ? 'bg-brand-500 text-white ring-2 ring-brand-200' : entered ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400'
              const label = d ? discoveryStatusInfo(s).label : valuationStatusInfo(s).label
              return <span key={i} className={`px-2.5 py-1 rounded-lg text-[10px] font-semibold whitespace-nowrap ${tone}`}>

{isCurrent ? '● ' : entered ? '✓ ' : '○ '}
{label}
                    </span>
            })}
              </div>            </Section>}
          {
          /* The property is the subject: information on the left, what it              actually looks like on the right. */
        }
          <div className="grid lg:grid-cols-2 gap-6 items-start">

<div className="space-y-4">

{ident.length > 0 && <Section title="Identification">

<InfoGrid rows={ident} />
                </Section>}
              <Section title="Owner information">

<InfoGrid rows={ownerRows} />
              </Section>
<Section title="Address">

<div className="text-sm text-navy-800">
{address || '—'}
</div>
<div className="text-xs text-slate-500 mt-0.5">

{[ident0?.county ?? d?.county, ident0?.district ?? d?.district, ident0?.city_town ?? d?.city_town, ident0?.community ?? d?.community, [ident0?.street ?? d?.street, ident0?.house_number ?? d?.house_number].filter(Boolean).join(' / ')].filter(Boolean).join(' · ') || 'No location details'}
                </div>              </Section>            </div>
<Section title={`Property Photos${photoList ? ` (${photoList.length})` : ''}`}>

{photoList === null ? <p className="text-sm text-slate-400">
Loading photographs…</p> : <PhotoGallery photos={photoList} officialGps={gps} />}
            </Section>          </div>
{
          /* Value declaration. Each figure keeps its own source, and a missing              assessment reads as "Not yet assessed" rather than as a zero. */
        }
          {(d || v || val) && <Section title="Value declaration">

<div className="rounded-xl border border-slate-200 overflow-hidden">

<div className="px-4 py-3 bg-slate-50 flex items-center justify-between gap-3 flex-wrap">

<span className="text-sm font-semibold text-navy-800">
{decl ? decl.label : 'Not declared'}
</span>
{decl && <Badge tone={decl.tone}>
{decl.label}
</Badge>}
                </div>
<div className="px-4 py-3 grid sm:grid-cols-3 gap-3 text-xs">

<div>

<div className="text-slate-400 uppercase tracking-wide">
Declared / assessed</div>
<div className="font-semibold text-navy-800">
{fmtValue(declaredAssessed ?? declared, 'No declared value')}
</div>                  </div>
<div>

<div className="text-slate-400 uppercase tracking-wide">
Documentary</div>
<div className="font-semibold text-navy-800">
{fmtValue(documentary, 'No documentary value')}
</div>                  </div>
<div>

<div className="text-slate-400 uppercase tracking-wide">
Official assessed</div>
<div className={`font-semibold ${officialAssessed === null || officialAssessed === undefined ? 'text-slate-400 italic' : 'text-emerald-700'}`}>

{fmtValue(officialAssessed, 'Not yet assessed')}
                    </div>                  </div>                </div>              </div>
{valHistory.length > 1 && <div className="mt-3 overflow-x-auto border border-slate-200 rounded-xl">

<table className="w-full text-xs">

<thead>

<tr className="text-left text-[11px] uppercase tracking-wider text-slate-400 bg-slate-50">

{['Value', 'Amount', 'Source', 'Date', 'Standing'].map((h) => <th key={h} className="px-2.5 py-2">
{h}
</th>)}
                      </tr>                    </thead>
<tbody className="divide-y divide-slate-100">

{valHistory.map((h, i) => <tr key={i}>

<td className="px-2.5 py-2">
{h.type}
</td>
<td className="px-2.5 py-2 font-semibold">
{fmtMoney(h.amount)}
</td>
<td className="px-2.5 py-2 text-slate-600">
{h.source}
</td>
<td className="px-2.5 py-2 text-slate-600">
{h.date || '—'}
</td>
<td className="px-2.5 py-2">

<Badge tone={h.status === 'Approved' ? 'emerald' : h.status === 'Original' ? 'navy' : 'slate'}>
{h.status}
</Badge>                          </td>                        </tr>)}
                    </tbody>                  </table>                </div>}
            </Section>}
          {
          /* Current task — the one open action on this property */
        }
          {agg?.current_task && <Section title="Current task">

<div className="rounded-xl border border-slate-200 px-4 py-3">

<div className="flex flex-wrap gap-2 items-center">

<span className="font-semibold text-navy-800">
{agg.current_task.task_type}
</span>
<Badge tone="blue">
{agg.current_task.status}
</Badge>                </div>
<div className="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">

<Field label="Assigned to" value={agg.current_task.assigned_to || '—'} />

<Field label="Due" value={agg.current_task.due_date || '—'} />

<Field label="On" value={agg.current_task.reference_type} />

<Field label="Record #" value={agg.current_task.reference_id} />
                </div>              </div>            </Section>}
          {
          /* Property location — an embedded map with the certification state              made explicit, not a bare coordinate behind a link. */
        }
          <Section title="GPS / Property location">

<GpsMap coordinate={gps} accuracy={gpsAgg?.accuracy ?? d?.gps_accuracy} isOfficial={gpsOfficial} officialLabel={gpsAgg?.official_label} source={gpsSource} capturedAt={d?.gps_captured_at || d?.discovery_date} capturedBy={d?.discovered_by} externalUrl={mapLink} />
          </Section>
{
          /* Valuation info */
        }
          {v && <Section title="Valuation information">

<InfoGrid rows={valRows} />
            </Section>}
          {
          /* Property descriptions */
        }
          {v && <Section title={`Property description ${descriptions.length ? `(${descriptions.length})` : ''}`}>

{descriptions.length === 0 ? <p className="text-sm text-slate-400">
No property-description rows recorded.</p> : <div className="overflow-x-auto border border-slate-200 rounded-xl">

<table className="w-full text-xs">

<thead>

<tr className="text-left text-[11px] uppercase tracking-wider text-slate-400 bg-slate-50">

{['No.', 'Property Description', 'Level', 'Area (sq. ft.)', 'TAR', 'Qty / Lots', 'Amount', 'Age', 'Depr. %', 'Value'].map((h) => <th key={h} className="px-2.5 py-2">
{h}
</th>)}
                      </tr>                    </thead>
<tbody className="divide-y divide-slate-100">

{descriptions.map((r, i) => <tr key={r.id ?? i}>

<td className="px-2.5 py-2">
{i + 1}
</td>
<td className="px-2.5 py-2 text-slate-700">
{r.description || '—'}
</td>
<td className="px-2.5 py-2">
{r.level || '—'}
</td>
<td className="px-2.5 py-2">
{r.area_sqft ?? '—'}
</td>
<td className="px-2.5 py-2">
{r.tar == null ? '—' : (isLandRow(r) ? `${r.tar} / lot` : r.tar)}
</td>
<td className="px-2.5 py-2">
{r.quantity ?? '—'}
</td>
<td className="px-2.5 py-2">
{r.amount ?? '—'}
</td>
<td className="px-2.5 py-2">
{r.building_age ?? '—'}
</td>
<td className="px-2.5 py-2">
{r.depreciation_pct ?? '—'}
</td>
<td className="px-2.5 py-2 font-semibold">
{r.value ?? '—'}
</td>                        </tr>)}
                    </tbody>                  </table>                </div>}
            </Section>}
          {
          /* Property history — the lifecycle the account/enforcement views used              to render as three separate lists. */
        }
          {propertyMode && <Section title="Property history">

<div className="space-y-3">

<div>

<div className="text-[11px] uppercase tracking-wider text-slate-400 mb-1.5">
                    Valuation / reassessment {agg.related?.valuations ? `(${agg.valuations.length})` : ''}
                  </div>
{agg.valuations.length === 0 ? <p className="text-sm text-slate-400">
No valuation or reassessment on record.</p> : <div className="space-y-2">

{agg.valuations.map((val2) => <div key={val2.id} className="rounded-xl border border-slate-200 px-4 py-3">

<div className="flex flex-wrap gap-2 items-center">

<span className="font-semibold text-navy-800">
{val2.valuation_reference}
</span>
<Badge tone="slate">
{val2.status}
</Badge>
<Badge tone={val2.record_type === 'reassessment' ? 'amber' : 'navy'}>

{val2.record_type === 'reassessment' ? 'Reassessment' : 'New property'}
                            </Badge>                          </div>
<div className="mt-2 grid grid-cols-2 sm:grid-cols-3 gap-2 text-xs">

<Field label="Assessed value" value={fmtValue(val2.assessed_value)} />

<Field label="Reassessed value" value={fmtValue(val2.reassessed_value, 'Not a reassessment')} />

<Field label="Annual tax" value={fmtMoney(val2.annual_tax)} />

<Field label="Officer" value={val2.valuation_officer || '—'} />

<Field label="Assessment date" value={val2.assessment_date || '—'} />

<Field label="Registration" value={val2.registration_status || '—'} />
                          </div>                        </div>)}
                    </div>}
                </div>
<div>

<div className="text-[11px] uppercase tracking-wider text-slate-400 mb-1.5">
                    Bill {agg.bills.length ? `(${agg.bills.length})` : ''}
                  </div>
{agg.bills.length === 0 ? <p className="text-sm text-slate-400">
Not yet registered — no bill exists for this property.</p> : <div className="space-y-2">

{agg.bills.map((b) => <div key={b.id} className="rounded-xl border border-slate-200 px-4 py-3">

<div className="flex flex-wrap gap-2 items-center">

<span className="font-semibold text-navy-800">
{b.document_number}
</span>
<Badge tone={billCaseTone(b.case_status)}>
{b.case_status}
</Badge>
<Badge tone={statusTone(b.payment_status)}>
{b.payment_status}
</Badge>                          </div>
<div className="mt-2 grid grid-cols-2 sm:grid-cols-3 gap-2 text-xs">

<Field label="Taxpayer" value={b.taxpayer_name || '—'} />

<Field label="TIN" value={b.tin || '—'} />

<Field label="Tax period" value={b.tax_period || '—'} />

<Field label="Total due" value={fmtMoney(b.total_tax_due)} />

<Field label="Outstanding" value={fmtMoney(b.outstanding_balance)} />

<Field label="Date logged" value={b.date_logged || '—'} />
                          </div>                        </div>)}
                    </div>}
                </div>              </div>            </Section>}
          {
          /* Supporting evidence — documentary only. Property photographs are              rendered as images in the gallery above, so they are deliberately              excluded here rather than duplicated as generic file rows. The              evidence_type column decides the grouping, never the extension: an              ownership deed is often a .jpg too. */
        }
          {(d || propertyMode) && <Section title={`Supporting evidence${documentList.length ? ` (${documentList.length})` : ''}`}>

{documentList.length === 0 ? <p className="text-sm text-slate-400">
                  No ownership or deed document attached to this record.                </p> : <div className="grid sm:grid-cols-2 gap-2">

{documentList.map((ev) => <div key={ev.id} className="rounded-xl border border-slate-200 p-3 flex items-center gap-3">

<div className="w-20 h-20 shrink-0 rounded-lg overflow-hidden border border-slate-100 bg-slate-50">

<EvidenceFile key={`${ev.id}-${ev.updated_at || ''}`} evidence={ev} />
                      </div>
<div className="text-xs min-w-0">

<div className="font-semibold text-navy-800">

{ev.evidence_type === 'OWNERSHIP_DOCUMENT' ? 'Ownership / deed document' : ev.evidence_type}
                        </div>
<div className="text-slate-500 truncate">
{ev.original_name || '—'}
</div>
<div className="text-slate-400 mt-0.5">

{ev.capture_source === 'CAMERA' ? 'Captured in field' : 'Uploaded'}
                          {ev.captured_at ? ` · ${new Date(ev.captured_at).toLocaleString()}` : ''}
                        </div>
{ev.captured_by_name && <div className="text-slate-400">
By {ev.captured_by_name}
</div>}
                      </div>                    </div>)}
                </div>}
            </Section>}
          {
          /* Valuation action panel (review-before-action) */
        }
          {v && runValuationAction && <ValuationActionPanel valuation={v} runValuationAction={runValuationAction} onActionDone={onActionDone} can={can} users={assignmentUsers} me={user} />}
          {
          /* Discovery action panel */
        }
          {d && runAction && <DiscoveryActionPanel d={d} runAction={runAction} onActionDone={onActionDone} can={can} users={assignmentUsers} me={user} />}
        </div>      </div>    </div>
}
/** Shared modal chrome, so every surface that opens a property looks the same. */
function ModalShell({
  title,
  onClose,
  children
}) {
  return <div className="fixed inset-0 z-50 bg-navy-950/60 backdrop-blur-sm grid place-items-center p-4 overflow-y-auto">

<div className="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[92vh] overflow-y-auto">

<div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white z-10">

<h3 className="font-bold text-navy-800">
{title}
</h3>
<button onClick={onClose} className="w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-500 font-bold">
✕</button>        </div>
<div className="p-6 space-y-6">
{children}
</div>      </div>    </div>
}
function Section({
  title,
  children
}) {
  return <div>

<div className="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">
{title}
</div>
{children}
    </div>
}
function InfoGrid({
  rows
}) {
  return <div className="grid sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">

{rows.map(([k, v]) => <div key={k} className="flex justify-between gap-3 border-b border-slate-50 pb-1">

<span className="text-slate-400 text-xs uppercase tracking-wide">
{k}
</span>
<span className="font-medium text-navy-800 text-right">
{v}
</span>        </div>)}
    </div>
}
/** Compact label/value cell, used by the current-task and history cards. */
function Field({
  label,
  value,
  mono
}) {
  return <div>

<div className="text-[11px] uppercase tracking-wider text-slate-400">
{label}
</div>
<div className={`mt-0.5 text-xs font-medium text-slate-700 ${mono ? 'font-mono' : ''}`}>
{value ?? '—'}
</div>    </div>
}
const coordText = (lat, lng) => lat === null || lat === undefined || lng === null || lng === undefined ? null : `${Number(lat).toFixed(6)}, ${Number(lng).toFixed(6)}`
/** Aggregate-mode photo rows already carry capture provenance; normalise shape. */
function normalizeAggregatePhoto(p) {
  return {
    key: `ap-${p.id}`,
    id: p.id,
    seq: p.sequence_no ?? 99,
    url: p.url,
    name: p.original_name,
    mime: p.mime_type,
    capturedAt: p.captured_at,
    capturedBy: p.captured_by || null,
    captureSource: p.capture_source,
    gps: coordText(p.gps_latitude, p.gps_longitude),
    store: 'aggregate'
  }
}
/**
 * Property photographs are PROPERTY_PHOTO rows on the discovery's own evidence
 * set. The count is always derived from the rows themselves — never from a
 * separately maintained tally, which is how three photos came to be reported
 * as zero.
 */
function evidencePhotos(evidence) {
  return (evidence || []).filter((e) => e.evidence_type === 'PROPERTY_PHOTO').map((e) => ({
    key: `de-${e.id}`,
    id: e.id,
    seq: e.sequence_no ?? 99,
    url: e.url,
    name: e.original_name,
    mime: e.mime_type,
    capturedAt: e.captured_at,
    capturedBy: e.captured_by_name || null,
    captureSource: e.capture_source,
    gps: coordText(e.gps_latitude, e.gps_longitude),
    store: 'discovery'
  }))
}
/**
 * Legacy evidence_photos rows that reference this discovery. Kept so nothing
 * already captured becomes invisible when the two stores are both in play.
 */
function normalizeLegacyPhoto(p, valuationId) {
  const [lat, lng] = String(p.gps_coordinate || '').includes(',') ? String(p.gps_coordinate).split(',') : [null, null]
  return {
    key: `lp-${p.id}`,
    id: p.id,
    seq: 90,
    url: valuationId && p.valuation_id ? `/api/v1/evidence/photos/${p.id}/download` : `/api/v1/evidence/photos/${p.id}/download`,
    name: p.original_name || p.photo_reference,
    mime: p.mime,
    capturedAt: p.captured_at,
    capturedBy: null,
    captureSource: null,
    gps: coordText(lat, lng),
    store: 'legacy'
  }
}
/* ------------------------------------------------------------------ */
/*  Property photograph gallery - the property is the subject           */
/* ------------------------------------------------------------------ */
function PhotoGallery({
  photos,
  officialGps
}) {
  const [active, setActive] = useState(0)
  const [zoom, setZoom] = useState(false)
  const [zoomScale, setZoomScale] = useState(1)
  useEffect(() => {
    setActive(0)
  }, [photos.length])
  useEffect(() => {
    if (active > photos.length - 1) setActive(0)
  }, [photos.length, active])
  if (!photos || photos.length === 0) {
    return <p className="text-sm text-slate-400">
        No property photographs are attached to this record.      </p>
  }
  const p = photos[active] || photos[0]
  const step = (n) => setActive((i) => (i + n + photos.length) % photos.length)
  return <div>

{
      /* Hero photograph */
    }
      <figure className="m-0">

<button type="button" onClick={() => {
        setZoomScale(1)
        setZoom(true)
      }} className="block w-full rounded-xl overflow-hidden border border-slate-200 bg-slate-50 group relative">

<GatedImage src={p.url} alt={p.name} className="w-full h-72 sm:h-96 object-cover" />

<span className="absolute top-2 left-2 px-2 py-0.5 rounded-md bg-navy-950/75 text-[10px] font-bold uppercase tracking-wider text-white">
            Property Photo {p.seq}
          </span>
<span className="absolute bottom-2 right-2 px-2 py-1 rounded-md bg-navy-950/75 text-[10px] font-semibold text-white opacity-0 group-hover:opacity-100 transition">
            ⤢ Full screen          </span>        </button>
{
        /* Coordinate overlay ties the photograph to the property location. */
      }
        {p.gps && <figcaption className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500">

<span className="inline-flex items-center gap-1 font-mono">
              📍 {p.gps}
            </span>
{p.gps === officialGps && <span className="text-emerald-600 font-semibold">
matches property GPS</span>}
            <span>

{p.capturedAt ? `Captured ${fmtTime(p.capturedAt)}` : 'Capture time not recorded'}
              {p.capturedBy ? ` · ${p.capturedBy}` : ''}
            </span>          </figcaption>}
      </figure>
{
      /* Thumbnail strip */
    }
      {photos.length > 1 && <div className="mt-3 grid grid-cols-3 sm:grid-cols-4 gap-2">

{photos.map((ph, i) => <button key={ph.key} type="button" onClick={() => setActive(i)} aria-label={`Show property photo ${ph.seq}`} aria-current={i === active} className={`relative rounded-lg overflow-hidden border-2 transition ${i === active ? 'border-brand-500 ring-2 ring-brand-100' : 'border-slate-200 hover:border-slate-300'}`}>

<GatedImage src={ph.url} alt={ph.name} className="w-full h-20 object-cover" />

<span className="absolute bottom-0 inset-x-0 bg-navy-950/70 text-[9px] font-bold text-white py-0.5">
                Photo {ph.seq}
              </span>            </button>)}
        </div>}
      <div className="mt-3 flex items-center gap-2 flex-wrap text-xs">

{photos.length > 1 && <>
<Btn tone="white" className="!px-2.5 !py-1 !text-xs" onClick={() => step(-1)}>
‹ Prev</Btn>
<Btn tone="white" className="!px-2.5 !py-1 !text-xs" onClick={() => step(1)}>
Next ›</Btn>          </>}
        <span className="text-slate-500">
          Property Photo {p.seq}
 of {photos.length}
        </span>
<span className="text-slate-400 truncate">
{p.name}
</span>
{p.captureSource && <Badge tone="slate">
{String(p.captureSource).toLowerCase()}
</Badge>}
      </div>
{
      /* Full-screen viewer with zoom and keyboard paging */
    }
      {zoom && <div className="fixed inset-0 z-[60] bg-navy-950/95 flex flex-col" role="dialog" aria-modal="true" aria-label={`Property photo ${p.seq}`} onKeyDown={(e) => {
      if (e.key === 'Escape') setZoom(false)
      if (e.key === 'ArrowRight') step(1)
      if (e.key === 'ArrowLeft') step(-1)
    }}>

<div className="flex items-center justify-between px-4 py-3 text-white text-sm">

<span className="font-semibold">
Property Photo {p.seq}
 — {p.name}
</span>
<div className="flex items-center gap-2">

<Btn tone="white" className="!px-2 !py-1 !text-xs" onClick={() => setZoomScale((z) => Math.min(4, +(z + 0.5).toFixed(1)))}>
＋</Btn>
<Btn tone="white" className="!px-2 !py-1 !text-xs" onClick={() => setZoomScale((z) => Math.max(1, +(z - 0.5).toFixed(1)))}>
－</Btn>
<Btn tone="white" className="!px-2 !py-1 !text-xs" onClick={() => setZoomScale(1)}>
Reset</Btn>
<button onClick={() => setZoom(false)} className="w-8 h-8 rounded-lg hover:bg-white/10 font-bold">
✕</button>            </div>          </div>
<div className="flex-1 overflow-auto grid place-items-center p-4">

<GatedImage src={p.url} alt={p.name} className="rounded-lg transition-transform" style={{
          transform: `scale(${zoomScale})`,
          transformOrigin: 'center'
        }} contain="contain" />
          </div>
{photos.length > 1 && <div className="px-4 py-3 flex items-center justify-center gap-3 text-white text-xs">

<Btn tone="white" className="!px-3 !py-1 !text-xs" onClick={() => step(-1)}>
‹ Previous</Btn>
<span>
{active + 1}
 / {photos.length}</span>
<Btn tone="white" className="!px-3 !py-1 !text-xs" onClick={() => step(1)}>
Next ›</Btn>            </div>}
          <div className="px-4 pb-4 text-center text-[11px] text-white/70 space-y-0.5">

{p.gps && <div className="font-mono">
📍 {p.gps}
</div>}
            <div>

{p.capturedAt ? `Captured ${fmtTime(p.capturedAt)}` : 'Capture time not recorded'}
              {p.capturedBy ? ` · ${p.capturedBy}` : ''}
            </div>          </div>        </div>}
    </div>
}
/** Loads a gated evidence file through the axios client so the bearer token rides along. */
/* ------------------------------------------------------------------ */
/*  Property location - an embedded map, not a bare coordinate          */
/* ------------------------------------------------------------------ */
/**
 * The map is embedded so an officer never has to leave RETD to see where a
 * property is. The tile source is an embed URL that an operator can point at
 * an internal tile server via VITE_MAP_EMBED_URL; when the tiles cannot be
 * reached the coordinate readout below the map still carries the full
 * position, source, accuracy and certification state, so the spatial evidence
 * is never lost to a network failure.
 */
function GpsMap({
  coordinate,
  accuracy,
  isOfficial,
  officialLabel,
  source,
  capturedAt,
  capturedBy,
  externalUrl
}) {
  const [tileError, setTileError] = useState(false)
  const [satellite, setSatellite] = useState(false)
  if (!coordinate) {
    return <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 grid place-items-center h-40 text-center px-4">

<div>

<div className="text-sm font-semibold text-slate-500">
No property coordinate recorded</div>
<div className="text-xs text-slate-400 mt-0.5">
            The property has no discovery or certified GPS location yet.          </div>        </div>      </div>
  }
  const [lat, lng] = String(coordinate).split(',').map((n) => Number(n))
  const d = 0.004
  const bbox = [lng - d, lat - d / 2, lng + d, lat + d / 2].map((n) => n.toFixed(6)).join('%2C')
  const layer = satellite ? 'mapnik' : 'mapnik'
  const embedBase = import.meta.env.VITE_MAP_EMBED_URL || 'https://www.openstreetmap.org/export/embed.html'
  const embed = `${embedBase}?bbox=${bbox}&layer=${layer}&marker=${lat}%2C${lng}`
  return <div>

<div className="relative rounded-xl overflow-hidden border border-slate-200 bg-slate-100">

{!tileError ? <iframe key={embed} title="Property location map" src={embed} loading="lazy" referrerPolicy="no-referrer" onError={() => setTileError(true)} className="w-full h-64 border-0" /> : (/* Offline / blocked-tile fallback: the coordinate is still shown. */
      <div className="h-64 grid place-items-center bg-slate-100 text-center px-4">

<div>

<div className="text-2xl mb-1">
📍</div>
<div className="text-sm font-mono font-semibold text-navy-800">
{coordinate}
</div>
<div className="text-xs text-slate-500 mt-1">
Map tiles are unavailable on this network.</div>
<div className="text-xs text-slate-400">
The coordinate above is the recorded property position.</div>            </div>          </div>)}
        <div className="absolute top-2 right-2 flex gap-1">

<button type="button" onClick={() => setSatellite((v) => !v)} className="px-2 py-1 rounded-md bg-white/90 text-[10px] font-bold uppercase tracking-wide text-slate-600 shadow hover:bg-white">

{satellite ? 'Map' : 'Satellite'}
          </button>        </div>
{
        /* Candidate vs official must never be visually ambiguous. */
      }
        <div className="absolute bottom-2 left-2">

<Badge tone={isOfficial ? 'emerald' : 'amber'}>

{isOfficial ? '✓ Official Property GPS' : '⚠ Taxpayer GPS'}
          </Badge>        </div>      </div>
<div className="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">

<Field label="Latitude" value={Number(lat).toFixed(6)} mono />

<Field label="Longitude" value={Number(lng).toFixed(6)} mono />

<Field label="Accuracy" value={accuracy != null ? `${Math.round(accuracy)} m` : 'Not recorded'} />

<Field label="Source" value={source || 'Discovery GPS'} />
      </div>
<div className="mt-2 flex items-center gap-2 flex-wrap text-xs text-slate-500">

<span>

{capturedAt ? `Captured ${fmtTime(capturedAt)}` : 'Capture time not recorded'}
          {capturedBy ? ` · ${capturedBy}` : ''}
        </span>
<Badge tone={isOfficial ? 'emerald' : 'amber'}>

{isOfficial ? 'Official GPS Certified' : 'Official GPS Not Yet Certified'}
        </Badge>
{!isOfficial && <span className="text-amber-700">
            A field-collected coordinate is not the official property coordinate until it is certified.          </span>}
        {officialLabel && !isOfficial && <span className="text-slate-400">
{officialLabel}
</span>}
      </div>
{externalUrl && <a href={externalUrl} target="_blank" rel="noreferrer" className="inline-block mt-2 text-xs font-semibold text-brand-600 hover:underline">
          Open external map ↗        </a>}
    </div>
}
/* ------------------------------------------------------------------ */
/*  Valuation actions — stage driven, gated by individual permissions  */
/* ------------------------------------------------------------------ */
function ValuationActionPanel({
  valuation,
  runValuationAction,
  onActionDone,
  can,
  users,
  me
}) {
  const [mode, setMode] = useState(null)
  const [remarks, setRemarks] = useState('')
  const [officer, setOfficer] = useState('')
  const [inputs, setInputs] = useState({})
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const st = valuation.status
  const worth = can('valuation.submit')
  // One owner per step: the officer verbs belong to the officer the assessment is
  // assigned to. An unassigned valuation has no owner yet, so it stays open to any
  // officer until the manager routes it. The API enforces the same rule.
  const ownedBy = valuation.valuation_officer_id
  const ownsIt = !ownedBy || Number(ownedBy) === Number(me?.id)
  const officerStep = worth && ownsIt
  const manager = can('valuation.review')
  const assigner = can('valuation.review', 'valuation.forward_ac', 'valuation.approve')
  const ac = can('valuation.approve')
  const armmgr = can('valuation.account_review')
  const canRegist = can('valuation.property_registration')
  const completer = can('valuation.complete', 'valuation.account_review')
  const propOfficers = users.filter((u) => /valuation/i.test(roleName(u)))
  const propArmOfficers = users.filter((u) => /account.*(record|officer)|a&r|arm/i.test(roleName(u)))
  const picksOfficer = mode === 'assign' || mode === 'account-approve'
  const fetched = useAssignableOfficers(picksOfficer ? mode === 'account-approve' ? 'Account & Record Processing' : 'Valuation' : null)
  const [fetchedOfficers] = fetched
  const officers = fetched[0].length ? fetched[0] : propOfficers
  const armOfficers = fetched[0].length ? fetched[0] : propArmOfficers
  // A valuation that came from a discovery is processed on the discovery itself,
  // so the source identifiers and the property bill record are written once,
  // from one place.
  const fromDiscovery = !!valuation.discovery_id
  // What the submission is based on, and whether there is anything to submit yet. The
  // assessment form writes reassessed_value; assessed_value is the older name for the
  // same figure. Mirrors the server's own basis in ValuationsController::submit(), so
  // the button and the endpoint cannot disagree about what counts as complete.
  const assessmentBasis = valuation.reassessed_value ?? valuation.assessed_value
  const assessmentComplete = assessmentBasis != null || valuation.annual_tax != null
  const submitBlocked = worth && ownsIt
    && ['Assigned', 'Assessment in Progress', 'Returned for Correction'].includes(st)
    && !assessmentComplete
  const configured = [
  // Officer — start assigned assessment
  ...(officerStep && st === 'Assigned' ? [{
    key: 'begin',
    label: 'Start assessment'
  }] : []),
  // Officer — submit completed assessment. Shown to the officer the assessment is
  // assigned to, at the stages a submission is accepted from, and only once there is
  // an assessed figure to submit. Visibility follows the same permission + ownership +
  // stage rules the API enforces, so a viewer who is neither cannot offer it.
  ...(officerStep && ['Assigned', 'Assessment in Progress', 'Returned for Correction'].includes(st) ? [{
    key: 'submit',
    label: 'Submit to manager',
    disabled: !assessmentComplete
  }] : []),
  // Manager — assign/reassign an officer for fresh or returned assessments
  ...(assigner && ['Assigned', 'Returned for Correction', 'AC Returned'].includes(st) ? [{
    key: 'assign',
    label: 'Assign to officer'
  }] : []),
  // Manager — review submitted assessment
  ...(manager && ['Submitted to Manager', 'Under Manager Review'].includes(st) ? [{
    key: 'forward',
    label: 'Confirm & submit to AC'
  }, {
    key: 'return',
    label: 'Return for correction'
  }] : []),
  // AC — decide
  ...(ac && ['Submitted to AC', 'Awaiting AC Approval'].includes(st) ? [{
    key: 'approve',
    label: 'Approve',
    tone: 'success'
  }, {
    key: 'reject',
    label: 'Reject',
    tone: 'danger'
  }, {
    key: 'decide-return',
    label: 'Return',
    tone: 'white'
  }] : []),
  // A&R Manager — final review after AC approval
  ...(armmgr && st === 'AC Approved' ? [{
    key: 'account-approve',
    label: 'Approve & assign officer to process',
    tone: 'success'
  }, {
    key: 'account-return',
    label: 'Refer back to Valuation',
    tone: 'white'
  }] : []),
  // A&R Officer — record the processing result (standalone valuations only)
  ...(canRegist && st === 'Sent to Account & Record' && !fromDiscovery ? [{
    key: 'processing',
    label: 'Record processing result',
    regPrompt: true
  }] : []), ...(completer && st === 'Registered' ? [{
    key: 'complete',
    label: 'Complete'
  }] : [])]
  if (configured.length === 0) return null
  if (fromDiscovery && st === 'Sent to Account & Record') {
    return <Section title="Property processing">

<div className="rounded-xl border border-brand-200 bg-brand-50 p-4 text-sm text-slate-600">
          This valuation came from a property discovery. The assigned Account &amp;
 Record officer records the processing          result and updates the property bill record on the discovery itself, so it is written once, from one place.        </div>      </Section>
  }
  const run = async () => {
    setErr('')
    setBusy(true)
    try {
      const payload = {}
      if (mode === 'submit') {
        // The Valuation Assessment form records the officer's figure as
        // reassessed_value, so that is what the submission is based on; assessed_value
        // is the older name for the same money. Annual tax is derived on the server
        // from the figure and the rate, so it is only sent when the record has one.
        payload.assessed_value = valuation.assessed_value ?? undefined
        payload.reassessed_value = valuation.reassessed_value ?? undefined
        payload.annual_tax = valuation.annual_tax ?? undefined
        payload.applicable_tax_rate = valuation.applicable_tax_rate ?? undefined
        payload.other_amounts = valuation.other_amounts ?? undefined
      } else if (mode === 'processing') {
        payload.property_id = inputs.pid || undefined
        payload.document_number = inputs.doc || undefined
      } else if (mode === 'assign' || mode === 'account-approve') {
        if (!officer) throw new Error('Choose an officer to assign.')
        payload.officer_id = Number(officer)
      } else if (mode === 'forward') {
        payload.decision = 'confirm_ac'
        payload.remarks = remarks || undefined
      } else if (mode === 'return') {
        payload.decision = mode
        payload.remarks = remarks || undefined
      } else if (['decide-return', 'account-approve', 'account-return'].includes(mode)) {
        payload.decision = mode === 'account-approve' ? 'approve' : 'return'
        payload.remarks = remarks || undefined
      } else if (mode === 'approve' || mode === 'reject') {
        payload.decision = mode
        payload.remarks = remarks || undefined
      }
      const res = await runValuationAction(mode, payload)
      onActionDone?.(mode, res)
      setMode(null)
      setRemarks('')
      setOfficer('')
      setInputs({})
    } catch (ex) {
      setErr(errMsg(ex))
    }
    setBusy(false)
  }
  const isDecision = ['forward', 'return', 'decide-return', 'approve', 'reject', 'account-approve', 'account-return'].includes(mode)
  return <Section title="Valuation actions for this stage">

<div className="rounded-xl border border-slate-200 p-4 space-y-3">

{err && <div className="text-sm text-red-600">
{err}
</div>}
        {!mode ? <div className="space-y-2">
          <div className="flex gap-2 flex-wrap">
{configured.map((a) => <Btn key={a.key} tone={a.tone === 'success' ? 'success' : a.tone === 'danger' ? 'danger' : a.tone === 'white' ? 'white' : 'primary'} onClick={() => setMode(a.key)} disabled={a.disabled}>
{a.label}
</Btn>)}
          </div>
          {submitBlocked && <div className="text-xs text-slate-500">
            Submit to manager unlocks once the assessment values are completed and saved on
            the valuation form.
          </div>}
          </div> : <div className="space-y-3">

{picksOfficer && <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={officer} onChange={(e) => setOfficer(e.target.value)}>

<option value="">
{mode === 'account-approve' ? 'Account & Record officer…' : 'Valuation officer…'}
</option>
{(mode === 'account-approve' ? armOfficers : officers).map((u) => <option key={u.id} value={u.id}>
{u.full_name}
 ({roleName(u)}
)
</option>)}
              </select>}
            {isDecision && <textarea className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm" rows={2} placeholder={mode === 'reject' ? 'Rejection reason' : mode === 'return' || mode === 'decide-return' || mode === 'account-return' ? 'Correction instructions' : 'Remarks (optional)'} value={remarks} onChange={(e) => setRemarks(e.target.value)} />}
            {mode === 'processing' && <div className="grid sm:grid-cols-2 gap-2">

<Input placeholder="Property ID (source)" value={inputs.pid || ''} onChange={(e) => setInputs({
            ...inputs,
            pid: e.target.value
          })} />

<Input placeholder="Document # (source)" value={inputs.doc || ''} onChange={(e) => setInputs({
            ...inputs,
            doc: e.target.value
          })} />
              </div>}
            {mode === 'submit' && <p className="text-xs text-slate-500">
Submitting locks the assessment for manager review.</p>}
            <div className="flex gap-2">

<Btn onClick={run} disabled={busy}>
{busy ? 'Working…' : 'Confirm'}
</Btn>
<Btn tone="white" onClick={() => {
            setMode(null)
            setRemarks('')
            setOfficer('')
            setInputs({})
          }}>
Cancel</Btn>            </div>          </div>}
      </div>    </Section>
}
/* ------------------------------------------------------------------ */
/*  Discovery actions — the discovery workflow verbs for its status     */
/* ------------------------------------------------------------------ */
const ACTIONS_CONFIG = [
// Discovering officer — draft / correction
  {
  // "local" never reaches the server: it opens the correction form in place so the
  // record can be fixed and resubmitted without losing the corrections in a dialog.
  // The form's own "Save & resubmit" does the save and the push in one go.
  verb: 'correct',
  label: 'Edit for correction',
  from: ['Returned for Correction'],
  perm: 'canCorrect',
  local: true
}, {
  // For a record that has already been corrected and saved. resubmit() re-validates
  // and refuses an uncorrected record (a value on a formal valuation, or a
  // documentary value with no ownership evidence), so this is safe as a one-click
  // action - it is the button the form's "saved, ready to resubmit" note points at.
  verb: 'resubmit',
  label: 'Resubmit',
  from: ['Returned for Correction'],
  perm: 'canCreate',
  confirm: true
}, {
  verb: 'reopen',
  label: 'Reopen as draft',
  from: ['Rejected', 'Returned for Correction'],
  perm: 'canReopen',
  confirm: true
},
// A&R Manager desk — all four desk decisions are available at every stage the
// record sits on the manager's desk (pending review, and post-AC-approval).
{
  verb: 'acct-review',
  decision: 'approve',
  label: 'Approve & assign A&R officer',
  from: ['Awaiting Account Manager Review', 'Approved'],
  perm: 'canAcctApprove',
  officerChoose: true,
  officerKind: 'account'
}, {
  verb: 'acct-review',
  decision: 'refer_to_valuation',
  label: 'Refer to Valuation',
  from: ['Awaiting Account Manager Review', 'Approved'],
  perm: 'canReferValuation',
  prompt: {
    key: 'remarks',
    place: 'Why is a formal valuation required?'
  }
}, {
  verb: 'acct-review',
  decision: 'return',
  label: 'Return for correction',
  from: ['Awaiting Account Manager Review', 'Approved'],
  perm: 'canAcctReview',
  prompt: {
    key: 'remarks',
    place: 'Correction instructions'
  }
}, {
  verb: 'acct-review',
  decision: 'reject',
  label: 'Reject',
  from: ['Awaiting Account Manager Review', 'Approved'],
  perm: 'canAcctReview',
  prompt: {
    key: 'remarks',
    place: 'Rejection reason'
  }
},
// Valuation Manager desk — route to an eligible officer
{
  verb: 'val-review',
  decision: 'assign',
  label: 'Assign to Valuation Officer',
  from: ['Awaiting Valuation', 'Referred to Valuation'],
  perm: 'canRouteValuation',
  officerChoose: true,
  officerKind: 'valuation'
}, {
  verb: 'val-review',
  decision: 'self',
  label: 'Conduct personally (self)',
  from: ['Awaiting Valuation', 'Referred to Valuation'],
  perm: 'canConductValuation'
}, {
  verb: 'val-review',
  decision: 'return',
  label: 'Return for correction',
  from: ['Awaiting Valuation', 'Referred to Valuation'],
  perm: 'canRouteValuation',
  prompt: {
    key: 'remarks',
    place: 'Correction instructions'
  }
},
// A&R Officer — processing after assignment
{
  verb: 'account-processing',
  label: 'Record processing result',
  from: ['Assigned to Account & Record'],
  perm: 'canRegist',
  regPrompt: true
}, {
  verb: 'complete',
  label: 'Complete',
  from: ['Registered'],
  perm: 'canComplete'
}]
/**
 * Staff who may be assigned the given task type.
 *
 * The action panels load this themselves rather than trusting a parent to have
 * fetched it. The lookup is open to any signed-in staff member and is filtered
 * by the same eligibility rule the mutation endpoint enforces, so a person who
 * cannot be assigned is never offered. A failed call resolves to an empty list
 * with `failed` set, so the caller can fall back and can say so.
 */
function useAssignableOfficers(taskType) {
  const [rows, setRows] = useState([])
  const [failed, setFailed] = useState(false)
  const [loading, setLoading] = useState(false)
  const [nonce, setNonce] = useState(0)
  useEffect(() => {
    if (!taskType) {
      setRows([])
      setFailed(false)
      return
    }
    let active = true
    setFailed(false)
    setLoading(true)
    api.get('/lookup/assignable', {
      params: {
        task_type: taskType
      }
    }).then((r) => {
      if (!active) return
      // The endpoint always answers with { data: [...] }.
      setRows(Array.isArray(r.data?.data) ? r.data.data : [])
      setFailed(false)
    }).catch((e) => {
      if (!active) return console.warn('[useAssignableOfficers] lookup failed', taskType, e)
      setRows([])
      setFailed(true)
    }).finally(() => {
      if (active) setLoading(false)
    })
    return () => {
      active = false
    }
  }, [taskType, nonce])
  // Staff records are created while these pages stay open, so a roster fetched
  // once on mount goes stale. Revalidate whenever the tab regains focus so a
  // newly activated or newly created officer appears without a page reload.
  useEffect(() => {
    if (!taskType) return
    const revalidate = () => {
      if (document.visibilityState === 'visible') setNonce((n) => n + 1)
    }
    window.addEventListener('focus', revalidate)
    document.addEventListener('visibilitychange', revalidate)
    return () => {
      window.removeEventListener('focus', revalidate)
      document.removeEventListener('visibilitychange', revalidate)
    }
  }, [taskType])
  const reload = useCallback(() => setNonce((n) => n + 1), [])
  return [rows, failed, loading, reload]
}
/** The task type whose eligible staff a given desk action assigns. */
function taskTypeForAction(a) {
  if (!a) return null
  if (a.verb === 'acct-review' && a.decision === 'approve') return 'Account & Record Processing'
  if (a.verb === 'val-review' && a.decision === 'assign') return 'Valuation'
  return null
}
  function DiscoveryActionPanel({
    d,
    runAction,
    onActionDone,
    can,
    users,
    me
  }) {
  const [action, setAction] = useState(null)
  const [inputs, setInputs] = useState({})
  // The officer pick is held in its own state rather than in `inputs`. Sharing
  // one spread-updated object across the prompt/textarea and the select let a
  // sibling field overwrite the selection, and the failure surfaced as a
  // blocking "choose an officer" alert after a perfectly visible selection.
  const [officer, setOfficer] = useState('')
  const [formErr, setFormErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const canActions = {
    canCreate: can('discovery.create'),
    // only the officer who recorded the discovery may correct it, which is why the
    // stable discovered_by_user_id is used and not the name-or-id discovered_by
    canCorrect: can('discovery.create') && Number(d.discovered_by_user_id) > 0 && Number(d.discovered_by_user_id) === Number(me?.id),
    canReopen: can('discovery.reopen'),
    canAcctReview: can('discovery.aarm_review'),
    canAcctApprove: can('discovery.aarm_review') && can('discovery.aarm_approve'),
    canReferValuation: can('discovery.aarm_review') && can('discovery.refer_to_valuation'),
    canRouteValuation: can('discovery.route_to_valuation'),
    // "Conduct personally" makes the caller the valuation officer, which the API
    // only accepts for staff who can actually run a Valuation. Routing alone is not
    // enough, or a manager without valuation.submit is offered a button that 422s.
    canConductValuation: can('discovery.route_to_valuation') && can('valuation.submit'),
    canRegist: can('discovery.property_registration') || can('discovery.aarm_review'),
    canComplete: can('discovery.property_registration') || can('discovery.aarm_review')
  }
  const configured = ACTIONS_CONFIG.filter((a) => a.from.includes(d.status) && canActions[a.perm])
  if (configured.length === 0) return null
  const doAction = async (a) => {
    setErr('')
    setFormErr('')
    if (a.local) {
      setInputs({})
      return setAction({
        verb: a.verb,
        label: a.label,
        needs: a
      })
    }
    if (a.confirm && !window.confirm(`Confirm ${a.label.toLowerCase()} ${d.discovery_reference}?`)) return
    if (a.prompt || a.officerChoose || a.regPrompt) {
      setOfficer('')
      // The registration prompt prefills the tax from the approved assessment:
      // that figure is authoritative and the API rejects a contradicting entry.
      setInputs(a.verb === 'account-processing' && d.valuation?.annual_tax != null
        ? { tax: String(d.valuation.annual_tax) }
        : {})
      return setAction({
        verb: a.verb,
        label: a.label,
        needs: a
      })
    }
    setBusy(true)
    try {
      const res = await runAction(a.verb, {
        decision: a.decision
      })
      onActionDone?.(a.verb, res)
    } catch (ex) {
      setErr(errMsg(ex))
    }
    setBusy(false)
  }
  const confirmComplex = async () => {
    const a = action.needs
    const payload = a.decision ? {
      decision: a.decision
    } : {}
    if (action.prompt) payload[action.prompt.key] = inputs[action.prompt.key] || undefined
    if (a?.verb === 'acct-review' && a.decision === 'approve') {
      if (!officer) {
        setFormErr('No officer selected. Choose a name from the dropdown above, then confirm.')
        return
      }
      payload.officer_id = Number(officer)
    }
    if (a?.verb === 'val-review' && a.decision === 'assign') {
      if (!officer) {
        setFormErr('No officer selected. Choose a name from the dropdown above, then confirm.')
        return
      }
      payload.officer_id = Number(officer)
    }
    if (a?.verb === 'account-processing') {
      if (!inputs.pid || !inputs.doc || !inputs.tax || !inputs.period) {
        return setFormErr('Property ID, Document #, Tax amount and Tax period are all required.')
      }
      if (!(Number(inputs.tax) >= 0)) return setFormErr('Tax amount must be a non-negative number.')
      payload.property_id = inputs.pid
      payload.document_number = inputs.doc
      payload.property_tax_amount = Number(inputs.tax)
      payload.tax_period = inputs.period
      payload.remarks = inputs.remarks || undefined
    }
    setFormErr('')
    setBusy(true)
    try {
      const res = await runAction(action.verb, payload)
      onActionDone?.(action.verb, res)
      setAction(null)
      setInputs({})
      setOfficer('')
    } catch (ex) {
      setErr(errMsg(ex))
    }
    setBusy(false)
  }
  const [fetchedOfficers, fetchFailed, fetchLoading, reloadOfficers] = useAssignableOfficers(taskTypeForAction(action?.needs))
  const propOfficers = action?.needs?.officerKind === 'account' ? users.filter((u) => /account.*record|account.*officer|a&r|arm/i.test(roleName(u))) : users.filter((u) => /valuation/i.test(roleName(u)))
  // Prefer the eligibility-filtered lookup; fall back to the handed-in list if
  // the lookup is unavailable, so a transient failure cannot silently empty the
  // picker and leave the officer unable to proceed.
  const officerPool = fetchedOfficers.length ? fetchedOfficers : propOfficers
  const lookupUnavailable = !!action?.needs?.officerChoose && fetchedOfficers.length === 0 && propOfficers.length === 0
  return <Section title="Discovery actions for the current status">

<div className="rounded-xl border border-slate-200 p-4 space-y-3">

{err && <div className="text-sm text-red-600">
{err}
</div>}
        {!action ? <div className="flex gap-2 flex-wrap">

{configured.map((a) => <Btn key={`${a.verb}-${a.decision || 'x'}`} tone={a.decision === 'reject' ? 'danger' : a.decision === 'approve' ? 'success' : 'primary'} onClick={() => doAction(a)}>
{a.label}
</Btn>)}
          </div> : <div className="space-y-3">

{action.prompt && <textarea className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm" rows={2} placeholder={action.prompt.place} value={inputs[action.prompt.key] || ''} onChange={(e) => setInputs({
          ...inputs,
          [action.prompt.key]: e.target.value
        })} />}
            {action.needs?.officerChoose && (fetchLoading ? <p className="text-sm text-slate-500">
Loading the staff roster…</p> : officerPool.length === 0 ? <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 space-y-2">

<p className="text-sm text-amber-800">

{lookupUnavailable ? 'The officer roster could not be loaded from the server.' : `No eligible ${action.needs.officerKind === 'account' ? 'Account & Records' : 'valuation'} officer exists. An administrator must create or activate one before this can be assigned.`}
                  </p>
<button type="button" onClick={reloadOfficers} className="text-xs font-semibold text-brand-700 underline">
Retry loading the roster</button>                </div> : <>
{fetchFailed && <p className="text-xs text-amber-700">
Eligibility could not be verified — showing the page&apos;
s staff list.</p>}
                  <select className="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm bg-white" value={officer} onChange={(e) => {
            setOfficer(e.target.value)
            setFormErr('')
          }}>

<option value="">
Select an officer…</option>
{officerPool.map((u) => <option key={u.id} value={u.id}>
{u.full_name}
 — {roleName(u)}
</option>)}
                  </select>
{officer && <p className="text-xs text-slate-500">
                      Assigning to{' '}
                      <span className="font-semibold text-slate-700">

{officerPool.find((u) => String(u.id) === String(officer))?.full_name || `staff #${officer}`}
                      </span>                    </p>}
                </>)}
            {action.needs?.verb === 'account-processing' && <div className="space-y-2">

<p className="text-xs text-slate-500 leading-relaxed">
                  Confirming completes the Account &amp;
 Record Processing task and writes this property&apos;
s bill record                  once, from here. If a bill already exists for this TIN and tax period it is updated;
 otherwise one is created.                </p>
<div className="grid sm:grid-cols-2 gap-2">

<Input placeholder="Property ID (source)" value={inputs.pid || ''} onChange={(e) => setInputs({
              ...inputs,
              pid: e.target.value
            })} />

<Input placeholder="Document # (source)" value={inputs.doc || ''} onChange={(e) => setInputs({
              ...inputs,
              doc: e.target.value
            })} />

<Input placeholder="Tax amount (US$)" inputMode="decimal" value={inputs.tax || ''} onChange={(e) => setInputs({
              ...inputs,
              tax: e.target.value
            })} />

<Input placeholder="Tax period (e.g. 2026)" value={inputs.period || ''} onChange={(e) => setInputs({
              ...inputs,
              period: e.target.value
            })} />
                </div>
<Input placeholder="Remarks (optional)" value={inputs.remarks || ''} onChange={(e) => setInputs({
            ...inputs,
            remarks: e.target.value
          })} />
              </div>}
            {formErr && <div className="text-sm text-red-600">
{formErr}
</div>}
            {action.needs?.local ? <DiscoveryCorrectionForm discovery={d} onClose={() => {
            setAction(null)
            setInputs({})
            setFormErr('')
          }} onSaved={(resubmitted, result) => {
            setAction(null)
            setInputs({})
            setFormErr('')
            onActionDone?.(resubmitted ? 'resubmit' : 'correct', result)
          }} /> : <div className="flex gap-2">

{
            /* Deliberately never disabled for a missing selection: a greyed                  button says nothing about what is wrong. Confirm always                  responds, and an incomplete form is reported inline above. */
          }
              <Btn onClick={confirmComplex} disabled={busy}>
{busy ? 'Working…' : 'Confirm'}
</Btn>
<Btn tone="white" onClick={() => {
            setAction(null)
            setInputs({})
            setOfficer('')
            setFormErr('')
          }}>
Cancel</Btn>            </div>          }</div>}
      </div>    </Section>
}
/**
 * Supporting-evidence file (ownership/deed document), fetched with the bearer
 * token. Property photographs no longer come through here — they are rendered
 * as images by PhotoGallery.
 */
function EvidenceFile({
  evidence
}) {
  const [src, setSrc] = useState(null)
  const [failed, setFailed] = useState(false)
  useEffect(() => {
    if (!evidence.url) return
    let active = true
    let blobUrl = null
    api.get(apiPath(evidence.url), {
      responseType: 'blob'
    }).then((r) => {
      if (!active) return
      // A deed is frequently a scan or PDF; only image payloads get a preview.
      if (!/image/i.test(r.data.type || '')) {
        setFailed(true)
        return
      }
      blobUrl = URL.createObjectURL(r.data)
      setSrc(blobUrl)
    }).catch(() => {
      if (active) setFailed(true)
    })
    return () => {
      active = false
      if (blobUrl) URL.revokeObjectURL(blobUrl)
    }
  }, [evidence.url])
  if (failed) {
    return <a href={evidence.url} target="_blank" rel="noreferrer" className="w-full h-full grid place-items-center text-brand-600 text-[10px] font-bold underline">
        view      </a>
  }
  if (!src) return <div className="aspect-square rounded-lg bg-slate-50 animate-pulse" />
  return <a href={src} target="_blank" rel="noreferrer" className="block w-full h-full">

<img src={src} alt={evidence.original_name || 'evidence'} className="w-full h-full object-cover hover:scale-105 transition" />
    </a>
}
