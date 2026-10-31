import { useEffect, useRef, useState } from 'react'
import api, { errMsg, unwrap } from '../../api'
import { Badge, Btn, ErrorBox, Input, Spinner } from '../../ui'

const EMPTY_FORM = {
  change_type: 'create_region',
  name: '',
  code: '',
  classification: 'Urban',
  description: '',
  region_id: '',
  district_id: '',
  district_ids: [],
  reason: '',
  team_id: '',
  supervisor_id: '',
  officer_id: '',
  primary_district_id: '',
  is_team_lead: false,
  team_lead_id: '',
  member_ids: [],
  member_primary_district_id: '',
  is_active: true,
}

const titleFor = (type) => ({
  create_region: 'Add tax region',
  update_region: 'Update tax region',
  assign_region_supervisor: 'Assign regional supervisor',
  create_district: 'Add district',
  update_district: 'Update district',
  assign_districts_to_region: 'Assign districts to region',
  create_team: 'Create team',
  update_team: 'Update team',
  assign_officer: 'Assign officer',
  remove_officer: 'Remove officer',
}[type] || type)

const STAGES = [
  { id: 'regions', label: 'Tax Regions', icon: '◉', description: 'Define Rural and Urban operating areas.' },
  { id: 'districts', label: 'District Assignment', icon: '⌖', description: 'Place districts within an approved region.' },
  { id: 'supervisors', label: 'Regional Supervisors', icon: '◎', description: 'Assign one active Supervisor per region.' },
  { id: 'teams', label: 'Enforcement Teams', icon: '▦', description: 'Configure teams across districts in one region.' },
  { id: 'members', label: 'Team Leads & Officers', icon: '♙', description: 'Review approved officer memberships.' },
  { id: 'overview', label: 'Organization Overview', icon: '⌘', description: 'Explore the complete reporting hierarchy.' },
]

const STAGE_ACTIONS = {
  regions: ['create_region', 'update_region'],
  districts: ['create_district', 'update_district', 'assign_districts_to_region'],
  supervisors: ['assign_region_supervisor'],
  teams: ['create_team', 'update_team'],
  members: ['assign_officer', 'remove_officer'],
}

const CONTROL_CLASS = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal text-slate-800 shadow-sm transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/10'

const recommendationDetails = (item, data) => {
  const payload = item.payload || {}
  const regionName = (id) => data?.regions?.find((region) => String(region.id) === String(id))?.name || `Region ${id}`
  const districtName = (id) => data?.districts?.find((district) => String(district.id) === String(id))?.name || `District ${id}`
  const teamName = (id) => data?.teams?.find((team) => String(team.id) === String(id))?.name || `Team ${id}`
  const officerName = (id) => data?.officers?.find((officer) => String(officer.id) === String(id))?.full_name || `Officer ${id}`
  const supervisorName = (id) => data?.supervisors?.find((supervisor) => String(supervisor.id) === String(id))?.full_name || `Supervisor ${id}`
  const districts = (ids) => (ids || []).map(districtName).join(', ')

  switch (item.change_type) {
    case 'create_region':
      return [
        ['Region', payload.name],
        ['Code', payload.code],
        ['Classification', payload.classification],
        ['Reason', payload.reason],
      ]
    case 'update_region':
      return [
        ['Region', regionName(payload.region_id)],
        ['Name', payload.name],
        ['Code', payload.code],
        ['Classification', payload.classification],
        ['Active', typeof payload.is_active === 'boolean' ? (payload.is_active ? 'Yes' : 'No') : null],
        ['Reason', payload.reason],
      ]
    case 'assign_region_supervisor':
      return [
        ['Region', regionName(payload.region_id)],
        ['Supervisor', supervisorName(payload.supervisor_id)],
        ['Reason', payload.reason],
      ]
    case 'create_district':
      return [
        ['District', payload.name],
        ['Reason', payload.reason],
      ]
    case 'update_district':
      return [
        ['District', districtName(payload.district_id)],
        ['Name', payload.name],
        ['Active', typeof payload.is_active === 'boolean' ? (payload.is_active ? 'Yes' : 'No') : null],
        ['Reason', payload.reason],
      ]
    case 'assign_districts_to_region':
      return [
        ['Region', regionName(payload.region_id)],
        ['Districts', districts(payload.district_ids)],
        ['Reason', payload.reason],
      ]
    case 'create_team':
      return [
        ['Team', payload.name],
        ['Region', regionName(payload.region_id)],
        ['Supervisor', supervisorName(payload.supervisor_id)],
        ['Districts', districts(payload.district_ids)],
        ['Team lead', (payload.members || []).filter((member) => member.is_team_lead).map((member) => officerName(member.officer_id)).join(', ')],
        ['Members', (payload.members || []).map((member) => officerName(member.officer_id)).join(', ')],
        ['Reason', payload.reason],
      ]
    case 'update_team':
      return [
        ['Team', teamName(payload.team_id)],
        ['Name', payload.name],
        ['Supervisor', payload.supervisor_id ? supervisorName(payload.supervisor_id) : null],
        ['Districts', payload.district_ids ? districts(payload.district_ids) : null],
        ['Active', typeof payload.is_active === 'boolean' ? (payload.is_active ? 'Yes' : 'No') : null],
        ['Reason', payload.reason],
      ]
    case 'assign_officer':
      return [
        ['Team', teamName(payload.team_id)],
        ['Officer', officerName(payload.officer_id)],
        ['Primary district', districtName(payload.primary_district_id)],
        ['Team lead', payload.is_team_lead ? 'Yes' : 'No'],
      ]
    case 'remove_officer':
      return [
        ['Team', teamName(payload.team_id)],
        ['Officer', officerName(payload.officer_id)],
      ]
    default:
      return Object.entries(payload).map(([key, value]) => [
        key.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()),
        Array.isArray(value) ? value.join(', ') : String(value),
      ])
  }
}

export default function OrganizationTab() {
  const [data, setData] = useState(null)
  const [form, setForm] = useState(EMPTY_FORM)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  const [stage, setStage] = useState('regions')
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('pending')
  const [tableView, setTableView] = useState(false)
  const [expanded, setExpanded] = useState({})
  const [dialogOpen, setDialogOpen] = useState(false)
  const [reviewTarget, setReviewTarget] = useState(null)
  const [reviewDecision, setReviewDecision] = useState('')
  const [reviewNotes, setReviewNotes] = useState('')
  const [reviewError, setReviewError] = useState('')
  const dialogRef = useRef(null)
  const initializedStage = useRef(false)

  const load = async () => {
    try {
      setError('')
      const response = await api.get('/enforcement/organization')
      const nextData = unwrap(response).data
      setData(nextData)
      if (!initializedStage.current) {
        const nextRegions = nextData.regions || []
        const nextDistricts = nextData.districts || []
        const nextTeams = nextData.teams || []
        const activeRegionCount = nextRegions.filter((region) => region.is_active).length
        const assignedSupervisorCount = nextRegions.filter((region) => region.is_active && region.supervisor_id && region.supervisor_active).length
        const nextStep = nextRegions.length === 0 ? 'regions'
          : nextDistricts.length === 0 || nextDistricts.some((district) => district.is_active && !district.region_id) ? 'districts'
            : assignedSupervisorCount < activeRegionCount ? 'supervisors'
              : nextTeams.length === 0 ? 'teams'
                : nextTeams.every((item) => !(item.officers || []).some((person) => person.is_team_lead)) ? 'members'
                  : 'overview'
        setStage(nextStep)
        initializedStage.current = true
      }
      const allowedTypes = [
        ...(nextData.can_administer_geography ? ['create_region', 'update_region', 'create_district', 'update_district', 'assign_districts_to_region'] : []),
        ...(nextData.can_assign_supervisor ? ['assign_region_supervisor'] : []),
        ...(nextData.can_manage ? ['create_team', 'update_team', 'assign_officer', 'remove_officer'] : []),
        ...(nextData.can_recommend ? ['update_team', 'assign_officer', 'remove_officer'] : []),
      ]
      setForm((current) => allowedTypes.includes(current.change_type)
        ? current
        : { ...EMPTY_FORM, change_type: allowedTypes[0] || EMPTY_FORM.change_type })
    } catch (ex) {
      setError(errMsg(ex, 'Could not load Enforcement organization data.'))
    }
  }

  useEffect(() => { load() }, [])

  useEffect(() => {
    if (!dialogOpen && !reviewTarget) return undefined
    const handleKeyDown = (event) => {
      if (event.key === 'Escape' && !busy) {
        setDialogOpen(false)
        setReviewTarget(null)
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    document.body.style.overflow = 'hidden'
    requestAnimationFrame(() => dialogRef.current?.focus())
    return () => {
      window.removeEventListener('keydown', handleKeyDown)
      document.body.style.overflow = ''
    }
  }, [dialogOpen, reviewTarget, busy])

  const team = data?.teams?.find((item) => String(item.id) === String(form.team_id))
  const visibleTypes = [
    ...(data?.can_administer_geography ? ['create_region', 'update_region', 'create_district', 'update_district', 'assign_districts_to_region'] : []),
    ...(data?.can_assign_supervisor ? ['assign_region_supervisor'] : []),
    ...(data?.can_manage ? ['create_team', 'update_team', 'assign_officer', 'remove_officer'] : []),
    ...(data?.can_recommend ? ['update_team', 'assign_officer', 'remove_officer'] : []),
  ]

  const stageTypes = (STAGE_ACTIONS[stage] || []).filter((type) => visibleTypes.includes(type))

  const openAction = (type, item = null) => {
    const next = { ...EMPTY_FORM, change_type: type }
    if (type === 'update_region' && item) Object.assign(next, {
      region_id: String(item.id),
      name: item.name,
      code: item.code,
      classification: item.classification,
      description: item.description || '',
      is_active: item.is_active,
    })
    if (type === 'update_district' && item) Object.assign(next, {
      district_id: String(item.id),
      name: item.name,
      is_active: item.is_active,
    })
    if (type === 'assign_region_supervisor' && item) Object.assign(next, {
      region_id: String(item.id),
      supervisor_id: item.supervisor_id ? String(item.supervisor_id) : '',
    })
    if (type === 'update_team' && item) Object.assign(next, {
      team_id: String(item.id),
      name: item.name,
      region_id: item.region_id ? String(item.region_id) : '',
      district_ids: item.districts.map((district) => String(district.id)),
      is_active: item.is_active,
    })
    if (type === 'assign_officer' && item) Object.assign(next, {
      team_id: String(item.id),
      primary_district_id: String(item.districts[0]?.id || ''),
    })
    if (type === 'remove_officer' && item) Object.assign(next, { team_id: String(item.id) })
    setForm(next)
    setError('')
    setDialogOpen(true)
  }

  const updateForm = (key) => (event) => {
    const value = event.target.type === 'checkbox' ? event.target.checked : event.target.value
    setForm((current) => ({ ...current, [key]: value }))
  }

  const chooseTeam = (event) => {
    const teamId = event.target.value
    const selected = data?.teams?.find((item) => String(item.id) === String(teamId))
    setForm((current) => ({
      ...current,
      team_id: teamId,
      ...(current.change_type === 'update_team' && selected ? {
        name: selected.name,
        region_id: selected.region_id ? String(selected.region_id) : '',
        district_ids: selected.districts.map((district) => String(district.id)),
        is_active: selected.is_active,
      } : {}),
      ...(current.change_type === 'assign_officer' && selected ? {
        primary_district_id: String(selected.districts[0]?.id || ''),
      } : {}),
    }))
  }

  const chooseRegion = (event) => {
    const regionId = event.target.value
    const selected = data?.regions?.find((item) => String(item.id) === String(regionId))
    setForm((current) => ({
      ...current,
      region_id: regionId,
      district_ids: current.change_type === 'update_team' && selected
        ? current.district_ids.filter((id) => selected.districts.some((district) => String(district.id) === String(id)))
        : [],
      ...(current.change_type === 'create_team' ? { member_primary_district_id: '' } : {}),
      ...(current.change_type === 'update_region' && selected ? {
        name: selected.name,
        code: selected.code,
        classification: selected.classification,
        description: selected.description || '',
        is_active: selected.is_active,
      } : {}),
    }))
  }

  const chooseDistrict = (event) => {
    const districtId = event.target.value
    const selected = data?.districts?.find((item) => String(item.id) === String(districtId))
    setForm((current) => ({
      ...current,
      district_id: districtId,
      ...(current.change_type === 'update_district' && selected ? {
        name: selected.name,
        is_active: selected.is_active,
      } : {}),
    }))
  }

  const submit = async (event) => {
    event.preventDefault()
    setBusy(true)
    setError('')
    setMessage('')
    const payload = { change_type: form.change_type }
    if (['create_region', 'create_district', 'create_team'].includes(form.change_type)) payload.name = form.name
    if (form.change_type === 'create_region') {
      payload.code = form.code
      payload.classification = form.classification
      if (form.description) payload.description = form.description
    }
    if (form.change_type === 'update_region') {
      payload.region_id = Number(form.region_id)
      payload.name = form.name
      payload.code = form.code
      payload.classification = form.classification
      payload.description = form.description || null
      payload.is_active = form.is_active
    }
    if (form.change_type === 'assign_region_supervisor') {
      payload.region_id = Number(form.region_id)
      payload.supervisor_id = Number(form.supervisor_id)
      if (form.reason) payload.reason = form.reason
    }
    if (form.change_type === 'update_district') {
      payload.district_id = Number(form.district_id)
      payload.name = form.name
      payload.is_active = form.is_active
    }
    if (form.change_type === 'assign_districts_to_region') {
      payload.region_id = Number(form.region_id)
      payload.district_ids = form.district_ids.map(Number)
      if (form.reason) payload.reason = form.reason
    }
    if (['create_team', 'update_team'].includes(form.change_type)) {
      payload.district_ids = form.district_ids.map(Number)
    }
    if (['create_region', 'update_region', 'create_district', 'update_district', 'create_team', 'update_team'].includes(form.change_type) && form.reason) {
      payload.reason = form.reason
    }
    if (form.change_type === 'create_team') {
      const memberIds = [...new Set([form.team_lead_id, ...form.member_ids].filter(Boolean))]
      if (!form.team_lead_id) {
        setError('Select a Team Lead for the new team.')
        setBusy(false)
        return
      }
      const primaryDistrictId = Number(form.member_primary_district_id)
      if (!primaryDistrictId) {
        setError('Select a primary district for the new team members.')
        setBusy(false)
        return
      }
      payload.members = memberIds.map((id) => ({
        officer_id: Number(id),
        primary_district_id: primaryDistrictId,
        is_team_lead: String(id) === String(form.team_lead_id),
      }))
    }
    if (['update_team', 'assign_officer', 'remove_officer'].includes(form.change_type)) payload.team_id = Number(form.team_id)
    if (form.change_type === 'update_team') {
      payload.name = form.name
      payload.is_active = form.is_active
    }
    if (form.change_type === 'assign_officer') {
      payload.officer_id = Number(form.officer_id)
      payload.primary_district_id = Number(form.primary_district_id)
      payload.is_team_lead = form.is_team_lead
    }
    if (form.change_type === 'remove_officer') payload.officer_id = Number(form.officer_id)

    try {
      const response = await api.post('/enforcement/organization/recommendations', payload)
      const result = unwrap(response)
      setMessage(result.message || 'Organization change submitted for review.')
      setForm(EMPTY_FORM)
      setDialogOpen(false)
      setStage('overview')
      setStatusFilter(result.data?.status || 'pending')
      await load()
    } catch (ex) {
      setError(errMsg(ex, 'Could not submit the organization change.'))
    } finally {
      setBusy(false)
    }
  }

  const review = async () => {
    if (!reviewTarget || !reviewDecision) return
    if (reviewDecision === 'reject' && !reviewNotes.trim()) {
      setReviewError('A reason is required to reject this recommendation.')
      return
    }
    setBusy(true)
    setError('')
    setMessage('')
    try {
      await api.post(`/enforcement/organization/recommendations/${reviewTarget.id}/${reviewDecision}`, reviewDecision === 'reject' ? { notes: reviewNotes.trim() } : {})
      setMessage(`Recommendation ${reviewDecision === 'approve' ? 'approved' : 'rejected'}.`)
      setStatusFilter(reviewDecision === 'approve' ? 'approved' : 'rejected')
      setReviewTarget(null)
      setReviewDecision('')
      setReviewNotes('')
      setReviewError('')
      await load()
    } catch (ex) {
      setError(errMsg(ex, 'Could not review the recommendation.'))
    } finally {
      setBusy(false)
    }
  }

  if (!data && !error) return <Spinner label="Loading Enforcement organization…" />

  const regions = data?.regions || []
  const districts = data?.districts || []
  const teams = data?.teams || []
  const officers = data?.officers || []
  const recommendations = data?.recommendations || []
  const activeRegions = regions.filter((region) => region.is_active)
  const unassignedDistricts = districts.filter((district) => district.is_active && !district.region_id)
  const pendingDistrictAssignments = recommendations.filter((item) =>
    item.change_type === 'assign_districts_to_region' && item.status === 'pending')
  const pendingDistricts = new Map()
  pendingDistrictAssignments.forEach((recommendation) => {
    const districtIds = recommendation.payload?.district_ids || []
    districtIds.forEach((districtId) => {
      if (!pendingDistricts.has(String(districtId))) {
        pendingDistricts.set(String(districtId), recommendation)
      }
    })
  })
  const assignableUnassignedDistricts = unassignedDistricts.filter((district) => !pendingDistricts.has(String(district.id)))
  const pendingUnassignedDistrictCount = unassignedDistricts.filter((district) => pendingDistricts.has(String(district.id))).length
  const assignedDistrictCount = districts.filter((district) => district.region_id).length
  const supervisorCount = activeRegions.filter((region) => region.supervisor_id && region.supervisor_active).length
  const teamLeads = teams.flatMap((item) => item.officers || []).filter((person) => person.is_team_lead)
  const activeOfficerCount = teams.reduce((count, item) => count + (item.officers || []).length, 0)
  const pendingCount = recommendations.filter((item) => item.status === 'pending').length
  const nextStage = regions.length === 0 ? 'regions'
    : districts.length === 0 || unassignedDistricts.length > 0 ? 'districts'
      : supervisorCount < activeRegions.length ? 'supervisors'
        : teams.length === 0 ? 'teams'
          : teamLeads.length === 0 ? 'members'
            : 'overview'
  const stats = [
    { label: 'Tax regions', value: regions.length, icon: '◉', stage: 'regions', note: `${activeRegions.length} active` },
    { label: 'Districts configured', value: `${assignedDistrictCount}/${districts.length}`, icon: '⌖', stage: 'districts', note: `${unassignedDistricts.length} need assignment` },
    { label: 'Regional Supervisors', value: `${supervisorCount}/${activeRegions.length}`, icon: '◎', stage: 'supervisors', note: 'One primary per region' },
    { label: 'Approved teams', value: teams.length, icon: '▦', stage: 'teams', note: `${teams.filter((item) => item.is_active).length} active` },
    { label: 'Leads & officers', value: activeOfficerCount, icon: '♙', stage: 'members', note: `${teamLeads.length} Team Leads` },
    { label: 'Pending approvals', value: pendingCount, icon: '◷', stage: 'overview', note: 'Awaiting review' },
  ]
  const stageStatus = (id) => {
    if (id === 'regions') return regions.length > 0 ? 'Completed' : 'Current'
    if (id === 'districts') return districts.length > 0 && unassignedDistricts.length === 0 ? 'Completed' : regions.length === 0 ? 'Ready' : 'Current'
    if (id === 'supervisors') return activeRegions.length > 0 && supervisorCount === activeRegions.length ? 'Completed' : activeRegions.length === 0 ? 'Locked' : 'Current'
    if (id === 'teams') return teams.length > 0 ? 'Completed' : supervisorCount > 0 ? 'Current' : 'Ready'
    if (id === 'members') return teamLeads.length > 0 ? 'Completed' : teams.length > 0 ? 'Current' : 'Locked'
    return 'Ready'
  }
  const stageLockedReason = (id) => id === 'supervisors' && activeRegions.length === 0
    ? 'Create a Tax Region first'
    : id === 'members' && teams.length === 0 ? 'Create a team first' : ''
  const filteredRecommendations = recommendations.filter((item) => item.status === statusFilter)
  const matches = (value) => String(value || '').toLocaleLowerCase().includes(search.trim().toLocaleLowerCase())
  const searchedRegions = regions.filter((region) => matches(region.name) || matches(region.code))
  const searchedDistricts = districts.filter((district) => matches(district.name) || matches(district.region))
  const searchedTeams = teams.filter((item) => matches(item.name) || matches(item.region) || matches(item.supervisor))
  const openReview = (item, decision) => {
    setReviewTarget(item)
    setReviewDecision(decision)
    setReviewNotes('')
    setReviewError('')
  }
  const toggleBranch = (key) => setExpanded((current) => ({ ...current, [key]: !(current[key] ?? true) }))
  const openAll = () => setExpanded(Object.fromEntries(regions.map((region) => [`region-${region.id}`, true])))
  const closeAll = () => setExpanded(Object.fromEntries(regions.map((region) => [`region-${region.id}`, false])))
  const actionsForStage = stageTypes
  const stagePrimaryAction = {
    regions: 'create_region',
    districts: assignableUnassignedDistricts.length ? 'assign_districts_to_region' : unassignedDistricts.length ? null : 'create_district',
    supervisors: 'assign_region_supervisor',
    teams: 'create_team',
    members: 'assign_officer',
  }[stage]
  const stageTitle = STAGES.find((item) => item.id === stage)?.label || 'Organization Overview'

  const field = (label, control) => (
    <label className="block space-y-1.5 text-xs font-semibold text-slate-600">
      <span>{label}</span>
      {control}
    </label>
  )

  return (
    <div className="space-y-5 pb-8">
      <ErrorBox error={error} />
      {message && <div role="status" className="flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><span className="text-lg">✓</span><span>{message}</span></div>}

      <header className="overflow-hidden rounded-3xl bg-gradient-to-br from-navy-900 via-navy-800 to-navy-600 p-5 text-white shadow-lg sm:p-7">
        <div className="flex flex-col justify-between gap-6 lg:flex-row lg:items-end">
          <div>
            <nav aria-label="Breadcrumb" className="mb-3 text-xs font-semibold tracking-wide text-blue-100/80">Enforcement <span className="mx-1.5 text-blue-200/50">/</span> Team Management</nav>
            <p className="mb-2 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.16em] text-blue-100">
              <span className="h-1.5 w-1.5 rounded-full bg-emerald-300" /> Organization workspace
            </p>
            <h1 className="text-2xl font-extrabold tracking-tight sm:text-3xl">Team Management</h1>
            <p className="mt-2 max-w-2xl text-sm leading-6 text-blue-100/85">Configure and manage the Enforcement organizational structure, from Tax Regions through field teams.</p>
          </div>
          {nextStage === 'overview' ? (
            <button type="button" onClick={() => setStage('overview')} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-navy-800 shadow-sm transition hover:bg-blue-50 focus:outline-none focus:ring-4 focus:ring-white/30">
              <span className="text-lg leading-none">⌘</span>View organization
            </button>
          ) : stagePrimaryAction && visibleTypes.includes(stagePrimaryAction) && (
            <button type="button" onClick={() => { setStage(nextStage); openAction(stagePrimaryAction) }} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-navy-800 shadow-sm transition hover:bg-blue-50 focus:outline-none focus:ring-4 focus:ring-white/30">
              <span className="text-lg leading-none">＋</span>{titleFor(stagePrimaryAction)}
            </button>
          )}
        </div>
      </header>

      <section aria-label="Organization summary" className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
        {stats.map((item) => (
          <button type="button" key={item.label} onClick={() => setStage(item.stage)} className="group rounded-2xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md focus:outline-none focus:ring-4 focus:ring-brand-500/10">
            <span className="flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-slate-500"><span>{item.label}</span><span className="text-xl text-brand-500">{item.icon}</span></span>
            <span className="mt-2 block text-2xl font-extrabold tracking-tight text-navy-800">{item.value}</span>
            <span className="mt-1 block text-[11px] text-slate-500">{item.note}</span>
          </button>
        ))}
      </section>

      <section className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
          <div>
            <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-brand-600">Guided setup</p>
            <h2 className="mt-1 text-lg font-extrabold text-navy-800">Build your organization</h2>
          </div>
          <p className="text-xs text-slate-500">Select any available stage to manage or review it.</p>
        </div>
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
          {STAGES.map((item, index) => {
            const status = stageStatus(item.id)
            const locked = status === 'Locked'
            const active = stage === item.id
            return (
              <button type="button" key={item.id} disabled={locked} onClick={() => setStage(item.id)} title={locked ? stageLockedReason(item.id) : item.description}
                className={`relative min-h-[112px] rounded-2xl border p-3 text-left transition focus:outline-none focus:ring-4 focus:ring-brand-500/10 ${active ? 'border-navy-700 bg-navy-800 text-white shadow-md' : locked ? 'cursor-not-allowed border-slate-100 bg-slate-50 text-slate-400' : 'border-slate-200 bg-white text-slate-700 hover:border-brand-200 hover:bg-brand-50/40'}`}>
                {index < STAGES.length - 1 && <span aria-hidden="true" className="absolute -right-2 top-7 z-10 hidden h-px w-3 bg-slate-300 2xl:block" />}
                <span className="flex items-center justify-between">
                  <span className={`flex h-8 w-8 items-center justify-center rounded-xl text-sm font-bold ${active ? 'bg-white/15 text-white' : 'bg-navy-50 text-navy-700'}`}>{item.icon}</span>
                  <span className={`rounded-full px-2 py-1 text-[9px] font-extrabold uppercase tracking-wide ${active ? 'bg-white/15 text-blue-100' : status === 'Completed' ? 'bg-emerald-50 text-emerald-700' : locked ? 'bg-slate-100 text-slate-500' : 'bg-amber-50 text-amber-700'}`}>{status}</span>
                </span>
                <span className={`mt-3 block text-sm font-bold ${active ? 'text-white' : ''}`}>{item.label}</span>
                <span className={`mt-1 block text-[11px] leading-4 ${active ? 'text-blue-100/80' : locked ? 'text-slate-400' : 'text-slate-500'}`}>{locked ? stageLockedReason(item.id) : item.description}</span>
              </button>
            )
          })}
        </div>
      </section>

      <section className="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <div className="flex flex-col gap-4 border-b border-slate-100 p-4 sm:p-5 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <p className="text-[11px] font-bold uppercase tracking-[0.15em] text-brand-600">Workspace · {stageTitle}</p>
            <h2 className="mt-1 text-xl font-extrabold text-navy-800">{stageTitle}</h2>
            <p className="mt-1 max-w-2xl text-sm text-slate-500">{STAGES.find((item) => item.id === stage)?.description || 'Explore relationships across the Enforcement organization.'}</p>
          </div>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            {stage === 'overview' && <div className="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1" role="group" aria-label="Overview display mode">
              <button type="button" onClick={() => setTableView(false)} aria-pressed={!tableView} className={`rounded-lg px-3 py-2 text-xs font-bold ${!tableView ? 'bg-white text-navy-800 shadow-sm' : 'text-slate-500'}`}>Hierarchy</button>
              <button type="button" onClick={() => setTableView(true)} aria-pressed={tableView} className={`rounded-lg px-3 py-2 text-xs font-bold ${tableView ? 'bg-white text-navy-800 shadow-sm' : 'text-slate-500'}`}>Table</button>
            </div>}
            <label className="relative block min-w-0 sm:min-w-56">
                <span className="sr-only">Search {stageTitle}</span>
                <span aria-hidden="true" className="absolute left-3 top-2.5 text-slate-400">⌕</span>
                <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={`Search ${stageTitle.toLowerCase()}`} className="w-full pl-9" />
            </label>
          </div>
        </div>

        <div className="p-4 sm:p-6">
          {stage === 'regions' && (
            regions.length === 0 ? (
              <EmptyState icon="◉" title="Start with a Tax Region" description="Create an approved Rural or Urban region before assigning districts and regional supervision." action={actionsForStage.includes('create_region') ? () => openAction('create_region') : null} actionLabel="Create Tax Region" />
            ) : (
              <div className="grid gap-3 xl:grid-cols-2">
                {searchedRegions.map((region) => (
                  <article key={region.id} className="rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-navy-200 hover:shadow-sm sm:p-5">
                    <div className="flex items-start justify-between gap-3">
                      <div className="flex min-w-0 gap-3">
                        <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-lg ${region.classification === 'Rural' ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700'}`}>{region.classification === 'Rural' ? '⌂' : '▥'}</span>
                        <div className="min-w-0"><h3 className="truncate font-extrabold text-navy-800">{region.name}</h3><p className="mt-1 text-xs text-slate-500">{region.code} · {region.classification}</p></div>
                      </div>
                      <Badge tone={region.is_active ? 'green' : 'slate'}>{region.is_active ? 'Active' : 'Inactive'}</Badge>
                    </div>
                    <div className="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3">
                      <SummaryValue label="Regional Supervisor" value={region.supervisor || 'Not assigned'} />
                      <SummaryValue label="Tax Districts" value={region.districts?.length || 0} />
                    </div>
                    {region.description && <p className="mt-3 text-sm leading-5 text-slate-600">{region.description}</p>}
                    <div className="mt-4 flex justify-end">{actionsForStage.includes('update_region') && <ActionButton onClick={() => openAction('update_region', region)}>Edit region</ActionButton>}</div>
                  </article>
                ))}
                {searchedRegions.length === 0 && <EmptyState icon="⌕" title="No matching regions" description="Try another search term or clear the search field." />}
              </div>
            )
          )}

          {stage === 'districts' && (
            <>
              {unassignedDistricts.length > 0 && <div className="mb-4 flex flex-col justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center"><div><p className="font-bold text-amber-900">{unassignedDistricts.length} active {unassignedDistricts.length === 1 ? 'district needs' : 'districts need'} a region</p>{pendingUnassignedDistrictCount > 0 && <p className="mt-1 text-xs font-semibold text-amber-900">{pendingUnassignedDistrictCount} {pendingUnassignedDistrictCount === 1 ? 'district is' : 'districts are'} pending Assistant Commissioner approval. Districts update after approval.</p>}<p className="mt-1 text-xs text-amber-800">Existing district classification is not inferred from its name.</p></div>{assignableUnassignedDistricts.length > 0 && actionsForStage.includes('assign_districts_to_region') && <ActionButton prominent onClick={() => openAction('assign_districts_to_region')}>Assign districts</ActionButton>}</div>}
              {districts.length === 0 ? <EmptyState icon="⌖" title="No Tax Districts yet" description="Add a district, then assign it to an approved Tax Region." action={actionsForStage.includes('create_district') ? () => openAction('create_district') : null} actionLabel="Add Tax District" /> : (
                <div className="overflow-hidden rounded-2xl border border-slate-200">
                  <div className="hidden grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_120px_100px] gap-3 bg-slate-50 px-4 py-3 text-[10px] font-extrabold uppercase tracking-wider text-slate-500 sm:grid"><span>Tax District</span><span>Region</span><span>Status</span><span className="text-right">Actions</span></div>
                  <div className="divide-y divide-slate-100">
                    {searchedDistricts.map((district) => (
                      <div key={district.id} className="grid gap-2 px-4 py-3 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_120px_100px] sm:items-center sm:gap-3">
                        <div><p className="font-bold text-navy-800">{district.name}</p><p className="text-[11px] text-slate-500 sm:hidden">{district.region ? `${district.region} · ${district.classification}` : pendingDistricts.has(String(district.id)) ? `Pending AC approval · ${regions.find((region) => String(region.id) === String(pendingDistricts.get(String(district.id)).payload?.region_id))?.name || 'region assignment'}` : 'Not assigned to a region'}</p></div>
                        <p className="hidden text-sm text-slate-600 sm:block">{district.region ? `${district.region} · ${district.classification}` : pendingDistricts.has(String(district.id)) ? <span className="text-amber-700">Pending AC approval · {regions.find((region) => String(region.id) === String(pendingDistricts.get(String(district.id)).payload?.region_id))?.name || 'region assignment'}</span> : <span className="text-amber-700">Not assigned</span>}</p>
                        <span><Badge tone={district.is_active ? 'green' : 'slate'}>{district.is_active ? 'Active' : 'Inactive'}</Badge></span>
                        <div className="flex gap-2 sm:justify-end">{actionsForStage.includes('update_district') && <ActionButton onClick={() => openAction('update_district', district)}>Edit</ActionButton>}</div>
                      </div>
                    ))}
                    {searchedDistricts.length === 0 && <EmptyState icon="⌕" title="No matching districts" description="Try another search term." />}
                  </div>
                </div>
              )}
            </>
          )}

          {stage === 'supervisors' && (
            activeRegions.length === 0 ? <EmptyState icon="◎" title="No active regions to supervise" description="Create a Tax Region before assigning its regional Supervisor." action={actionsForStage.includes('create_region') ? () => { setStage('regions'); openAction('create_region') } : null} actionLabel="Create Tax Region" /> : (
              <div className="grid gap-3 xl:grid-cols-2">
                {activeRegions.filter((region) => matches(region.name) || matches(region.supervisor)).map((region) => (
                  <article key={region.id} className="flex flex-col justify-between gap-4 rounded-2xl border border-slate-200 p-4 sm:flex-row sm:items-center sm:p-5">
                    <div className="flex items-center gap-3">
                      <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-navy-50 text-lg text-navy-700">◎</span>
                      <div><h3 className="font-extrabold text-navy-800">{region.name}</h3><p className="mt-1 text-xs text-slate-500">{region.classification} · {region.districts?.length || 0} districts</p><p className="mt-1 text-sm text-slate-700">{region.supervisor || 'Supervisor not assigned'}</p></div>
                    </div>
                    <div className="flex items-center gap-2">{region.supervisor_id && region.supervisor_active ? <Badge tone="green">Assigned</Badge> : <Badge tone="amber">Requires attention</Badge>}{actionsForStage.includes('assign_region_supervisor') && <ActionButton onClick={() => openAction('assign_region_supervisor', region)}>{region.supervisor ? 'Reassign' : 'Assign Supervisor'}</ActionButton>}</div>
                  </article>
                ))}
              </div>
            )
          )}

          {stage === 'teams' && (
            teams.length === 0 ? <EmptyState icon="▦" title="No approved teams yet" description={supervisorCount ? 'Create a team in an active region. The regional Supervisor is inherited automatically.' : 'Assign an active Supervisor to a region before creating a team.'} action={actionsForStage.includes('create_team') && supervisorCount > 0 ? () => openAction('create_team') : null} actionLabel="Create Enforcement Team" /> : (
              <div className="grid gap-3 xl:grid-cols-2">
                {searchedTeams.map((item) => (
                  <article key={item.id} className="rounded-2xl border border-slate-200 p-4 transition hover:border-navy-200 hover:shadow-sm sm:p-5">
                    <div className="flex items-start justify-between gap-3"><div><h3 className="font-extrabold text-navy-800">{item.name}</h3><p className="mt-1 text-xs text-slate-500">{item.region || 'Region not configured'}{item.classification ? ` · ${item.classification}` : ''}</p></div><div className="flex flex-wrap justify-end gap-1.5">{!item.is_configured && <Badge tone="amber">Needs setup</Badge>}<Badge tone={item.is_active ? 'green' : 'slate'}>{item.is_active ? 'Active' : 'Inactive'}</Badge></div></div>
                    <div className="mt-4 grid gap-3 rounded-xl bg-slate-50 p-3 sm:grid-cols-2"><SummaryValue label="Regional Supervisor" value={item.supervisor || '—'} /><SummaryValue label="Districts served" value={item.districts?.map((district) => district.name).join(', ') || '—'} /></div>
                    <div className="mt-4 flex flex-wrap justify-end gap-2">{actionsForStage.includes('update_team') && <ActionButton onClick={() => openAction('update_team', item)}>Update team</ActionButton>}{actionsForStage.includes('assign_officer') && <ActionButton prominent onClick={() => openAction('assign_officer', item)}>Manage members</ActionButton>}</div>
                  </article>
                ))}
                {searchedTeams.length === 0 && <EmptyState icon="⌕" title="No matching teams" description="Try another search term." />}
              </div>
            )
          )}

          {stage === 'members' && (
            <>
              <div className="mb-4 flex flex-col justify-between gap-3 rounded-2xl bg-navy-50 p-4 sm:flex-row sm:items-center"><div><p className="font-bold text-navy-900">Membership follows the approved team structure</p><p className="mt-1 text-xs text-slate-600">Every team requires a Team Lead, and each officer has an assigned primary district.</p></div>{actionsForStage.includes('assign_officer') && <ActionButton prominent onClick={() => openAction('assign_officer')}>Assign Officer</ActionButton>}</div>
              {teams.length === 0 ? <EmptyState icon="♙" title="Create an Enforcement Team first" description="Team membership can be managed after a team has been approved." action={actionsForStage.includes('create_team') ? () => { setStage('teams'); openAction('create_team') } : null} actionLabel="Create Enforcement Team" /> : (
                <div className="space-y-3">
                  {searchedTeams.map((item) => (
                    <article key={item.id} className="overflow-hidden rounded-2xl border border-slate-200">
                      <div className="flex flex-col justify-between gap-2 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center"><div><h3 className="font-extrabold text-navy-800">{item.name}</h3><p className="text-xs text-slate-500">{item.region || 'Unconfigured region'} · Supervisor: {item.supervisor || '—'}</p></div><span className="text-xs font-semibold text-slate-500">{item.officers?.length || 0} members</span></div>
                      <div className="divide-y divide-slate-100">
                        {(item.officers || []).map((person) => (
                          <div key={person.membership_id} className="flex flex-col justify-between gap-2 px-4 py-3 sm:flex-row sm:items-center">
                            <div className="flex min-w-0 items-center gap-3"><span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-extrabold ${person.is_team_lead ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'}`}>{(person.officer || '?').slice(0, 1)}</span><div><p className="font-bold text-slate-800">{person.officer || 'Officer'} {person.is_team_lead && <Badge tone="amber">Team Lead</Badge>}</p><p className="text-xs text-slate-500">{person.staff_id || 'No staff ID'} · Primary district: {person.primary_district || '—'}</p></div></div>
                            {actionsForStage.includes('remove_officer') && <ActionButton onClick={() => { openAction('remove_officer', item); setForm((current) => ({ ...current, officer_id: String(person.officer_id) })) }}>Remove</ActionButton>}
                          </div>
                        ))}
                        {(!item.officers || item.officers.length === 0) && <p className="px-4 py-5 text-sm text-slate-500">No officers assigned. Assign a Team Lead to configure this team for work.</p>}
                      </div>
                    </article>
                  ))}
                </div>
              )}
            </>
          )}

          {stage === 'overview' && (
            <div className="space-y-8">
              <section>
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                  <div><h3 className="font-extrabold text-navy-800">Organization hierarchy</h3><p className="mt-1 text-xs text-slate-500">Enforcement Manager → Tax Region → Supervisor → Districts → Teams → Team Leads and officers</p></div>
                  {!tableView && <div className="flex gap-2"><ActionButton onClick={openAll}>Expand all</ActionButton><ActionButton onClick={closeAll}>Collapse all</ActionButton></div>}
                </div>
                {tableView ? (
                  <div className="overflow-x-auto rounded-2xl border border-slate-200">
                    <table className="w-full min-w-[700px] text-left text-sm"><thead className="bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500"><tr><th className="px-4 py-3">Region / Team</th><th className="px-4 py-3">Supervisor</th><th className="px-4 py-3">Districts</th><th className="px-4 py-3">Members</th><th className="px-4 py-3">Status</th></tr></thead><tbody className="divide-y divide-slate-100">{regions.map((region) => (region.teams || teams.filter((item) => item.region_id === region.id)).map((item) => <tr key={`${region.id}-${item.id}`}><td className="px-4 py-3 font-semibold text-navy-800">{region.name} / {item.name}</td><td className="px-4 py-3">{region.supervisor || item.supervisor || '—'}</td><td className="px-4 py-3">{item.districts?.map((district) => district.name).join(', ') || '—'}</td><td className="px-4 py-3">{item.officers?.length || 0}</td><td className="px-4 py-3"><Badge tone={item.is_configured ? 'green' : 'amber'}>{item.is_configured ? 'Configured' : 'Needs setup'}</Badge></td></tr>))}</tbody></table>
                  </div>
                ) : regions.length === 0 ? <EmptyState icon="⌘" title="Your hierarchy will appear here" description="Add a Tax Region to begin configuring the organizational structure." action={actionsForStage.includes('create_region') ? () => { setStage('regions'); openAction('create_region') } : null} actionLabel="Create Tax Region" /> : (
                  <div className="space-y-3">
                    {searchedRegions.map((region) => {
                      const regionTeams = searchedTeams.filter((item) => item.region_id === region.id)
                      const regionDistricts = searchedDistricts.filter((district) => district.region_id === region.id)
                      const open = expanded[`region-${region.id}`] ?? true
                      return (
                        <article key={region.id} className="rounded-2xl border border-slate-200 bg-white">
                          <button type="button" aria-expanded={open} onClick={() => toggleBranch(`region-${region.id}`)} className="flex w-full items-center gap-3 p-4 text-left focus:outline-none focus:ring-4 focus:ring-brand-500/10">
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-navy-50 font-bold text-navy-700">◉</span>
                            <span className="min-w-0 flex-1"><span className="block font-extrabold text-navy-800">{region.name} <span className="text-xs font-semibold text-slate-400">{region.classification}</span></span><span className="mt-1 block text-xs text-slate-500">Regional Supervisor: {region.supervisor || 'Not assigned'} · {regionDistricts.length} districts · {regionTeams.length} teams</span></span>
                            <Badge tone={region.is_active ? 'green' : 'slate'}>{region.is_active ? 'Active' : 'Inactive'}</Badge><span aria-hidden="true" className="text-slate-400">{open ? '−' : '+'}</span>
                          </button>
                          {open && <div className="ml-5 space-y-3 border-l-2 border-navy-100 pb-4 pl-4 sm:ml-8 sm:pl-5">
                            <div className="flex items-center gap-2 rounded-xl bg-blue-50 px-3 py-2.5 text-sm"><span className="text-blue-700">◎</span><span className="font-bold text-blue-900">Regional Supervisor</span><span className="text-slate-600">{region.supervisor || 'Not assigned'}</span>{!region.supervisor && <Badge tone="amber">Requires attention</Badge>}</div>
                            <div className="rounded-xl border border-slate-100 bg-slate-50/70 p-3"><p className="mb-2 text-[10px] font-extrabold uppercase tracking-wider text-slate-500">Tax Districts</p><div className="flex flex-wrap gap-2">{regionDistricts.map((district) => <span key={district.id} className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700">⌖ {district.name}</span>)}{regionDistricts.length === 0 && <span className="text-xs text-slate-500">No districts assigned</span>}</div></div>
                            {regionTeams.map((item) => <div key={item.id} className="rounded-xl border border-slate-200 bg-white p-3"><div className="flex flex-wrap items-center justify-between gap-2"><div><p className="font-extrabold text-navy-800">▦ {item.name}</p><p className="mt-1 text-xs text-slate-500">Districts: {item.districts?.map((district) => district.name).join(', ') || '—'}</p></div><Badge tone={item.is_configured ? 'green' : 'amber'}>{item.is_configured ? 'Configured' : 'Needs setup'}</Badge></div><div className="mt-3 flex flex-wrap gap-2">{(item.officers || []).map((person) => <span key={person.membership_id} className={`rounded-lg px-2.5 py-1.5 text-xs font-semibold ${person.is_team_lead ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-700'}`}>♙ {person.officer}{person.is_team_lead ? ' · Team Lead' : ''}</span>)}{(!item.officers || item.officers.length === 0) && <span className="text-xs text-slate-500">No assigned members</span>}</div></div>)}
                          </div>}
                        </article>
                      )
                    })}
                  </div>
                )}
              </section>

              <section>
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3"><div><h3 className="font-extrabold text-navy-800">Approvals & recommendations</h3><p className="mt-1 text-xs text-slate-500">Review structural and operational requests within your authority.</p></div><span className="rounded-full bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-800">{pendingCount} pending</span></div>
                <div className="mb-3 flex flex-wrap gap-2" role="tablist" aria-label="Recommendation status">
                  {['pending', 'approved', 'rejected'].map((status) => <button type="button" role="tab" aria-selected={statusFilter === status} key={status} onClick={() => setStatusFilter(status)} className={`rounded-xl px-3 py-2 text-xs font-bold capitalize transition focus:outline-none focus:ring-4 focus:ring-brand-500/10 ${statusFilter === status ? 'bg-navy-800 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}>{status} <span className={statusFilter === status ? 'text-white/70' : 'text-slate-400'}>{recommendations.filter((item) => item.status === status).length}</span></button>)}
                </div>
                {filteredRecommendations.length === 0 ? <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50/70 px-4 py-8 text-center"><span className="text-2xl">✓</span><p className="mt-2 text-sm font-bold text-slate-700">No {statusFilter} requests</p><p className="mt-1 text-xs text-slate-500">Requests will appear here when an organization change is submitted.</p></div> : (
                  <div className="space-y-3">
                    {filteredRecommendations.map((item) => (
                      <article key={item.id} className="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start"><div className="flex min-w-0 gap-3"><span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${item.status === 'pending' ? 'bg-amber-50 text-amber-700' : item.status === 'approved' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}>{item.status === 'pending' ? '◷' : item.status === 'approved' ? '✓' : '×'}</span><div><h4 className="font-extrabold text-navy-800">{titleFor(item.change_type)}</h4><p className="mt-1 text-xs text-slate-500">Submitted by {item.submitted_by || '—'} · {item.submitted_at ? new Date(item.submitted_at).toLocaleString() : '—'}</p></div></div><Badge tone={item.status === 'approved' ? 'green' : item.status === 'rejected' ? 'red' : 'amber'}>{item.status}</Badge></div>
                        <dl className="mt-4 grid gap-x-5 gap-y-3 rounded-xl bg-slate-50 p-3 text-sm sm:grid-cols-2">{recommendationDetails(item, data).filter(([, value]) => value !== null && value !== undefined && value !== '').map(([name, value]) => <div key={name}><dt className="text-[10px] font-bold uppercase tracking-wide text-slate-500">{name}</dt><dd className="mt-1 break-words font-medium text-slate-700">{value}</dd></div>)}</dl>
                        {item.previous_values && <details className="mt-3 rounded-xl border border-slate-200 px-3 py-2"><summary className="cursor-pointer text-xs font-bold text-slate-600">Previous values</summary><pre className="mt-2 overflow-auto text-xs text-slate-600">{JSON.stringify(item.previous_values, null, 2)}</pre></details>}
                        {item.decision_notes && <p className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">Decision note: {item.decision_notes}</p>}
                        {item.can_review && item.status === 'pending' && <div className="mt-4 flex flex-wrap justify-end gap-2"><ActionButton onClick={() => openReview(item, 'reject')}>Reject request</ActionButton><ActionButton prominent onClick={() => openReview(item, 'approve')}>Approve request</ActionButton></div>}
                      </article>
                    ))}
                  </div>
                )}
              </section>
            </div>
          )}
        </div>
      </section>

      {dialogOpen && (
        <div className="fixed inset-0 z-[500] flex items-end justify-center bg-navy-950/55 p-0 backdrop-blur-sm sm:items-center sm:p-5" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) setDialogOpen(false) }}>
          <section ref={dialogRef} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby="org-dialog-title" className="flex max-h-[96dvh] w-full max-w-2xl flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl outline-none sm:max-h-[90vh] sm:rounded-3xl">
            <div className="flex items-start justify-between gap-4 border-b border-slate-100 bg-gradient-to-r from-navy-50 to-white px-5 py-4 sm:px-6">
              <div><p className="text-[10px] font-extrabold uppercase tracking-[0.16em] text-brand-600">{stageTitle} · Organization setup</p><h2 id="org-dialog-title" className="mt-1 text-xl font-extrabold text-navy-800">{titleFor(form.change_type)}</h2><p className="mt-1 text-xs leading-5 text-slate-500">{data?.can_manage && ['assign_region_supervisor', 'assign_officer', 'remove_officer'].includes(form.change_type) ? 'This operational change is applied under your assigned authority.' : `This request will be sent to ${form.change_type === 'update_team' && data?.can_recommend ? 'the responsible Enforcement Manager' : 'the Assistant Commissioner'} for review.`}</p></div>
              <button type="button" disabled={busy} onClick={() => setDialogOpen(false)} aria-label="Close dialog" title="Close" className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-xl text-slate-500 transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-brand-500/10">×</button>
            </div>
            <form onSubmit={submit} className="min-h-0 flex-1 overflow-y-auto">
              <div className="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                {visibleTypes.filter((type) => STAGE_ACTIONS[stage]?.includes(type)).length > 1 && field('Change type', <select className={CONTROL_CLASS} value={form.change_type} onChange={(event) => setForm({ ...EMPTY_FORM, change_type: event.target.value })}>{visibleTypes.filter((type) => STAGE_ACTIONS[stage]?.includes(type)).map((type) => <option key={type} value={type}>{titleFor(type)}</option>)}</select>)}
                {['update_region', 'assign_region_supervisor'].includes(form.change_type) && field('Tax Region', <select required className={CONTROL_CLASS} value={form.region_id} onChange={chooseRegion}><option value="">Select a region</option>{regions.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.classification})</option>)}</select>)}
                {form.change_type === 'update_district' && field('Tax District', <select required className={CONTROL_CLASS} value={form.district_id} onChange={chooseDistrict}><option value="">Select a district</option>{districts.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>)}
                {['update_team', 'assign_officer', 'remove_officer'].includes(form.change_type) && field('Enforcement Team', <select required className={CONTROL_CLASS} value={form.team_id} onChange={chooseTeam}><option value="">Select a team</option>{teams.filter((item) => form.change_type === 'update_team' || item.is_active).map((item) => <option key={item.id} value={item.id}>{item.name}{item.is_active ? '' : ' (inactive)'}</option>)}</select>)}
                {['create_region', 'update_region', 'create_district', 'update_district', 'create_team', 'update_team'].includes(form.change_type) && field(form.change_type.includes('region') ? 'Region name' : form.change_type.includes('district') ? 'District name' : 'Team name', <Input required value={form.name} onChange={updateForm('name')} placeholder="Enter a name" className={CONTROL_CLASS} />)}
                {['create_region', 'update_region'].includes(form.change_type) && field('Unique region code', <Input required value={form.code} onChange={updateForm('code')} placeholder="e.g. CENTRAL-URBAN" className={CONTROL_CLASS} />)}
                {['create_region', 'update_region'].includes(form.change_type) && field('Classification', <div className="grid grid-cols-2 gap-2">{['Urban', 'Rural'].map((classification) => <button type="button" key={classification} onClick={() => setForm((current) => ({ ...current, classification }))} aria-pressed={form.classification === classification} className={`rounded-xl border px-3 py-3 text-sm font-bold transition focus:outline-none focus:ring-4 focus:ring-brand-500/10 ${form.classification === classification ? 'border-navy-700 bg-navy-50 text-navy-800' : 'border-slate-200 text-slate-500 hover:bg-slate-50'}`}>{classification === 'Urban' ? '▥' : '⌂'} {classification}</button>)}</div>)}
                {['create_region', 'update_region'].includes(form.change_type) && field('Description (optional)', <textarea className={`${CONTROL_CLASS} resize-y`} value={form.description} onChange={updateForm('description')} rows={3} placeholder="Add context for this region" />)}
                {form.change_type === 'assign_region_supervisor' && field('Enforcement Supervisor', <select required className={CONTROL_CLASS} value={form.supervisor_id} onChange={updateForm('supervisor_id')}><option value="">Select a Supervisor</option>{(data?.supervisors || []).map((person) => <option key={person.id} value={person.id}>{person.full_name}</option>)}</select>)}
                {form.change_type === 'assign_districts_to_region' && field('Destination Tax Region', <select required className={CONTROL_CLASS} value={form.region_id} onChange={chooseRegion}><option value="">Select a region</option>{activeRegions.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.classification})</option>)}</select>)}
                {form.change_type === 'assign_districts_to_region' && field('Choose districts · Ctrl/Cmd-click to select multiple', <select required multiple className={`${CONTROL_CLASS} min-h-36`} value={form.district_ids} onChange={(event) => setForm((current) => ({ ...current, district_ids: Array.from(event.target.selectedOptions, (option) => option.value) }))}>{districts.filter((item) => item.is_active).map((item) => {
                  const pending = pendingDistricts.get(String(item.id))
                  const pendingRegion = regions.find((region) => String(region.id) === String(pending?.payload?.region_id))
                  return <option key={item.id} value={item.id} disabled={!!pending}>{item.name}{pending ? ` — Pending AC approval${pendingRegion ? ` · ${pendingRegion.name}` : ''}` : item.region ? ` — ${item.region}` : ' — Unassigned'}</option>
                })}</select>)}
                {['create_team', 'update_team'].includes(form.change_type) && field('Tax Region', <select required className={CONTROL_CLASS} value={form.region_id} onChange={chooseRegion}><option value="">Select a region</option>{activeRegions.filter((item) => item.supervisor_id).map((item) => <option key={item.id} value={item.id}>{item.name} ({item.classification}) — {item.supervisor}</option>)}</select>)}
                {['create_team', 'update_team'].includes(form.change_type) && field('Team districts · Ctrl/Cmd-click for multiple', <select required multiple className={`${CONTROL_CLASS} min-h-36`} value={form.district_ids} onChange={(event) => setForm((current) => ({ ...current, district_ids: Array.from(event.target.selectedOptions, (option) => option.value) }))}>{districts.filter((district) => district.is_active && String(district.region_id) === String(form.region_id)).map((district) => <option key={district.id} value={district.id}>{district.name}</option>)}</select>)}
                {['create_team', 'update_team'].includes(form.change_type) && <div className="rounded-xl border border-blue-100 bg-blue-50 p-3 text-xs leading-5 text-blue-900 sm:col-span-2">{form.region_id ? `Inherited Supervisor: ${regions.find((item) => String(item.id) === String(form.region_id))?.supervisor || 'Not assigned'}. ` : ''}A team can cover multiple districts within one region. Structural team changes require review before taking effect.</div>}
                {form.change_type === 'create_team' && <>{field('Team Lead', <select required className={CONTROL_CLASS} value={form.team_lead_id} onChange={updateForm('team_lead_id')}><option value="">Select a Team Lead</option>{officers.filter((person) => !person.current_team_id).map((person) => <option key={person.id} value={person.id}>{person.full_name}</option>)}</select>)}{field('Additional officers (optional)', <select multiple className={`${CONTROL_CLASS} min-h-28`} value={form.member_ids} onChange={(event) => setForm((current) => ({ ...current, member_ids: Array.from(event.target.selectedOptions, (option) => option.value) }))}>{officers.filter((person) => !person.current_team_id && String(person.id) !== String(form.team_lead_id)).map((person) => <option key={person.id} value={person.id}>{person.full_name}</option>)}</select>)}{field('Primary district for new members', <select required className={CONTROL_CLASS} value={form.member_primary_district_id} onChange={updateForm('member_primary_district_id')}><option value="">Select a district</option>{districts.filter((district) => form.district_ids.includes(String(district.id))).map((district) => <option key={district.id} value={district.id}>{district.name}</option>)}</select>)}</>}
                {form.change_type === 'assign_officer' && field('Enforcement Officer', <select required className={CONTROL_CLASS} value={form.officer_id} onChange={updateForm('officer_id')}><option value="">Select an officer</option>{officers.filter((person) => !person.current_team_id || String(person.current_team_id) === String(form.team_id)).map((person) => <option key={person.id} value={person.id}>{person.full_name}{person.current_team ? ` — ${person.current_team}` : ''}</option>)}</select>)}
                {form.change_type === 'remove_officer' && field('Current team member', <select required className={CONTROL_CLASS} value={form.officer_id} onChange={updateForm('officer_id')}><option value="">Select an officer</option>{officers.filter((person) => String(person.current_team_id) === String(form.team_id)).map((person) => <option key={person.id} value={person.id}>{person.full_name}</option>)}</select>)}
                {form.change_type === 'assign_officer' && team && field('Primary district', <select required className={CONTROL_CLASS} value={form.primary_district_id} onChange={updateForm('primary_district_id')}><option value="">Select a primary district</option>{team.districts.filter((district) => district.is_active).map((district) => <option key={district.id} value={district.id}>{district.name}</option>)}</select>)}
                {form.change_type === 'assign_officer' && <label className="flex items-center gap-3 rounded-xl border border-slate-200 p-3 text-sm font-semibold text-slate-700"><input type="checkbox" checked={form.is_team_lead} onChange={updateForm('is_team_lead')} className="h-4 w-4 accent-navy-700" />Designate as Team Lead</label>}
                {['update_region', 'update_district', 'update_team'].includes(form.change_type) && <label className="flex items-center gap-3 rounded-xl border border-slate-200 p-3 text-sm font-semibold text-slate-700"><input type="checkbox" checked={form.is_active} onChange={updateForm('is_active')} className="h-4 w-4 accent-navy-700" />Keep this {form.change_type.includes('region') ? 'region' : form.change_type.includes('district') ? 'district' : 'team'} active</label>}
                {['create_region', 'update_region', 'create_district', 'update_district', 'create_team', 'update_team', 'assign_region_supervisor', 'assign_districts_to_region'].includes(form.change_type) && field('Reason (optional)', <Input value={form.reason} onChange={updateForm('reason')} placeholder="Add a reason for this change" className={CONTROL_CLASS} />)}
              </div>
              <div className="sticky bottom-0 flex flex-col-reverse justify-between gap-2 border-t border-slate-100 bg-white px-5 py-4 sm:flex-row sm:items-center sm:px-6">
                <p className="text-[11px] text-slate-500">{data?.can_manage && ['assign_officer', 'remove_officer', 'assign_region_supervisor'].includes(form.change_type) ? 'This update takes effect immediately and is recorded.' : `This request will not change the live organization until the ${form.change_type === 'update_team' && data?.can_recommend ? 'responsible Enforcement Manager' : 'Assistant Commissioner'} approves it.`}</p>
                <div className="flex gap-2"><Btn type="button" tone="white" disabled={busy} onClick={() => setDialogOpen(false)}>Cancel</Btn><Btn type="submit" disabled={busy}>{busy ? 'Saving…' : data?.can_manage && ['assign_officer', 'remove_officer', 'assign_region_supervisor'].includes(form.change_type) ? 'Apply change' : `Submit ${titleFor(form.change_type).toLowerCase()}`}</Btn></div>
              </div>
            </form>
          </section>
        </div>
      )}

      {reviewTarget && (
        <div className="fixed inset-0 z-[510] flex items-end justify-center bg-navy-950/60 p-0 backdrop-blur-sm sm:items-center sm:p-5">
          <section ref={dialogRef} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby="review-dialog-title" className="w-full max-w-lg rounded-t-3xl bg-white p-5 shadow-2xl outline-none sm:rounded-3xl sm:p-6">
            <p className="text-[10px] font-extrabold uppercase tracking-[0.16em] text-brand-600">Recommendation review</p>
            <h2 id="review-dialog-title" className="mt-1 text-xl font-extrabold text-navy-800">{reviewDecision === 'approve' ? 'Approve this request?' : 'Reject this request?'}</h2>
            <p className="mt-2 text-sm text-slate-600">{titleFor(reviewTarget.change_type)} · submitted by {reviewTarget.submitted_by || '—'}</p>
            {reviewDecision === 'reject' && <label className="mt-4 block space-y-1.5 text-xs font-semibold text-slate-600"><span>Reason for rejection <span className="text-red-600">*</span></span><textarea required autoFocus rows={3} value={reviewNotes} onChange={(event) => { setReviewNotes(event.target.value); setReviewError('') }} className={CONTROL_CLASS} placeholder="Explain what needs to change before resubmission." /></label>}
            {reviewError && <p role="alert" className="mt-2 text-xs font-semibold text-red-700">{reviewError}</p>}
            <p className="mt-4 rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-600">{reviewDecision === 'approve' ? 'Approval applies the proposed change and records the decision in the organization history.' : 'The submitter will be notified and the recommendation will remain in the history.'}</p>
            <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><Btn type="button" tone="white" disabled={busy} onClick={() => setReviewTarget(null)}>Cancel</Btn><Btn type="button" tone={reviewDecision === 'approve' ? 'success' : 'danger'} disabled={busy} onClick={review}>{busy ? 'Processing…' : reviewDecision === 'approve' ? 'Confirm approval' : 'Confirm rejection'}</Btn></div>
          </section>
        </div>
      )}
    </div>
  )
}

function ActionButton({ children, onClick, prominent = false }) {
  return <button type="button" onClick={onClick} className={`inline-flex min-h-9 items-center justify-center rounded-xl border px-3 py-2 text-xs font-bold transition focus:outline-none focus:ring-4 focus:ring-brand-500/10 ${prominent ? 'border-navy-800 bg-navy-800 text-white hover:bg-navy-700' : 'border-slate-200 bg-white text-slate-700 hover:border-navy-200 hover:bg-slate-50'}`}>{children}</button>
}

function SummaryValue({ label, value }) {
  return <div className="min-w-0"><p className="text-[9px] font-extrabold uppercase tracking-wider text-slate-400">{label}</p><p className="mt-1 break-words text-xs font-bold text-slate-700">{value}</p></div>
}

function EmptyState({ icon, title, description, action, actionLabel }) {
  return <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50/70 px-5 py-10 text-center"><span className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-2xl text-navy-700 shadow-sm">{icon}</span><h3 className="mt-3 text-sm font-extrabold text-navy-800">{title}</h3><p className="mx-auto mt-1 max-w-md text-xs leading-5 text-slate-500">{description}</p>{action && <button type="button" onClick={action} className="mt-4 inline-flex min-h-10 items-center justify-center rounded-xl bg-navy-800 px-4 py-2 text-xs font-bold text-white transition hover:bg-navy-700 focus:outline-none focus:ring-4 focus:ring-brand-500/10">{actionLabel}</button>}</div>
}
