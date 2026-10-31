import { useSearchParams } from 'react-router-dom'
import { useAuth } from '../../auth'
import { PageTitle } from '../../ui'
import OverviewTab from './Overview'
import TasksTab from './TasksTab'
import DiscoveriesTab from './DiscoveriesTab'
import FollowupsTab from './FollowupsTab'
import RecordsTab from './RecordsTab'
import OrganizationTab from './OrganizationTab'
import AwaitingAssignmentTab from './AwaitingAssignmentTab'

// Roles that genuinely operate the field enforcement pipeline. Valuation roles
// must never be offered the enforcement workspace (they only hold generic
// tasks.view_own); when they land here from their own dashboard they should see
// just a minimal own-tasks view.
const ENF_ROLES = [
  'Enforcement Officer', 'Enforcement Supervisor', 'Enforcement Manager',
  'Assistant Commissioner', 'System Administrator',
]
const IS_ENF = (can, user) =>
  ENF_ROLES.includes(user?.role) ||
  can('enforcement.bill_delivery', 'enforcement.visit', 'enforcement.payment_followup',
    'enforcement.view_assignments', 'enforcement.record_visit', 'enforcement.issue_warning',
    'enforcement.escalate') ||
  can('me.reassign', 'me.revise')

const TABS = [
  { key: 'overview', label: 'Overview', icon: '📊', show: (can, user) => IS_ENF(can, user) && user?.role !== 'Enforcement Officer' && user?.role !== 'Account & Records Officer' },
  { key: 'tasks', label: 'My Tasks', icon: '📋', show: (can) => can('tasks.view_own') },
  { key: 'awaiting-assignment', label: 'Awaiting Assignment', icon: '📥', show: (can, user) => IS_ENF(can, user) && user?.role !== 'Assistant Commissioner' && can('enforcement.view_assignments') },
  { key: 'discoveries', label: 'Property Discoveries', icon: '📡', show: (can, user) => IS_ENF(can, user) && can('discovery.view', 'discovery.create', 'discovery.review') },
  { key: 'followups', label: 'Follow-ups', icon: '📞', show: (can) => can('enforcement.record_visit', 'enforcement.view_assignments', 'enforcement.bill_delivery') },
  { key: 'records', label: 'Records', icon: '🗂️', show: (can, user) => IS_ENF(can, user) && can('tasks.view_own', 'tasks.view_section', 'tasks.view_division') },
  { key: 'organization', label: 'Team Management', icon: '👥', show: (can) => can('enforcement.org.manage', 'enforcement.org.recommend', 'enforcement.org.review') },
]

export default function EnforcementHub() {
  const { user, can } = useAuth()
  const [params, setParams] = useSearchParams()

  // Enforcement & Account Officers land on their single merged OfficerDashboard;
  // the section's own Overview tab stays for supervisory roles only, so no account
  // ever has two dashboards.
  const isOfficer = user?.role === 'Enforcement Officer' || user?.role === 'Account & Records Officer'

  const tabs = TABS.filter((t) => t.show(can, user) && !(t.key === 'overview' && isOfficer))
  const requested = params.get('tab')
  const active = tabs.some((t) => t.key === requested) ? requested : (tabs[0]?.key || null)

  const go = (key) => {
    const next = new URLSearchParams(params)
    next.set('tab', key)
    next.delete('scope')
    next.delete('group')
    setParams(next)
  }

  return (
    <div className="space-y-4">
      <PageTitle sub="Field enforcement workspace — assignment queue, tasks, property discoveries, follow-ups and historical records.">
        Enforcement
      </PageTitle>

      <div className="flex items-center gap-1.5 flex-wrap bg-white rounded-2xl border border-slate-200 shadow-sm p-1.5">
        {tabs.map((t) => (
          <button key={t.key} onClick={() => go(t.key)}
            className={`px-3.5 py-2 rounded-xl text-sm font-semibold transition ${active === t.key ? 'bg-brand-500 text-white shadow-sm' : 'text-slate-500 hover:bg-slate-100'}`}>
            {t.icon} {t.label}
          </button>
        ))}
      </div>

      {active === 'overview' && <OverviewTab />}
      {active === 'tasks' && <TasksTab presetScope={params.get('scope')} presetGroup={params.get('group')} />}
      {active === 'awaiting-assignment' && <AwaitingAssignmentTab />}
      {active === 'discoveries' && <DiscoveriesTab />}
      {active === 'followups' && <FollowupsTab />}
      {active === 'records' && <RecordsTab />}
      {active === 'organization' && <OrganizationTab />}
    </div>
  )
}