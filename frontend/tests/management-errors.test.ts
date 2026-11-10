import test from 'node:test'
import assert from 'node:assert/strict'
import { managementError } from '../app/composables/useManagement.ts'

test('gateway conflict reasons produce actionable account messages', () => {
  const occupied = managementError({ statusCode: 409, data: { data: { reason: 'user_source_occupied' } } })
  assert.match(occupied, /ابتدا اتصال قبلی را حذف/)
  const taken = managementError({ statusCode: 409, data: { reason: 'identity_already_connected' } })
  assert.match(taken, /پرسنل دیگری/)
})
test('provider errors and ineligible accounts preserve their specific meaning', () => {
  assert.match(managementError({ statusCode: 503, data: { data: { reason: 'provider_directory_unconfigured' } } }), /تنظیم نشده/)
  assert.match(managementError({ statusCode: 422, data: { data: { reason: 'account_ineligible' } } }), /قابل اتصال نیست/)
})
test('unknown server error text is not rendered and existing status messages remain available', () => {
  assert.doesNotMatch(managementError({ statusCode: 503, data: { reason: '<secret>' } }), /secret/)
  assert.match(managementError({ statusCode: 403 }), /مجوز/)
})
