import { loginApi, pendingCookie, randomProof, requireMutation, sourceSlug, writeProtectedCookie } from '../../../../../utils/login'
export default defineEventHandler(async event => {
  requireMutation(event)
  const slug = sourceSlug(event)
  const binding = randomProof()
  const result = await loginApi<{ authorization_url: string }>(event, `/sources/${slug}/authorize`, { method: 'POST', body: { browser_binding: binding } })
  if (new URL(result.authorization_url).origin !== useRuntimeConfig(event).appOrigin) throw createError({ statusCode: 503, message: 'Provider origin unavailable' })
  writeProtectedCookie(event, pendingCookie, { slug, binding }, 300)
  return { url: result.authorization_url }
})
