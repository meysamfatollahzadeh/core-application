export type DirectoryItem = { id: number; title?: string; name?: string; [key: string]: unknown }
export type DirectoryPage = { data: DirectoryItem[]; meta?: { total: number; last_page: number }; total?: number; last_page?: number }
export function managementError(error: unknown): string {
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
