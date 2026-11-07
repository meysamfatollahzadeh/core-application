import { mdiDomain, mdiAccountGroup, mdiSitemap, mdiOfficeBuilding, mdiAccountTie, mdiShieldAccount, mdiKeyChain } from '@mdi/js'
export function useAdminSections() {
  const { can, state } = useAccess()
  const sections = [
    { key: 'companies', title: 'شرکت‌ها', description: 'تعریف و نگهداری شرکت‌های سازمان', permission: 'companies.view', scoped: false, icon: mdiDomain },
    { key: 'positions', title: 'چارت و پوزیشن‌ها', description: 'ساختار مستقل سازمانی در هر شرکت', permission: 'positions.view', scoped: true, icon: mdiSitemap },
    { key: 'personnel', title: 'پرسنل', description: 'اطلاعات مشترک افراد و محل استقرار فعلی', permission: 'personnel.view', scoped: true, icon: mdiAccountGroup },
    { key: 'assignments', title: 'سمت‌ها و گزارش‌دهی', description: 'انتساب افراد به سمت‌ها و انتخاب پوزیشن‌های مدیر', permission: 'user_positions.view', scoped: true, icon: mdiAccountTie },
    { key: 'buildings', title: 'ساختمان‌ها و ناحیه‌ها', description: 'محل استقرار مشترک پرسنل سازمان', permission: 'buildings.view', scoped: false, icon: mdiOfficeBuilding },
    { key: 'roles', title: 'نقش‌ها و مجوزها', description: 'تعریف مشترک نقش‌ها و مجوزهای عملیاتی', permission: 'roles.view', scoped: false, icon: mdiShieldAccount },
    { key: 'grants', title: 'تخصیص دسترسی', description: 'نقش‌های مستقیم کاربران و نقش‌های پوزیشن‌ها', permission: 'access.assign', scoped: true, icon: mdiKeyChain },
  ]
  const available = computed(() => sections.filter(section => can(section.permission) || (section.scoped && state.value.visible_companies.some(company => can(section.permission, company.id)))))
  return { sections, available }
}
