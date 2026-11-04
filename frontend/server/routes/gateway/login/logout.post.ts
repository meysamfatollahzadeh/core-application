import { authCookie, clearCookie, loginApi, logoutCookie, randomProof, readProtectedCookie, requireMutation, writeProtectedCookie } from '../../../utils/login'
export default defineEventHandler(async event => {
  requireMutation(event)
  const credential = readProtectedCookie<{ token: string }>(event, authCookie)
  if (!credential) return { url: '/' }
  const binding = randomProof()
  try {
    const result = await loginApi<{ handoff: string | null; state?: string }>(event, '/logout', { method: 'POST', token: credential.token, body: { browser_binding: binding } })
    clearCookie(event, authCookie)
    if (!result.handoff) return { url: '/?logout_error=remote_unavailable' }
    writeProtectedCookie(event, logoutCookie, { handoff: result.handoff, binding, state: result.state }, 120)
    return { url: '/login/logout' }
  } catch (error: unknown) {
    if ((error as { statusCode?: number }).statusCode === 401) { clearCookie(event, authCookie); return { url: '/' } }
    throw error
  }
})
