import { createCipheriv, createDecipheriv, randomBytes, timingSafeEqual } from 'node:crypto'
import { createError, deleteCookie, getCookie, getHeader, setCookie, setHeader, type H3Event } from 'h3'
import type { LoginIdentity, LoginState } from '../../shared/types/login'

export const authCookie = 'organization_credential'
export const pendingCookie = 'organization_login_pending'
export const logoutCookie = 'organization_logout_pending'
const csrfCookie = 'organization_csrf'

type Envelope<T> = { expires: number; value: T }

export function randomProof() { return randomBytes(48).toString('base64url') }
export function noStore(event: H3Event) {
  setHeader(event, 'Cache-Control', 'no-store, private')
  setHeader(event, 'Referrer-Policy', 'no-referrer')
  setHeader(event, 'X-Content-Type-Options', 'nosniff')
}
function cookieOptions(event: H3Event) {
  return { httpOnly: true, sameSite: 'lax' as const, secure: useRuntimeConfig(event).appOrigin.startsWith('https:'), path: '/' }
}
function cookieKey(event: H3Event) {
  const key = Buffer.from(useRuntimeConfig(event).authCookieKey, 'base64')
  if (key.length !== 32) throw createError({ statusCode: 503, message: 'Authentication configuration unavailable' })
  return key
}
export function clearCookie(event: H3Event, name: string) { deleteCookie(event, name, cookieOptions(event)) }
export function writeProtectedCookie<T>(event: H3Event, name: string, value: T, ttl: number) {
  const nonce = randomBytes(12)
  const cipher = createCipheriv('aes-256-gcm', cookieKey(event), nonce)
  cipher.setAAD(Buffer.from(name))
  const envelope: Envelope<T> = { expires: Date.now() + ttl * 1000, value }
  const encrypted = Buffer.concat([cipher.update(JSON.stringify(envelope), 'utf8'), cipher.final()])
  const cookie = Buffer.concat([nonce, cipher.getAuthTag(), encrypted]).toString('base64url')
  if (cookie.length > 3900) throw createError({ statusCode: 503, message: 'Authentication transport unavailable' })
  setCookie(event, name, cookie, { ...cookieOptions(event), maxAge: ttl })
}
export function readProtectedCookie<T>(event: H3Event, name: string): T | null {
  const raw = getCookie(event, name)
  if (!raw) return null
  try {
    const bytes = Buffer.from(raw, 'base64url')
    const decipher = createDecipheriv('aes-256-gcm', cookieKey(event), bytes.subarray(0, 12))
    decipher.setAuthTag(bytes.subarray(12, 28))
    decipher.setAAD(Buffer.from(name))
    const envelope = JSON.parse(Buffer.concat([decipher.update(bytes.subarray(28)), decipher.final()]).toString('utf8')) as Envelope<T>
    if (envelope.expires <= Date.now()) throw new Error('expired')
    return envelope.value
  } catch { clearCookie(event, name); return null }
}
export function csrfProof(event: H3Event): string {
  let proof = readProtectedCookie<string>(event, csrfCookie)
  if (!proof) { proof = randomProof(); writeProtectedCookie(event, csrfCookie, proof, 86400) }
  return proof
}
export function requireMutation(event: H3Event) {
  noStore(event)
  const origin = getHeader(event, 'origin')
  const proof = readProtectedCookie<string>(event, csrfCookie)
  const supplied = getHeader(event, 'x-csrf-token')
  if (origin !== useRuntimeConfig(event).appOrigin || !proof || !supplied || !safeEqual(proof, supplied)) {
    throw createError({ statusCode: 403, message: 'Invalid request origin or CSRF proof' })
  }
}
export function safeEqual(a: string, b: string) {
  const left = Buffer.from(a); const right = Buffer.from(b)
  return left.length === right.length && timingSafeEqual(left, right)
}
export function sourceSlug(event: H3Event): string {
  const slug = event.context.params?.source
  if (!slug || slug.length > 63 || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) throw createError({ statusCode: 404, message: 'Unknown source' })
  return slug
}
export async function loginApi<T>(event: H3Event, path: string, options: { method?: 'GET' | 'POST' | 'PATCH'; body?: Record<string, unknown>; token?: string } = {}): Promise<T> {
  try {
    return await $fetch<T>(useRuntimeConfig(event).backendUrl + '/api/login' + path, {
      method: options.method ?? 'GET', body: options.body,
      headers: { Accept: 'application/json', ...(options.token ? { Authorization: `Bearer ${options.token}` } : {}) },
      retry: 0, timeout: 170000, redirect: 'error',
    }) as T
  } catch (error: unknown) {
    const failure = error as { status?: number; data?: { error?: string } }
    const status = failure.status && [401, 403, 404, 422, 429, 503].includes(failure.status) ? failure.status : 503
    throw createError({ statusCode: status, message: 'Authentication request failed', data: { error: typeof failure.data?.error === 'string' ? failure.data.error : 'authentication_unavailable' } })
  }
}
export async function resolveLogin(event: H3Event): Promise<LoginState> {
  noStore(event)
  clearCookie(event, 'organization_session')
  const csrf = csrfProof(event)
  const credential = readProtectedCookie<{ token: string }>(event, authCookie)
  if (!credential) return { status: 'anonymous', identity: null, csrf, canLogout: false }
  try {
    const result = await loginApi<{ data: LoginIdentity }>(event, '/me', { token: credential.token })
    return { status: 'authenticated', identity: result.data, csrf, canLogout: true }
  } catch (error: unknown) {
    if ((error as { statusCode?: number }).statusCode === 401) {
      clearCookie(event, authCookie)
      return { status: 'anonymous', identity: null, csrf, canLogout: false }
    }
    return { status: 'unavailable', identity: null, csrf, canLogout: true }
  }
}
