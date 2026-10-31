// @vitest-environment jsdom

import React, { act } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import OrganizationTab from './OrganizationTab'

const { mockApiGet, mockApiPost } = vi.hoisted(() => ({ mockApiGet: vi.fn(), mockApiPost: vi.fn() }))

vi.mock('../../api', () => ({
  default: { get: mockApiGet, post: mockApiPost },
  unwrap: (response) => response.data,
  errMsg: (error) => error.message,
}))

let root
let mount

beforeEach(() => {
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  mount = document.createElement('div')
  document.body.append(mount)
  root = createRoot(mount)
})

afterEach(async () => {
  await act(async () => root?.unmount())
  document.body.replaceChildren()
  mockApiGet.mockReset()
  mockApiPost.mockReset()
})

const clickButton = async (label) => {
  const button = Array.from(mount.querySelectorAll('button')).find((item) => item.textContent.includes(label))
  if (!button) throw new Error(`Could not find button: ${label}. Available buttons: ${Array.from(mount.querySelectorAll('button')).map((item) => item.textContent.trim()).join(' | ')}`)
  await act(async () => button.click())
}

describe('Enforcement organization tab', () => {
  it('displays district recommendations as readable details instead of raw JSON', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: {
          can_manage: true,
          can_recommend: false,
          can_review: false,
          can_administer_geography: true,
          can_assign_supervisor: true,
          teams: [],
          districts: [],
          officers: [],
          supervisors: [],
          recommendations: [{
            id: 1,
            change_type: 'create_district',
            payload: { name: 'PAYNESVILLE TAX DISTRICT' },
            status: 'pending',
            submitted_by: 'Mr. Doe',
            submitted_at: '2026-10-30T08:52:48.000Z',
          }],
        },
      },
    })

    await act(async () => root.render(<OrganizationTab />))
    await act(async () => new Promise((resolve) => setTimeout(resolve, 0)))
    await clickButton('Organization Overview')

    expect(mount.textContent).toContain('District')
    expect(mount.textContent).toContain('PAYNESVILLE TAX DISTRICT')
    expect(mount.querySelector('pre')).toBeNull()
  })

  it('lets an Enforcement Manager submit structural changes for review', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: {
          can_manage: true,
          can_recommend: false,
          can_review: false,
          can_administer_geography: true,
          can_assign_supervisor: true,
          teams: [],
          districts: [],
          officers: [],
          supervisors: [],
          recommendations: [],
        },
      },
    })

    await act(async () => root.render(<OrganizationTab />))
    await act(async () => new Promise((resolve) => setTimeout(resolve, 0)))

    expect(mount.textContent).toContain('Team Management')
    await clickButton('Create Tax Region')
    expect(mount.querySelector('[role="dialog"]')).not.toBeNull()
    expect(mount.textContent).toContain('Assistant Commissioner for review')
    expect(mount.querySelector('button[aria-pressed="true"]')).not.toBeNull()
  })

  it('offers a Supervisor team recommendation to the responsible Manager', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: {
          can_manage: true,
          can_recommend: false,
          can_review: false,
          can_review_membership: true,
          teams: [],
          districts: [],
          officers: [],
          supervisors: [],
          recommendations: [{
            id: 8,
            change_type: 'update_team',
            payload: { team_id: 1, name: 'Updated team' },
            status: 'pending',
            submitted_by: 'Regional Supervisor',
            can_review: true,
          }],
        },
      },
    })

    await act(async () => root.render(<OrganizationTab />))
    await act(async () => new Promise((resolve) => setTimeout(resolve, 0)))
    await clickButton('Organization Overview')

    expect(mount.textContent).toContain('Approve')
    expect(mount.textContent).toContain('Reject')
  })

  it('closes the review confirmation and shows the decision after approval', async () => {
    const pending = {
      can_manage: false,
      can_review: true,
      regions: [],
      districts: [],
      teams: [],
      officers: [],
      supervisors: [],
      recommendations: [{
        id: 8,
        change_type: 'create_district',
        payload: { name: 'Paynesville Tax District' },
        status: 'pending',
        can_review: true,
      }],
    }
    const approved = {
      ...pending,
      recommendations: [{
        ...pending.recommendations[0],
        status: 'approved',
      }],
    }
    mockApiGet
      .mockResolvedValueOnce({ data: { data: pending } })
      .mockResolvedValueOnce({ data: { data: approved } })
    mockApiPost.mockResolvedValue({ data: { data: { status: 'approved' } } })

    await act(async () => root.render(<OrganizationTab />))
    await act(async () => new Promise((resolve) => setTimeout(resolve, 0)))
    await clickButton('Organization Overview')
    await clickButton('Approve request')
    expect(mount.querySelector('[role="dialog"]')).not.toBeNull()
    await clickButton('Confirm approval')

    expect(mockApiPost).toHaveBeenCalledWith('/enforcement/organization/recommendations/8/approve', {})
    expect(mount.querySelector('[role="dialog"]')).toBeNull()
    expect(mount.textContent).toContain('Recommendation approved.')
    expect(mount.textContent).toContain('approved')
    expect(mount.textContent).toContain('Paynesville Tax District')
  })

  it('requires a Team Lead when creating a team', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: {
          can_manage: true,
          can_recommend: false,
          can_review: false,
          can_administer_geography: true,
          can_assign_supervisor: true,
          regions: [{ id: 1, name: 'Region One', classification: 'Urban', is_active: true, supervisor_id: 5, supervisor_active: true, supervisor: 'Sup One', districts: [] }],
          districts: [{ id: 2, name: 'District One', region_id: 1, is_active: true, region: 'Region One', classification: 'Urban' }],
          officers: [{ id: 9, full_name: 'Officer One', current_team_id: null }],
          teams: [],
          supervisors: [],
          recommendations: [],
        },
      },
    })

    await act(async () => root.render(<OrganizationTab />))
    await act(async () => new Promise((resolve) => setTimeout(resolve, 0)))

    await clickButton('Enforcement Teams')
    await clickButton('Create Enforcement Team')

    expect(mount.querySelector('[role="dialog"]')).not.toBeNull()
    expect(mount.textContent).toContain('Team Lead')
    expect(mount.textContent).not.toContain('Team Lead (optional)')
    expect(mount.textContent).toContain('Additional officers (optional)')
    expect(mount.querySelector('select[required]')).not.toBeNull()
  })

  it('shows district assignments awaiting AC approval and excludes them from duplicate requests', async () => {
    const organization = {
      can_manage: true,
      can_administer_geography: true,
      regions: [{ id: 3, name: 'Central Region', classification: 'Urban', is_active: true, districts: [] }],
      districts: [
        { id: 10, name: 'Central Monrovia District', is_active: true, region_id: null },
        { id: 11, name: 'Paynesville Tax District', is_active: true, region_id: null },
      ],
      teams: [],
      officers: [],
      supervisors: [],
      recommendations: [],
    }
    const pending = {
      ...organization,
      recommendations: [{
        id: 24,
        change_type: 'assign_districts_to_region',
        payload: { region_id: 3, district_ids: [10, 11] },
        status: 'pending',
      }],
    }
    mockApiGet
      .mockResolvedValueOnce({ data: { data: organization } })
      .mockResolvedValueOnce({ data: { data: pending } })
    mockApiPost.mockResolvedValue({
      data: { message: 'Organization change submitted for review.' },
    })

    await act(async () => root.render(<OrganizationTab />))
    await act(async () => new Promise((resolve) => setTimeout(resolve, 0)))

    await clickButton('Assign districts')
    const dialog = mount.querySelector('[role="dialog"]')
    const regionSelect = Array.from(dialog.querySelectorAll('select')).find((select) =>
      Array.from(select.options).some((option) => option.textContent === 'Select a region'))
    const districtSelect = dialog.querySelector('select[multiple]')
    await act(async () => {
      regionSelect.value = '3'
      regionSelect.dispatchEvent(new Event('change', { bubbles: true }))
    })
    await act(async () => {
      for (const option of districtSelect.options) option.selected = true
      districtSelect.dispatchEvent(new Event('change', { bubbles: true }))
    })
    await clickButton('Submit assign districts to region')

    expect(mockApiPost).toHaveBeenCalledWith('/enforcement/organization/recommendations', {
      change_type: 'assign_districts_to_region',
      region_id: 3,
      district_ids: [10, 11],
    })
    expect(mount.querySelector('[role="dialog"]')).toBeNull()
    expect(mount.textContent).toContain('Approvals & recommendations')
    expect(mount.textContent).toContain('Assign districts to region')
    expect(mount.textContent).toContain('Central Region')
    await clickButton('District Assignment')
    expect(mount.textContent).toContain('2 districts are pending Assistant Commissioner approval.')
    expect(mount.textContent).toContain('Pending AC approval · Central Region')
    expect(Array.from(mount.querySelectorAll('button')).some((button) => button.textContent.includes('Assign districts'))).toBe(false)
  })

  it('does not offer district creation to an Enforcement Supervisor', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: {
          can_manage: false,
          can_recommend: true,
          can_review: false,
          teams: [],
          districts: [],
          officers: [],
          supervisors: [],
          recommendations: [],
        },
      },
    })

    await act(async () => root.render(<OrganizationTab />))
    await act(async () => new Promise((resolve) => setTimeout(resolve, 0)))

    await clickButton('District Assignment')
    expect(mount.textContent).toContain('No Tax Districts yet')
    expect(mount.querySelector('button[aria-label="Close dialog"]')).toBeNull()
    expect(mount.textContent).not.toContain('Add Tax District')
    await clickButton('Enforcement Teams')
    expect(mount.textContent).toContain('No approved teams yet')
    expect(mount.textContent).not.toContain('Create Enforcement Team')
  })
})
