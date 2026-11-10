import assert from 'node:assert/strict'
import test from 'node:test'
import { buildAdminNavigation, canOpenAdminSection } from '../shared/utils/admin-navigation.ts'
import type { AccessState } from '../shared/types/access.ts'

const state = (global: string[] = [], companies: Record<string, string[]> = {}): AccessState => ({
  status: 'ready', global, companies,
  visible_companies: [{ id: 7, title: 'A' }, { id: 8, title: 'B' }],
})

test('empty parent groups are hidden; each authorized child keeps its own group', () => {
  assert.deepEqual(buildAdminNavigation(state()), [])
  const groups = buildAdminNavigation(state(['roles.view'], { '7': ['personnel.view'] }))
  assert.deepEqual(groups.map(group => group.key), ['personnel', 'access'])
  assert.deepEqual(groups[0]!.children.map(child => child.key), ['personnel'])
  assert.deepEqual(groups[1]!.children.map(child => child.key), ['roles'])
})

test('company-only permissions do not open global pages or another company', () => {
  const scoped = state([], { '7': ['positions.view', 'roles.view'] })
  assert.equal(canOpenAdminSection(scoped, 'positions'), true)
  assert.equal(canOpenAdminSection(scoped, 'positions', 7), true)
  assert.equal(canOpenAdminSection(scoped, 'positions', 8), false)
  assert.equal(canOpenAdminSection(scoped, 'roles'), false)
  assert.equal(canOpenAdminSection(scoped, 'roles', 7), false)
  assert.deepEqual(buildAdminNavigation(scoped).map(group => group.key), ['organization'])
})

test('global permission covers existing company pages but never unrelated permissions', () => {
  const global = state(['positions.view', 'companies.view'])
  assert.equal(canOpenAdminSection(global, 'positions', 8), true)
  assert.equal(canOpenAdminSection(global, 'positions', 999), false)
  assert.equal(canOpenAdminSection(global, 'companies'), true)
  assert.equal(canOpenAdminSection(global, 'companies', 7), false)
  assert.equal(canOpenAdminSection(global, 'roles'), false)
  assert.equal(canOpenAdminSection(global, 'unknown'), false)
})

test('unavailable or revoked access clears all menu groups', () => {
  const original = state(['roles.view', 'access.assign'])
  assert.equal(buildAdminNavigation(original).length, 1)
  assert.deepEqual(buildAdminNavigation({ ...original, status: 'unavailable' }), [])
  assert.deepEqual(buildAdminNavigation({ ...original, status: 'anonymous' }), [])
  assert.deepEqual(buildAdminNavigation({ ...original, global: [] }), [])
})


test('login sources require global view permission and stay in the access group', () => {
  assert.equal(canOpenAdminSection(state([], { '7': ['login_sources.view'] }), 'login-sources'), false)
  assert.equal(canOpenAdminSection(state(['login_sources.create']), 'login-sources'), false)
  const allowed = state(['login_sources.view'])
  assert.equal(canOpenAdminSection(allowed, 'login-sources'), true)
  assert.equal(canOpenAdminSection(allowed, 'login-sources', 7), false)
  assert.deepEqual(buildAdminNavigation(allowed).map(group => [group.key, group.children.map(child => child.key)]), [['access', ['login-sources']]])
})
