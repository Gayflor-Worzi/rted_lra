// @vitest-environment jsdom

import React, { act } from 'react'
import { createRoot } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App'

const { mockUseAuth } = vi.hoisted(() => ({ mockUseAuth: vi.fn() }))

vi.mock('./auth', () => ({ useAuth: mockUseAuth }))
vi.mock('./components/IdleGuard', () => ({ default: ({ children }) => children }))
vi.mock('./components/Layout', async () => {
  const { Outlet } = await import('react-router-dom')
  return { default: () => <Outlet /> }
})

vi.mock('./pages/Login', () => ({ default: () => <div data-testid="login-page">Sign in</div> }))
vi.mock('./pages/ForceReset', () => ({ default: () => <div data-testid="force-reset-page">Reset password</div> }))
vi.mock('./pages/Reports', () => ({ default: () => <div data-testid="reports-page">Reports</div> }))
vi.mock('./pages/Dashboard', async () => {
  const { useLocation } = await import('react-router-dom')
  return { default: () => <div data-testid="dashboard-page">{useLocation().pathname}</div> }
})
vi.mock('./pages/enforcement/EnforcementHub', async () => {
  const { useSearchParams } = await import('react-router-dom')
  return { default: () => {
    const [params] = useSearchParams()
    return <div data-testid="enforcement-tab">{params.get('tab')}</div>
  } }
})

let root
let mount

async function settle() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20))
  })
}

async function renderAt(path) {
  await act(async () => root.unmount())
  mount.replaceChildren()
  root = createRoot(mount)
  await act(async () => root.render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  ))
  await settle()
}

beforeEach(() => {
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  mount = document.createElement('div')
  document.body.append(mount)
  root = createRoot(mount)
})

afterEach(async () => {
  await act(async () => root?.unmount())
  document.body.replaceChildren()
  vi.clearAllMocks()
})

describe('React Router application behavior', () => {
  it('shows login for unauthenticated protected URLs', async () => {
    mockUseAuth.mockReturnValue({ user: null, loading: false })

    await renderAt('/users')

    expect(mount.querySelector('[data-testid="login-page"]')).not.toBeNull()
    expect(mount.querySelector('[data-testid="dashboard-page"]')).toBeNull()
  })

  it('keeps forced password reset ahead of ordinary routes', async () => {
    mockUseAuth.mockReturnValue({
      user: { must_reset_password: true },
      loading: false,
    })

    await renderAt('/users')

    expect(mount.querySelector('[data-testid="force-reset-page"]')).not.toBeNull()
    expect(mount.querySelector('[data-testid="login-page"]')).toBeNull()
  })

  it('keeps permission guards and the default dashboard redirect', async () => {
    mockUseAuth.mockReturnValue({
      user: { role: 'Staff member', full_name: 'Test User' },
      loading: false,
      can: () => false,
    })

    await renderAt('/users')

    expect(mount.textContent).toContain('Access Restricted')

    mockUseAuth.mockReturnValue({
      user: { role: 'Authorized staff', permissions: ['dashboard.view_own'] },
      loading: false,
      can: (permission) => permission === 'dashboard.view_own',
    })
    await renderAt('/')

    expect(mount.querySelector('[data-testid="dashboard-page"]')?.textContent).toBe('/dashboard')
  })

  it('allows any authenticated user to reach the personally scoped Reports page', async () => {
    mockUseAuth.mockReturnValue({
      user: { role: 'Staff member', full_name: 'Test User', permissions: [] },
      loading: false,
      can: () => false,
    })

    await renderAt('/reports')

    expect(mount.querySelector('[data-testid="reports-page"]')).not.toBeNull()
  })

  it.each([
    ['/tasks', 'tasks'],
    ['/discoveries', 'discoveries'],
  ])('preserves the legacy redirect and search tab for %s', async (path, expectedTab) => {
    mockUseAuth.mockReturnValue({
      user: { role: 'Enforcement Officer' },
      loading: false,
      can: (permission) => permission === 'tasks.view_own',
    })

    await renderAt(path)

    expect(mount.querySelector('[data-testid="enforcement-tab"]')?.textContent).toBe(expectedTab)
  })
})
