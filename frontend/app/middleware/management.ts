import { adminSections, canOpenAdminSection } from '#shared/utils/admin-navigation'

export default defineNuxtRouteMiddleware(async to => {
  const login = useLogin()
  const access = useAccess()
  if (import.meta.client) await login.refresh()
  if (login.state.value.status === 'unavailable' || access.state.value.status === 'unavailable') {
    throw createError({ statusCode: 503, message: 'بررسی وضعیت حساب و دسترسی ممکن نشد.' })
  }
  if (login.state.value.status !== 'authenticated') return navigateTo('/')
  const key = String(to.params.section ?? '')
  if (!key) return
  if (!adminSections.some(item => item.key === key)) throw createError({ statusCode: 404, message: 'صفحه پیدا نشد.' })
  const denied = () => {
    if (import.meta.server) throw createError({ statusCode: 403, message: 'مجوز دسترسی به این صفحه را ندارید.' })
    return navigateTo('/403', { replace: true })
  }
  if (!canOpenAdminSection(access.state.value, key)) return denied()
  if (to.query.company !== undefined) {
    const value = to.query.company
    if (typeof value !== 'string' || !/^[1-9]\d*$/.test(value) || !Number.isSafeInteger(Number(value)) || !canOpenAdminSection(access.state.value, key, Number(value))) return denied()
  }
})
