export function useLogout() {
  const open = useState('logout-confirmation', () => false)
  const busy = useState('logout-busy', () => false)
  const error = useState('logout-error', () => '')
  const login = useLogin().state
  function requestLogout() { error.value = ''; open.value = true }
  async function confirmLogout() {
    if (busy.value) return
    busy.value = true
    error.value = ''
    try {
      const result = await $fetch<{ url: string }>('/gateway/login/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': login.value.csrf } })
      window.location.assign(result.url)
    } catch { error.value = 'خروج انجام نشد. دوباره تلاش کنید.'; busy.value = false }
  }
  return { open, busy, error, requestLogout, confirmLogout }
}
