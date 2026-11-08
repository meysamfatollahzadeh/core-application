import { useTheme } from 'vuetify'
import { mdiWeatherSunny, mdiWeatherNight, mdiMonitor } from '@mdi/js'
import type { ThemeMode, ResolvedTheme, Performances } from '#shared/types/appearance'
import { normalizeTheme, resolveTheme } from '#shared/utils/appearance'

export function useAppearance() {
  const theme = useTheme()
  const login = useLogin().state
  const preference = useState<ThemeMode>('appearance', () => 'auto')
  const resolved = useState<ResolvedTheme>('appearance-resolved', () => 'light')
  const saving = useState('appearance-saving', () => false)
  const error = useState('appearance-error', () => '')
  const preferenceCookie = useCookie<string>('organization_theme', { sameSite: 'lax', path: '/', maxAge: 31536000 })
  const deviceCookie = useCookie<string>('organization_color_scheme', { sameSite: 'lax', path: '/', maxAge: 31536000 })
  const themes = [
    { title: 'خودکار', value: 'auto', icon: mdiMonitor },
    { title: 'روشن', value: 'light', icon: mdiWeatherSunny },
    { title: 'تاریک', value: 'dark', icon: mdiWeatherNight },
  ] as const
  let media: MediaQueryList | undefined
  function apply() {
    resolved.value = resolveTheme(preference.value, media ? (media.matches ? 'dark' : 'light') : deviceCookie.value)
    if (theme.name.value !== resolved.value) theme.change(resolved.value)
    preferenceCookie.value = preference.value
  }
  function deviceChanged() {
    deviceCookie.value = media?.matches ? 'dark' : 'light'
    if (preference.value === 'auto') apply()
  }
  async function select(value: ThemeMode) {
    if (saving.value || !['auto', 'light', 'dark'].includes(value) || value === preference.value) return
    const previous = preference.value
    preference.value = value
    error.value = ''
    apply()
    if (login.value.status !== 'authenticated') return
    saving.value = true
    try {
      const result = await $fetch<{ data: { performances: Performances } }>('/gateway/login/performances', {
        method: 'PATCH', body: { theme: value }, headers: { 'X-CSRF-TOKEN': login.value.csrf },
      })
      if (login.value.identity) login.value.identity.user.performances = result.data.performances
    } catch {
      preference.value = previous
      apply()
      error.value = 'ذخیره ظاهر سامانه انجام نشد. دوباره تلاش کنید.'
    } finally { saving.value = false }
  }
  const mode = computed({ get: () => preference.value, set: value => { void select(value) } })
  watch(() => login.value.identity?.user.performances?.theme, value => {
    if (value !== undefined && !saving.value) { preference.value = normalizeTheme(value); apply() }
  })
  onMounted(() => {
    media = window.matchMedia('(prefers-color-scheme: dark)')
    deviceChanged()
    apply()
    media.addEventListener('change', deviceChanged)
  })
  onBeforeUnmount(() => media?.removeEventListener('change', deviceChanged))
  return { mode, themes, saving, error }
}
