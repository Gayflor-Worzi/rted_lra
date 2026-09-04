import { PageTitle } from '../ui'
import { useAuth } from '../auth'
import Analytics from '../components/Analytics'

export default function Dashboard() {
  const { user, can } = useAuth()
  const division = can('dashboard.view_division', 'dashboard.view_section')

  return (
    <div className="space-y-6">
      <PageTitle sub={`Signed in as ${user?.role}${division ? ' · division-wide view' : ''}`}>
        {user?.full_name?.split(' ')[0]} Dashboard
      </PageTitle>
      <Analytics heading={division ? 'Division-wide analytics' : 'My dashboard analytics'} />
    </div>
  )
}