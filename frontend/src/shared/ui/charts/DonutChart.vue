<script setup lang="ts">
import { computed, ref } from 'vue'
import { seriesColor } from './palette'

const props = withDefaults(defineProps<{ segments: { name: string; value: number; color?: string }[]; centerLabel?: string; format?: (v: number) => string; size?: number }>(), {
  centerLabel: undefined,
  format: (v: number) => new Intl.NumberFormat('vi-VN').format(v),
  size: 160,
})
const total = computed(() => props.segments.reduce((s, x) => s + x.value, 0))
const R = 40
const C = 2 * Math.PI * R
const arcs = computed(() => {
  let acc = 0
  return props.segments.map((s, i) => {
    const frac = total.value ? s.value / total.value : 0
    const gap = frac > 0 && props.segments.length > 1 ? 1.2 : 0
    const arc = { ...s, color: s.color ?? seriesColor(i), dash: `${Math.max(0, frac * C - gap)} ${C}`, offset: -acc * C, pct: frac * 100 }
    acc += frac
    return arc
  })
})
const hover = ref<number | null>(null)
</script>

<template>
  <div class="flex flex-wrap items-center gap-5">
    <div class="relative shrink-0" :style="{ width: `${size}px`, height: `${size}px` }">
      <svg viewBox="0 0 100 100" class="h-full w-full -rotate-90" role="img" :aria-label="segments.map((s) => `${s.name}: ${s.value}`).join(', ')">
        <circle cx="50" cy="50" :r="R" fill="none" stroke="#f1f5f9" stroke-width="12" />
        <circle v-for="(a, i) in arcs" :key="a.name" cx="50" cy="50" :r="R" fill="none" :stroke="a.color" :stroke-width="hover === i ? 14 : 12" :stroke-dasharray="a.dash" :stroke-dashoffset="a.offset" class="cursor-pointer transition-all" @pointerenter="hover = i" @pointerleave="hover = null" />
      </svg>
      <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
        <span class="text-lg font-semibold tabular-nums text-ink">{{ hover !== null ? format(arcs[hover]!.value) : format(total) }}</span>
        <span class="max-w-[70%] truncate text-[11px] text-ink-muted">{{ hover !== null ? arcs[hover]!.name : centerLabel }}</span>
      </div>
    </div>
    <ul class="min-w-36 flex-1 space-y-1.5 text-sm">
      <li v-for="(a, i) in arcs" :key="a.name" class="flex items-center gap-2" :class="hover !== null && hover !== i ? 'opacity-60' : ''" @pointerenter="hover = i" @pointerleave="hover = null">
        <span class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ background: a.color }" />
        <span class="truncate text-ink-muted">{{ a.name }}</span>
        <span class="ml-auto tabular-nums text-ink">{{ format(a.value) }}</span>
        <span class="w-12 text-right text-xs tabular-nums text-ink-subtle">{{ a.pct.toFixed(0) }}%</span>
      </li>
    </ul>
  </div>
</template>
