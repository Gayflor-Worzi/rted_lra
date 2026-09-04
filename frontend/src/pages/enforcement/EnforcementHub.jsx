import { useSearchParams } from 'react-router-dom'
import { useAuth } from '../../auth'
import { PageTitle } from '../../ui'
import OverviewTab from './Overview'
import TasksTab from './TasksTab'
import DiscoveriesTab from './DiscoveriesTab'
import FollowupsTab from './FollowupsTab'
import RecordsTab from './RecordsTab'

const TABS = [
  { key: 'overview', label: 'Overview', icon: '📊', show: (can) => true },
  { key: 'tasks', label: 'My Tasks', icon: '📋', show: (can) => can('tasks.view_own') },
  { key: 'discoveries', label: 'Property Discoveries', icon: '📡', show: (can) => can('discovery.view', 'discovery.create', 'discovery.review') },
  { key: 'followups', label: 'Follow-ups', icon: '📞', show: (can) => can('tasks.view_own', 'enforcement.record_visit') },
  { key: 'records', label: 'Records', icon: '🗂️', show: (can) => can('tasks.view_own', 'tasks.view_section', 'tasks.view_division') },
]

export default function EnforcementHub() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()

  const tabs = TABS.filter((t) => t.show(can))
  const requested = params.get('tab')
  const active = tabs.some((t) => t.key === requested) ? requested : (tabs[0]?.key || 'overview')

  const go = (key) => {
    const next = new URLSearchParams(params)
    next.set('tab', key)
    next.delete('scope')
    next.delete('group')
    setParams(next)
  }

  return (
    <div className="space-y-4">
      <PageTitle sub="Field enforcement workspace — tasks, property discoveries, follow-ups and historical records.">
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
      {active === 'discoveries' && <DiscoveriesTab />}
      {active === 'followups' && <FollowupsTab />}
      {active === 'records' && <RecordsTab />}
    </div>
  )
}