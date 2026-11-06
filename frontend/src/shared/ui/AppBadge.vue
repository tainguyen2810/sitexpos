<script setup lang="ts">
defineProps<{ tone?: 'neutral' | 'brand' | 'success' | 'warning' | 'danger'; dot?: boolean }>()
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
    :class="{
      neutral: 'bg-slate-50 text-slate-600 ring-slate-500/15',
      brand: 'bg-brand-50 text-brand-700 ring-brand-600/20',
      success: 'bg-success-50 text-success-700 ring-success-600/20',
      warning: 'bg-warning-50 text-warning-700 ring-warning-600/25',
      danger: 'bg-danger-50 text-danger-700 ring-danger-600/15',
    }[tone ?? 'neutral']"
  >
    <svg
      v-if="dot"
      viewBox="0 0 6 6"
      aria-hidden="true"
      class="size-1.5"
      :class="{ neutral: 'fill-slate-400', brand: 'fill-brand-500', success: 'fill-success-500', warning: 'fill-warning-500', danger: 'fill-danger-500' }[tone ?? 'neutral']"
    ><circle cx="3" cy="3" r="3" /></svg>
    <slot />
  </span>
</template>
