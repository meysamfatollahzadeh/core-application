<script setup lang="ts">
import { mdiLinkVariantOff } from '@mdi/js'
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
const formOpen = ref(false)
const accountsFor = ref<DirectoryItem | null>(null)
const name = ref('')
const area = ref<number | null>(null)
const notice = ref('')
const busy = ref(false)
function edit(item: DirectoryItem) { current.value = item; name.value = item.name ?? ''; area.value = item.building_area_id as number; notice.value = ''; formOpen.value = true }
function create() { current.value = null; name.value = ''; area.value = null; notice.value = ''; formOpen.value = true }
async function save() {
  if (busy.value || !area.value || !name.value.trim()) return
  busy.value = true
  try {
    await api.mutate(current.value ? `personnel/${current.value.id}` : 'personnel', current.value ? 'PATCH' : 'POST', { name: name.value.trim(), building_area_id: area.value })
    formOpen.value = false
    if (!current.value) { search.value = ''; query.value = ''; page.value = 1 }
    await refresh()
  }
  catch (e) { notice.value = managementError(e) }
  finally { busy.value = false }
}
</script>
<template>
  <section><div class="admin-section-heading"><div><h2>پرسنل {{ company ? 'شرکت' : 'سامانه' }}</h2><p class="admin-muted">{{ (data?.total ?? 0).toLocaleString('fa-IR') }} نفر · فقط وضعیت فعلی</p></div><v-btn v-if="!company && can('personnel.create')" color="primary" variant="flat" @click="create">افزودن پرسنل</v-btn></div>
    <v-form class="admin-search" @submit.prevent="page = 1; query = search"><v-text-field v-model="search" label="جست‌وجوی نام" variant="outlined" density="compact" hide-details /><v-btn type="submit" color="info" variant="tonal">جست‌وجو</v-btn></v-form>
    <v-alert v-if="error" type="error" variant="tonal">{{ managementError(error) }}<v-btn variant="text" @click="refresh()">تلاش دوباره</v-btn></v-alert><v-progress-linear v-if="status === 'pending'" indeterminate aria-label="دریافت پرسنل" />
    <v-table class="admin-table"><thead><tr><th>نام و نام خانوادگی</th><th>محل استقرار</th><th>عملیات</th></tr></thead><tbody><tr v-for="item in data?.data ?? []" :key="item.id"><td><strong>{{ item.name }}</strong><div class="admin-muted">شناسه {{ item.id.toLocaleString('fa-IR') }}</div><div v-if="item.has_active_member === false" class="mt-2"><v-chip color="warning" variant="tonal" size="small" :prepend-icon="mdiLinkVariantOff" class="personnel-connection-warning">فاقد اتصال به منبع فعال</v-chip></div></td><td>{{ item.location_title ?? `ناحیه شماره ${item.building_area_id}` }}</td><td><div class="admin-row-actions"><v-btn v-if="can('personnel.update')" size="small" color="warning" variant="tonal" @click="edit(item)">ویرایش</v-btn><v-btn v-if="can('personnel.members.manage')" size="small" color="info" variant="tonal" @click="accountsFor = item">حساب‌های ورود</v-btn><v-btn v-if="can('access.assign', company)" size="small" color="success" variant="tonal" @click="emit('roles', item)">نقش‌ها</v-btn></div></td></tr><tr v-if="!data?.data.length && status !== 'pending'"><td colspan="3" class="admin-empty">پرسنلی در این محدوده پیدا نشد. پرسنل را می‌توان ابتدا ایجاد و سپس حساب ورود او را متصل کرد.</td></tr></tbody></v-table>
    <v-pagination v-if="(data?.last_page ?? 1) > 1" v-model="page" :length="data?.last_page ?? 1" :total-visible="5" />
    <v-dialog v-model="formOpen" @keydown.esc="!busy && (formOpen = false)" max-width="540"><v-card :title="current ? 'مشخصات و محل استقرار' : 'افزودن پرسنل'"><v-form @submit.prevent="save"><v-card-text><p class="admin-help mb-4">{{ current ? 'این اطلاعات بین همه شرکت‌های فرد مشترک است.' : 'ابتدا مشخصات فرد ذخیره می‌شود. حساب ورود را پس از ایجاد، از فهرست پرسنل متصل کنید.' }}</p><v-alert v-if="notice" type="error" variant="tonal" class="mb-4">{{ notice }}</v-alert><v-text-field v-model="name" label="نام و نام خانوادگی" variant="outlined" maxlength="255" :disabled="busy" required /><AdminRemoteChoice v-model="area" endpoint="organization/location-options" label="ساختمان و ناحیه استقرار (الزامی)" :disabled="busy" /></v-card-text><v-card-actions><v-btn :disabled="busy" @click="formOpen = false">انصراف</v-btn><v-spacer /><v-btn type="submit" color="primary" variant="flat" :disabled="!area || !name.trim()" :loading="busy">{{ current ? 'ذخیره' : 'ایجاد پرسنل' }}</v-btn></v-card-actions></v-form></v-card></v-dialog>
    <AdminMemberAccounts v-if="accountsFor" :user="accountsFor" @close="accountsFor = null; refresh()" />
  </section>
</template>
