<script setup lang="ts">
import { mdiShieldLockOutline, mdiViewDashboardOutline, mdiHomeOutline } from '@mdi/js'
</script>
<template>
  <section class="access-denied" aria-labelledby="access-denied-heading">
    <div class="access-denied-panel">
      <v-icon :icon="mdiShieldLockOutline" size="44" color="primary" />
      <div class="access-denied-code" aria-hidden="true">۴۰۳</div>
      <h1 id="access-denied-heading">دسترسی به این صفحه مجاز نیست</h1>
      <p>حساب شما مجوز مشاهده این بخش یا شرکت انتخاب‌شده را ندارد. برای تغییر دسترسی با مسئول سامانه تماس بگیرید.</p>
      <div class="access-denied-actions">
        <v-btn href="/admin" color="primary" :prepend-icon="mdiViewDashboardOutline">پیشخوان مدیریت</v-btn>
        <v-btn href="/" color="secondary" variant="tonal" :prepend-icon="mdiHomeOutline">فضای کاری سازمان</v-btn>
      </div>
    </div>
  </section>
</template>
