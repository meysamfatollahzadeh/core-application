<script setup lang="ts">
import type { DirectoryItem } from '~/composables/useManagement'
type Profile = { uuid: string; username: string | null; first_name: string | null; last_name: string | null; email: string | null; enabled: boolean }
type Connection = { id: number; uuid: string; source: { slug: string; title: string; is_active: boolean }; profile: Profile | null; profile_state: string }
type Source = { slug: string; title: string; can_search: boolean }
type Account = Profile & { can_connect: boolean; reasons: string[]; owner: { id: number; name: string } | null }
type SearchPage = { data: Account[]; page: number; per_page: number; has_more: boolean }
const props = defineProps<{ user: DirectoryItem }>()
const emit = defineEmits<{ close: [] }>()
const api = useManagement()
const login = useLogin()
const base = `personnel/${props.user.id}`
const connections = ref<Connection[]>([])
const sources = ref<Source[]>([])
const loading = ref(true)
const loadError = ref('')
const notice = ref('')
const busy = ref(false)
const sourceSlug = ref<string | null>(null)
const searchInput = ref('')
const searching = ref(false)
const results = ref<SearchPage | null>(null)
const selected = ref<string | null>(null)
const deleting = ref<Connection | null>(null)
const source = computed(() => sources.value.find(item => item.slug === sourceSlug.value))
const selectedAccount = computed(() => results.value?.data.find(item => item.uuid === selected.value && item.can_connect))
let loadGeneration = 0
let searchGeneration = 0
function resetSearch() { searchGeneration++; searching.value = false; results.value = null; selected.value = null }
watch([sourceSlug, searchInput], resetSearch)
function close() { if (!busy.value) emit('close') }
function fullName(profile: Profile) { return [profile.first_name, profile.last_name].filter(Boolean).join(' ') || 'نام ثبت نشده' }
async function load() {
  const generation = ++loadGeneration
  loading.value = true
  loadError.value = ''
  try {
    const [members, available] = await Promise.all([
      api.get<{ data: Connection[] }>(`${base}/members`),
      api.get<{ data: Source[] }>(`${base}/member-sources`),
    ])
    if (generation !== loadGeneration) return
    connections.value = members.data
    sources.value = available.data
    if (sourceSlug.value && !sources.value.some(item => item.slug === sourceSlug.value)) sourceSlug.value = null
  } catch (error) { if (generation === loadGeneration) loadError.value = managementError(error) }
  finally { if (generation === loadGeneration) loading.value = false }
}
async function search(page = 1) {
  if (busy.value || searching.value || !source.value?.can_search || !searchInput.value.trim()) return
  const generation = ++searchGeneration
  searching.value = true
  notice.value = ''
  selected.value = null
  try {
    const response = await api.get<SearchPage>(`${base}/member-sources/${sourceSlug.value}/accounts`, { search: searchInput.value.trim(), page, per_page: 10 })
    if (generation === searchGeneration) results.value = response
  } catch (error) {
    if (generation === searchGeneration) { notice.value = managementError(error); results.value = null; await load() }
  } finally { if (generation === searchGeneration) searching.value = false }
}
async function attach() {
  if (busy.value || !source.value?.can_search || !selectedAccount.value) return
  busy.value = true
  notice.value = ''
  try {
    await api.mutate(`${base}/members`, 'POST', { source_slug: sourceSlug.value, uuid: selectedAccount.value.uuid })
    resetSearch()
    await load()
  } catch (error) { notice.value = managementError(error); resetSearch(); await load() }
  finally { busy.value = false }
}
async function detach() {
  if (busy.value || !deleting.value) return
  const member = deleting.value
  const self = login.state.value.identity?.member.id === member.id
  busy.value = true
  notice.value = ''
  try {
    await api.mutate(`${base}/members/${member.id}`, 'DELETE')
    deleting.value = null
    resetSearch()
    if (self) { await navigateTo('/', { external: true, replace: true }); return }
    await load()
  } catch (error) { notice.value = managementError(error); deleting.value = null }
  finally { busy.value = false }
}
onMounted(load)
onBeforeUnmount(() => { loadGeneration++; searchGeneration++ })
</script>

<template>
  <v-dialog :model-value="true" max-width="820" scrollable @keydown.esc="!deleting && close()" @update:model-value="!$event && close()">
    <v-card class="member-dialog" :title="`حساب‌های ورود — ${user.name}`">
      <v-card-text>
        <p class="admin-help mb-5">حساب‌های ورود این فرد بین همه شرکت‌ها مشترک است. از هر منبع فقط یک حساب قابل اتصال است.</p>
        <v-alert v-if="notice" type="error" variant="tonal" class="mb-4" role="alert">{{ notice }}</v-alert>
        <v-alert v-if="loadError" type="error" variant="tonal" class="mb-4">{{ loadError }}<v-btn variant="text" :loading="loading" @click="load">تلاش دوباره</v-btn></v-alert>
        <v-progress-linear v-if="loading" indeterminate aria-label="دریافت حساب‌های ورود" class="mb-4" />
        <section aria-labelledby="connected-accounts-title">
          <h3 id="connected-accounts-title" class="member-section-title">حساب‌های متصل</h3>
          <p v-if="!loading && !loadError && !connections.length" class="admin-muted mb-5">هنوز حسابی متصل نیست. اطلاعات پرسنلی بدون حساب ورود هم باقی می‌ماند.</p>
          <div class="member-account-list">
            <article v-for="member in connections" :key="member.id" class="member-account-row">
              <div class="member-account-details">
                <strong>{{ member.source.title }}</strong>
                <v-chip v-if="!member.source.is_active" size="small" color="warning" variant="tonal" class="ms-2">منبع غیرفعال</v-chip>
                <template v-if="member.profile">
                  <p>{{ fullName(member.profile) }} · <bdi>{{ member.profile.username || 'نام کاربری ثبت نشده' }}</bdi></p>
                  <p v-if="member.profile.email" class="admin-muted"><bdi>{{ member.profile.email }}</bdi></p>
                  <p v-if="!member.profile.enabled" class="admin-muted">حساب در منبع غیرفعال است.</p>
                </template>
                <p v-else class="admin-muted">{{ member.profile_state === 'account_missing' ? 'این حساب در منبع پیدا نشد.' : 'اطلاعات حساب از منبع در دسترس نیست؛ حذف اتصال امکان‌پذیر است.' }}</p>
                <p class="member-subject admin-muted">شناسه حساب: <bdi>{{ member.uuid }}</bdi></p>
              </div>
              <v-btn color="error" variant="text" size="small" :disabled="busy || loading" :aria-label="`حذف اتصال ${member.source.title}`" @click="deleting = member">حذف اتصال</v-btn>
            </article>
          </div>
        </section>
        <v-divider class="my-6" />
        <section aria-labelledby="connect-account-title">
          <h3 id="connect-account-title" class="member-section-title">اتصال حساب موجود</h3>
          <v-select v-model="sourceSlug" :items="sources" item-title="title" item-value="slug" label="منبع کاربران" variant="outlined" :disabled="busy || loading || !!loadError" no-data-text="منبع فعالی وجود ندارد." clearable />
          <v-alert v-if="source && !source.can_search" type="info" variant="tonal" class="mb-4">این فرد از این منبع حساب متصل دارد. برای جست‌وجوی حساب دیگر، ابتدا اتصال فعلی را حذف کنید.</v-alert>
          <v-form class="member-search" @submit.prevent="search()">
            <v-text-field v-model="searchInput" label="نام کاربری، نام و نام خانوادگی یا ایمیل" variant="outlined" maxlength="100" :disabled="busy || loading || !source?.can_search" hide-details />
            <v-btn type="submit" color="info" variant="tonal" :loading="searching" :disabled="busy || loading || !source?.can_search || !searchInput.trim()">جست‌وجو در منبع</v-btn>
          </v-form>
          <div v-if="results" aria-live="polite" class="mt-4">
            <p v-if="!results.data.length" class="admin-muted">در این صفحه حسابی برای نمایش پیدا نشد.</p>
            <v-radio-group v-model="selected" :disabled="busy || searching" hide-details>
              <article v-for="account in results.data" :key="account.uuid" class="member-account-row">
                <div class="member-account-details">
                  <strong>{{ fullName(account) }}</strong>
                  <p><bdi>{{ account.username || 'نام کاربری ثبت نشده' }}</bdi></p>
                  <p v-if="account.email" class="admin-muted"><bdi>{{ account.email }}</bdi></p>
                  <p v-if="account.owner" class="member-owner">متصل به: {{ account.owner.name }}</p>
                  <p v-if="!account.enabled" class="admin-muted">حساب غیرفعال؛ قابل اتصال نیست.</p>
                </div>
                <v-radio :value="account.uuid" :disabled="!account.can_connect" :label="account.can_connect ? 'انتخاب' : 'غیرقابل انتخاب'" :aria-label="`انتخاب حساب ${account.username || fullName(account)}`" />
              </article>
            </v-radio-group>
            <div class="member-pagination mt-3">
              <v-btn variant="text" :disabled="busy || searching || results.page <= 1" @click="search(results.page - 1)">صفحه قبل</v-btn>
              <span class="admin-muted">صفحه {{ results.page.toLocaleString('fa-IR') }}</span>
              <v-btn variant="text" :disabled="busy || searching || !results.has_more" @click="search(results.page + 1)">صفحه بعد</v-btn>
            </div>
            <v-btn v-if="selectedAccount" color="primary" variant="flat" class="mt-4" :loading="busy" :disabled="searching || loading" @click="attach">اتصال حساب انتخاب‌شده</v-btn>
          </div>
        </section>
      </v-card-text>
      <v-card-actions><v-spacer /><v-btn :disabled="busy" @click="close">بستن</v-btn></v-card-actions>
    </v-card>
    <v-dialog :model-value="!!deleting" max-width="480" @keydown.esc.stop="!busy && (deleting = null)" @update:model-value="!$event && !busy && (deleting = null)">
      <v-card class="member-dialog" title="حذف اتصال حساب">
        <v-card-text><p class="admin-help">اتصال حساب از منبع «{{ deleting?.source.title }}» حذف و تمام ورودهای فعال مربوط به همین اتصال در سامانه لغو می‌شود. حساب Keycloak و اطلاعات پرسنلی باقی می‌ماند.</p><p v-if="deleting?.id === login.state.value.identity?.member.id" class="admin-help mt-3">با حذف این اتصال، از مرورگر فعلی هم خارج می‌شوید.</p></v-card-text>
        <v-card-actions><v-btn :disabled="busy" @click="deleting = null">انصراف</v-btn><v-spacer /><v-btn color="error" variant="flat" :loading="busy" @click="detach">حذف اتصال</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </v-dialog>
</template>
