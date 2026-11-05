<script setup lang="ts">
import AppIcon, { type IconName } from './AppIcon.vue'

defineProps<{ title: string; description?: string; icon?: IconName }>()
</script>

<template>
  <div class="flex flex-col items-center justify-center px-6 py-12 text-center" data-testid="empty-state">
    <div class="mb-3 rounded-full bg-brand-50 p-3 text-brand-600"><AppIcon :name="icon ?? 'inbox'" :size="24" /></div>
    <h3 class="text-sm font-semibold text-ink">{{ title }}</h3>
    <p v-if="description" class="mt-1 max-w-sm text-sm text-ink-muted">{{ description }}</p>
    <div v-if="$slots.default" class="mt-4"><slot /></div>
  </div>
</template>
