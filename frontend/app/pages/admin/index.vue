<script setup lang="ts">
definePageMeta({ middleware: 'management' })
const { groups } = useAdminSections()
const groupColors = { organization: 'primary', personnel: 'success', access: 'warning' } as const
</script>
<template>
  <section class="admin-start">
    <div class="page-heading"><h1>پیشخوان مدیریت</h1><p>بخش موردنظر را برای مدیریت ساختار، پرسنل و دسترسی‌ها انتخاب کنید.</p></div>
    <div v-if="groups.length" class="admin-dashboard-groups">
      <section v-for="group in groups" :key="group.key" class="admin-dashboard-group" :aria-labelledby="`dashboard-group-${group.key}`">
        <div class="admin-dashboard-group-heading">
          <v-icon :icon="group.icon" :color="groupColors[group.key]" size="24" aria-hidden="true" />
          <h2 :id="`dashboard-group-${group.key}`">{{ group.title }}</h2>
        </div>
        <div class="admin-shortcut-grid">
          <v-card v-for="item in group.children" :key="item.key" :to="`/admin/${item.key}`" class="admin-shortcut" :class="`admin-shortcut--${group.key}`" border :aria-labelledby="`dashboard-link-${item.key}`" :aria-describedby="`dashboard-description-${item.key}`">
            <div class="admin-shortcut-content">
              <v-avatar :color="groupColors[group.key]" variant="tonal" size="52" rounded="lg" class="admin-shortcut-icon"><v-icon :icon="item.icon" size="28" aria-hidden="true" /></v-avatar>
              <h3 :id="`dashboard-link-${item.key}`">{{ item.title }}</h3>
              <p :id="`dashboard-description-${item.key}`">{{ item.description }}</p>
            </div>
          </v-card>
        </div>
      </section>
    </div>
    <v-alert v-else type="info" variant="tonal">هنوز دسترسی مدیریتی به حساب شما اختصاص نیافته است. با مدیر سامانه تماس بگیرید.</v-alert>
  </section>
</template>
