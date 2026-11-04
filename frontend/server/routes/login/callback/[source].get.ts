import { authCookie, clearCookie, loginApi, noStore, pendingCookie, readProtectedCookie, sourceSlug, writeProtectedCookie } from '../../../utils/login'
export default defineEventHandler(async event => {
  noStore(event)
  const pending = readProtectedCookie<{ slug: string; binding: string }>(event, pendingCookie)
  clearCookie(event, pendingCookie)
  const slug = sourceSlug(event)
  const query = getQuery(event)
  if (!pending || pending.slug !== slug || typeof query.code !== 'string' || typeof query.state !== 'string') return sendRedirect(event, '/?login_error=invalid_transaction', 303)
  try {
    const result = await loginApi<{ token: string; expires_at: string }>(event, `/sources/${slug}/callback`, { method: 'POST', body: { state: query.state, code: query.code, browser_binding: pending.binding } })
    const ttl = Math.min(86400, Math.floor((Date.parse(result.expires_at) - Date.now()) / 1000))
    if (ttl <= 0) throw new Error('expired')
    writeProtectedCookie(event, authCookie, { token: result.token }, ttl)
    return sendRedirect(event, '/', 303)
  } catch (error: unknown) {
    const denied = (error as { statusCode?: number }).statusCode === 403
    return sendRedirect(event, '/?login_error=' + (denied ? 'member_not_imported' : 'failed'), 303)
  }
})
