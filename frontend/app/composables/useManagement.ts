export type DirectoryItem = { id: number; title?: string; name?: string; has_active_member?: boolean; [key: string]: unknown }
export type DirectoryPage = { data: DirectoryItem[]; meta?: { total: number; last_page: number }; total?: number; last_page?: number }
export function managementError(error: unknown): string {
  const payload = (error as { data?: { reason?: string; data?: { reason?: string } } }).data
  const reason = payload?.data?.reason ?? payload?.reason
  const reasons: Record<string, string> = {
    source_inactive: 'این منبع غیرفعال است؛ منبع فعال دیگری انتخاب کنید.',
    source_changed: 'تنظیمات منبع تغییر کرده است. دوباره جست‌وجو کنید.',
    user_source_occupied: 'این فرد از این منبع حساب متصل دارد. ابتدا اتصال قبلی را حذف کنید.',
    identity_already_connected: 'این حساب قبلاً به پرسنل دیگری متصل شده است. نتایج را دوباره دریافت کنید.',
    membership_conflict: 'اتصال حساب هم‌زمان تغییر کرده است. اطلاعات را دوباره دریافت کنید.',
    account_ineligible: 'این حساب حذف یا غیرفعال شده، یا حساب سیستمی است و قابل اتصال نیست.',
    provider_directory_unconfigured: 'دسترسی جست‌وجوی حساب‌ها برای این منبع تنظیم نشده است.',
    provider_directory_unavailable: 'جست‌وجوی حساب‌ها در این منبع در دسترس نیست. دوباره تلاش کنید.',
  }
  if (reason && reasons[reason]) return reasons[reason]
  const status = (error as { statusCode?: number; status?: number }).statusCode ?? (error as { status?: number }).status
  return ({ 401: 'ورود شما معتبر نیست. دوباره وارد شوید.', 403: 'مجوز انجام این عملیات را ندارید.', 404: 'رکورد در این محدوده پیدا نشد.', 409: 'این تغییر با اطلاعات وابسته سازگار نیست. سمت‌ها، مدیران، محل استقرار یا نقش‌های وابسته را بررسی کنید.', 422: 'اطلاعات معتبر نیست. فیلدهای الزامی و انتخاب‌های تکراری را بررسی کنید.', 429: 'تعداد درخواست‌ها زیاد است. کمی بعد دوباره تلاش کنید.' } as Record<number, string>)[status ?? 0] ?? 'ارتباط با سامانه برقرار نشد. دوباره تلاش کنید.'
}
export function useManagement() {
  const fetcher = useRequestFetch()
  const login = useLogin()
  async function get<T>(path: string, query: Record<string, string | number> = {}): Promise<T> {
    return await fetcher<T>('/gateway/manage/' + path, { query }) as T
  }
  async function mutate<T>(path: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', body?: Record<string, unknown>): Promise<T> {
    const result = await $fetch<T>('/gateway/manage/' + path, { method, body, headers: { 'X-CSRF-TOKEN': login.state.value.csrf } }) as T
    await useAccess().refresh()
    return result
  }
  return { get, mutate }
}
