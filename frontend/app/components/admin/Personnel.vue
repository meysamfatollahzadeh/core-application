<script setup lang="ts">
import type { DirectoryItem, DirectoryPage } from '~/composables/useManagement'
const props = defineProps<{ company?: number | null }>()
const emit = defineEmits<{ roles: [item: DirectoryItem] }>()
const api = useManagement()
const { can } = useAccess()
const search = ref('')
const query = ref('')
const page = ref(1)
const endpoint = computed(() => props.company ? `companies/${props.company}/personnel` : 'personnel')
const { data, error, status, refresh } = await useAsyncData(computed(() => `people:${endpoint.value}:${page.value}:${query.value}`), () => api.get<DirectoryPage>(endpoint.value, { page: page.value, search: query.value }))
const current = ref<DirectoryItem | null>(null)
const name = ref('')
const area = ref<number | null>(null)
const notice = ref('')
const busy = ref(false)
function edit(item: DirectoryItem) { current.value = item; name.value = item.name ?? ''; area.value = item.building_area_id as number; notice.value = '' }
async function save() {
  if (!current.value || !area.value || !name.value.trim()) return
  busy.value = true
  try { await api.mutate(`personnel/${current.value.id}`, 'PATCH', { name: name.value.trim(), building_area_id: area.value }); current.value = null; await refresh() }
  catch (e) { notice.value = managementError(e) }
  finally { busy.value = false }
}
</script>
<template>
  <section><div class="admin-section-heading"><div><h2>پرسنل {{ company ? 'شرکت' : 'سامانه' }}</h2><p class="admin-muted">{{ (data?.total ?? 0).toLocaleString('fa-IR') }} نفر · فقط وضعیت فعلی</p></div></div>
    <v-form class="admin-search" @submit.prevent="page = 1; query = search"><v-text-field v-model="search" label="جست‌وجوی نام" variant="outlined" density="compact" hide-details /><v-btn type="submit" variant="tonal">جست‌وجو</v-btn></v-form>
    <v-alert v-if="error" type="error" variant="tonal">{{ managementError(error) }}<v-btn variant="text" @click="refresh()">تلاش دوباره</v-btn></v-alert><v-progress-linear v-if="status === 'pending'" indeterminate aria-label="دریافت پرسنل" />
    <v-table class="admin-table"><thead><tr><th>نام و نام خانوادگی</th><th>محل استقرار</th><th>عملیات</th></tr></thead><tbody><tr v-for="item in data?.data ?? []" :key="item.id"><td><strong>{{ item.name }}</strong></td><td>{{ item.location_title ?? `ناحیه شماره ${item.building_area_id}` }}</td><td><div class="admin-row-actions"><v-btn v-if="can('personnel.update')" size="small" variant="text" @click="edit(item)">ویرایش</v-btn><v-btn v-if="can('access.assign', company)" size="small" variant="text" @click="emit('roles', item)">نقش‌ها</v-btn></div></td></tr><tr v-if="!data?.data.length && status !== 'pending'"><td colspan="3" class="admin-empty">پرسنلی در این محدوده پیدا نشد. حساب‌های جدید از منبع هویت وارد سامانه می‌شوند.</td></tr></tbody></v-table>
    <v-pagination v-if="(data?.last_page ?? 1) > 1" v-model="page" :length="data?.last_page ?? 1" :total-visible="5" />
    <v-dialog :model-value="!!current" max-width="540" :persistent="busy" @update:model-value="!$event && (current = null)"><v-card title="مشخصات و محل استقرار"><v-form @submit.prevent="save"><v-card-text><p class="admin-help mb-4">این اطلاعات بین همه شرکت‌های فرد مشترک است.</p><v-alert v-if="notice" type="error" variant="tonal" class="mb-4">{{ notice }}</v-alert><v-text-field v-model="name" label="نام و نام خانوادگی" variant="outlined" maxlength="255" required /><AdminRemoteChoice v-model="area" endpoint="organization/location-options" label="ساختمان و ناحیه استقرار (الزامی)" /></v-card-text><v-card-actions><v-btn :disabled="busy" @click="current = null">انصراف</v-btn><v-spacer /><v-btn type="submit" color="primary" variant="flat" :disabled="!area || !name.trim()" :loading="busy">ذخیره</v-btn></v-card-actions></v-form></v-card></v-dialog>
  </section>
</template>
