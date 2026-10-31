// @vitest-environment jsdom

import React, { act } from 'react'
import { createRoot } from 'react-dom/client'
import { MemoryRouter, Outlet, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Layout from './Layout'

const { mockUseAuth, mockApiGet } = vi.hoisted(() => ({
  mockUseAuth: vi.fn(),
  mockApiGet: vi.fn(),
}))

vi.mock('../auth', () => ({ useAuth: mockUseAuth }))
vi.mock('../api', () => ({ default: { get: mockApiGet } }))

let root
let mount

async function renderFor(role, permissions, organizationalAssignment = null) {
  mockUseAuth.mockReturnValue({
    user: { role, full_name: 'Test User', must_reset_password: false, organizational_assignment: organizationalAssignment },
    can: (...requested) => requested.some((permission) => permissions.includes(permission)),
    logout: vi.fn(),
  })

  await act(async () => root.render(
    <MemoryRouter initialEntries={['/dashboard']}>
      <Routes>
        <Route element={<Layout />}>
          <Route path="/dashboard" element={<Outlet />} />
          <Route path="/enforcement" element={<div />} />
        </Route>
      </Routes>
    </MemoryRouter>,
  ))
  await act(async () => new Promise((resolve) => setTimeout(resolve, 0)))

  return [...mount.querySelectorAll('a[href="/enforcement?tab=organization"]')]
}

beforeEach(() => {
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  mockApiGet.mockResolvedValue({ data: { data: { unread_count: 0, unread: 0 } } })
  mount = document.createElement('div')
  document.body.append(mount)
  root = createRoot(mount)
})

afterEach(async () => {
  await act(async () => root?.unmount())
  document.body.replaceChildren()
  vi.clearAllMocks()
})

describe('Team Management navigation', () => {
  it.each([
    ['Enforcement Manager', ['enforcement.org.manage']],
    ['Enforcement Supervisor', ['enforcement.org.recommend']],
    ['Assistant Commissioner', ['enforcement.org.review']],
    ['System Administrator', ['enforcement.org.manage', 'enforcement.org.recommend', 'enforcement.org.review']],
  ])('links %s to the organization tab', async (role, permissions) => {
    const links = await renderFor(role, permissions)

    expect(links.length).toBeGreaterThan(0)
    expect(links.some((link) => link.textContent.includes('Team Management'))).toBe(true)
  })

  it('does not show Team Management without an organization permission', async () => {
    const links = await renderFor('Enforcement Officer', ['tasks.view_own'])

    expect(links).toHaveLength(0)
  })
})

describe('Enforcement assignment queue navigation', () => {
  it.each([
    ['Enforcement Supervisor', ['enforcement.view_assignments']],
    ['Enforcement Manager', ['enforcement.view_assignments']],
    ['System Administrator', ['enforcement.view_assignments']],
  ])('links %s to the awaiting-assignment tab', async (role, permissions) => {
    await renderFor(role, permissions)

    const links = [...mount.querySelectorAll('a[href="/enforcement?tab=awaiting-assignment"]')]
    expect(links.some((link) => link.textContent.includes('Awaiting Assignment'))).toBe(true)
  })

  it('does not link the Assistant Commissioner to the enforcement queue', async () => {
    await renderFor('Assistant Commissioner', ['enforcement.view_assignments'])

    expect(mount.querySelectorAll('a[href="/enforcement?tab=awaiting-assignment"]')).toHaveLength(0)
  })
})

describe('Unified dashboard navigation', () => {
  it('shows one main dashboard and labels section workspaces as operations', async () => {
    await renderFor('Enforcement Manager', [
      'dashboard.view_own',
      'dashboard.view_division',
      'tasks.view_section',
      'enforcement.view_assignments',
      'valuation.view_history',
    ])

    expect(mount.querySelectorAll('a[href="/dashboard"]')).toHaveLength(1)
    expect(mount.querySelector('a[href="/enforcement?tab=overview"]')?.textContent).toContain('Operational Overview')
    expect(mount.querySelector('a[href="/valuations"]')?.textContent).toContain('Valuations')
    expect(mount.textContent).not.toContain('Division Dashboard')

    await act(async () => mount.querySelector('button[aria-label="Open menu"]').click())

    expect(mount.querySelectorAll('a[href="/dashboard"]')).toHaveLength(1)
  })
})

describe('Dashboard welcome assignment credentials', () => {
  it('shows an enforcement officer’s tax region and primary district inline with their welcome credentials', async () => {
    await renderFor('Enforcement Officer', ['dashboard.view_own'], {
      tax_region: 'North Region',
      tax_district: 'Central District',
    })

    const welcome = [...mount.querySelectorAll('span')].find((element) => element.textContent === 'Welcome back,')?.parentElement
    expect(welcome?.textContent).toContain('Tax Region: North Region')
    expect(welcome?.textContent).toContain('Tax District: Central District')
    expect(welcome?.textContent).toContain('Enforcement Officer')
  })

  it('shows Not Assigned for missing officer assignment and does not add the credentials for other roles', async () => {
    await renderFor('Enforcement Officer', ['dashboard.view_own'])
    expect(mount.textContent).toContain('Tax Region: Not Assigned')
    expect(mount.textContent).toContain('Tax District: Not Assigned')

    await renderFor('Enforcement Manager', ['dashboard.view_own'])
    expect(mount.textContent).not.toContain('Tax Region:')
    expect(mount.textContent).not.toContain('Tax District:')
  })
})
