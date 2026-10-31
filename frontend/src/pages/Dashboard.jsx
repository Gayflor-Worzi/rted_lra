import { PageTitle } from '../ui'
import { useAuth } from '../auth'
import Analytics from '../components/Analytics'
import PerformanceTargets from '../components/PerformanceTargets'
import ManagementPerformance from '../components/ManagementPerformance'
import OfficerDashboard from './OfficerDashboard'
import ValuationHome from './ValuationHome'
import ManagerDashboard, { managerType } from './ManagerDashboard'

export default function Dashboard() {
  const { user, can } = useAuth()
  const division = can('dashboard.view_division', 'dashboard.view_section')
  const officer = user?.role === 'Account & Records Officer' || user?.role === 'Enforcement Officer'
  const managementPerformance = [
    'Account Manager', 'Valuation Manager', 'Enforcement Manager',
    'Account Supervisor', 'Valuation Supervisor', 'Enforcement Supervisor',
    'Assistant Commissioner',
  ].includes(user?.role)

  if (officer) return <OfficerDashboard showExports={false} />

  // Valuation Officers get a focused workload dashboard (own tasks, valuations,
  // discoveries, priorities) instead of the generic analytics surface.
  if (user?.role === 'Valuation Officer') return <ValuationHome showExports={false} />

  // Account & Record / Valuation / Enforcement managers get role-specific
  // manager dashboards served by /dashboard/manager/*.
  if (managerType(user)) return <ManagerDashboard />

  return (
    <div className="space-y-6">
      <PageTitle sub={`Signed in as ${user?.role}${division ? ' · division-wide view' : ''}`}>
        {user?.full_name?.split(' ')[0]} Dashboard
      </PageTitle>
      {managementPerformance ? <ManagementPerformance /> : <PerformanceTargets />}
      <Analytics heading={division ? 'Division-wide analytics' : 'My dashboard analytics'} showExports={false} />
    </div>
  )
}