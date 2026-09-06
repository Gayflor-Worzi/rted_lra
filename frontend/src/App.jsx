import { Navigate, Route, Routes, useLocation, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from './auth'
import ErrorBoundary from './components/ErrorBoundary'
import Layout from './components/Layout'
import Login from './pages/Login'
import ForceReset from './pages/ForceReset'
import Dashboard from './pages/Dashboard'
import Drill from './pages/Drill'
import Bills from './pages/Bills'
import BillDetail from './pages/BillDetail'
import Valuations from './pages/Valuations'
import Targets from './pages/Targets'
import Reports from './pages/Reports'
import EnforcementHub from './pages/enforcement/EnforcementHub'
import Users from './pages/Users'
import RolesAdmin from './pages/RolesAdmin'
import AuditLogs from './pages/AuditLogs'
import Notifications from './pages/Notifications'
import Payments from './pages/Payments'

/**
 * Access Restricted — shown ONLY when a user intentionally navigates to a
 * protected URL they are not permitted to reach (direct URL entry, stale
 * bookmark, etc.). Normal navigation never lands here because the menus, tabs,
 * quick actions and dashboard are all permission-filtered before rendering; a
 * genuinely authorized user would have to bypass the menus to trigger this.
 *
 * Spec 7 / 18: this is deliberately distinct from everyday navigation, and
 * offers a clear path back to the dashboard instead of a dead end.
 */
function AccessRestricted() {
  const { user } = useAuth()
  const nav = useNavigate()
  const location = useLocation()

  return (
    <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-10 max-w-xl">
      <div className="text-3xl mb-3">🚫</div>
      <h1 className="text-xl font-bold text-navy-800">Access Restricted</h1>
      <p className="mt-2 text-sm text-slate-500">
        You do not have permission to access this module.
      </p>
      <p className="mt-1 text-sm text-slate-400">
        Your current role (<span className="font-semibold text-navy-800">{user?.role || 'your role'}</span>) does
        not include access to this function. If you believe this is a mistake, contact the System Administrator.
      </p>
      <p className="mt-4 text-xs text-slate-400">
        <span className="font-mono">{location.pathname}</span> · {user?.full_name} ({user?.staff_id})
      </p>
      <button
        onClick={() => nav('/dashboard')}
        className="mt-6 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition">
        ← Return to Dashboard
      </button>
    </div>
  )
}

function Guard({ perms, children }) {
  const { can } = useAuth()
  if (!can(...perms)) return <AccessRestricted />
  return children
}

const DRILL_PERMS = { bills: ['bills.view'], valuations: ['valuation.view_history', 'valuation.review', 'valuation.approve'], tasks: ['tasks.view_own'] }

function DrillGuard() {
  const { table } = useParams()
  const perms = DRILL_PERMS[table] || ['tasks.view_own', 'bills.view', 'valuation.view_history', 'valuation.review', 'valuation.approve']
  return <Guard perms={perms}><Drill /></Guard>
}

export default function App() {
  const { user, loading } = useAuth()

  if (loading) return <div className="min-h-screen grid place-items-center text-slate-400">Loading…</div>
  if (!user) return <Routes><Route path="*" element={<Login />} /></Routes>
  // Forced password reset takes over the whole app until a new password is set.
  if (user.must_reset_password) return <Routes><Route path="*" element={<ForceReset />} /></Routes>

  return (
    <ErrorBoundary>
      <Routes>
        <Route element={<Layout />}>
          <Route path="/" element={<Navigate to="/dashboard" replace />} />
          <Route path="/force-reset" element={<ForceReset />} />
          <Route path="/dashboard" element={<Guard perms={['dashboard.view_own']}><Dashboard /></Guard>} />
          <Route path="/drill/:table" element={<DrillGuard />} />
          <Route path="/bills" element={<Guard perms={['bills.view', 'bills.create', 'bills.edit']}><Bills /></Guard>} />
          <Route path="/bills/:id" element={<Guard perms={['bills.view', 'bills.create', 'bills.edit']}><BillDetail /></Guard>} />
          <Route path="/tasks" element={<Navigate to="/enforcement?tab=tasks" replace />} />
          <Route path="/valuations" element={<Guard perms={['valuation.create', 'valuation.review', 'valuation.approve', 'valuation.submit', 'valuation.view_history']}><Valuations /></Guard>} />
          <Route path="/discoveries" element={<Navigate to="/enforcement?tab=discoveries" replace />} />
          <Route path="/targets" element={<Guard perms={['targets.view']}><Targets /></Guard>} />
          <Route path="/reports" element={<Guard perms={['reports.view', 'reports.export']}><Reports /></Guard>} />
          <Route path="/enforcement" element={<Guard perms={['tasks.view_own', 'tasks.view_section', 'tasks.view_division']}><EnforcementHub /></Guard>} />
          <Route path="/users" element={<Guard perms={['staff.view', 'staff.create', 'staff.edit']}><Users /></Guard>} />
          <Route path="/roles" element={<Guard perms={['rbac.assign_permissions', 'rbac.create_role', 'rbac.edit_role']}><RolesAdmin /></Guard>} />
          <Route path="/audit" element={<Guard perms={['audit.view']}><AuditLogs /></Guard>} />
          <Route path="/notifications" element={<Guard perms={['notifications.view']}><Notifications /></Guard>} />
          <Route path="/payments" element={<Guard perms={['payments.view_queue', 'payments.view_history']}><Payments /></Guard>} />
          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Route>
      </Routes>
    </ErrorBoundary>
  )
}