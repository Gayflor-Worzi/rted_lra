import React, { useCallback, useState } from 'react'
import { ActivityIndicator, RefreshControl, ScrollView, Text, TextInput, TouchableOpacity, View } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import api from '../api'
import { useAuth } from '../auth'
import { fetchOfflineRead } from '../offline'
import { serverMessage } from '../rbac'
import { theme } from '../theme'
import { Badge, Card, Empty, Screen, OfflineReadStatus } from '../components'

const label = (value) => String(value || '').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())

export default function EnforcementOrganizationScreen() {
  const { user, canUseOfflineCache } = useAuth()
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [readStatus, setReadStatus] = useState(null)
  const [syncedAt, setSyncedAt] = useState(null)
  const [selectedStage, setSelectedStage] = useState(null)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('pending')
  const [expanded, setExpanded] = useState({})
  const [tableView, setTableView] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setError('')
      const result = await fetchOfflineRead({
        module: 'organization',
        cacheKey: `${user?.role}:workspace`,
        user,
        allowCache: canUseOfflineCache,
        request: () => api.get('/enforcement/organization'),
        select: (response) => response.data?.data || response.data || {},
      })
      if (result.status === 'expired') {
        setData(null)
        setError('Offline organization data has expired. Connect to refresh.')
      } else {
        setData(result.data)
        setError('')
      }
      setReadStatus(result.status)
      setSyncedAt(result.syncedAt)
    } catch (exception) {
      setError(serverMessage(exception, 'Could not load Enforcement organization data.'))
      setData(null)
      setReadStatus(null)
    } finally {
      setLoading(false)
    }
  }, [user, canUseOfflineCache])

  useFocusEffect(useCallback(() => { load() }, [load]))

  const regions = data?.regions || []
  const teams = data?.teams || []
  const districts = data?.districts || []
  const recommendations = data?.recommendations || []
  const activeRegions = regions.filter((region) => region.is_active)
  const unassignedDistricts = districts.filter((district) => district.is_active && !district.region_id)
  const supervisorCount = activeRegions.filter((region) => region.supervisor_id && region.supervisor_active).length
  const leadCount = teams.flatMap((team) => team.officers || []).filter((officer) => officer.is_team_lead).length
  const pendingCount = recommendations.filter((item) => item.status === 'pending').length
  const nextStage = regions.length === 0 ? 'regions'
    : districts.length === 0 || unassignedDistricts.length > 0 ? 'districts'
      : supervisorCount < activeRegions.length ? 'supervisors'
        : teams.length === 0 ? 'teams'
          : leadCount === 0 ? 'members'
            : 'overview'
  const stage = selectedStage || nextStage
  const stages = [
    { id: 'regions', name: 'Tax Regions', icon: '◉', done: regions.length > 0, locked: false },
    { id: 'districts', name: 'Districts', icon: '⌖', done: districts.length > 0 && unassignedDistricts.length === 0, locked: false },
    { id: 'supervisors', name: 'Supervisors', icon: '◎', done: activeRegions.length > 0 && supervisorCount === activeRegions.length, locked: activeRegions.length === 0 },
    { id: 'teams', name: 'Teams', icon: '▦', done: teams.length > 0, locked: false },
    { id: 'members', name: 'Team Members', icon: '♙', done: leadCount > 0, locked: teams.length === 0 },
    { id: 'overview', name: 'Overview', icon: '⌘', done: true, locked: false },
  ]
  const query = search.trim().toLowerCase()
  const hasMatch = (...values) => !query || values.some((value) => String(value || '').toLowerCase().includes(query))
  const visibleRegions = regions.filter((region) => hasMatch(
    region.name,
    region.code,
    region.supervisor,
    ...districts.filter((district) => district.region_id === region.id).map((district) => district.name),
    ...teams.filter((team) => team.region_id === region.id).map((team) => team.name),
  ))
  const visibleDistricts = districts.filter((district) => hasMatch(district.name, district.region))
  const visibleTeams = teams.filter((team) => hasMatch(team.name, team.region, team.supervisor))
  const visibleRecommendations = recommendations.filter((item) => item.status === statusFilter)
  const stageTitle = stages.find((item) => item.id === stage)?.name || 'Organization Overview'

  return (
    <Screen refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={theme.colors.primary} />}>
      <OfflineReadStatus status={readStatus} syncedAt={syncedAt} />
      {error ? (
        <Card>
          <Text accessibilityRole="alert" style={{ color: theme.colors.danger, fontWeight: '700' }}>{error}</Text>
          <TouchableOpacity onPress={load} accessibilityRole="button" style={{ marginTop: 8, minHeight: 44, justifyContent: 'center' }}>
            <Text style={{ color: theme.colors.primary, fontWeight: '700' }}>Try again</Text>
          </TouchableOpacity>
        </Card>
      ) : loading && !data ? (
        <View style={{ padding: 32, alignItems: 'center' }}>
          <ActivityIndicator color={theme.colors.primary} />
          <Text style={{ marginTop: 10, color: theme.colors.textMuted }}>Loading organization details…</Text>
        </View>
      ) : (
        <>
          <View style={{ marginHorizontal: 16, marginTop: 16, marginBottom: 12, borderRadius: 18, backgroundColor: theme.colors.navy, padding: 18 }}>
            <Text style={{ color: '#BFDBFE', fontSize: 10, fontWeight: '900', letterSpacing: 1.4 }}>ORGANIZATION WORKSPACE</Text>
            <Text style={{ marginTop: 5, color: '#fff', fontSize: 22, fontWeight: '900' }}>Team Management</Text>
            <Text style={{ marginTop: 6, color: '#E2E8F0', fontSize: 12, lineHeight: 18 }}>
              Explore your role-scoped Enforcement structure. Changes and approvals are managed in the web workspace.
            </Text>
          </View>

          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 16, gap: 8, paddingVertical: 4 }}>
            {[
              ['Regions', regions.length, 'regions'],
              ['Districts', `${districts.filter((item) => item.region_id).length}/${districts.length}`, 'districts'],
              ['Supervisors', `${supervisorCount}/${activeRegions.length}`, 'supervisors'],
              ['Teams', teams.length, 'teams'],
              ['Members', teams.reduce((count, team) => count + (team.officers || []).length, 0), 'members'],
              ['Pending', pendingCount, 'overview'],
            ].map(([title, value, target]) => (
              <TouchableOpacity key={title} accessibilityRole="button" onPress={() => setSelectedStage(target)} activeOpacity={0.75} style={{ width: 116, borderWidth: 1, borderColor: theme.colors.border, borderRadius: 14, backgroundColor: theme.colors.white, padding: 12 }}>
                <Text numberOfLines={1} style={{ color: theme.colors.textMuted, fontSize: 10, fontWeight: '800', textTransform: 'uppercase' }}>{title}</Text>
                <Text style={{ marginTop: 4, color: theme.colors.navy, fontSize: 21, fontWeight: '900' }}>{value}</Text>
              </TouchableOpacity>
            ))}
          </ScrollView>

          <Card style={{ marginTop: 12 }}>
            <View style={row}>
              <View style={{ flex: 1 }}>
                <Text style={cardTitle}>Guided setup</Text>
                <Text style={detail}>Select a stage to inspect your organization.</Text>
              </View>
              <Badge status={stages.find((item) => item.id === nextStage)?.done ? 'approved' : 'pending'} label={nextStage === 'overview' ? 'Ready' : 'Next step'} />
            </View>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingTop: 14, paddingBottom: 2 }}>
              {stages.map((item, index) => {
                const active = stage === item.id
                return (
                  <React.Fragment key={item.id}>
                    <TouchableOpacity
                      accessibilityRole="button"
                      accessibilityState={{ selected: active, disabled: item.locked }}
                      disabled={item.locked}
                      onPress={() => setSelectedStage(item.id)}
                      style={{
                        width: 112, minHeight: 84, borderRadius: 13, borderWidth: 1,
                        borderColor: active ? theme.colors.navy : item.locked ? theme.colors.border : theme.colors.border,
                        backgroundColor: active ? theme.colors.navy : item.locked ? theme.colors.bg : '#fff',
                        padding: 10, opacity: item.locked ? 0.6 : 1,
                      }}
                    >
                      <View style={row}>
                        <Text style={{ color: active ? '#fff' : theme.colors.navy, fontSize: 16, fontWeight: '900' }}>{item.icon}</Text>
                        <Text style={{ color: active ? '#BBF7D0' : item.done ? theme.colors.success : theme.colors.warning, fontSize: 10, fontWeight: '900' }}>{item.done ? '✓' : item.locked ? '•' : `${index + 1}`}</Text>
                      </View>
                      <Text numberOfLines={2} style={{ marginTop: 8, color: active ? '#fff' : theme.colors.text, fontSize: 11, fontWeight: '800' }}>{item.name}</Text>
                      <Text style={{ marginTop: 2, color: active ? '#CBD5E1' : theme.colors.textMuted, fontSize: 9 }}>{item.locked ? 'Complete prior step' : item.done ? 'Completed' : 'In progress'}</Text>
                    </TouchableOpacity>
                    {index < stages.length - 1 ? <View style={{ width: 10, height: 1, backgroundColor: theme.colors.border, alignSelf: 'center' }} /> : null}
                  </React.Fragment>
                )
              })}
            </ScrollView>
          </Card>

          <Card style={{ marginTop: 12 }}>
            <View style={row}>
              <View style={{ flex: 1 }}>
                <Text style={sectionTitle}>{stageTitle}</Text>
                <Text style={detail}>Organization details are scoped to your account.</Text>
              </View>
              {stage === 'overview' ? (
                <TouchableOpacity accessibilityRole="button" onPress={() => setTableView((value) => !value)} style={{ borderWidth: 1, borderColor: theme.colors.border, borderRadius: 9, paddingHorizontal: 10, paddingVertical: 8 }}>
                  <Text style={{ color: theme.colors.navy, fontSize: 11, fontWeight: '800' }}>{tableView ? 'Hierarchy' : 'Table'}</Text>
                </TouchableOpacity>
              ) : null}
            </View>
            <TextInput value={search} onChangeText={setSearch} placeholder={`Search ${stageTitle.toLowerCase()}`} placeholderTextColor={theme.colors.textLight} accessibilityLabel={`Search ${stageTitle}`} style={{ marginTop: 12, borderWidth: 1, borderColor: theme.colors.border, borderRadius: 11, backgroundColor: '#F8FAFC', color: theme.colors.text, paddingHorizontal: 12, paddingVertical: 10, fontSize: 13 }} />
          </Card>

          {stage === 'regions' && (
            visibleRegions.length === 0 ? <Empty icon="◉" title="No tax regions in scope" sub={regions.length ? 'No regions match your search.' : 'No tax regions have been configured yet.'} /> : visibleRegions.map((region) => (
              <Card key={region.id} style={{ marginTop: 0 }}>
                <View style={row}><Text style={cardTitle}>{region.name}</Text><Badge status={region.is_active ? 'approved' : 'inactive'} label={region.is_active ? 'Active' : 'Inactive'} /></View>
                <Text style={detail}>{region.code} · {region.classification}</Text>
                <Text style={detail}>Regional Supervisor: {region.supervisor || 'Not assigned'}</Text>
                <Text style={detail}>Tax Districts: {region.districts?.map((district) => district.name).join(', ') || 'None assigned'}</Text>
              </Card>
            ))
          )}

          {stage === 'districts' && (
            visibleDistricts.length === 0 ? <Empty icon="⌖" title="No tax districts in scope" sub="District assignment becomes available after Regions are configured." /> : visibleDistricts.map((district) => (
              <Card key={district.id} style={{ marginTop: 0 }}>
                <View style={row}><Text style={cardTitle}>{district.name}</Text><Badge status={district.is_active ? 'approved' : 'inactive'} label={district.is_active ? 'Active' : 'Inactive'} /></View>
                <Text style={detail}>{district.region ? `${district.region} · ${district.classification}` : 'Not assigned to a Tax Region'}</Text>
              </Card>
            ))
          )}

          {stage === 'supervisors' && (
            activeRegions.length === 0 ? <Empty icon="◎" title="No active regions to supervise" sub="Create a Tax Region before assigning its regional Supervisor." /> : activeRegions.filter((region) => hasMatch(region.name, region.supervisor)).map((region) => (
              <Card key={region.id} style={{ marginTop: 0 }}>
                <View style={row}><Text style={cardTitle}>{region.name}</Text><Badge status={region.supervisor_id && region.supervisor_active ? 'approved' : 'pending'} label={region.supervisor_id && region.supervisor_active ? 'Assigned' : 'Needs setup'} /></View>
                <Text style={detail}>{region.classification} · {region.districts?.length || 0} districts</Text>
                <Text style={detail}>Primary Supervisor: {region.supervisor || 'Not assigned'}</Text>
              </Card>
            ))
          )}

          {stage === 'teams' && (
            visibleTeams.length === 0 ? <Empty icon="▦" title="No approved teams in scope" sub={teams.length ? 'No teams match your search.' : 'Teams will appear after the required regional setup and approval.'} /> : visibleTeams.map((team) => (
              <Card key={team.id} style={{ marginTop: 0 }}>
                <View style={row}><Text style={cardTitle}>{team.name}</Text><Badge status={team.is_configured ? 'approved' : 'pending'} label={team.is_configured ? 'Configured' : 'Needs setup'} /></View>
                <Text style={detail}>Region: {team.region ? `${team.region} · ${team.classification}` : 'Not configured'}</Text>
                <Text style={detail}>Supervisor: {team.supervisor || '—'} · Manager: {team.manager || '—'}</Text>
                <Text style={detail}>Team Lead: {team.officers?.find((officer) => officer.is_team_lead)?.officer || 'Not assigned'}</Text>
                <Text style={detail}>Team coverage districts: {team.districts?.map((district) => district.name).join(', ') || '—'}</Text>
                <Text style={detail}>Members: {team.officers?.length || 0}</Text>
              </Card>
            ))
          )}

          {stage === 'members' && (
            visibleTeams.length === 0 ? <Empty icon="♙" title="No team members in scope" sub="Officer memberships appear beneath each approved team." /> : visibleTeams.map((team) => (
              <Card key={team.id} header={team.name} style={{ marginTop: 0 }}>
                <Text style={detail}>{team.region || 'Region not configured'} · Supervisor: {team.supervisor || '—'}</Text>
                {(team.officers || []).length === 0 ? <Text style={detail}>No current officers assigned.</Text> : team.officers.map((officer) => (
                  <View key={officer.membership_id} style={{ marginTop: 10, borderTopWidth: 1, borderTopColor: theme.colors.border, paddingTop: 9 }}>
                    <View style={row}><Text style={{ flex: 1, color: theme.colors.text, fontSize: 13, fontWeight: '800' }}>{officer.officer}</Text>{officer.is_team_lead ? <Badge status="pending" label="Team Lead" /> : null}</View>
                    <Text style={detail}>{officer.staff_id || 'No staff ID'} · Primary district: {officer.primary_district || 'Not assigned'}</Text>
                  </View>
                ))}
              </Card>
            ))
          )}

          {stage === 'overview' && (
            <>
              <Card style={{ marginTop: 0 }}>
                <View style={row}><Text style={cardTitle}>Organization hierarchy</Text><TouchableOpacity onPress={() => setExpanded(Object.fromEntries(regions.map((region) => [region.id, !regions.every((item) => expanded[item.id] !== false)])))} accessibilityRole="button"><Text style={{ color: theme.colors.primary, fontSize: 11, fontWeight: '800' }}>{regions.every((region) => expanded[region.id] === false) ? 'Expand all' : 'Collapse all'}</Text></TouchableOpacity></View>
                {tableView ? (
                  teams.filter((team) => hasMatch(team.name, team.region, team.supervisor)).map((team) => (
                    <View key={team.id} style={{ marginTop: 12, borderTopWidth: 1, borderTopColor: theme.colors.border, paddingTop: 10 }}>
                      <Text style={{ color: theme.colors.navy, fontSize: 13, fontWeight: '800' }}>{team.name}</Text>
                      <Text style={detail}>Supervisor: {team.supervisor || '—'} · Region: {team.region || '—'}</Text>
                      <Text style={detail}>Team Lead: {team.officers?.find((officer) => officer.is_team_lead)?.officer || 'Not assigned'}</Text>
                      <Text style={detail}>Team coverage districts: {team.districts?.map((district) => district.name).join(', ') || '—'}</Text>
                    </View>
                  ))
                ) : visibleRegions.length === 0 ? <Empty icon="⌘" title="No hierarchy data" sub="No configured regions match your search." /> : visibleRegions.map((region) => {
                  const open = expanded[region.id] !== false
                  const regionDistricts = visibleDistricts.filter((district) => district.region_id === region.id)
                  const regionTeams = visibleTeams.filter((team) => team.region_id === region.id)
                  return (
                    <View key={region.id} style={{ marginTop: 12, borderTopWidth: 1, borderTopColor: theme.colors.border, paddingTop: 10 }}>
                      <TouchableOpacity accessibilityRole="button" accessibilityState={{ expanded: open }} onPress={() => setExpanded((current) => ({ ...current, [region.id]: !open }))} style={row}>
                        <Text style={{ flex: 1, color: theme.colors.navy, fontSize: 13, fontWeight: '900' }}>{open ? '−' : '+'} {region.name} · {region.classification}</Text>
                        <Badge status={region.is_active ? 'approved' : 'inactive'} label={region.is_active ? 'Active' : 'Inactive'} />
                      </TouchableOpacity>
                      {open ? (
                        <View style={{ marginLeft: 8, marginTop: 8, borderLeftWidth: 2, borderLeftColor: theme.colors.navyBg, paddingLeft: 10 }}>
                          <Text style={detail}>Regional Supervisor · {region.supervisor || 'Not assigned'}</Text>
                          {regionDistricts.map((district) => <Text key={district.id} style={detail}>⌖ {district.name}</Text>)}
                          {regionTeams.map((team) => (
                            <View key={team.id} style={{ marginTop: 9, borderRadius: 10, backgroundColor: '#F8FAFC', padding: 10 }}>
                              <Text style={{ color: theme.colors.navy, fontSize: 12, fontWeight: '900' }}>▦ {team.name}</Text>
                              <Text style={detail}>Districts: {team.districts?.map((district) => district.name).join(', ') || '—'}</Text>
                              {(team.officers || []).map((officer) => <Text key={officer.membership_id} style={detail}>♙ {officer.officer}{officer.is_team_lead ? ' · Team Lead' : ''}</Text>)}
                            </View>
                          ))}
                        </View>
                      ) : null}
                    </View>
                  )
                })}
              </Card>

              <Card header="Approvals & Recommendations" style={{ marginTop: 0 }}>
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, marginBottom: 12 }}>
                  {['pending', 'approved', 'rejected'].map((status) => (
                    <TouchableOpacity key={status} accessibilityRole="button" accessibilityState={{ selected: statusFilter === status }} onPress={() => setStatusFilter(status)} style={{ borderRadius: 18, backgroundColor: statusFilter === status ? theme.colors.navy : theme.colors.bg, paddingHorizontal: 12, paddingVertical: 8 }}>
                      <Text style={{ color: statusFilter === status ? '#fff' : theme.colors.textMuted, fontSize: 11, fontWeight: '800', textTransform: 'capitalize' }}>{status} · {recommendations.filter((item) => item.status === status).length}</Text>
                    </TouchableOpacity>
                  ))}
                </ScrollView>
                {visibleRecommendations.length === 0 ? <Text style={{ paddingVertical: 18, textAlign: 'center', color: theme.colors.textMuted, fontSize: 12 }}>No {statusFilter} recommendations.</Text> : visibleRecommendations.map((item) => (
                  <View key={item.id} style={{ borderTopWidth: 1, borderTopColor: theme.colors.border, paddingVertical: 11 }}>
                    <View style={row}><Text style={cardTitle}>{label(item.change_type)}</Text><Badge status={item.status} label={item.status} /></View>
                    <Text style={detail}>Submitted by {item.submitted_by || '—'} · {item.submitted_at ? new Date(item.submitted_at).toLocaleDateString() : '—'}</Text>
                    {item.decision_notes ? <Text style={detail}>Decision note: {item.decision_notes}</Text> : null}
                  </View>
                ))}
              </Card>
            </>
          )}
        </>
      )}
    </Screen>
  )
}

const sectionTitle = { marginHorizontal: 16, marginTop: 18, marginBottom: 8, color: theme.colors.navy, fontSize: 15, fontWeight: '800' }
const cardTitle = { flex: 1, color: theme.colors.navy, fontSize: 15, fontWeight: '800', marginRight: 8 }
const detail = { marginTop: 5, color: theme.colors.textMuted, fontSize: 12, lineHeight: 18 }
const row = { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }
