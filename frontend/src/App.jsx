import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes, useLocation, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from './auth'
import ErrorBoundary from './components/ErrorBoundary'
import DialogMaximize from './components/DialogMaximize'
import IdleGuard from './components/IdleGuard'
import Layout from './components/Layout'

// Route-level code-split: each page ships in its own chunk so field staff only
// download the screens they open (single bundle was ~640 kB; gzip 167 kB).
const Login = lazy(() => import('./pages/Login'))
const ForceReset = lazy(() => import('./pages/ForceReset'))
const Dashboard = lazy(() => import('./pages/Dashboard'))
const Drill = lazy(() => import('./pages/Drill'))
const Bills = lazy(() => import('./pages/Bills'))
const BillDetail = lazy(() => import('./pages/BillDetail'))
const Valuations = lazy(() => import('./pages/Valuations'))
const Targets = lazy(() => import('./pages/Targets'))
const Reports = lazy(() => import('./pages/Reports'))
const EnforcementHub = lazy(() => import('./pages/enforcement/EnforcementHub'))
const Users = lazy(() => import('./pages/Users'))
const RolesAdmin = lazy(() => import('./pages/RolesAdmin'))
const AuditLogs = lazy(() => import('./pages/AuditLogs'))
const Notifications = lazy(() => import('./pages/Notifications'))
const Payments = lazy(() => import('./pages/Payments'))
const GpsRegistry = lazy(() => import('./pages/GpsRegistry'))
const MonitoringHub = lazy(() => import('./pages/monitoring/MonitoringHub'))
const AccountMyTasks = lazy(() => import('./pages/AccountMyTasks'))
const Amendments = lazy(() => import('./pages/Amendments'))
const SectionWorkspaceTasks = lazy(() => import('./pages/SectionWorkspaceTasks'))

function PageFallback() {
  return <div className="min-h-screen grid place-items-center text-slate-400">Loading…</div>
}

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

const DRILL_PERMS = { bills: ['bills.view'], valuations: ['valuation.view_history', 'valuation.review', 'valuation.approve'], tasks: ['tasks.view_own'], flags: ['dashboard.view_own', 'dashboard.view_section', 'dashboard.view_division'] }

function DrillGuard() {
  const { table } = useParams()
  const perms = DRILL_PERMS[table] || ['tasks.view_own', 'bills.view', 'valuation.view_history', 'valuation.review', 'valuation.approve']
  return <Guard perms={perms}><Drill /></Guard>
}

export default function App() {
  const { user, loading } = useAuth()

  if (loading) return <div className="min-h-screen grid place-items-center text-slate-400">Loading…</div>
  if (!user) return <Suspense fallback={<PageFallback />}><Routes><Route path="*" element={<Login />} /></Routes></Suspense>
  // Forced password reset takes over the whole app until a new password is set.
  if (user.must_reset_password) return (
    <IdleGuard>
      <Suspense fallback={<PageFallback />}><Routes><Route path="*" element={<ForceReset />} /></Routes></Suspense>
    </IdleGuard>
  )

  return (
    <IdleGuard>
      <ErrorBoundary>
      <Suspense fallback={<PageFallback />}>
      <Routes>
        <Route element={<Layout />}>
          <Route path="/" element={<Navigate to="/dashboard" replace />} />
          <Route path="/force-reset" element={<ForceReset />} />
          <Route path="/dashboard" element={<Guard perms={['dashboard.view_own']}><Dashboard /></Guard>} />
          <Route path="/drill/:table" element={<DrillGuard />} />
          <Route path="/bills" element={<Guard perms={['bills.view', 'bills.create', 'bills.edit']}><Bills /></Guard>} />
          <Route path="/bills/:id" element={<Guard perms={['bills.view', 'bills.create', 'bills.edit']}><BillDetail /></Guard>} />
          <Route path="/amendments" element={<Guard perms={['bills.view', 'bills.history', 'bills.amend_document', 'bills.amend_penalty_interest']}><Amendments /></Guard>} />
          <Route path="/tasks" element={<Navigate to="/enforcement?tab=tasks" replace />} />
          <Route path="/valuations" element={<Guard perms={['valuation.create', 'valuation.review', 'valuation.approve', 'valuation.submit', 'valuation.view_history']}><Valuations /></Guard>} />
          <Route path="/discoveries" element={<Navigate to="/enforcement?tab=discoveries" replace />} />
          <Route path="/targets" element={<Guard perms={['targets.view']}><Targets /></Guard>} />
          <Route path="/reports" element={<Reports />} />
          <Route path="/enforcement" element={<Guard perms={['tasks.view_own', 'tasks.view_section', 'tasks.view_division']}><EnforcementHub /></Guard>} />
          <Route path="/account/my-tasks" element={<Guard perms={['tasks.view_own', 'discovery.create', 'discovery.property_registration', 'discovery.aarm_review', 'valuation.account_review', 'payments.verify']}><AccountMyTasks /></Guard>} />
          <Route path="/ac/tasks" element={<Guard perms={['tasks.view_own', 'tasks.view_section', 'tasks.view_division', 'valuation.approve']}><SectionWorkspaceTasks workspace="ac" /></Guard>} />
          <Route path="/valuation/tasks" element={<Guard perms={['tasks.view_own', 'tasks.view_section', 'tasks.view_division', 'valuation.review', 'valuation.view_history']}><SectionWorkspaceTasks workspace="valuation" /></Guard>} />
          <Route path="/users" element={<Guard perms={['staff.view', 'staff.create', 'staff.edit']}><Users /></Guard>} />
          <Route path="/roles" element={<Guard perms={['rbac.assign_permissions', 'rbac.create_role', 'rbac.edit_role']}><RolesAdmin /></Guard>} />
          <Route path="/audit" element={<Guard perms={['audit.view']}><AuditLogs /></Guard>} />
          <Route path="/notifications" element={<Guard perms={['notifications.view']}><Notifications /></Guard>} />
          <Route path="/payments" element={<Guard perms={['payments.view_queue', 'payments.view_history']}><Payments /></Guard>} />
          <Route path="/gps" element={<Guard perms={['gps.view']}><GpsRegistry /></Guard>} />
          <Route path="/monitoring" element={<Guard perms={['me.view', 'me.create', 'me.respond', 'me.close', 'me.assign_walkin', 'me.reassign', 'me.revise', 'me.flag_data_quality', 'me.review', 'valuation.approve']}><MonitoringHub /></Guard>} />
          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Route>
      </Routes>
      </Suspense>
      <DialogMaximize />
    </ErrorBoundary>
    </IdleGuard>
  )
}