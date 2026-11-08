<script setup lang="ts">
import { mdiLogout } from '@mdi/js'
const { open, busy, error, confirmLogout } = useLogout()
</script>
<template>
  <v-dialog v-model="open" @keydown.esc="!busy && (open = false)" max-width="440" aria-labelledby="logout-confirmation-heading">
    <v-card><v-card-title id="logout-confirmation-heading">خروج از حساب</v-card-title>
      <v-card-text><p class="admin-help">آیا می‌خواهید از حساب سازمانی خود خارج شوید؟</p><v-alert v-if="error" class="mt-4" type="error" variant="tonal" role="alert">{{ error }}</v-alert></v-card-text>
      <v-card-actions><v-btn :disabled="busy" color="secondary" variant="tonal" @click="open = false">انصراف</v-btn><v-spacer /><v-btn color="error" variant="flat" :prepend-icon="mdiLogout" :loading="busy" @click="confirmLogout">تأیید خروج</v-btn></v-card-actions>
    </v-card>
  </v-dialog>
</template>
