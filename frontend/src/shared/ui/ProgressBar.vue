<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ value: number; max?: number; tone?: 'brand' | 'success' | 'warning' | 'danger' | 'auto'; label?: string }>()
const pct = computed(() => {
  const max = props.max ?? 100
  if (max <= 0) return 0
  return Math.min(100, Math.round((props.value / max) * 100))
})
const color = computed(() => {
  const t = props.tone ?? 'brand'
  if (t === 'auto') return pct.value >= 90 ? 'bg-danger-600' : pct.value >= 70 ? 'bg-warning-600' : 'bg-success-600'
  return { brand: 'bg-brand-600', success: 'bg-success-600', warning: 'bg-warning-600', danger: 'bg-danger-600' }[t]
})
</script>

<template>
  <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="pct" aria-valuemin="0" aria-valuemax="100" :aria-label="label">
    <div class="h-full rounded-full transition-all duration-500" :class="color" :style="{ width: `${pct}%` }" />
  </div>
</template>
