import test from 'node:test'
import assert from 'node:assert/strict'
import { normalizeTheme, resolveTheme } from '../shared/utils/appearance.ts'

test('default and malformed preferences normalize to auto, explicit modes remain intact', () => {
  for (const value of [undefined, null, {}, 'system', 'invalid']) assert.equal(normalizeTheme(value), 'auto')
  assert.equal(normalizeTheme('dark'), 'dark')
  assert.equal(normalizeTheme('light'), 'light')
})
test('explicit dark wins over a light or unknown device hint before rendering', () => {
  assert.equal(resolveTheme('dark', 'light'), 'dark')
  assert.equal(resolveTheme('dark', undefined), 'dark')
  assert.equal(resolveTheme('light', 'dark'), 'light')
})
test('auto uses validated device hints and a deterministic fallback', () => {
  assert.equal(resolveTheme('auto', 'dark'), 'dark')
  assert.equal(resolveTheme('auto', 'light'), 'light')
  assert.equal(resolveTheme('auto', undefined), 'light')
  assert.equal(resolveTheme('auto', 'malformed'), 'light')
})
