export default defineNuxtRouteMiddleware(async to => {
  const login = useLogin()
  const access = useAccess()
  if (import.meta.client) { await login.refresh() }
  if (login.state.value.status === 'unavailable' || access.state.value.status === 'unavailable') throw createError({ statusCode: 503, message: 'بررسی وضعیت حساب و دسترسی ممکن نشد.' })
  if (login.state.value.status !== 'authenticated') return navigateTo('/')
  const { available, sections } = useAdminSections()
  const key = String(to.params.section ?? '')
  if (!key) return
  const section = sections.find(item => item.key === key)
  if (!section) throw createError({ statusCode: 404, message: 'صفحه پیدا نشد.' })
  if (!available.value.some(item => item.key === key)) throw createError({ statusCode: 403, message: 'مجوز مشاهده این بخش را ندارید.' })
  if (to.query.company && (!/^\d+$/.test(String(to.query.company)) || !section.scoped || !access.state.value.visible_companies.some(company => company.id === Number(to.query.company)) || !access.can(section.permission, Number(to.query.company)))) {
    throw createError({ statusCode: 403, message: 'دسترسی به این شرکت مجاز نیست.' })
  }
})
