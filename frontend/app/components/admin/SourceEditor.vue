<script setup lang="ts">
import type { ManagedLoginSource } from '#shared/types/login-sources'
const props = defineProps<{ source: ManagedLoginSource | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const api = useManagement()
const login = useLogin()
const title = ref(props.source?.title ?? '')
const slug = ref(props.source?.slug ?? '')
const issuer = ref(props.source?.data.issuer ?? '')
const internalIssuer = ref(props.source?.data.internal_issuer ?? '')
const clientId = ref(props.source?.data.client_id ?? '')
const clientSecret = ref('')
const directory = ref(!!props.source?.data.import_client_id)
const importClientId = ref(props.source?.data.import_client_id ?? '')
const importClientSecret = ref('')
const adminBaseUrl = ref(props.source?.data.admin_base_url ?? '')
const timeout = ref(props.source?.data.timeout ?? 10)
const pageSize = ref(props.source?.data.page_size ?? 100)
const busy = ref(false)
const notice = ref('')
const errors = ref<Record<string, string[]>>({})
const confirm = ref(false)
watch(() => props.source, source => {
  title.value = source?.title ?? ''; slug.value = source?.slug ?? ''
  issuer.value = source?.data.issuer ?? ''; internalIssuer.value = source?.data.internal_issuer ?? ''
  clientId.value = source?.data.client_id ?? ''; clientSecret.value = ''
  directory.value = !!source?.data.import_client_id; importClientId.value = source?.data.import_client_id ?? ''
  importClientSecret.value = ''; adminBaseUrl.value = source?.data.admin_base_url ?? ''
  timeout.value = source?.data.timeout ?? 10; pageSize.value = source?.data.page_size ?? 100
  errors.value = {}; notice.value = ''; confirm.value = false
}, { immediate: true })
const gateway = useRequestURL().origin
const callback = computed(() => `${gateway}/login/callback/${slug.value || 'source-slug'}`)
const securityChanged = computed(() => !!props.source && (
  issuer.value !== props.source.data.issuer || internalIssuer.value !== props.source.data.internal_issuer ||
  clientId.value !== props.source.data.client_id || !!clientSecret.value.trim()
))
const valid = computed(() => !!title.value.trim() && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug.value) &&
  !!issuer.value.trim() && !!internalIssuer.value.trim() && !!clientId.value.trim() &&
  (!!props.source?.client_secret_configured || !!clientSecret.value.trim()) &&
  Number(timeout.value) >= 1 && Number(timeout.value) <= 30 && Number(pageSize.value) >= 1 && Number(pageSize.value) <= 500 &&
  (!directory.value || (!!importClientId.value.trim() && !!adminBaseUrl.value.trim() && (!!props.source?.import_client_secret_configured || !!importClientSecret.value.trim()))))
function fieldError(key: string): string[] {
  if (!errors.value[key]) return []
  if (key === 'data.issuer' && errors.value[key]?.some(message => message.includes('connected accounts'))) return ['این سورس حساب متصل دارد. برای تغییر صادرکننده، سورس جدید بسازید.']
  if (errors.value[key]?.some(message => message.includes('origin is not allowed'))) return ['این آدرس در فهرست مبداهای مجاز نیست؛ از مسئول استقرار بخواهید آن را بررسی کند.']
  return ['مقدار این فیلد معتبر نیست؛ آدرس، قالب و حدود مجاز را بررسی کنید.']
}
function submit() { if (valid.value && !busy.value) { if (securityChanged.value) confirm.value = true; else void save() } }
async function save() {
  if (!valid.value || busy.value) return
  busy.value = true; notice.value = ''; errors.value = {}
  try {
    const data: Record<string, unknown> = {
      issuer: issuer.value.trim(), internal_issuer: internalIssuer.value.trim(), client_id: clientId.value.trim(),
      timeout: Number(timeout.value), page_size: Number(pageSize.value), allowed_algorithms: ['RS256'],
    }
    if (clientSecret.value.trim()) data.client_secret = clientSecret.value
    if (directory.value) {
      data.import_client_id = importClientId.value.trim(); data.admin_base_url = adminBaseUrl.value.trim()
      if (importClientSecret.value.trim()) data.import_client_secret = importClientSecret.value
    }
    const body: Record<string, unknown> = { title: title.value.trim(), data }
    if (!props.source) body.slug = slug.value
    else body.remove_directory = !directory.value
    await api.mutate('login-sources' + (props.source ? '/' + props.source.slug : ''), props.source ? 'PATCH' : 'POST', body)
    clientSecret.value = ''; importClientSecret.value = ''; confirm.value = false
    await login.refresh()
    emit('saved')
    if (login.state.value.status === 'anonymous') await navigateTo('/')
  } catch (error) {
    const payload = (error as { data?: { data?: { errors?: Record<string, string[]> }; errors?: Record<string, string[]> } }).data
    errors.value = payload?.data?.errors ?? payload?.errors ?? {}
    notice.value = managementError(error); confirm.value = false
  } finally { busy.value = false }
}
</script>
<template>
  <v-dialog :model-value="true" :persistent="busy" max-width="850" @keydown.esc="!busy && emit('close')" @update:model-value="!$event && !busy && emit('close')">
    <v-card :title="source ? `ویرایش ${source.title}` : 'افزودن سورس ورود'">
      <v-form @submit.prevent="submit">
        <v-card-text>
          <v-alert v-if="notice" type="error" variant="tonal" class="mb-4" role="alert">{{ notice }}</v-alert>
          <p class="admin-help mb-5">هر سورس به یک اتصال مستقل Keycloak اشاره می‌کند. اطلاعات کاربران و رمز ورود آن‌ها در Keycloak مدیریت می‌شود.</p>
          <div class="source-form-grid">
            <v-text-field v-model="title" label="عنوان نمایشی" variant="outlined" maxlength="255" required autofocus :disabled="busy" :error-messages="fieldError('title')" />
            <v-text-field v-model="slug" label="شناسه یکتا (slug)" dir="ltr" variant="outlined" maxlength="63" :readonly="!!source" :disabled="busy" :hint="source ? 'شناسه پس از ایجاد تغییر نمی‌کند.' : 'حروف کوچک لاتین، عدد و خط تیره؛ مانند head-office'" persistent-hint required :error-messages="fieldError('slug')" />
            <v-text-field v-model="issuer" label="آدرس صادرکننده عمومی (Issuer)" dir="ltr" variant="outlined" :readonly="!!source?.members_count" :disabled="busy" :hint="source?.members_count ? 'این سورس حساب متصل دارد؛ صادرکننده آن ثابت است.' : 'آدرس کامل realm در Keycloak'" persistent-hint required :error-messages="fieldError('data.issuer')" class="source-form-wide" />
            <v-text-field v-model="internalIssuer" label="آدرس داخلی همان صادرکننده" dir="ltr" variant="outlined" :disabled="busy" hint="آدرسی که سرور برای ارتباط با همان realm استفاده می‌کند." persistent-hint required :error-messages="fieldError('data.internal_issuer')" class="source-form-wide" />
            <v-text-field v-model="clientId" label="شناسه کلاینت ورود (Client ID)" dir="ltr" variant="outlined" :disabled="busy" required :error-messages="fieldError('data.client_id')" />
            <v-text-field v-model="clientSecret" label="رمز کلاینت ورود (Client Secret)" dir="ltr" type="password" autocomplete="new-password" variant="outlined" :disabled="busy" :hint="source?.client_secret_configured ? 'رمز ذخیره شده است؛ برای حفظ آن خالی بگذارید.' : 'رمز کلاینت الزامی است.'" persistent-hint :required="!source?.client_secret_configured" :error-messages="fieldError('data.client_secret')" />
          </div>
          <div class="source-redirects mb-5"><strong>آدرس‌های بازگشت سامانه</strong><span>بازگشت ورود</span><bdi>{{ source?.data.callback_url ?? callback }}</bdi><span>بازگشت خروج</span><bdi>{{ source?.data.post_logout_redirect_uri ?? `${gateway}/login/logged-out` }}</bdi></div>
          <v-divider class="mb-4" />
          <v-switch v-model="directory" label="جست‌وجو و اتصال حساب‌های پرسنل" color="primary" :disabled="busy" hide-details />
          <p class="admin-help mb-4">برای جست‌وجو و واردکردن کاربران، کلاینتی با دسترسی خواندن کاربران همان realm لازم است. خاموش‌کردن این گزینه اتصال‌های قبلی را حذف نمی‌کند.</p>
          <div v-if="directory" class="source-form-grid">
            <v-text-field v-model="importClientId" label="شناسه کلاینت خواندن کاربران" dir="ltr" variant="outlined" required :disabled="busy" :error-messages="fieldError('data.import_client_id')" />
            <v-text-field v-model="importClientSecret" label="رمز کلاینت خواندن کاربران" dir="ltr" type="password" autocomplete="new-password" variant="outlined" :disabled="busy" :hint="source?.import_client_secret_configured ? 'رمز ذخیره شده است؛ برای حفظ آن خالی بگذارید.' : 'رمز کلاینت خواندن کاربران را وارد کنید.'" persistent-hint :error-messages="fieldError('data.import_client_secret')" />
            <v-text-field v-model="adminBaseUrl" label="آدرس API مدیریت همان realm" dir="ltr" variant="outlined" :disabled="busy" hint="نمونه: http://keycloak:8080/auth/admin/realms/organization-dev" persistent-hint required :error-messages="fieldError('data.admin_base_url')" class="source-form-wide" />
          </div>
          <div class="source-form-grid mt-4">
            <v-text-field v-model="timeout" label="مهلت پاسخ سرویس (ثانیه)" type="number" min="1" max="30" variant="outlined" :disabled="busy" :error-messages="fieldError('data.timeout')" />
            <v-text-field v-model="pageSize" label="تعداد کاربران در هر صفحه واردسازی" type="number" min="1" max="500" variant="outlined" :disabled="busy" :error-messages="fieldError('data.page_size')" />
          </div>
          <p v-if="!source" class="admin-help">سورس جدید غیرفعال ساخته می‌شود. پس از بررسی تنظیمات، آن را از فهرست فعال کنید.</p>
          <v-alert v-if="source?.data.import_client_id && !directory" type="warning" variant="tonal">با ذخیره، تنظیمات و رمز دسترسی خواندن کاربران از این سورس حذف می‌شود.</v-alert>
        </v-card-text>
        <v-card-actions><v-btn :disabled="busy" @click="emit('close')">انصراف</v-btn><v-spacer /><v-btn type="submit" color="primary" variant="flat" :disabled="!valid" :loading="busy">{{ source ? 'ذخیره تغییرات' : 'ایجاد سورس' }}</v-btn></v-card-actions>
      </v-form>
    </v-card>
    <v-dialog v-model="confirm" :persistent="busy" max-width="490"><v-card title="تغییر تنظیمات ورود"><v-card-text>این تغییر، توکن‌های ورود این سورس را باطل می‌کند و کاربران باید دوباره وارد شوند. اگر با همین سورس وارد شده‌اید، دسترسی فعلی شما نیز قطع می‌شود.</v-card-text><v-card-actions><v-btn :disabled="busy" @click="confirm = false">بازگشت به فرم</v-btn><v-spacer /><v-btn color="warning" variant="flat" :loading="busy" @click="save">تأیید و ذخیره</v-btn></v-card-actions></v-card></v-dialog>
  </v-dialog>
</template>
