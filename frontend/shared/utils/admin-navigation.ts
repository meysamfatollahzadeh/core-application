import type { AccessState } from '../types/access'

export const adminSections = [
  { key: 'companies', group: 'organization', title: 'شرکت‌ها', description: 'تعریف و نگهداری شرکت‌های سازمان', permission: 'companies.view', scoped: false },
  { key: 'positions', group: 'organization', title: 'چارت و پوزیشن‌ها', description: 'ساختار مستقل سازمانی در هر شرکت', permission: 'positions.view', scoped: true },
  { key: 'buildings', group: 'organization', title: 'ساختمان‌ها و ناحیه‌ها', description: 'محل استقرار مشترک پرسنل سازمان', permission: 'buildings.view', scoped: false },
  { key: 'personnel', group: 'personnel', title: 'فهرست پرسنل', description: 'اطلاعات مشترک افراد و محل استقرار فعلی', permission: 'personnel.view', scoped: true },
  { key: 'assignments', group: 'personnel', title: 'سمت‌ها و گزارش‌دهی', description: 'انتساب افراد به سمت‌ها و انتخاب پوزیشن‌های مدیر', permission: 'user_positions.view', scoped: true },
  { key: 'roles', group: 'access', title: 'نقش‌ها و مجوزها', description: 'تعریف مشترک نقش‌ها و مجوزهای عملیاتی', permission: 'roles.view', scoped: false },
  { key: 'grants', group: 'access', title: 'تخصیص دسترسی', description: 'نقش‌های مستقیم کاربران و نقش‌های پوزیشن‌ها', permission: 'access.assign', scoped: true },
] as const

export const adminGroups = [
  { key: 'organization', title: 'ساختار سازمان' },
  { key: 'personnel', title: 'پرسنل' },
  { key: 'access', title: 'دسترسی‌ها' },
] as const

export function hasPermission(state: AccessState, key: string, company?: number | null): boolean {
  return state.status === 'ready' && (state.global.includes(key) || (company != null && (state.companies[String(company)] ?? []).includes(key)))
}

export function canOpenAdminSection(state: AccessState, key: string, company?: number): boolean {
  const section = adminSections.find(item => item.key === key)
  if (!section || state.status !== 'ready') return false
  if (company !== undefined) {
    return section.scoped && state.visible_companies.some(item => item.id === company) && hasPermission(state, section.permission, company)
  }
  return hasPermission(state, section.permission) || (section.scoped && state.visible_companies.some(item => hasPermission(state, section.permission, item.id)))
}

export function buildAdminNavigation(state: AccessState) {
  const available = adminSections.filter(section => canOpenAdminSection(state, section.key))
  return adminGroups.map(group => ({ ...group, children: available.filter(section => section.group === group.key) }))
    .filter(group => group.children.length > 0)
}
