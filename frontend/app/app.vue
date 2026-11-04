<script setup lang="ts">
import { useTheme } from 'vuetify'
import { mdiDomain, mdiLogin, mdiLogout, mdiWeatherSunny, mdiWeatherNight, mdiMonitor } from '@mdi/js'

type ThemeMode = 'light' | 'dark' | 'system'
const theme = useTheme()
const mode = ref<ThemeMode>('system')
const { state: login, refresh } = useLogin()
const sourceDialog = ref(false)
const identity = computed(() => login.value.identity)
const route = useRoute()
const message = ref('')
const busy = ref(false)
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
const loginError = computed(() => route.query.login_error === 'member_not_imported'
  ? 'حساب شما هنوز در سامانه مجاز نشده است. برای ایمپورت حساب با مسئول سامانه تماس بگیرید.'
  : route.query.login_error ? 'ورود کامل نشد. دوباره حساب سازمانی خود را انتخاب کنید.' : '')
const logoutError = computed(() => route.query.logout_error ? 'دسترسی محلی بسته شد، اما پایان ورود در سرویس هویت تأیید نشد. حساب سازمانی خود را بررسی کنید.' : '')

async function logout() {
  busy.value = true
  try {
    const result = await $fetch<{ url: string }>('/gateway/login/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': login.value.csrf } })
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
        <v-alert v-if="loginError || logoutError" type="warning" variant="tonal" class="mb-6" role="alert">{{ loginError || logoutError }}</v-alert>
        <v-alert v-if="message" type="warning" variant="tonal" class="mb-6" role="alert">{{ message }}</v-alert>
        <v-card border class="welcome-panel">
          <div class="welcome-content">
            <v-icon :icon="mdiDomain" color="primary" size="48" class="mb-5" />
            <h2>{{ identity?.user ? `${identity.user.name}، خوش آمدید` : 'به سامانه سازمان خوش آمدید' }}</h2>
            <p>{{ identity?.user ? 'شما وارد حساب سازمانی خود شده‌اید. خدمات سازمان در مراحل بعدی به این فضا اضافه می‌شوند.' : 'برای دسترسی به فضای کاری، با حساب سازمانی خود وارد شوید.' }}</p>
            <v-alert v-if="login.status === 'unavailable'" type="warning" variant="tonal" class="mb-5" role="alert">بررسی وضعیت حساب ممکن نشد. ارتباط با سرویس هویت را بررسی کنید و دوباره تلاش کنید.<v-btn variant="text" @click="refresh">بررسی دوباره</v-btn></v-alert>
            <p v-if="identity" class="identity-source">حساب فعال: {{ identity.source.title }}</p>
            <v-btn v-if="login.status === 'anonymous'" @click="sourceDialog = true" color="primary" size="large" :prepend-icon="mdiLogin">ورود با حساب سازمانی</v-btn>
            <v-btn v-if="login.canLogout" variant="outlined" color="primary" size="large" :prepend-icon="mdiLogout" :loading="busy" @click="logout">خروج از حساب</v-btn>
          </div>
        </v-card>
        <footer class="workspace-footer">سامانه یکپارچه سازمان · نسخه اولیه</footer>
      </v-container>
    </v-main>
    <LoginSourceDialog v-model="sourceDialog" />
  </v-app>
</template>
