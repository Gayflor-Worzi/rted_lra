// @vitest-environment jsdom

import React, { act } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Reports from './Reports'

const { mockUseAuth, mockApiGet } = vi.hoisted(() => ({
  mockUseAuth: vi.fn(),
  mockApiGet: vi.fn(),
}))

vi.mock('../auth', () => ({ useAuth: mockUseAuth }))
vi.mock('../api', () => ({
  default: { get: mockApiGet },
  unwrap: (response) => response.data,
  errMsg: () => 'Request failed',
}))

let root
let mount

beforeEach(() => {
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  mockUseAuth.mockReturnValue({
    user: { role: 'Enforcement Officer' },
    can: (...permissions) => permissions.includes('tasks.view_own'),
  })
  mockApiGet.mockResolvedValue({
    data: { data: { rows: { data: [], total: 0 } } },
  })
  mount = document.createElement('div')
  document.body.append(mount)
  root = createRoot(mount)
})

afterEach(async () => {
  await act(async () => root?.unmount())
  document.body.replaceChildren()
  vi.clearAllMocks()
})

describe('Reports dashboard consolidation', () => {
  it.each(['Enforcement Officer', 'Valuation Officer'])('does not present a second dashboard to %s', async (role) => {
    mockUseAuth.mockReturnValue({
      user: { role },
      can: (...permissions) => permissions.includes('tasks.view_own'),
    })

    await act(async () => root.render(<Reports />))
    await act(async () => new Promise((resolve) => setTimeout(resolve, 0)))

    expect(mount.textContent).not.toContain('Officer Dashboard Export')
    expect(mount.textContent).not.toContain('Valuation Dashboard Export')
  })
})
