<script setup lang="ts">
import type { DirectoryItem } from '~/composables/useManagement'
const props = defineProps<{ company?: number | null }>()
const user = ref<number | null>(null)
const position = ref<number | null>(null)
const selectedUser = ref<DirectoryItem | null>(null)
const selectedPosition = ref<DirectoryItem | null>(null)
const userDialog = ref<DirectoryItem | null>(null)
const positionDialog = ref<DirectoryItem | null>(null)
const { state, can } = useAccess()
const positionCompany = ref<number | null>(props.company ?? state.value.visible_companies[0]?.id ?? null)
const notice = ref('')
async function openUser() {
  if (!user.value || selectedUser.value?.id !== user.value) return
  userDialog.value = selectedUser.value
}
async function openPosition() {
  if (!position.value || !positionCompany.value || selectedPosition.value?.id !== position.value) return
  positionDialog.value = selectedPosition.value
}
watch(() => props.company, value => { positionCompany.value = value ?? null; user.value = null; position.value = null; userDialog.value = null; positionDialog.value = null })
</script>
<template><section><h2 class="mb-4">تخصیص نقش در محدوده {{ company ? 'شرکت انتخاب‌شده' : 'سراسری' }}</h2><p class="scope-explanation admin-help">{{ company ? 'مجوزهای نقش‌های این بخش فقط در شرکت انتخاب‌شده اعمال می‌شوند.' : 'نقش مستقیم سراسری در همه شرکت‌ها معتبر است. برای نقش شرکتی، شرکت موردنظر را از بالای صفحه انتخاب کنید.' }}</p><v-alert v-if="notice" type="error" variant="tonal">{{ notice }}</v-alert><div class="grant-columns"><v-card variant="outlined" title="نقش مستقیم کاربر"><v-card-text><AdminRemoteChoice v-model="user" endpoint="personnel/options" label="انتخاب کاربر (با یا بدون سمت)" :show-identifier="false" @selected-item="selectedUser = $event" /><v-btn color="primary" :disabled="!user || selectedUser?.id !== user" @click="openUser">ویرایش نقش‌های کاربر</v-btn></v-card-text></v-card><v-card variant="outlined" title="نقش پوزیشن"><v-card-text><v-select v-if="!company" v-model="positionCompany" :items="state.visible_companies.filter(item => can('access.assign', item.id))" item-title="title" item-value="id" label="شرکت صاحب پوزیشن" variant="outlined" @update:model-value="position = null" /><AdminRemoteChoice v-if="positionCompany" :key="positionCompany" v-model="position" :endpoint="`organization/company-position-options?company_id=${positionCompany}`" label="انتخاب پوزیشن" hierarchy :show-identifier="false" @selected-item="selectedPosition = $event" /><p v-else class="admin-help mb-4">برای تخصیص نقش به پوزیشن، ابتدا یک شرکت تعریف کنید.</p><v-btn color="primary" :disabled="!position || !positionCompany || selectedPosition?.id !== position" @click="openPosition">ویرایش نقش‌های پوزیشن</v-btn></v-card-text></v-card></div><AdminUserRoles v-if="userDialog" :user="userDialog" :company="company" @close="userDialog = null" /><AdminPositionRoles v-if="positionDialog && positionCompany" :position="positionDialog" :company="positionCompany" @close="positionDialog = null" /></section></template>
