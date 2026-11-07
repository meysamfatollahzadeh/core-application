<script setup lang="ts">
import type { DirectoryItem } from '~/composables/useManagement'
const props = defineProps<{ position: DirectoryItem; company: number }>()
const emit = defineEmits<{ close: [] }>()
const api = useManagement()
const { can } = useAccess()
const endpoint = `companies/${props.company}/positions/${props.position.id}/roles`
const { data, error } = await useAsyncData(`position-roles:${endpoint}`, () => api.get<{ data: { grants: { role_id: number; scope: 'global' | 'company' }[] } }>(endpoint))
const companyRoles = ref<number[]>(data.value?.data.grants.filter(grant => grant.scope === 'company').map(grant => grant.role_id) ?? [])
const globalRoles = ref<number[]>(data.value?.data.grants.filter(grant => grant.scope === 'global').map(grant => grant.role_id) ?? [])
const notice = ref('')
const busy = ref(false)
async function save() {
  busy.value = true
  try {
    const grants = [...companyRoles.value.map(role_id => ({ role_id, scope: 'company' })), ...(can('access.assign') ? globalRoles.value.map(role_id => ({ role_id, scope: 'global' })) : [])]
    await api.mutate(endpoint, 'PUT', { grants }); emit('close')
  } catch (e) { notice.value = managementError(e) }
  finally { busy.value = false }
}
</script>
<template><v-dialog :model-value="true" max-width="650" :persistent="busy" @update:model-value="!$event && emit('close')"><v-card :title="`نقش‌های پوزیشن ${position.title}`"><v-card-text><p class="scope-explanation admin-help">همه افراد دارای این پوزیشن مجوزها را می‌گیرند. پوزیشن‌های زیرمجموعه و مدیران گزارش‌دهی، این نقش‌ها را به ارث نمی‌برند.</p><v-alert v-if="error || notice" type="error" variant="tonal" class="mb-4">{{ notice || managementError(error) }}</v-alert><AdminRemoteChoice v-model="companyRoles" :endpoint="`access/role-options?company_id=${company}`" label="نقش‌ها در محدوده شرکت صاحب پوزیشن" multiple /><template v-if="can('access.assign')"><v-divider class="my-4" /><AdminRemoteChoice v-model="globalRoles" endpoint="access/role-options" label="نقش‌های سراسری (معتبر در همه شرکت‌ها)" multiple /></template><p v-else class="admin-help">این فرم فقط نقش‌های شرکتی را تغییر می‌دهد.</p></v-card-text><v-card-actions><v-btn :disabled="busy" @click="emit('close')">انصراف</v-btn><v-spacer /><v-btn color="primary" variant="flat" :loading="busy" :disabled="!!error" @click="save">ذخیره نقش‌های پوزیشن</v-btn></v-card-actions></v-card></v-dialog></template>
