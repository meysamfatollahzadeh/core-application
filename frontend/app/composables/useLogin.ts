import type { LoginState } from '#shared/types/login'
export function useLogin() {
  const state = useState<LoginState>('login', () => ({ status: 'unavailable', identity: null, csrf: '', canLogout: false }))
  let checking = false
  async function refresh() {
    if (checking) return
    checking = true
    try { state.value = await $fetch<LoginState>('/gateway/login/me') }
    catch { state.value = { ...state.value, status: 'unavailable', identity: null } }
    finally { checking = false }
  }
  return { state, refresh }
}
