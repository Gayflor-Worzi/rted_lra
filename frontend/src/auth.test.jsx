// @vitest-environment jsdom

import React, { act } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider, useAuth } from './auth'

const { mockApi } = vi.hoisted(() => ({ mockApi: { get: vi.fn(), post: vi.fn() } }))

vi.mock('./api', () => ({
  default: mockApi,
  TOKEN_KEY: 'retd_auth_token',
  unwrap: (response) => response,
}))

function AuthControls() {
  const auth = useAuth()
  return (
    <div>
      <span data-testid="current-user">{auth.user?.full_name || 'signed out'}</span>
      <button type="button" onClick={() => auth.login('staff@example.test', 'password')}>Sign in</button>
      <button type="button" onClick={() => auth.logout()}>Sign out</button>
    </div>
  )
}

let root
let mount

async function click(label) {
  const button = Array.from(mount.querySelectorAll('button')).find((candidate) => candidate.textContent === label)
  await act(async () => button.dispatchEvent(new MouseEvent('click', { bubbles: true })))
}

beforeEach(async () => {
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  localStorage.clear()
  mockApi.get.mockReset()
  mockApi.post.mockReset()
  mockApi.get.mockResolvedValue({ data: { full_name: 'Test Officer', permissions: ['tasks.view_own'] } })
  mockApi.post.mockResolvedValue({
    data: {
      token: 'test-token',
      user: { full_name: 'Test Officer', permissions: ['tasks.view_own'] },
    },
  })
  mount = document.createElement('div')
  document.body.append(mount)
  root = createRoot(mount)
  await act(async () => root.render(
    <AuthProvider>
      <AuthControls />
    </AuthProvider>,
  ))
})

afterEach(async () => {
  await act(async () => root?.unmount())
  localStorage.clear()
  document.body.replaceChildren()
})

describe('authentication with React Router 7 dependencies', () => {
  it('retains the login token and authenticated user, then clears both on logout', async () => {
    expect(mount.querySelector('[data-testid="current-user"]').textContent).toBe('signed out')

    await click('Sign in')

    expect(mockApi.post).toHaveBeenCalledWith('/auth/login', {
      email: 'staff@example.test',
      password: 'password',
    })
    expect(mockApi.get).toHaveBeenCalledWith('/auth/me')
    expect(localStorage.getItem('retd_auth_token')).toBe('test-token')
    expect(mount.querySelector('[data-testid="current-user"]').textContent).toBe('Test Officer')

    await click('Sign out')

    expect(mockApi.post).toHaveBeenLastCalledWith('/auth/logout')
    expect(localStorage.getItem('retd_auth_token')).toBeNull()
    expect(mount.querySelector('[data-testid="current-user"]').textContent).toBe('signed out')
  })
})
