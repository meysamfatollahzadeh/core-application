import { useTheme } from 'vuetify'
import { mdiWeatherSunny, mdiWeatherNight, mdiMonitor } from '@mdi/js'

export function useAppearance() {
  const theme = useTheme()
  const mode = useState<'light' | 'dark' | 'system'>('appearance', () => 'system')
  const themes = [
    { title: 'خودکار', value: 'system', icon: mdiMonitor },
    { title: 'روشن', value: 'light', icon: mdiWeatherSunny },
    { title: 'تاریک', value: 'dark', icon: mdiWeatherNight },
  ] as const
  let media: MediaQueryList | undefined
  function applyTheme() { theme.change(mode.value === 'system' ? (media?.matches ? 'dark' : 'light') : mode.value) }
  watch(mode, () => { if (import.meta.client) localStorage.setItem('organization-theme', mode.value); applyTheme() })
  onMounted(() => {
    media = window.matchMedia('(prefers-color-scheme: dark)')
    const stored = localStorage.getItem('organization-theme')
    if (stored === 'dark' || stored === 'light' || stored === 'system') mode.value = stored
    applyTheme()
    media.addEventListener('change', applyTheme)
  })
  onBeforeUnmount(() => media?.removeEventListener('change', applyTheme))
  return { mode, themes }
}
