<script setup lang="ts" generic="T extends number | number[] | null">
import type { DirectoryItem, DirectoryPage } from '~/composables/useManagement'
const props = withDefaults(defineProps<{ endpoint: string; label: string; multiple?: boolean; disabled?: boolean; hierarchy?: boolean; showIdentifier?: boolean }>(), { multiple: false, disabled: false, hierarchy: false, showIdentifier: true })
const model = defineModel<T>({ required: true })
const emit = defineEmits<{ 'selected-path': [path: string | null]; 'selected-item': [item: DirectoryItem | null] }>()
const api = useManagement()
const items = ref<{ title: string; value: number; path?: string; record: DirectoryItem }[]>([])
const search = ref('')
const page = ref(1)
const last = ref(1)
const busy = ref(false)
const error = ref('')
let timer: ReturnType<typeof setTimeout> | undefined
let generation = 0
function itemLabel(item: DirectoryItem) {
  const prefix = item.company_title ?? item.building_title
  const title = item.position_path ?? (prefix ? `${prefix} / ${item.title}` : (item.title ?? item.name ?? ''))
  if (!props.showIdentifier) return String(title)
  const identifier = item.key ? String(item.key) : `شناسه ${item.id.toLocaleString('fa-IR')}`
  return `${title} (${identifier})`
}
async function load(append = false) {
  if (!props.endpoint || props.disabled) return
  const current = ++generation
  busy.value = true
  try {
    const selectedIds = Array.isArray(model.value) ? model.value : model.value ? [model.value] : []
    const result = await api.get<DirectoryPage>(props.endpoint, { page: page.value, search: search.value, ...(selectedIds.length ? { selected: selectedIds.join(',') } : {}) })
    if (current !== generation) return
    const next = result.data.map(item => ({ title: itemLabel(item), value: item.id, path: typeof item.position_path === 'string' ? item.position_path : undefined, record: item }))
    const selected = new Set(Array.isArray(model.value) ? model.value : model.value ? [model.value] : [])
    const existing = append ? items.value : items.value.filter(item => selected.has(item.value))
    items.value = [...new Map([...existing, ...next].map(item => [item.value, item])).values()]
    last.value = result.meta?.last_page ?? result.last_page ?? 1
    error.value = ''
  } catch (e) { if (current === generation) error.value = managementError(e) }
  finally { if (current === generation) busy.value = false }
}
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => { page.value = 1; load() }, 300) })
watch(() => props.endpoint, () => { page.value = 1; items.value = []; load() })
watch(() => model.value, value => {
  const ids = Array.isArray(value) ? value : value ? [value] : []
  if (ids.some(id => !items.value.some(item => item.value === id))) { page.value = 1; load() }
}, { deep: true })
watchEffect(() => emit('selected-path', items.value.find(item => item.value === model.value)?.path ?? null))
watchEffect(() => emit('selected-item', items.value.find(item => item.value === model.value)?.record ?? null))
onMounted(() => load())
onBeforeUnmount(() => clearTimeout(timer))
</script>
<template>
  <v-autocomplete v-model="model" v-model:search="search" :label="label" :items="items" :multiple="multiple" :chips="multiple" :closable-chips="multiple" :disabled="disabled" :loading="busy" :error-messages="error" :class="{ 'position-choice': hierarchy }" :list-props="{ class: hierarchy ? 'position-choice-menu' : undefined }" item-title="title" item-value="value" no-filter clearable no-data-text="نتیجه‌ای پیدا نشد؛ عنوان یا نام دیگری جست‌وجو کنید." variant="outlined">
    <template v-if="hierarchy && !multiple" #selection="{ item }"><span class="position-choice-selection">{{ item.title }}</span></template>
    <template #append-item><v-btn v-if="page < last" block variant="text" :loading="busy" @click.stop="page++; load(true)">نمایش موارد بیشتر</v-btn></template>
  </v-autocomplete>
</template>
