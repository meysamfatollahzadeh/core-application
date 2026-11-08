<script setup lang="ts">
import { setResponseStatus } from 'h3'
useHead({ title: 'دسترسی غیرمجاز | سامانه سازمان' })
if (import.meta.server) setResponseStatus(useRequestEvent()!, 403)
</script>
<template><AccessDenied /></template>
