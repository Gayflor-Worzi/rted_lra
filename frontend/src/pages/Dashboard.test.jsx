// @vitest-environment jsdom

import React, { act } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Dashboard from './Dashboard'

const { mockUseAuth } = vi.hoisted(() => ({ mockUseAuth: vi.fn() }))

vi.mock('../auth', () => ({ useAuth: mockUseAuth }))
vi.mock('../components/Analytics', () => ({ default: () => null }))
vi.mock('../components/PerformanceTargets', () => ({ default: () => null }))
vi.mock('../components/ManagementPerformance', () => ({ default: () => null }))
vi.mock('./OfficerDashboard', () => ({
  default: ({ showExports = true }) => <div data-testid="officer-dashboard">{String(showExports)}</div>,
}))
vi.mock('./ValuationHome', () => ({
  default: ({ showExports = true }) => <div data-testid="valuation-dashboard">{String(showExports)}</div>,
}))
vi.mock('./ManagerDashboard', () => ({
  default: () => null,
  managerType: () => null,
}))

let root
let mount

async function renderFor(role) {
  mockUseAuth.mockReturnValue({
    user: { role, full_name: 'Test User' },
    can: () => false,
  })

  await act(async () => root.render(<Dashboard />))
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

describe('Main dashboard exports', () => {
  it('does not render officer exports on the main dashboard', async () => {
    await renderFor('Enforcement Officer')

    expect(mount.querySelector('[data-testid="officer-dashboard"]')?.textContent).toBe('false')
  })

  it('does not render valuation exports on the main dashboard', async () => {
    await renderFor('Valuation Officer')

    expect(mount.querySelector('[data-testid="valuation-dashboard"]')?.textContent).toBe('false')
  })
})
