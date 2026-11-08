<script setup lang="ts">
definePageMeta({ middleware: 'management' })
const { available, groups } = useAdminSections()
</script>
<template>
  <section class="admin-start"><div class="page-heading"><h1>مدیریت سازمان</h1><p>بخش موردنظر را برای مدیریت ساختار، پرسنل و دسترسی‌ها انتخاب کنید.</p></div><v-list v-if="available.length" border rounded="lg" class="admin-start-list"><template v-for="group in groups" :key="group.key"><v-list-subheader>{{ group.title }}</v-list-subheader><v-list-item v-for="item in group.children" :key="item.key" :to="`/admin/${item.key}`" :prepend-icon="item.icon" :title="item.title" :subtitle="item.description" /></template></v-list><v-alert v-else type="info" variant="tonal">هنوز دسترسی مدیریتی به حساب شما اختصاص نیافته است. با مدیر سامانه تماس بگیرید.</v-alert></section>
</template>
