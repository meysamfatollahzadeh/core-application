<script setup lang="ts">
import type { NuxtError } from '#app'
useAppearance()
const props = defineProps<{ error: NuxtError }>()
const forbidden = computed(() => props.error.statusCode === 403)
useHead({ title: computed(() => forbidden.value ? 'دسترسی غیرمجاز | سامانه سازمان' : 'خطا | سامانه سازمان') })
</script>
<template>
  <v-app><v-main>
    <AccessDenied v-if="forbidden" />
    <section v-else class="access-denied"><div class="access-denied-panel"><div class="access-denied-code">{{ error.statusCode }}</div><h1>{{ error.statusCode === 404 ? 'صفحه پیدا نشد' : error.statusCode === 503 ? 'بررسی وضعیت حساب ممکن نشد' : 'دریافت اطلاعات ممکن نشد' }}</h1><p>{{ error.statusCode === 503 ? 'ارتباط با سامانه یا سرویس هویت را بررسی کنید و دوباره تلاش کنید.' : 'می‌توانید به فضای کاری سازمان بازگردید و دوباره تلاش کنید.' }}</p><div class="access-denied-actions"><v-btn href="/" color="primary">فضای کاری سازمان</v-btn></div></div></section>
  </v-main></v-app>
</template>
