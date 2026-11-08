<script setup lang="ts">
import { mdiDomain, mdiMenu, mdiAccountCircleOutline, mdiChevronDown, mdiLogout } from '@mdi/js'

const { mode, themes, saving: themeSaving, error: themeError } = useAppearance()
const { busy: logoutBusy, requestLogout } = useLogout()
const { state: login, refresh } = useLogin()
const route = useRoute()
const isAdmin = computed(() => route.path === '/admin' || route.path.startsWith('/admin/'))
const identity = computed(() => login.value.identity)
const drawer = ref<boolean | null>(null)
const accountMenu = ref(false)
let recheck: ReturnType<typeof setInterval> | undefined
function recheckVisible() { if (document.visibilityState === 'visible') refresh() }
watch(() => login.value.status, status => { if (status === 'anonymous' && isAdmin.value) navigateTo('/') })
function closeNavigation() {
  drawer.value = false
  nextTick(() => document.querySelector<HTMLButtonElement>('.admin-menu-toggle')?.focus())
}
function onMenuKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && drawer.value && !accountMenu.value && window.matchMedia('(max-width: 959px)').matches) closeNavigation()
}
onMounted(() => {
  window.addEventListener('keydown', onMenuKeydown)
  window.addEventListener('focus', recheckVisible)
  document.addEventListener('visibilitychange', recheckVisible)
  recheck = setInterval(recheckVisible, 15000)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onMenuKeydown)
  window.removeEventListener('focus', recheckVisible)
  document.removeEventListener('visibilitychange', recheckVisible)
  if (recheck) clearInterval(recheck)
})
</script>

<template>
  <v-app :class="{ 'admin-application': isAdmin }">
    <v-app-bar flat border="b" class="organization-header" height="72">
      <v-container :fluid="isAdmin" class="header-content" :class="{ 'admin-header-content': isAdmin }">
        <div class="brand">
          <v-btn v-if="isAdmin && login.status === 'authenticated'" class="admin-mobile-control admin-menu-toggle" :icon="mdiMenu" variant="text" aria-label="باز کردن منوی مدیریت" aria-controls="admin-navigation" :aria-expanded="!!drawer" @click="drawer = !drawer" />
          <v-icon :icon="mdiDomain" color="primary" size="30" class="brand-icon" />
          <NuxtLink to="/" class="brand-link"><span class="brand-full">سامانه یکپارچه سازمان</span><span v-if="isAdmin" class="brand-short">مدیریت سازمان</span></NuxtLink>
          <v-chip v-if="isAdmin" class="admin-header-badge" size="small" color="primary" variant="tonal">مدیریت سازمان</v-chip>
        </div>
        <v-menu v-if="identity || login.canLogout" v-model="accountMenu" :close-on-content-click="false" location="bottom end">
          <template #activator="{ props }">
            <v-btn v-bind="props" class="account-trigger" variant="text" aria-label="منوی حساب کاربری" :append-icon="mdiChevronDown">
              <v-avatar color="primary" variant="tonal" size="36" class="me-3"><v-icon :icon="mdiAccountCircleOutline" /></v-avatar>
              <span class="account-trigger-text"><strong>{{ identity?.user.name ?? 'حساب سازمانی' }}</strong><span>{{ identity?.source.title ?? 'بررسی وضعیت حساب ممکن نشد' }}</span></span>
            </v-btn>
          </template>
          <v-card class="account-menu" min-width="280" max-width="340" border>
            <div class="account-details"><v-avatar color="primary" variant="tonal" size="44"><v-icon :icon="mdiAccountCircleOutline" /></v-avatar><div><strong>{{ identity?.user.name ?? 'حساب سازمانی' }}</strong><p>{{ identity?.source.title ?? 'بررسی وضعیت حساب ممکن نشد' }}</p><p v-if="identity">شناسه کاربر: {{ identity.user.id.toLocaleString('fa-IR') }}</p></div></div>
            <v-divider />
            <div class="account-theme"><p>ظاهر سامانه</p><v-btn-toggle v-model="mode" mandatory divided rounded="lg" :disabled="themeSaving" color="primary" variant="tonal" aria-label="حالت نمایش"><v-btn v-for="item in themes" :key="item.value" :value="item.value" :rounded="false" :prepend-icon="item.icon">{{ item.title }}</v-btn></v-btn-toggle><p v-if="themeSaving" class="theme-feedback" role="status">در حال ذخیره…</p><v-alert v-if="themeError" class="mt-3" type="error" variant="tonal" density="compact" role="alert">{{ themeError }}</v-alert></div>
            <v-divider />
            <v-list class="account-actions"><v-list-item :prepend-icon="mdiLogout" title="خروج از حساب" role="menuitem" base-color="error" :disabled="logoutBusy" @click="accountMenu = false; requestLogout()"><template #append><v-progress-circular v-if="logoutBusy" indeterminate size="20" width="2" color="error" /></template></v-list-item></v-list>
          </v-card>
        </v-menu>
        <v-menu v-else>
          <template #activator="{ props }"><v-btn v-bind="props" variant="text" :prepend-icon="themes.find(t => t.value === mode)?.icon" aria-label="انتخاب ظاهر سامانه">ظاهر سامانه</v-btn></template>
          <v-list aria-label="حالت نمایش"><v-list-item v-for="item in themes" :key="item.value" :title="item.title" :prepend-icon="item.icon" :active="mode === item.value" @click="mode = item.value" /></v-list>
        </v-menu>
      </v-container>
    </v-app-bar>
    <v-navigation-drawer v-if="isAdmin && login.status === 'authenticated'" id="admin-navigation" v-model="drawer" :mobile-breakpoint="960" location="start" width="272" color="navigation" class="admin-drawer" aria-label="منوی مدیریت" :touchless="false">
      <AdminNavigation @close="closeNavigation" />
    </v-navigation-drawer>
    <LogoutDialog />
    <v-main>
      <v-container :fluid="isAdmin" class="workspace" :class="{ 'admin-workspace': isAdmin }">
        <v-alert v-if="isAdmin && login.status === 'unavailable'" type="warning" variant="tonal" role="alert">بررسی وضعیت حساب ممکن نشد. ارتباط با سرویس هویت را بررسی کنید.<v-btn variant="text" @click="refresh">بررسی دوباره</v-btn></v-alert>
        <NuxtPage v-if="!isAdmin || login.status === 'authenticated'" />
        <footer class="workspace-footer">سامانه یکپارچه سازمان</footer>
      </v-container>
    </v-main>
  </v-app>
</template>
