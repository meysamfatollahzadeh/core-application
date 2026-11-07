import { createError, getQuery, readBody, setResponseStatus } from 'h3'
import { authCookie, clearCookie, noStore, readProtectedCookie, requireMutation } from '../../../utils/login'

const endpoints: [RegExp, string[]][] = [
  [/^access\/(me|role-options)$/, ['GET']],
  [/^organization\/(reporting-position-options|location-options|company-position-options)$/, ['GET']],
  [/^personnel\/options$/, ['GET']],
  [/^(companies|buildings|roles)$/, ['GET', 'POST']],
  [/^(companies|buildings|roles)\/[1-9]\d*$/, ['GET', 'PATCH', 'DELETE']],
  [/^personnel$/, ['GET']],
  [/^personnel\/[1-9]\d*$/, ['GET', 'PATCH']],
  [/^personnel\/[1-9]\d*\/positions$/, ['GET']],
  [/^permissions$/, ['GET']],
  [/^roles\/[1-9]\d*\/permissions$/, ['PUT']],
  [/^personnel\/[1-9]\d*\/global-roles$/, ['GET', 'PUT']],
  [/^companies\/[1-9]\d*\/users\/[1-9]\d*\/roles$/, ['GET', 'PUT']],
  [/^companies\/[1-9]\d*\/positions\/[1-9]\d*\/roles$/, ['GET', 'PUT']],
  [/^companies\/[1-9]\d*\/personnel$/, ['GET']],
  [/^companies\/[1-9]\d*\/(positions|user-positions)$/, ['GET', 'POST']],
  [/^companies\/[1-9]\d*\/positions\/[1-9]\d*$/, ['GET', 'PATCH', 'DELETE']],
  [/^companies\/[1-9]\d*\/user-positions\/[1-9]\d*$/, ['GET', 'PUT', 'DELETE']],
  [/^buildings\/[1-9]\d*\/areas$/, ['GET', 'POST']],
  [/^buildings\/[1-9]\d*\/areas\/[1-9]\d*$/, ['GET', 'PATCH', 'DELETE']],
]

export default defineEventHandler(async event => {
  noStore(event)
  const path = event.context.params?.path ?? ''
  if (!endpoints.some(([pattern, methods]) => pattern.test(path) && methods.includes(event.method))) {
    throw createError({ statusCode: 404, message: 'Unknown management route' })
  }
  const credential = readProtectedCookie<{ token: string }>(event, authCookie)
  if (!credential) throw createError({ statusCode: 401, message: 'Authentication required' })
  if (event.method !== 'GET') requireMutation(event)
  const incoming = getQuery(event)
  const query: Record<string, string> = {}
  for (const key of ['page', 'per_page', 'search', 'company_id', 'selected']) {
    if (typeof incoming[key] === 'string') query[key] = incoming[key]
  }
  const origin = new URL(useRuntimeConfig(event).appOrigin)
  try {
    return await $fetch(useRuntimeConfig(event).backendUrl + '/api/' + path, {
      method: event.method as 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE',
      query, body: event.method !== 'GET' ? await readBody(event) : undefined,
      headers: { Accept: 'application/json', Authorization: `Bearer ${credential.token}`, 'X-Forwarded-Host': origin.host, 'X-Forwarded-Proto': origin.protocol.slice(0, -1), 'X-Forwarded-Port': origin.port || (origin.protocol === 'https:' ? '443' : '80') },
      onResponse({ response }) { setResponseStatus(event, response.status) },
      retry: 0, timeout: 170000, redirect: 'error',
    })
  } catch (error: unknown) {
    const failure = error as { status?: number; data?: { message?: string; errors?: Record<string, string[]>; assignment_ids?: number[] } }
    const status = failure.status && [401, 403, 404, 409, 422, 429, 503].includes(failure.status) ? failure.status : 503
    if (status === 401) clearCookie(event, authCookie)
    throw createError({ statusCode: status, message: 'Management request failed', data: { errors: status === 422 ? failure.data?.errors : undefined, assignment_ids: failure.data?.assignment_ids } })
  }
})
