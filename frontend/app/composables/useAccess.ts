import type { AccessState } from '#shared/types/access'
export function useAccess() {
  const state = useState<AccessState>('access', () => ({ status: 'anonymous', global: [], companies: {}, visible_companies: [] }))
  function can(key: string, company?: number | null) {
    return state.value.status === 'ready' && (state.value.global.includes(key) || (company != null && (state.value.companies[String(company)] ?? []).includes(key)))
  }
  async function refresh() {
    if (useLogin().state.value.status !== 'authenticated') {
      state.value = { status: 'anonymous', global: [], companies: {}, visible_companies: [] }; return
    }
    try {
      const result = await $fetch<{ data: Omit<AccessState, 'status'> }>('/gateway/manage/access/me')
      state.value = { ...result.data, status: 'ready' }
    } catch { state.value = { status: 'unavailable', global: [], companies: {}, visible_companies: [] } }
  }
  return { state, can, refresh }
}
