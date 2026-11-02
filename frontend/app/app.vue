<script setup lang="ts">
import { useTheme } from 'vuetify'
import { mdiDomain, mdiLogin, mdiLogout, mdiWeatherSunny, mdiWeatherNight, mdiMonitor } from '@mdi/js'

type ThemeMode = 'light' | 'dark' | 'system'
type Session = { user: { name: string; username: string } | null; csrfToken: string }
const theme = useTheme()
const mode = ref<ThemeMode>('system')
const session = ref<Session | null>(null)
const message = ref('')
const busy = ref(false)
const themes = [
  { title: 'خودکار', value: 'system', icon: mdiMonitor },
  { title: 'روشن', value: 'light', icon: mdiWeatherSunny },
  { title: 'تاریک', value: 'dark', icon: mdiWeatherNight },
]
let media: MediaQueryList | undefined
function applyTheme() {
  theme.change(mode.value === 'system' ? (media?.matches ? 'dark' : 'light') : mode.value)
}
watch(mode, () => {
  localStorage.setItem('organization-theme', mode.value)
  applyTheme()
})
onMounted(async () => {
  media = window.matchMedia('(prefers-color-scheme: dark)')
  const stored = localStorage.getItem('organization-theme')
  if (stored === 'dark' || stored === 'light' || stored === 'system') mode.value = stored
  applyTheme()
  media.addEventListener('change', applyTheme)
  try { session.value = await $fetch<Session>('/api/auth/session') }
  catch { message.value = 'ارتباط با سامانه برقرار نشد. صفحه را دوباره بارگذاری کنید.' }
  if (new URLSearchParams(location.search).has('auth_error')) message.value = 'ورود انجام نشد. دوباره تلاش کنید.'
})
onBeforeUnmount(() => media?.removeEventListener('change', applyTheme))
async function logout() {
  if (!session.value) return
  busy.value = true
  try {
    const result = await $fetch<{ url: string }>('/api/auth/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': session.value.csrfToken } })
    window.location.assign(result.url)
  } catch { message.value = 'خروج انجام نشد. دوباره تلاش کنید.'; busy.value = false }
}
</script>

<template>
  <v-app>
    <v-app-bar flat border="b" class="organization-header">
      <v-container class="header-content">
        <div class="brand"><v-icon :icon="mdiDomain" color="primary" size="30" /><span>سامانه یکپارچه سازمان</span></div>
        <v-menu>
          <template #activator="{ props }"><v-btn v-bind="props" variant="text" :prepend-icon="themes.find(t => t.value === mode)?.icon" aria-label="انتخاب ظاهر سامانه">ظاهر سامانه</v-btn></template>
          <v-list aria-label="حالت نمایش"><v-list-item v-for="item in themes" :key="item.value" :title="item.title" :prepend-icon="item.icon" :active="mode === item.value" @click="mode = item.value as ThemeMode" /></v-list>
        </v-menu>
      </v-container>
    </v-app-bar>
    <v-main>
      <v-container class="workspace">
        <div class="page-heading"><h1>فضای کاری سازمان</h1><p>دسترسی یکپارچه به خدمات و فرآیندهای سازمان</p></div>
        <v-alert v-if="message" type="warning" variant="tonal" class="mb-6" role="alert">{{ message }}</v-alert>
        <v-card border class="welcome-panel">
          <div class="welcome-content">
            <v-icon :icon="mdiDomain" color="primary" size="48" class="mb-5" />
            <h2>{{ session?.user ? `${session.user.name}، خوش آمدید` : 'به سامانه سازمان خوش آمدید' }}</h2>
            <p>{{ session?.user ? 'شما وارد حساب سازمانی خود شده‌اید. خدمات سازمان در مراحل بعدی به این فضا اضافه می‌شوند.' : 'برای دسترسی به فضای کاری، با حساب سازمانی خود وارد شوید.' }}</p>
            <v-btn v-if="!session?.user" href="/api/auth/login" color="primary" size="large" :prepend-icon="mdiLogin">ورود با حساب سازمانی</v-btn>
            <v-btn v-else variant="outlined" color="primary" size="large" :prepend-icon="mdiLogout" :loading="busy" @click="logout">خروج از حساب</v-btn>
          </div>
        </v-card>
        <footer class="workspace-footer">سامانه یکپارچه سازمان · نسخه اولیه</footer>
      </v-container>
    </v-main>
  </v-app>
</template>
