<script setup lang="ts">
import { mdiHomeOutline, mdiViewDashboardOutline, mdiClose } from '@mdi/js'
const emit = defineEmits<{ close: [] }>()
const { groups } = useAdminSections()
const route = useRoute()
const currentGroup = computed(() => groups.value.find(group => group.children.some(child => child.key === route.params.section))?.key)
const expanded = ref<string[]>(currentGroup.value ? [currentGroup.value] : [])
watch(currentGroup, group => { if (group && !expanded.value.includes(group)) expanded.value.push(group) })
</script>

<template>
  <div class="admin-drawer-heading">
    <div><span class="admin-drawer-eyebrow">فضای مدیریت</span><h2>سامانه سازمان</h2></div>
    <v-btn class="admin-mobile-control" :icon="mdiClose" variant="text" aria-label="بستن منوی مدیریت" @click="emit('close')" />
  </div>
  <v-list v-model:opened="expanded" nav class="admin-menu" aria-label="منوی اصلی مدیریت" open-strategy="multiple">
    <v-list-item to="/admin" exact :prepend-icon="mdiViewDashboardOutline" title="پیشخوان مدیریت" class="admin-dashboard-link" />
    <v-list-group v-for="group in groups" :key="group.key" :value="group.key">
      <template #activator="{ props }"><v-list-item v-bind="props" role="button" :prepend-icon="group.icon" :title="group.title" /></template>
      <v-list-item v-for="item in group.children" :key="item.key" :to="`/admin/${item.key}`" :prepend-icon="item.icon" :title="item.title" :active="route.params.section === item.key" class="admin-submenu-item" />
    </v-list-group>
  </v-list>
  <div class="admin-drawer-footer"><v-divider class="mb-3" /><v-btn to="/" block variant="text" :prepend-icon="mdiHomeOutline">فضای کاری سازمان</v-btn><p>دسترسی یکپارچه سازمانی</p></div>
</template>
