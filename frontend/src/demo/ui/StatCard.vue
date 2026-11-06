<script setup lang="ts">
import AppIcon, { type IconName } from '@/shared/ui/AppIcon.vue'

defineProps<{ label: string; value: string | number; icon?: IconName; delta?: number | null; hint?: string; to?: string }>()
</script>

<template>
  <component :is="to ? 'RouterLink' : 'div'" :to="to" class="block rounded-xl border border-line bg-surface p-4 shadow-sm transition" :class="to ? 'hover:border-brand-200 hover:shadow' : ''">
    <div class="flex items-start justify-between gap-2">
      <p class="text-sm text-ink-muted">{{ label }}</p>
      <span v-if="icon" class="rounded-lg bg-brand-50 p-1.5 text-brand-600"><AppIcon :name="icon" :size="16" /></span>
    </div>
    <p class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ value }}</p>
    <p class="mt-1 flex items-center gap-1 text-xs text-ink-muted">
      <span v-if="delta !== undefined && delta !== null" class="inline-flex items-center gap-0.5 font-medium" :class="delta >= 0 ? 'text-success-600' : 'text-danger-600'">
        <AppIcon :name="delta >= 0 ? 'arrowUp' : 'arrowDown'" :size="12" />{{ Math.abs(delta).toFixed(1).replace('.', ',') }}%
      </span>
      <span v-if="hint">{{ hint }}</span>
    </p>
  </component>
</template>
