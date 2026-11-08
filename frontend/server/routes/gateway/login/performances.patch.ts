import { authCookie, clearCookie, loginApi, readProtectedCookie, requireMutation } from '../../../utils/login'
import type { Performances } from '../../../../shared/types/appearance'

export default defineEventHandler(async event => {
  requireMutation(event)
  const credential = readProtectedCookie<{ token: string }>(event, authCookie)
  if (!credential) throw createError({ statusCode: 401, message: 'Authentication required' })
  const body = await readBody(event)
  if (!body || typeof body !== 'object' || Array.isArray(body) || Object.keys(body).some(key => key !== 'theme') || !['auto', 'light', 'dark'].includes(body.theme)) {
    throw createError({ statusCode: 422, message: 'Invalid appearance preference' })
  }
  try {
    return await loginApi<{ data: { performances: Performances } }>(event, '/performances', { method: 'PATCH', token: credential.token, body: { theme: body.theme } })
  } catch (error: unknown) {
    if ((error as { statusCode?: number }).statusCode === 401) clearCookie(event, authCookie)
    throw error
  }
})
