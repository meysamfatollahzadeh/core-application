<script setup lang="ts">
import type { DirectoryItem } from '~/composables/useManagement'
definePageMeta({ middleware: 'management' })
const route = useRoute()
const router = useRouter()
const { state: access, can } = useAccess()
const { sections, available } = useAdminSections()
const section = computed(() => sections.find(item => item.key === route.params.section)!)
const eligible = computed(() => access.value.visible_companies.filter(company => can(section.value.permission, company.id)))
const company = computed<number | null>(() => route.query.company ? Number(route.query.company) : null)
const requiresCompany = computed(() => ['positions', 'assignments'].includes(section.value.key))
const effectiveCompany = computed(() => company.value ?? (requiresCompany.value || !can(section.value.permission) ? eligible.value[0]?.id ?? null : null))
const building = ref<DirectoryItem | null>(null)
const userRoles = ref<DirectoryItem | null>(null)
const positionRoles = ref<DirectoryItem | null>(null)
const permissionRole = ref<DirectoryItem | null>(null)
const chartKey = ref(0)
const companyChoices = computed(() => !requiresCompany.value && can(section.value.permission) ? [{ id: 0, title: 'سراسری (همه شرکت‌ها)' }, ...eligible.value] : eligible.value)
const selector = computed({ get: () => effectiveCompany.value ?? 0, set: value => router.replace({ query: { ...route.query, company: value || undefined } }) })
watch(() => route.params.section, () => { building.value = null; userRoles.value = null; positionRoles.value = null; permissionRole.value = null })
watch(() => available.value.some(item => item.key === route.params.section), allowed => { if (route.path.startsWith('/admin/') && access.value.status === 'ready' && !allowed) navigateTo('/403', { replace: true }) })
function openCompany(item: DirectoryItem) { navigateTo({ path: '/admin/positions', query: { company: item.id } }) }
</script>
<template>
  <div class="admin-layout admin-section-layout">
    <div class="admin-content"><header class="admin-page-heading"><div><h1>{{ section.title }}</h1><p>{{ section.description }}</p></div><v-select v-if="section.scoped" v-model="selector" :items="companyChoices" item-title="title" item-value="id" label="محدوده شرکت"  :placeholder="!requiresCompany ? 'سراسری' : 'شرکت را انتخاب کنید'" variant="outlined" density="compact" hide-details class="admin-company-select" /></header>
      <v-alert v-if="requiresCompany && !effectiveCompany" type="info" variant="tonal">ابتدا یک شرکت تعریف کنید تا بتوانید چارت و سمت‌های آن را مدیریت کنید.</v-alert>
      <template v-if="section.key === 'companies'"><AdminDirectory endpoint="companies" title="فهرست شرکت‌ها" noun="شرکت" permission="companies" @select="openCompany" /></template>
      <template v-if="section.key === 'positions' && effectiveCompany"><AdminChart :key="`chart:${effectiveCompany}:${chartKey}`" :company="effectiveCompany" /><AdminDirectory :key="`positions:${effectiveCompany}`" :endpoint="`companies/${effectiveCompany}/positions`" title="پوزیشن‌های شرکت" noun="پوزیشن" permission="positions" kind="positions" :company="effectiveCompany" @select="positionRoles = $event" @changed="chartKey++" /></template>
      <AdminLoginSources v-if="section.key === 'login-sources'" />
      <AdminPersonnel v-if="section.key === 'personnel'" :key="`personnel:${effectiveCompany}`" :company="effectiveCompany" @roles="userRoles = $event" />
      <AdminAssignments v-if="section.key === 'assignments' && effectiveCompany" :key="effectiveCompany" :company="effectiveCompany" />
      <template v-if="section.key === 'buildings'"><template v-if="building"><v-btn color="secondary" variant="tonal" class="mb-4" @click="building = null">بازگشت به ساختمان‌ها</v-btn><AdminDirectory :key="building.id" :endpoint="`buildings/${building.id}/areas`" :title="`ناحیه‌های ${building.title}`" noun="ناحیه" permission="buildings" kind="areas" /></template><AdminDirectory v-else endpoint="buildings" title="فهرست ساختمان‌ها" noun="ساختمان" permission="buildings" @select="building = $event" /></template>
      <template v-if="section.key === 'roles'"><AdminDirectory endpoint="roles" title="نقش‌های مشترک سامانه" noun="نقش" permission="roles" kind="roles" @select="permissionRole = $event" /></template>
      <AdminGrantManager v-if="section.key === 'grants'" :company="effectiveCompany" />
      <AdminUserRoles v-if="userRoles" :key="`user:${userRoles.id}:${effectiveCompany}`" :user="userRoles" :company="effectiveCompany" @close="userRoles = null" />
      <AdminPositionRoles v-if="positionRoles && effectiveCompany" :key="`position:${positionRoles.id}`" :position="positionRoles" :company="effectiveCompany" @close="positionRoles = null" />
      <AdminRolePermissions v-if="permissionRole" :key="permissionRole.id" :role="permissionRole" @close="permissionRole = null" />
    </div>
  </div>
</template>
