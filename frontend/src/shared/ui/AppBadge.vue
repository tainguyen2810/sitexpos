<script setup lang="ts">
defineProps<{ tone?: 'neutral' | 'brand' | 'success' | 'warning' | 'danger' }>()
</script>

<template>
  <span
    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
    :class="{
      neutral: 'bg-surface-muted text-ink-muted ring-1 ring-line',
      brand: 'bg-brand-50 text-brand-700',
      success: 'bg-success-50 text-success-600',
      warning: 'bg-warning-50 text-warning-600',
      danger: 'bg-danger-50 text-danger-600',
    }[tone ?? 'neutral']"
  >
    <slot />
  </span>
</template>
