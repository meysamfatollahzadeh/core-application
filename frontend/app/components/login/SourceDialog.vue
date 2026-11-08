<script setup lang="ts">
import { mdiDomain, mdiChevronLeft, mdiRefresh } from '@mdi/js'
import type { UserSourceSummary } from '#shared/types/login'
const open = defineModel<boolean>({ default: false })
const { state } = useLogin()
const sources = ref<UserSourceSummary[]>([])
const loading = ref(false)
const selected = ref<string | null>(null)
const message = ref('')
async function load() {
  loading.value = true
  message.value = ''
  try { sources.value = (await $fetch<{ data: UserSourceSummary[] }>('/gateway/login/sources')).data }
  catch { message.value = 'دریافت فهرست حساب‌ها ممکن نشد. دوباره تلاش کنید.' }
  finally { loading.value = false }
}
watch(open, value => { if (value) { selected.value = null; load() } })
async function select(slug: string) {
  selected.value = slug
  message.value = ''
  try {
    const result = await $fetch<{ url: string }>(`/gateway/login/sources/${slug}/authorize`, { method: 'POST', headers: { 'X-CSRF-Token': state.value.csrf } })
    window.location.assign(result.url)
  } catch { message.value = 'شروع ورود ممکن نشد. صفحه را تازه کنید و دوباره تلاش کنید.'; selected.value = null }
}
</script>
<template>
  <v-dialog v-model="open" max-width="480" aria-labelledby="source-dialog-title">
    <v-card>
      <v-card-title id="source-dialog-title" class="pt-6 px-6">انتخاب حساب سازمانی</v-card-title>
      <v-card-text>
        <p class="source-help">سازمانی را که حساب شما در آن تعریف شده است انتخاب کنید.</p>
        <v-alert v-if="message" type="warning" variant="tonal" class="mb-4" role="alert">{{ message }}</v-alert>
        <div v-if="loading" class="source-loading" role="status"><v-progress-circular indeterminate size="24" color="primary" /><span>دریافت فهرست سازمان‌ها…</span></div>
        <v-list v-else-if="sources.length" aria-label="سازمان‌های قابل انتخاب" class="source-list">
          <v-list-item v-for="source in sources" :key="source.slug" :title="source.title" :prepend-icon="mdiDomain" :disabled="!!selected" @click="select(source.slug)">
            <template #append><v-progress-circular v-if="selected === source.slug" indeterminate size="20" /><v-icon v-else :icon="mdiChevronLeft" /></template>
          </v-list-item>
        </v-list>
        <p v-else-if="!message" role="status">در حال حاضر سازمان فعالی برای ورود وجود ندارد. با مسئول سامانه تماس بگیرید.</p>
      </v-card-text>
      <v-card-actions class="px-6 pb-5"><v-btn v-if="message" :prepend-icon="mdiRefresh" @click="load">تلاش دوباره</v-btn><v-spacer /><v-btn :disabled="!!selected" @click="open = false">انصراف</v-btn></v-card-actions>
    </v-card>
  </v-dialog>
</template>
