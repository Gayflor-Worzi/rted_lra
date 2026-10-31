import assert from 'node:assert/strict'
import test from 'node:test'
import { performanceProgress } from './performanceProgress.mjs'

test('missing or invalid progress stays unavailable instead of becoming zero', () => {
  assert.deepEqual(performanceProgress(null), { label: 'N/A', width: null })
  assert.deepEqual(performanceProgress(undefined), { label: 'N/A', width: null })
  assert.deepEqual(performanceProgress(''), { label: 'N/A', width: null })
  assert.deepEqual(performanceProgress('not-a-number'), { label: 'N/A', width: null })
})

test('valid progress preserves its percentage while clamping only the visual bar', () => {
  assert.deepEqual(performanceProgress(0), { label: '0%', width: 0 })
  assert.deepEqual(performanceProgress(27.46), { label: '27.5%', width: 27.46 })
  assert.deepEqual(performanceProgress(125), { label: '125%', width: 100 })
})
