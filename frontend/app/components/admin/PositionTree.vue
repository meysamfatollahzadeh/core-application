<script setup lang="ts">
export type ChartNode = { id: number; title: string; children: ChartNode[] }
defineProps<{ nodes: ChartNode[] }>()
</script>
<template><ul class="position-tree"><li v-for="node in nodes" :key="node.id"><span>{{ node.title }}</span><AdminPositionTree v-if="node.children.length" :nodes="node.children" /></li></ul></template>
