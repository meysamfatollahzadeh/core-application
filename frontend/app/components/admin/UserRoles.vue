<script setup lang="ts">
import type { DirectoryItem } from '~/composables/useManagement'
const props = defineProps<{ user: DirectoryItem; company?: number | null }>()
const emit = defineEmits<{ close: [] }>()
const api = useManagement()
const endpoint = computed(() => props.company ? `companies/${props.company}/users/${props.user.id}/roles` : `personnel/${props.user.id}/global-roles`)
const { data, error } = await useAsyncData(`user-roles:${endpoint.value}`, () => api.get<{ data: { role_ids: number[] } }>(endpoint.value))
const selected = ref<number[]>([...(data.value?.data.role_ids ?? [])])
const notice = ref('')
const busy = ref(false)
async function save() {
  busy.value = true
  try { await api.mutate(endpoint.value, 'PUT', { role_ids: selected.value }); emit('close') }
  catch (e) { notice.value = managementError(e) }
  finally { busy.value = false }
}
</script>
<template><v-dialog :model-value="true" max-width="600" :persistent="busy" @update:model-value="!$event && emit('close')"><v-card class="grant-role-dialog" :title="`نقش‌های ${user.name}`"><v-card-text><p class="scope-explanation admin-help">{{ company ? 'این نقش‌ها فقط در شرکت انتخاب‌شده معتبرند. داشتن سمت در این شرکت الزامی نیست.' : 'این نقش‌ها سراسری‌اند و مجوزهایشان در همه شرکت‌ها و بخش‌های سراسری معتبر است.' }}</p><v-alert v-if="error || notice" type="error" variant="tonal" class="mb-4">{{ notice || managementError(error) }}</v-alert><AdminRemoteChoice v-model="selected" :endpoint="company ? `access/role-options?company_id=${company}` : 'access/role-options'" label="نقش‌های مستقیم در این محدوده" multiple /><p class="admin-help">مجوزهای این نقش‌ها با نقش‌های پوزیشن‌های فرد جمع می‌شوند. خالی‌کردن انتخاب، فقط نقش‌های مستقیم همین محدوده را حذف می‌کند.</p></v-card-text><v-card-actions><v-btn :disabled="busy" @click="emit('close')">انصراف</v-btn><v-spacer /><v-btn color="primary" variant="flat" :loading="busy" :disabled="!!error" @click="save">ذخیره نقش‌ها</v-btn></v-card-actions></v-card></v-dialog></template>
