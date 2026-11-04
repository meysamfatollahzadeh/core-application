import { clearCookie, logoutCookie, noStore, readProtectedCookie, safeEqual } from '../../utils/login'
export default defineEventHandler(event => {
  noStore(event)
  const pending = readProtectedCookie<{ state: string }>(event, logoutCookie)
  clearCookie(event, logoutCookie)
  const state = getQuery(event).state
  if (!pending || typeof state !== 'string' || !safeEqual(pending.state, state)) return sendRedirect(event, '/?logout_error=unverified_return', 303)
  return sendRedirect(event, '/', 303)
})
