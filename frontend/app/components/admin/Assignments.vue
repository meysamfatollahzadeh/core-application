<script setup lang="ts">
import type { DirectoryItem, DirectoryPage } from '~/composables/useManagement'
const props = defineProps<{ company: number }>()
const api = useManagement()
const { can } = useAccess()
const page = ref(1)
const search = ref('')
const query = ref('')
const endpoint = computed(() => `companies/${props.company}/user-positions`)
const { data, error, refresh } = await useAsyncData(computed(() => `assignments:${props.company}:${page.value}:${query.value}`), () => api.get<DirectoryPage>(endpoint.value, { page: page.value, search: query.value }))
const open = ref(false)
const current = ref<DirectoryItem | null>(null)
const user = ref<number | null>(null)
const position = ref<number | null>(null)
const reports = ref<number[]>([])
const notice = ref('')
const busy = ref(false)
const removing = ref<DirectoryItem | null>(null)
function edit(item?: DirectoryItem) { current.value = item ?? null; user.value = item?.user_id as number ?? null; position.value = item?.position_id as number ?? null; reports.value = [...(item?.report_position_ids as number[] ?? [])]; notice.value = ''; open.value = true }
async function save() {
  if (!user.value || !position.value) return
  busy.value = true
  try { await api.mutate(endpoint.value + (current.value ? `/${current.value.id}` : ''), current.value ? 'PUT' : 'POST', { ...(!current.value ? { user_id: user.value, position_id: position.value } : {}), report_position_ids: reports.value }); open.value = false; await refresh() }
  catch (e) { notice.value = managementError(e) }
  finally { busy.value = false }
}
async function remove() {
  if (!removing.value) return
  busy.value = true
  try { await api.mutate(`${endpoint.value}/${removing.value.id}`, 'DELETE'); removing.value = null; await refresh() }
  catch (e) { notice.value = managementError(e); removing.value = null }
  finally { busy.value = false }
}
</script>
<template>
  <section><div class="admin-section-heading"><div><h2>سمت‌ها و گزارش‌دهی پرسنل</h2><p class="admin-muted">مدیران هر فرد در هر سمت، مستقل از چارت انتخاب می‌شوند.</p></div><v-btn v-if="can('user_positions.create', company)" color="primary" @click="edit()">تخصیص سمت</v-btn></div>
    <v-form class="admin-search" @submit.prevent="page = 1; query = search"><v-text-field v-model="search" label="جست‌وجوی نام فرد" variant="outlined" density="compact" hide-details /><v-btn type="submit" color="info" variant="tonal">جست‌وجو</v-btn></v-form>
    <v-alert v-if="error || (notice && !open)" type="error" variant="tonal" class="mb-4">{{ notice || managementError(error) }}</v-alert>
    <v-table class="admin-table assignments-table"><thead><tr><th>فرد</th><th>سمت</th><th>پوزیشن‌های مدیر</th><th>عملیات</th></tr></thead><tbody><tr v-for="item in data?.data ?? []" :key="item.id"><td><strong>{{ item.user_name }}</strong></td><td>{{ item.position_title }}</td><td>{{ (item.report_titles as string[] | undefined)?.join('، ') || ((item.report_position_ids as number[]).length ? (item.report_position_ids as number[]).map(id => `پوزیشن ${id}`).join('، ') : 'بدون مدیر') }}</td><td><div class="admin-row-actions"><v-btn v-if="can('user_positions.update', company)" size="small" color="warning" variant="tonal" @click="edit(item)">ویرایش مدیران</v-btn><v-btn v-if="can('user_positions.delete', company)" size="small" color="error" variant="tonal" @click="removing = item">حذف انتساب</v-btn></div></td></tr><tr v-if="!data?.data.length"><td colspan="4" class="admin-empty">هنوز سمتی به پرسنل این شرکت اختصاص نیافته است.</td></tr></tbody></v-table>
    <v-pagination v-if="(data?.last_page ?? 1) > 1" v-model="page" :length="data?.last_page ?? 1" :total-visible="5" />
    <v-dialog v-model="open" max-width="620" :persistent="busy"><v-card :title="current ? 'ویرایش مدیران گزارش‌دهی' : 'تخصیص سمت به فرد'"><v-form @submit.prevent="save"><v-card-text><v-alert v-if="notice" type="error" variant="tonal" class="mb-4">{{ notice }}</v-alert><template v-if="!current"><AdminRemoteChoice v-model="user" endpoint="personnel/options" label="فرد" /><AdminRemoteChoice v-model="position" :endpoint="`organization/company-position-options?company_id=${company}`" label="پوزیشن در این شرکت" /></template><p v-else class="admin-help mb-4">{{ current.user_name }} / {{ current.position_title }}</p><AdminRemoteChoice v-model="reports" endpoint="organization/reporting-position-options" label="پوزیشن‌های مدیر (از هر شرکت)" multiple /><p class="admin-help">اگر سمت در چارت بالادست دارد، حداقل یک مدیر انتخاب کنید. همان سمت نمی‌تواند مدیر خودش باشد؛ مدیر از شرکت دیگر مجاز است.</p></v-card-text><v-card-actions><v-btn :disabled="busy" @click="open = false">انصراف</v-btn><v-spacer /><v-btn type="submit" color="primary" variant="flat" :disabled="!user || !position" :loading="busy">ذخیره</v-btn></v-card-actions></v-form></v-card></v-dialog>
    <v-dialog :model-value="!!removing" max-width="440" @update:model-value="!$event && (removing = null)"><v-card title="حذف انتساب سمت"><v-card-text>انتساب «{{ removing?.position_title }}» برای «{{ removing?.user_name }}» حذف شود؟ دسترسی‌های ناشی از این سمت نیز حذف می‌شوند.</v-card-text><v-card-actions><v-btn @click="removing = null">انصراف</v-btn><v-spacer /><v-btn color="error" :loading="busy" @click="remove">حذف انتساب</v-btn></v-card-actions></v-card></v-dialog>
  </section>
</template>
