<script setup lang="ts">
import type { DirectoryItem, DirectoryPage } from '~/composables/useManagement'
const props = defineProps<{ endpoint: string; title: string; noun: string; permission: string; company?: number | null; kind?: 'positions' | 'areas' | 'roles'; buildingId?: number }>()
const emit = defineEmits<{ select: [item: DirectoryItem]; changed: [] }>()
const api = useManagement()
const { can } = useAccess()
const page = ref(1)
const search = ref('')
const searchQuery = ref('')
const key = computed(() => `directory:${props.endpoint}:${page.value}:${searchQuery.value}`)
const { data, error, status, refresh } = await useAsyncData(key, () => api.get<DirectoryPage>(props.endpoint, { page: page.value, search: searchQuery.value, per_page: 25 }))
const rows = computed(() => data.value?.data ?? [])
const total = computed(() => data.value?.meta?.total ?? data.value?.total ?? 0)
const last = computed(() => data.value?.meta?.last_page ?? data.value?.last_page ?? 1)
const dialog = ref(false)
const current = ref<DirectoryItem | null>(null)
const title = ref('')
const roleKey = ref('')
const parent = ref<number | null>(null)
const busy = ref(false)
const notice = ref('')
const deleting = ref<DirectoryItem | null>(null)
function open(row?: DirectoryItem) { current.value = row ?? null; title.value = row?.title ?? ''; roleKey.value = String(row?.key ?? ''); parent.value = row?.parent_position_id as number | null ?? null; notice.value = ''; dialog.value = true }
async function save() {
  if (!title.value.trim() || (!current.value && props.kind === 'roles' && !/^[a-z][a-z0-9.-]*$/.test(roleKey.value))) return
  busy.value = true
  try {
    const body: Record<string, unknown> = { title: title.value.trim() }
    if (props.kind === 'positions') body.parent_position_id = parent.value
    if (props.kind === 'roles' && !current.value) body.key = roleKey.value
    await api.mutate(props.endpoint + (current.value ? '/' + current.value.id : ''), current.value ? 'PATCH' : 'POST', body)
    dialog.value = false; notice.value = ''; await refresh(); emit('changed')
  } catch (e) { notice.value = managementError(e) }
  finally { busy.value = false }
}
async function remove() {
  if (!deleting.value) return
  busy.value = true
  try { await api.mutate(props.endpoint + '/' + deleting.value.id, 'DELETE'); deleting.value = null; notice.value = ''; await refresh(); emit('changed') }
  catch (e) { notice.value = managementError(e); deleting.value = null }
  finally { busy.value = false }
}
function submitSearch() { page.value = 1; searchQuery.value = search.value }
</script>
<template>
  <section>
    <div class="admin-section-heading"><div><h2>{{ props.title }}</h2><p class="admin-muted">{{ total.toLocaleString('fa-IR') }} مورد ثبت‌شده</p></div><v-btn v-if="can(permission + '.create', company)" color="primary" @click="open()">افزودن {{ noun }}</v-btn></div>
    <v-form class="admin-search" @submit.prevent="submitSearch"><v-text-field v-model="search" label="جست‌وجوی عنوان" variant="outlined" density="compact" hide-details clearable /><v-btn type="submit" color="info" variant="tonal">جست‌وجو</v-btn></v-form>
    <v-alert v-if="error || (notice && !dialog)" type="error" variant="tonal" class="mb-4" role="alert">{{ notice || managementError(error) }}<v-btn variant="text" @click="refresh()">تلاش دوباره</v-btn></v-alert>
    <v-progress-linear v-if="status === 'pending'" indeterminate color="primary" aria-label="در حال دریافت اطلاعات" />
    <v-table class="admin-table"><thead><tr><th>عنوان</th><th v-if="kind === 'positions'">بالادست در چارت</th><th v-if="kind === 'roles'">کلید نقش</th><th v-if="kind === 'areas'">نوع ناحیه</th><th>عملیات</th></tr></thead><tbody>
      <tr v-for="row in rows" :key="row.id"><td><strong>{{ row.title }}</strong></td><td v-if="kind === 'positions'">{{ row.parent_title || (row.parent_position_id ? `پوزیشن شماره ${row.parent_position_id}` : 'ریشه') }}</td><td v-if="kind === 'roles'"><bdi>{{ row.key }}</bdi></td><td v-if="kind === 'areas'"><v-chip v-if="row.is_default" size="small" variant="tonal">پیش‌فرض · نامشخص</v-chip><span v-else>ناحیه عادی</span></td><td><div class="admin-row-actions"><v-btn v-if="kind !== 'areas' && (kind !== 'positions' || can('access.assign', company))" size="small" :color="kind === 'roles' || kind === 'positions' ? 'success' : 'info'" variant="tonal" @click="emit('select', row)">{{ kind === 'roles' ? 'مجوزها' : kind === 'positions' ? 'نقش‌ها' : 'باز کردن' }}</v-btn><v-btn v-if="can(permission + '.update', company) && !row.is_default" size="small" color="warning" variant="tonal" @click="open(row)">ویرایش</v-btn><v-btn v-if="can(permission + '.delete', company) && !row.is_default" size="small" color="error" variant="tonal" @click="deleting = row">حذف</v-btn></div></td></tr>
      <tr v-if="!rows.length && status !== 'pending'"><td colspan="4" class="admin-empty">هنوز موردی ثبت نشده است. برای شروع، {{ noun }} اضافه کنید.</td></tr>
    </tbody></v-table>
    <v-pagination v-if="last > 1" v-model="page" :length="last" :total-visible="5" aria-label="صفحه‌بندی" />
    <v-dialog v-model="dialog" max-width="540" :persistent="busy"><v-card><v-card-title>{{ current ? 'ویرایش' : 'افزودن' }} {{ noun }}</v-card-title><v-form @submit.prevent="save"><v-card-text><v-alert v-if="notice" type="error" variant="tonal" class="mb-4">{{ notice }}</v-alert><v-text-field v-model="title" label="عنوان" variant="outlined" maxlength="255" required autofocus /><v-text-field v-if="kind === 'roles' && !current" v-model="roleKey" label="کلید یکتای نقش (حروف لاتین)" hint="مثال: personnel-manager" persistent-hint variant="outlined" required /><AdminRemoteChoice v-if="kind === 'positions'" v-model="parent" :endpoint="`companies/${company}/positions`" label="پوزیشن بالادست (برای ریشه خالی بماند)" /><p v-if="kind === 'positions'" class="admin-help">با تعیین بالادست، همه افراد این پوزیشن باید مدیر گزارش‌دهی داشته باشند. مدیر گزارش‌دهی جداگانه انتخاب می‌شود.</p></v-card-text><v-card-actions><v-btn :disabled="busy" @click="dialog = false">انصراف</v-btn><v-spacer /><v-btn type="submit" color="primary" variant="flat" :loading="busy" :disabled="!title.trim()">ذخیره</v-btn></v-card-actions></v-form></v-card></v-dialog>
    <v-dialog :model-value="!!deleting" max-width="440" @update:model-value="!$event && (deleting = null)"><v-card title="حذف رکورد"><v-card-text>«{{ deleting?.title }}» حذف شود؟ اگر اطلاعات وابسته داشته باشد، ابتدا باید وابستگی‌ها را برطرف کنید.</v-card-text><v-card-actions><v-btn :disabled="busy" @click="deleting = null">انصراف</v-btn><v-spacer /><v-btn color="error" :loading="busy" @click="remove">حذف</v-btn></v-card-actions></v-card></v-dialog>
  </section>
</template>
