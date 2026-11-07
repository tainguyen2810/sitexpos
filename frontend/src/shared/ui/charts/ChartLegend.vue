<script setup lang="ts">
defineProps<{ items: { name: string; color: string }[] }>()
</script>

<template>
  <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-muted">
    <li v-for="it in items" :key="it.name" class="flex items-center gap-1.5">
      <span class="h-2.5 w-2.5 rounded-sm" :style="{ background: it.color }" />{{ it.name }}
    </li>
  </ul>
</template>
