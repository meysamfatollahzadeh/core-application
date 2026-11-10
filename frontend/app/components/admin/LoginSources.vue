<script setup lang="ts">
import type { LoginSourcePage, LoginSourceSummary, ManagedLoginSource } from '#shared/types/login-sources'
const api = useManagement()
const { can } = useAccess()
const login = useLogin()
const page = ref(1)
const search = ref('')
const query = ref('')
const key = computed(() => `login-sources:${page.value}:${query.value}`)
const { data, error, status, refresh } = await useAsyncData(key, () => api.get<LoginSourcePage>('login-sources', { page: page.value, search: query.value, per_page: 25 }))
const editing = ref(false)
const current = ref<ManagedLoginSource | null>(null)
const loadingSlug = ref('')
const notice = ref('')
const success = ref('')
const busy = ref(false)
const pending = ref<{ action: 'activity' | 'delete'; source: LoginSourceSummary } | null>(null)
async function open(source?: LoginSourceSummary) {
  notice.value = ''; success.value = ''; current.value = null
  if (!source) { editing.value = true; return }
  loadingSlug.value = source.slug
  try { current.value = (await api.get<{ data: ManagedLoginSource }>('login-sources/' + source.slug)).data; editing.value = true }
  catch (error) { notice.value = managementError(error) }
  finally { loadingSlug.value = '' }
}
function request(action: 'activity' | 'delete', source: LoginSourceSummary) { notice.value = ''; success.value = ''; pending.value = { action, source } }
async function apply() {
  if (!pending.value || busy.value) return
  busy.value = true
  const { action, source } = pending.value
  try {
    if (action === 'delete') await api.mutate('login-sources/' + source.slug, 'DELETE')
    else await api.mutate('login-sources/' + source.slug + '/activity', 'PATCH', { is_active: !source.is_active })
    pending.value = null
    await login.refresh()
    if (login.state.value.status === 'anonymous') { await navigateTo('/'); return }
    success.value = action === 'delete' ? 'سورس حذف شد.' : source.is_active ? 'سورس غیرفعال شد.' : 'سورس فعال شد.'
    await refresh()
  } catch (error) { notice.value = managementError(error); pending.value = null }
  finally { busy.value = false }
}
async function saved() { editing.value = false; current.value = null; if (login.state.value.status === 'authenticated') { success.value = 'تنظیمات سورس ذخیره شد.'; await refresh() } }
function closeEditor() { editing.value = false; current.value = null }
function submitSearch() { page.value = 1; query.value = search.value ?? '' }
</script>
<template>
  <section>
    <div class="admin-section-heading"><div><h2>فهرست سورس‌های ورود</h2><p class="admin-muted">{{ (data?.meta.total ?? 0).toLocaleString('fa-IR') }} سورس ثبت‌شده</p></div><v-btn v-if="can('login_sources.create')" color="primary" @click="open()">افزودن سورس</v-btn></div>
    <v-alert type="info" variant="tonal" class="mb-5">غیرفعال‌کردن سورس، ورودهای فعلی آن را قطع می‌کند. حساب‌های متصل و اطلاعات پرسنل حفظ می‌شوند.</v-alert>
    <v-form class="admin-search" @submit.prevent="submitSearch"><v-text-field v-model="search" label="جست‌وجوی عنوان یا شناسه" variant="outlined" density="compact" clearable hide-details /><v-btn type="submit" color="info" variant="tonal">جست‌وجو</v-btn></v-form>
    <v-alert v-if="error || notice" type="error" variant="tonal" class="mb-4" role="alert">{{ notice || managementError(error) }}<v-btn variant="text" @click="refresh()">تلاش دوباره</v-btn></v-alert>
    <v-alert v-if="success" type="success" variant="tonal" class="mb-4" role="status">{{ success }}</v-alert>
    <v-progress-linear v-if="status === 'pending'" indeterminate color="primary" aria-label="دریافت سورس‌ها" />
    <v-table class="admin-table"><thead><tr><th>عنوان و شناسه</th><th>وضعیت</th><th>حساب‌های متصل</th><th>عملیات</th></tr></thead><tbody>
      <tr v-for="source in data?.data ?? []" :key="source.slug">
        <td><div class="source-identity"><strong>{{ source.title }}</strong><bdi class="admin-muted">{{ source.slug }}</bdi></div></td>
        <td><v-chip :color="source.is_active ? 'success' : undefined" variant="tonal" size="small">{{ source.is_active ? 'فعال' : 'غیرفعال' }}</v-chip></td>
        <td>{{ source.members_count.toLocaleString('fa-IR') }}</td>
        <td><div class="admin-row-actions"><v-btn v-if="can('login_sources.update')" size="small" color="warning" variant="tonal" :loading="loadingSlug === source.slug" :disabled="!!loadingSlug || busy" @click="open(source)">ویرایش</v-btn><v-btn v-if="can('login_sources.activate')" size="small" :color="source.is_active ? 'warning' : 'success'" variant="tonal" :disabled="busy" @click="request('activity', source)">{{ source.is_active ? 'غیرفعال‌کردن' : 'فعال‌کردن' }}</v-btn><v-btn v-if="can('login_sources.delete')" size="small" color="error" variant="tonal" :disabled="busy" @click="request('delete', source)">حذف</v-btn></div></td>
      </tr>
      <tr v-if="!data?.data.length && status !== 'pending'"><td colspan="4" class="admin-empty">{{ query ? 'سورسی با این عنوان یا شناسه پیدا نشد.' : 'هنوز سورسی ثبت نشده است. برای شروع، سورس ورود اضافه کنید.' }}</td></tr>
    </tbody></v-table>
    <v-pagination v-if="(data?.meta.last_page ?? 1) > 1" v-model="page" :length="data?.meta.last_page ?? 1" :total-visible="5" aria-label="صفحه‌بندی سورس‌ها" />
    <AdminSourceEditor v-if="editing" :source="current" @close="closeEditor" @saved="saved" />
    <v-dialog :model-value="!!pending" :persistent="busy" max-width="520" @keydown.esc="!busy && (pending = null)" @update:model-value="!$event && !busy && (pending = null)"><v-card :title="pending?.action === 'delete' ? 'حذف سورس ورود' : pending?.source.is_active ? 'غیرفعال‌کردن سورس' : 'فعال‌کردن سورس'"><v-card-text>
      <p class="mb-4">{{ pending?.source.title }} <bdi class="admin-muted">({{ pending?.source.slug }})</bdi></p>
      <template v-if="pending?.action === 'delete'"><v-alert v-if="pending.source.members_count" type="warning" variant="tonal">این سورس {{ pending.source.members_count.toLocaleString('fa-IR') }} حساب متصل دارد و قابل حذف نیست. برای بستن ورود، آن را غیرفعال کنید.</v-alert><p v-else>این سورس از سامانه حذف شود؟ این عملیات هیچ حسابی را در Keycloak حذف نمی‌کند.</p></template>
      <p v-else-if="pending?.source.is_active">توکن‌های ورود این سورس باطل می‌شوند و ورود جدید متوقف می‌شود. اگر با همین سورس وارد شده‌اید، از سامانه خارج می‌شوید. اگر سورس فعال دیگری نماند، بازیابی دسترسی به مسئول استقرار نیاز دارد.</p>
      <p v-else>این سورس برای ورود و اتصال حساب‌های جدید فعال شود؟ پیش از تأیید، از درستی تنظیمات آن مطمئن شوید.</p>
    </v-card-text><v-card-actions><v-btn :disabled="busy" @click="pending = null">انصراف</v-btn><v-spacer /><v-btn v-if="pending?.action !== 'delete' || !pending?.source.members_count" :color="pending?.action === 'delete' ? 'error' : pending?.source.is_active ? 'warning' : 'success'" variant="flat" :loading="busy" @click="apply">{{ pending?.action === 'delete' ? 'حذف سورس' : pending?.source.is_active ? 'تأیید و غیرفعال‌کردن' : 'فعال‌کردن' }}</v-btn></v-card-actions></v-card></v-dialog>
  </section>
</template>
