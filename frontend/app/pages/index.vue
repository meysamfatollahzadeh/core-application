<script setup lang="ts">
import { mdiDomain, mdiLogin, mdiLogout } from '@mdi/js'
const { state: login, refresh } = useLogin()
const { available } = useAdminSections()
const identity = computed(() => login.value.identity)
const route = useRoute()
const sourceDialog = ref(false)
const message = ref('')
const busy = ref(false)
const loginError = computed(() => route.query.login_error === 'member_not_imported' ? 'حساب شما هنوز در سامانه مجاز نشده است. برای ایمپورت حساب با مسئول سامانه تماس بگیرید.' : route.query.login_error ? 'ورود کامل نشد. دوباره حساب سازمانی خود را انتخاب کنید.' : '')
const logoutError = computed(() => route.query.logout_error ? 'دسترسی محلی بسته شد، اما پایان ورود در سرویس هویت تأیید نشد. حساب سازمانی خود را بررسی کنید.' : '')
async function logout() {
  busy.value = true
  try { const result = await $fetch<{ url: string }>('/gateway/login/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': login.value.csrf } }); window.location.assign(result.url) }
  catch { message.value = 'خروج انجام نشد. دوباره تلاش کنید.'; busy.value = false }
}
</script>

<template>
<div>
        <div class="page-heading"><h1>فضای کاری سازمان</h1><p>دسترسی یکپارچه به خدمات و فرآیندهای سازمان</p></div>
        <v-alert v-if="loginError || logoutError" type="warning" variant="tonal" class="mb-6" role="alert">{{ loginError || logoutError }}</v-alert>
        <v-alert v-if="message" type="warning" variant="tonal" class="mb-6" role="alert">{{ message }}</v-alert>
        <v-card border class="welcome-panel">
          <div class="welcome-content">
            <v-icon :icon="mdiDomain" color="primary" size="48" class="mb-5" />
            <h2>{{ identity?.user ? `${identity.user.name}، خوش آمدید` : 'به سامانه سازمان خوش آمدید' }}</h2>
            <p>{{ identity?.user ? 'از بخش مدیریت، ساختار سازمان، پرسنل و دسترسی‌های مجاز خود را مدیریت کنید.' : 'برای دسترسی به فضای کاری، با حساب سازمانی خود وارد شوید.' }}</p>
            <v-alert v-if="login.status === 'unavailable'" type="warning" variant="tonal" class="mb-5" role="alert">بررسی وضعیت حساب ممکن نشد. ارتباط با سرویس هویت را بررسی کنید و دوباره تلاش کنید.<v-btn variant="text" @click="refresh">بررسی دوباره</v-btn></v-alert>
            <p v-if="identity" class="identity-source">حساب فعال: {{ identity.source.title }}</p>
            <v-btn v-if="login.status === 'anonymous'" @click="sourceDialog = true" color="primary" size="large" :prepend-icon="mdiLogin">ورود با حساب سازمانی</v-btn>
            <v-btn v-if="available.length" to="/admin" color="primary" size="large" class="me-3 mb-3">مدیریت سازمان</v-btn>
            <v-btn v-if="login.canLogout" variant="outlined" color="primary" size="large" :prepend-icon="mdiLogout" :loading="busy" @click="logout">خروج از حساب</v-btn>
          </div>
        </v-card>
<LoginSourceDialog v-model="sourceDialog" />
</div>
</template>
