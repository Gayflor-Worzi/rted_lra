// @vitest-environment jsdom

import React, { act } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import DialogMaximize from './DialogMaximize'

let root
let mount
let dialog
let panel
let input
let submitCount

function rect(left, top, width, height) {
  return { left, top, width, height, right: left + width, bottom: top + height, x: left, y: top, toJSON() {} }
}

function addDialog(id, zIndex = 50) {
  const overlay = document.createElement('div')
  overlay.className = 'fixed'
  overlay.dataset.testDialog = id
  Object.assign(overlay.style, { position: 'fixed', inset: '0', zIndex: String(zIndex) })
  overlay.getBoundingClientRect = () => rect(0, 0, document.documentElement.clientWidth, document.documentElement.clientHeight)

  const form = document.createElement('form')
  form.dataset.testPanel = id
  form.style.backgroundColor = 'white'
  form.getBoundingClientRect = () => form.hasAttribute('data-retd-dialog-maximized')
    ? rect(16, 16, document.documentElement.clientWidth - 32, document.documentElement.clientHeight - 32)
    : rect(240, 150, 600, 420)

  const field = document.createElement('input')
  field.value = 'unsaved value'
  const validation = document.createElement('p')
  validation.textContent = 'Validation message'
  const longContent = document.createElement('div')
  longContent.style.height = '1200px'
  longContent.textContent = 'Long form content'
  const submit = document.createElement('button')
  submit.type = 'submit'
  submit.textContent = 'Save'

  form.append(field, validation, longContent, submit)
  form.addEventListener('submit', (event) => {
    event.preventDefault()
    submitCount += 1
  })
  overlay.append(form)
  document.body.append(overlay)
  return { overlay, form }
}

async function settle() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 10))
  })
}

function clickControl(label) {
  const control = document.querySelector(`[aria-label="${label}"]`)
  expect(control).not.toBeNull()
  act(() => control.dispatchEvent(new MouseEvent('click', { bubbles: true })))
}

beforeEach(async () => {
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  Object.defineProperty(document.documentElement, 'clientWidth', { configurable: true, value: 1280 })
  Object.defineProperty(document.documentElement, 'clientHeight', { configurable: true, value: 800 })
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1280 })
  Object.defineProperty(window, 'innerHeight', { configurable: true, value: 800 })
  window.requestAnimationFrame = (callback) => setTimeout(() => callback(Date.now()), 0)
  window.cancelAnimationFrame = clearTimeout
  submitCount = 0
  dialog = addDialog('primary')
  panel = dialog.form
  input = panel.querySelector('input')
  mount = document.createElement('div')
  document.body.append(mount)
  root = createRoot(mount)
  await act(async () => root.render(<DialogMaximize />))
  await settle()
})

afterEach(async () => {
  await act(async () => root?.unmount())
  document.body.replaceChildren()
})

describe('DialogMaximize', () => {
  it('maximizes and restores while preserving form state, scroll, and action isolation', async () => {
    expect(document.querySelector('[aria-label="Maximize dialog"]')).not.toBeNull()
    input.value = 'edited unsaved value'
    panel.scrollTop = 180

    clickControl('Maximize dialog')
    await settle()

    expect(panel.getAttribute('data-retd-dialog-maximized')).toBe('1')
    expect(document.querySelector('[aria-label="Restore dialog size"]')).not.toBeNull()
    expect(panel.getBoundingClientRect()).toMatchObject({
      left: 16,
      top: 16,
      width: 1248,
      height: 768,
    })
    expect(getComputedStyle(panel).overflow).toBe('auto')
    expect(input.value).toBe('edited unsaved value')
    expect(panel.querySelector('p').textContent).toBe('Validation message')
    expect(panel.scrollTop).toBe(180)
    expect(submitCount).toBe(0)

    clickControl('Restore dialog size')
    await settle()

    expect(panel.hasAttribute('data-retd-dialog-maximized')).toBe(false)
    expect(document.querySelector('[aria-label="Maximize dialog"]')).not.toBeNull()
    expect(panel.getBoundingClientRect()).toMatchObject({ left: 240, top: 150, width: 600, height: 420 })
    expect(input.value).toBe('edited unsaved value')
    expect(panel.scrollTop).toBe(180)
    expect(submitCount).toBe(0)
  })

  it('keeps maximize state independent for nested dialogs', async () => {
    clickControl('Maximize dialog')
    await settle()
    const nested = addDialog('nested', 60)
    await settle()

    clickControl('Maximize dialog')
    await settle()
    expect(panel.getAttribute('data-retd-dialog-maximized')).toBe('1')
    expect(nested.form.getAttribute('data-retd-dialog-maximized')).toBe('1')

    nested.overlay.remove()
    await settle()
    expect(document.querySelector('[aria-label="Restore dialog size"]')).not.toBeNull()
    clickControl('Restore dialog size')
    await settle()
    expect(panel.hasAttribute('data-retd-dialog-maximized')).toBe(false)
    expect(submitCount).toBe(0)
  })

  it('keeps the expanded panel inside a narrow viewport', async () => {
    Object.defineProperty(document.documentElement, 'clientWidth', { configurable: true, value: 390 })
    Object.defineProperty(document.documentElement, 'clientHeight', { configurable: true, value: 844 })
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 390 })
    Object.defineProperty(window, 'innerHeight', { configurable: true, value: 844 })
    act(() => window.dispatchEvent(new Event('resize')))
    await settle()

    clickControl('Maximize dialog')
    await settle()

    expect(panel.getBoundingClientRect()).toMatchObject({
      left: 16,
      top: 16,
      width: 358,
      height: 812,
    })
    expect(submitCount).toBe(0)
  })
})
