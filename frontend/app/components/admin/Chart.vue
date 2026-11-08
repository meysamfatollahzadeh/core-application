<script setup lang="ts">
import type { DirectoryPage } from '~/composables/useManagement'
import type { ChartNode } from './PositionTree.vue'
const props = defineProps<{ company: number }>()
const api = useManagement()
const { data, error } = await useAsyncData(`chart:${props.company}`, async () => {
  const rows = []
  let page = 1; let last = 1
  do { const result = await api.get<DirectoryPage>(`companies/${props.company}/positions`, { page, per_page: 100 }); rows.push(...result.data); last = result.meta?.last_page ?? 1; page++ } while (page <= last)
  const nodes = new Map<number, ChartNode>(rows.map(row => [row.id, { id: row.id, title: row.title ?? '', children: [] }]))
  const roots: ChartNode[] = []
  for (const row of rows) { const node = nodes.get(row.id)!; const parent = nodes.get(row.parent_position_id as number); if (parent) parent.children.push(node); else roots.push(node) }
  return roots
})
</script>
<template><v-expansion-panels class="mb-6" variant="accordion"><v-expansion-panel title="نمایش چارت سازمانی"><v-expansion-panel-text><v-alert v-if="error" type="error" variant="tonal">{{ managementError(error) }}</v-alert><AdminPositionTree v-else-if="data?.length" :nodes="data" /><p v-else class="admin-muted">چارت با ایجاد اولین پوزیشن ساخته می‌شود.</p></v-expansion-panel-text></v-expansion-panel></v-expansion-panels></template>
