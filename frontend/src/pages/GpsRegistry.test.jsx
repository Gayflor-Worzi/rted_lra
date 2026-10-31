// @vitest-environment jsdom

import React, { act } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import GpsRegistry from './GpsRegistry'

const { mockGet, mockPost, mockPut, mockSetParams } = vi.hoisted(() => ({
  mockGet: vi.fn(),
  mockPost: vi.fn(),
  mockPut: vi.fn(),
  mockSetParams: vi.fn(),
}))

vi.mock('../auth', () => ({
  useAuth: () => ({ can: (...permissions) => permissions.some((permission) => ['gps.view', 'gps.view_history', 'gps.review', 'gps.approve', 'gps.capture', 'gps.certify', 'gps.update'].includes(permission)) }),
}))

vi.mock('../api', () => ({
  default: { get: mockGet, post: mockPost, put: mockPut },
  errMsg: (error) => error?.message || 'Request failed',
}))

vi.mock('react-router-dom', () => ({
  useSearchParams: () => [new URLSearchParams(), mockSetParams],
}))

const reviewRecord = {
  id: 8,
  property_id: 'GPS-TEST-8',
  new_latitude: '6.3156110',
  new_longitude: '-10.8074070',
  accuracy: '167.35',
  captured_at: '2026-10-30T12:00:00Z',
  captured_by: 'Field Officer',
  verification_status: 'pending_review',
  source: 'other',
  certification_decision: 'yes',
  remarks: 'Main entrance',
  property: { address: 'GPS Test Street', tax_district: 'Central', taxpayer_name: 'Test Taxpayer' },
}

let root
let mount

beforeEach(() => {
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  mockGet.mockImplementation((path) => {
    if (path === '/gps/reviews') return Promise.resolve({ data: { data: { data: [reviewRecord], current_page: 1, last_page: 1 } } })
    if (path === '/gps/registry') return Promise.resolve({ data: { data: { records: { data: [], current_page: 1, last_page: 1 }, counts: { total: 1, pending_review: 1, approved: 0, rejected: 0 } } } })
    return Promise.reject(new Error(`Unexpected request: ${path}`))
  })
  mockPost.mockResolvedValue({ data: { data: { message: 'Certification rejected.' } } })
  mount = document.createElement('div')
  document.body.append(mount)
  root = createRoot(mount)
})

afterEach(async () => {
  await act(async () => root?.unmount())
  document.body.replaceChildren()
  vi.clearAllMocks()
})

describe('GPS Registry review workspace', () => {
  it('uses a dedicated reviews workspace and requires a reason before rejection', async () => {
    await act(async () => root.render(<GpsRegistry />))
    await act(async () => new Promise((resolve) => setTimeout(resolve, 300)))

    expect(mount.textContent).toContain('GPS Registry')
    const reviewsTab = [...mount.querySelectorAll('button')].find((button) => button.textContent.includes('Certification Reviews'))
    await act(async () => reviewsTab.click())
    await act(async () => new Promise((resolve) => setTimeout(resolve, 300)))

    expect(mockGet).toHaveBeenCalledWith('/gps/reviews', { params: { page: 1, per_page: 20 } })
    expect(mount.textContent).toContain('GPS Test Street')
    expect(mount.textContent).toContain('167.35 m')

    const reject = [...mount.querySelectorAll('button')].find((button) => button.textContent === 'Reject')
    await act(async () => reject.click())

    const confirm = [...mount.querySelectorAll('button')].find((button) => button.textContent === 'Confirm rejection')
    expect(confirm.disabled).toBe(true)
    const textarea = mount.querySelector('textarea')
    await act(async () => {
      const setter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value').set
      setter.call(textarea, 'Please recapture at the entrance.')
      textarea.dispatchEvent(new Event('input', { bubbles: true }))
      textarea.dispatchEvent(new Event('change', { bubbles: true }))
    })
    expect(confirm.disabled).toBe(false)

    await act(async () => confirm.click())
    expect(mockPost).toHaveBeenCalledWith('/gps/reviews/8/decision', {
      decision: 'reject',
      remarks: 'Please recapture at the entrance.',
    })
  })
})
