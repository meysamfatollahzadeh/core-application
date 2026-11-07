<script setup lang="ts">
import type { DirectoryItem, DirectoryPage } from '~/composables/useManagement'
const props = defineProps<{ role: DirectoryItem }>()
const emit = defineEmits<{ close: [] }>()
const api = useManagement()
const { can } = useAccess()
const selected = ref<number[]>([])
const busy = ref(false)
const notice = ref('')
const { data, error } = await useAsyncData(`role-permissions:${props.role.id}`, async () => {
  const role = await api.get<{ data: { permission_ids: number[] } }>(`roles/${props.role.id}`)
  const permissions: DirectoryItem[] = []; let page = 1; let last = 1
  do { const result = await api.get<DirectoryPage>('permissions', { page, per_page: 100 }); permissions.push(...result.data); last = result.last_page ?? 1; page++ } while (page <= last)
  return { permissions, selected: role.data.permission_ids }
})
selected.value = [...(data.value?.selected ?? [])]
async function save() {
  busy.value = true
  try { await api.mutate(`roles/${props.role.id}/permissions`, 'PUT', { permission_ids: selected.value }); emit('close') }
  catch (e) { notice.value = managementError(e) }
  finally { busy.value = false }
}
</script>
<template>
  <v-dialog :model-value="true" max-width="700" :persistent="busy" @update:model-value="!$event && emit('close')"><v-card :title="`مجوزهای ${role.title}`"><v-card-text><p class="scope-explanation admin-help">تعریف این نقش در همه شرکت‌ها مشترک است. تغییر مجوزها روی تمام کاربران و پوزیشن‌های دارای این نقش اثر می‌گذارد.</p><v-alert v-if="error || notice" type="error" variant="tonal" class="mb-4">{{ notice || managementError(error) }}</v-alert><div class="permission-list"><v-checkbox v-for="permission in data?.permissions ?? []" :key="permission.id" v-model="selected" :value="permission.id" :label="permission.title" :disabled="!can('roles.permissions.update')" density="compact" hide-details color="primary"><template #label><span>{{ permission.title }} <bdi class="admin-muted">({{ permission.key }})</bdi></span></template></v-checkbox></div></v-card-text><v-card-actions><v-btn :disabled="busy" @click="emit('close')">بستن</v-btn><v-spacer /><v-btn v-if="can('roles.permissions.update')" color="primary" variant="flat" :disabled="!!error" :loading="busy" @click="save">ذخیره مجوزها</v-btn></v-card-actions></v-card></v-dialog>
</template>
