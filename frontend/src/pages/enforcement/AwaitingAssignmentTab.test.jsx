// @vitest-environment jsdom

import React, { act } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AwaitingAssignmentTab from './AwaitingAssignmentTab'

const { mockApiGet } = vi.hoisted(() => ({ mockApiGet: vi.fn() }))

vi.mock('../../api', () => ({
  default: { get: mockApiGet },
  unwrap: (response) => response.data,
  errMsg: (error) => error.message,
}))
vi.mock('../../components/BillDrawer', () => ({ default: () => null }))

let root
let mount

beforeEach(() => {
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  mockApiGet.mockResolvedValue({
    data: {
      data: {
        data: [{
          id: 17,
          task_type: 'Bill Delivery',
          status: 'Awaiting Assignment',
          reference_id: 29,
          due_date: '2026-11-30',
          bill: {
            id: 29,
            property_id: 'P-QUEUE-29',
            taxpayer_name: 'Queue Taxpayer',
            tin: 'TIN-29',
            total_tax_due: 450,
            property_address: 'Queue address',
          },
        }],
        current_page: 1,
        last_page: 1,
        total: 1,
      },
    },
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

describe('Enforcement awaiting-assignment tab', () => {
  it('requests and displays only the unassigned Bill Delivery queue', async () => {
    await act(async () => root.render(<AwaitingAssignmentTab />))
    await act(async () => new Promise((resolve) => setTimeout(resolve, 0)))

    expect(mockApiGet).toHaveBeenCalledWith('/tasks', {
      params: {
        workspace: 'enforcement',
        view: 'unassigned',
        task_type: 'Bill Delivery',
        status: 'Awaiting Assignment',
        per_page: 25,
        page: 1,
      },
    })
    expect(mount.textContent).toContain('Bills Awaiting Assignment')
    expect(mount.textContent).toContain('P-QUEUE-29')
    expect(mount.textContent).toContain('Queue Taxpayer')
    expect(mount.textContent).not.toContain('No bills are awaiting assignment.')
  })
})
