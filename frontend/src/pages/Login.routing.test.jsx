// @vitest-environment jsdom

import React, { act } from 'react'
import { createRoot } from 'react-dom/client'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Login from './Login'
import api from '../api'

const { mockUseAuth } = vi.hoisted(() => ({ mockUseAuth: vi.fn() }))

vi.mock('../auth', () => ({ useAuth: mockUseAuth }))
vi.mock('../api', () => ({
  default: { post: vi.fn() },
  errMsg: (_error, fallback) => fallback,
}))

function PathProbe() {
  const { pathname } = useLocation()
  return <span data-testid="current-path">{pathname}</span>
}

let root
let mount

async function setValue(input, value) {
  const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set
  await act(async () => {
    setter.call(input, value)
    input.dispatchEvent(new Event('input', { bubbles: true }))
  })
}

beforeEach(() => {
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  mockUseAuth.mockReturnValue({
    login: vi.fn().mockResolvedValue({ ok: true, mustReset: false }),
    refreshMe: vi.fn(),
    logout: vi.fn(),
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

describe('login navigation with React Router 7', () => {
  it('shows the current Android app version and public release download', async () => {
    await act(async () => root.render(
      <MemoryRouter initialEntries={['/login']}>
        <Routes>
          <Route path="/login" element={<Login />} />
        </Routes>
      </MemoryRouter>,
    ))

    const download = mount.querySelector(
      'a[href="https://github.com/Gayflor-Worzi/rted_lra/releases/download/v1.0.1/retd-lra-field-1.0.4.apk"]',
    )
    expect(download).not.toBeNull()
    expect(mount.textContent).toContain('Field App v1.0.4')
  })

  it('navigates to the dashboard after successful login', async () => {
    await act(async () => root.render(
      <MemoryRouter initialEntries={['/login']}>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/dashboard" element={<div data-testid="dashboard-page">Dashboard</div>} />
        </Routes>
        <PathProbe />
      </MemoryRouter>,
    ))

    await setValue(mount.querySelector('input[type="email"]'), 'staff@example.test')
    await setValue(mount.querySelector('input[type="password"]'), 'password')
    const form = mount.querySelector('form')
    await act(async () => {
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
      await new Promise((resolve) => setTimeout(resolve, 0))
    })

    expect(mount.querySelector('[data-testid="dashboard-page"]')).not.toBeNull()
    expect(mount.querySelector('[data-testid="current-path"]').textContent).toBe('/dashboard')
  })

  it('starts the password-recovery flow from the login screen', async () => {
    api.post.mockResolvedValue({
      data: {
        data: { reference: 'a'.repeat(64) },
        message: 'If this address belongs to an active System Administrator, a verification code will be sent.',
      },
    })

    await act(async () => root.render(
      <MemoryRouter initialEntries={['/login']}>
        <Routes>
          <Route path="/login" element={<Login />} />
        </Routes>
      </MemoryRouter>,
    ))

    await act(async () => {
      mount.querySelector('button[type="button"]').click()
    })

    await setValue(mount.querySelector('input[type="email"]'), 'admin@example.test')
    await act(async () => {
      mount.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
      await new Promise((resolve) => setTimeout(resolve, 0))
    })

    expect(api.post).toHaveBeenCalledWith('/auth/password-recovery/request', { email: 'admin@example.test' })
    expect(mount.textContent).toContain('Verify your email')
    expect(mount.querySelector('input[autocomplete="one-time-code"]')).not.toBeNull()
  })
})
