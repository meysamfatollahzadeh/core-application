<script setup lang="ts">
import { useTheme } from 'vuetify'
import { mdiDomain, mdiWeatherSunny, mdiWeatherNight, mdiMonitor } from '@mdi/js'

type ThemeMode = 'light' | 'dark' | 'system'
const theme = useTheme()
const mode = ref<ThemeMode>('system')
const { state: login, refresh } = useLogin()
const route = useRoute()
watch(() => login.value.status, status => { if (status === 'anonymous' && route.path.startsWith('/admin')) navigateTo('/') })
const themes = [
  { title: 'خودکار', value: 'system', icon: mdiMonitor },
  { title: 'روشن', value: 'light', icon: mdiWeatherSunny },
  { title: 'تاریک', value: 'dark', icon: mdiWeatherNight },
]
let media: MediaQueryList | undefined
let recheck: ReturnType<typeof setInterval> | undefined
function recheckVisible() { if (document.visibilityState === 'visible') refresh() }
function applyTheme() {
  theme.change(mode.value === 'system' ? (media?.matches ? 'dark' : 'light') : mode.value)
}
watch(mode, () => {
  localStorage.setItem('organization-theme', mode.value)
  applyTheme()
})
onMounted(() => {
  media = window.matchMedia('(prefers-color-scheme: dark)')
  const stored = localStorage.getItem('organization-theme')
  if (stored === 'dark' || stored === 'light' || stored === 'system') mode.value = stored
  applyTheme()
  media.addEventListener('change', applyTheme)
  window.addEventListener('focus', recheckVisible)
  document.addEventListener('visibilitychange', recheckVisible)
  recheck = setInterval(recheckVisible, 15000)
})
onBeforeUnmount(() => {
  media?.removeEventListener('change', applyTheme)
  window.removeEventListener('focus', recheckVisible)
  document.removeEventListener('visibilitychange', recheckVisible)
  if (recheck) clearInterval(recheck)
})
</script>

<template>
  <v-app>
    <v-app-bar flat border="b" class="organization-header">
      <v-container class="header-content">
        <div class="brand"><v-icon :icon="mdiDomain" color="primary" size="30" /><NuxtLink to="/" class="brand-link">سامانه یکپارچه سازمان</NuxtLink></div>
        <v-menu>
          <template #activator="{ props }"><v-btn v-bind="props" variant="text" :prepend-icon="themes.find(t => t.value === mode)?.icon" aria-label="انتخاب ظاهر سامانه">ظاهر سامانه</v-btn></template>
          <v-list aria-label="حالت نمایش"><v-list-item v-for="item in themes" :key="item.value" :title="item.title" :prepend-icon="item.icon" :active="mode === item.value" @click="mode = item.value as ThemeMode" /></v-list>
        </v-menu>
      </v-container>
    </v-app-bar>
    <v-main>
      <v-container class="workspace">
        <v-alert v-if="route.path.startsWith('/admin') && login.status === 'unavailable'" type="warning" variant="tonal" role="alert">بررسی وضعیت حساب ممکن نشد. ارتباط با سرویس هویت را بررسی کنید.<v-btn variant="text" @click="refresh">بررسی دوباره</v-btn></v-alert>
        <NuxtPage v-if="!route.path.startsWith('/admin') || login.status === 'authenticated'" />
        <footer class="workspace-footer">سامانه یکپارچه سازمان</footer>
      </v-container>
    </v-main>
  </v-app>
</template>
