import { Outlet, useNavigate, Link, Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '../auth'
import api from '../api'
import { useEffect, useState } from 'react'
import { BRAND } from '../lib/brand'

const NAV_BREAKPOINT = 1024 // lg; below this the sidebar becomes a drawer

/**
 * Information-architecture grouped navigation. Leaves map to the same route
 * URLs as before (no duplicate pages); Enforcement is a parent workspace whose
 * sub-pages are query-string tabs on /enforcement. Groups only render when at
 * least one of their items survives RBAC + first-wins de-duplication.
 */
const GROUPS = (can) => [
  {
    key: 'acct',
    label: 'Account & Records',
    items: [
      { to: '/bills', label: 'Bill Register', icon: '🧾', show: can('bills.view', 'bills.create', 'bills.edit') },
      { to: '/payments', label: 'Payment Verification', icon: '💳', show: can('payments.view_queue', 'payments.view_history') },
      { to: '/drill/bills', label: 'Records', icon: '🗂️', show: can('bills.view', 'records.view') },
      { to: '/reports', label: 'Reports', icon: '📑', show: can('reports.view', 'reports.export') },
    ],
  },
  {
    key: 'enf',
    label: 'Enforcement',
    items: [
      { to: '/enforcement?tab=overview', label: 'Dashboard', icon: '🚔', show: true },
      { to: '/enforcement?tab=tasks', label: 'My Tasks', icon: '📋', show: can('tasks.view_own') },
      { to: '/enforcement?tab=tasks&scope=team', label: 'Team Tasks', icon: '👥', show: can('tasks.view_section', 'tasks.view_division') },
      { to: '/enforcement?tab=tasks&group=escalated', label: 'Escalated', icon: '🚨', show: can('tasks.view_own') },
      { to: '/enforcement?tab=discoveries', label: 'Property Discoveries', icon: '📡', show: can('discovery.view', 'discovery.create', 'discovery.review') },
      { to: '/enforcement?tab=followups', label: 'Follow-ups', icon: '📞', show: can('tasks.view_own', 'enforcement.record_visit') },
      { to: '/enforcement?tab=records', label: 'Records', icon: '🗂️', show: can('tasks.view_own', 'tasks.view_section', 'tasks.view_division') },
    ],
  },
  {
    key: 'valuation',
    label: 'Valuation',
    items: [
      { to: '/valuations', label: 'Valuation Dashboard', icon: '🏷️', show: can('valuation.create', 'valuation.review', 'valuation.approve', 'valuation.submit', 'valuation.view_history') },
      { to: '/drill/valuations', label: 'Valuation Records', icon: '🗂️', show: can('valuation.view_history', 'valuation.review', 'valuation.approve') },
    ],
  },
  {
    key: 'admin',
    label: 'Administration',
    items: [
      { to: '/users', label: 'Staff', icon: '👥', show: can('staff.view', 'staff.create', 'staff.edit') },
      { to: '/roles', label: 'Roles & Permissions', icon: '🔐', show: can('rbac.assign_permissions', 'rbac.create_role', 'rbac.edit_role') },
      { to: '/targets', label: 'Staff Targets', icon: '🎯', show: can('targets.view') },
      { to: '/audit', label: 'Audit Logs', icon: '🗂️', show: can('audit.view') },
    ],
  },
  {
    key: 'division',
    label: 'Division / AC',
    items: [
      { to: '/dashboard', label: 'Division Dashboard', icon: '📊', show: can('dashboard.view_division') },
      { to: '/bills', label: 'Account & Records', icon: '🧾', show: can('dashboard.view_division') },
      { to: '/enforcement?tab=overview', label: 'Enforcement', icon: '🚔', show: can('dashboard.view_division') },
      { to: '/valuations', label: 'Valuation', icon: '🏷️', show: can('dashboard.view_division', 'valuation.approve') },
      { to: '/enforcement?tab=discoveries', label: 'Property Discoveries', icon: '📡', show: can('dashboard.view_division', 'discovery.approve') },
      { to: '/payments', label: 'Payments', icon: '💳', show: can('dashboard.view_division') },
      { to: '/targets', label: 'Performance', icon: '🎯', show: can('dashboard.view_division') },
      { to: '/reports', label: 'Reports', icon: '📑', show: can('dashboard.view_division', 'reports.view') },
    ],
  },
]

function SideLink({ to, icon, label, sub }) {
  const { pathname, search } = useLocation()
  const [path, q] = to.split('?')
  const active = pathname === path && (q === undefined ? true : search === `?${q}`)
  return (
    <Link
      to={to}
      onClick={() => window.__lraCloseNav?.()}
      className={`flex items-center gap-3 px-3 py-2 rounded-xl text-sm transition ${sub ? 'ml-3' : ''} ${active ? 'bg-brand-500 text-white font-semibold shadow-lg shadow-brand-500/20' : 'hover:bg-navy-700 text-slate-300'}`}>
      <span className="text-base">{icon}</span>{label}
    </Link>
  )
}

// Sidebar body — shared by the static desktop rail and the mobile drawer.
function SidebarBody({ user, initial }) {
  const { logout, can } = useAuth()
  const nav = useNavigate()

  const seen = new Set()
  const visible = GROUPS(can)
    .map((g) => {
      const items = g.items.filter((n) => n.show && (!seen.has(n.to) ? (seen.add(n.to), true) : false))
      return { ...g, items }
    })
    .filter((g) => g.items.length > 0)

  return (
    <div className="flex flex-col h-full">
      <div className="px-5 py-5 border-b border-navy-700">
        <div className="flex items-center gap-2">
          <div className="w-10 h-10 rounded-md bg-white/95 grid place-items-center overflow-hidden">
            <img src="/assets/lra-logo.png" alt="LRA" className="max-w-full max-h-full object-contain" />
          </div>
          <div>
            <div className="font-bold text-white leading-tight">{BRAND.short}</div>
            <div className="text-[11px] text-slate-400">{BRAND.dept}</div>
          </div>
        </div>
      </div>
      <nav className="flex-1 p-3 space-y-4 overflow-y-auto">
        {can('dashboard.view_own') && <SideLink to="/dashboard" icon="📊" label="Dashboard" />}
        {visible.map((g) => (
          <div key={g.key}>
            <div className="px-3 pb-1 text-[10px] uppercase tracking-widest text-slate-500 font-bold">{g.label}</div>
            <div className="space-y-1">
              {g.items.map((n) => (
                <SideLink key={n.to} to={n.to} icon={n.icon} label={n.label} />
              ))}
            </div>
          </div>
        ))}
      </nav>
      <div className="p-4 border-t border-navy-700">
        <div className="flex items-center gap-3 mb-3">
          <div className="w-9 h-9 rounded-full bg-navy-600 grid place-items-center font-bold text-white text-sm">{initial}</div>
          <div className="min-w-0">
            <div className="font-semibold text-white text-sm truncate">{user?.full_name}</div>
            <div className="text-[11px] text-brand-200">{user?.role}</div>
          </div>
        </div>
        <button onClick={async () => { await logout(); nav('/login') }}
          className="mt-1 w-full py-2 rounded-xl bg-navy-700 hover:bg-navy-600 text-white text-sm transition">
          Log out
        </button>
      </div>
    </div>
  )
}

// Compact bottom navigation for small screens (uses the same RBAC-filtered items).
function BottomNav({ items }) {
  const nav = useNavigate()
  const { pathname } = useLocation()
  return (
    <nav className="fixed bottom-0 left-0 right-0 z-40 lg:hidden bg-white border-t border-slate-200 shadow-[0_-2px_10px_rgba(0,0,0,0.06)] pb-[env(safe-area-inset-bottom)]">
      <ul className="flex overflow-x-auto">
        {items.map((n) => {
          const [path] = n.to.split('?')
          const active = pathname === path
          return (
            <li key={n.to} className="flex-1 min-w-[64px]">
              <button onClick={() => nav(n.to)}
                className={`w-full flex flex-col items-center gap-0.5 py-2 px-1 text-[10px] ${active ? 'text-brand-500 font-bold' : 'text-slate-400'}`}>
                <span className="text-lg leading-none">{n.icon}</span>
                <span className="truncate">{n.label}</span>
              </button>
            </li>
          )
        })}
      </ul>
    </nav>
  )
}

export default function Layout() {
  const { user, logout, can } = useAuth()
  const nav = useNavigate()
  const location = useLocation()
  const [unread, setUnread] = useState(0)
  const [navOpen, setNavOpen] = useState(false)

  // If the account still needs a forced reset, force the screen.
  if (user?.must_reset_password) return <Navigate to="/force-reset" replace />

  useEffect(() => {
    const load = () =>
      api.get('/notifications/unread-count')
        .then((r) => setUnread(r.data.data.unread_count))
        .catch(() => {})
    load()
    const iv = setInterval(load, 30000)
    return () => clearInterval(iv)
  }, [])

  useEffect(() => {
    // Tap a nav link -> close the mobile drawer; also close on route change.
    window.__lraCloseNav = () => setNavOpen(false)
    return () => { window.__lraCloseNav = undefined }
  }, [])

  useEffect(() => { setNavOpen(false) }, [location.pathname, location.search])

  const initial = (user?.full_name || '?').charAt(0).toUpperCase()

  // First-wins de-dupe across groups (e.g. /dashboard or /reports appear once).
  const seen = new Set()
  const visible = GROUPS(can)
    .map((g) => {
      const items = g.items.filter((n) => n.show && (!seen.has(n.to) ? (seen.add(n.to), true) : false))
      return { ...g, items }
    })
    .filter((g) => g.items.length > 0)

  // Bottom nav = Dashboard + one representative item per visible group.
  const bottomItems = [
    ...(can('dashboard.view_own') ? [{ to: '/dashboard', icon: '📊', label: 'Home' }] : []),
    ...visible.map((g) => g.items[0]),
  ].filter(Boolean).slice(0, 5)

  return (
    <div className="min-h-screen flex">
      {/* Desktop/tablet static sidebar (lg and up) */}
      <aside className="hidden lg:flex w-64 bg-navy-800 text-slate-300 flex-col shrink-0">
        <SidebarBody user={user} initial={initial} />
      </aside>

      {/* Mobile drawer + backdrop (below lg) */}
      {navOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <div className="absolute inset-0 bg-black/50" onClick={() => setNavOpen(false)} />
          <aside className="absolute inset-y-0 left-0 w-72 max-w-[85vw] bg-navy-800 text-slate-300 shadow-xl">
            <SidebarBody user={user} initial={initial} />
            <button onClick={() => setNavOpen(false)}
              className="lg:hidden absolute top-3 right-3 w-9 h-9 grid place-items-center rounded-lg bg-navy-700 text-white text-lg">
              ✕
            </button>
          </aside>
        </div>
      )}

      <main className="flex-1 flex flex-col overflow-hidden">
        <header className="h-14 bg-white border-b border-slate-200 flex items-center justify-between px-4 sm:px-6 shrink-0">
          <div className="flex items-center gap-2 min-w-0">
            <button onClick={() => setNavOpen(true)}
              className="lg:hidden w-9 h-9 grid place-items-center rounded-xl text-navy-800 hover:bg-slate-100 text-xl shrink-0"
              aria-label="Open menu">
              ☰
            </button>
            <div className="text-sm text-slate-500 truncate">
              Welcome back, <span className="font-semibold text-navy-800">{user?.full_name?.split(' ')[0]}</span>
              {' · '}<span className="text-brand-500 font-medium hidden sm:inline">{user?.role}</span>
            </div>
          </div>
          <div className="flex items-center gap-3 shrink-0">
            <a href={BRAND.apkUrl}
              className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white border border-slate-200 shadow-sm hover:bg-slate-50 text-sm"
              title="Download the Android field app">
              📲<span className="hidden md:inline">Field App</span>
            </a>
            <Link to="/notifications"
              className="relative px-3 py-1.5 rounded-xl bg-white border border-slate-200 shadow-sm hover:bg-slate-50 text-sm">
              🔔<span className="hidden sm:inline"> Notifications</span>
              {unread > 0 && <span className="ml-1 absolute -top-1.5 -right-1.5 px-1.5 py-0.5 rounded-full bg-brand-500 text-white text-[10px] font-bold">{unread}</span>}
            </Link>
          </div>
        </header>
        <div className="p-4 sm:p-6 pb-24 lg:pb-6 overflow-y-auto flex-1 max-w-[1400px] w-full mx-auto">
          <Outlet />
        </div>
      </main>

      <BottomNav items={bottomItems} />
    </div>
  )
}