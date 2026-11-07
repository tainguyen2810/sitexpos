<script setup lang="ts">
import { computed, ref } from 'vue'
import ChartLegend from './ChartLegend.vue'
import { niceMax, seriesColor, type Series } from './palette'

const props = withDefaults(defineProps<{ labels: string[]; series: Series[]; height?: number; stacked?: boolean; format?: (v: number) => string }>(), {
  height: 220,
  stacked: true,
  format: (v: number) => new Intl.NumberFormat('vi-VN').format(Math.round(v)),
})
const colored = computed(() => props.series.map((s, i) => ({ ...s, color: s.color ?? seriesColor(i) })))
const totals = computed(() => props.labels.map((_, i) => props.series.reduce((sum, s) => sum + (s.values[i] ?? 0), 0)))
const max = computed(() => niceMax(Math.max(1, ...(props.stacked ? totals.value : props.series.flatMap((s) => s.values)))))
const ticks = computed(() => [1, 0.5, 0].map((f) => f * max.value))
const hover = ref<number | null>(null)
const labelEvery = computed(() => Math.max(1, Math.ceil(props.labels.length / 10)))
</script>

<template>
  <div>
    <ChartLegend v-if="series.length > 1" :items="colored" class="mb-2" />
    <div class="flex gap-2" :style="{ height: `${height}px` }">
      <div class="flex w-10 shrink-0 flex-col justify-between pb-5 text-right text-[10px] tabular-nums text-ink-subtle">
        <span v-for="t in ticks" :key="t">{{ format(t) }}</span>
      </div>
      <div class="relative flex min-w-0 flex-1 items-end gap-[2px] border-b border-line pb-0" :style="{ height: `calc(100% - 20px)` }" role="img" :aria-label="series.map((s) => s.name).join(', ')">
        <div v-for="(l, i) in labels" :key="i" class="group relative flex h-full flex-1 flex-col justify-end" @pointerenter="hover = i" @pointerleave="hover = null">
          <div v-if="stacked" class="flex w-full flex-col-reverse gap-[2px]" :style="{ height: `${(totals[i]! / max) * 100}%` }">
            <div v-for="s in colored" :key="s.name" class="w-full first:rounded-b-none last:rounded-t" :style="{ height: totals[i] ? `${((s.values[i] ?? 0) / totals[i]!) * 100}%` : '0', background: s.color, opacity: hover === null || hover === i ? 1 : 0.55 }" />
          </div>
          <div v-else class="flex h-full w-full items-end gap-[2px]">
            <div v-for="s in colored" :key="s.name" class="flex-1 rounded-t" :style="{ height: `${((s.values[i] ?? 0) / max) * 100}%`, background: s.color, opacity: hover === null || hover === i ? 1 : 0.55 }" />
          </div>
          <span v-if="i % labelEvery === 0" class="absolute -bottom-5 text-[10px] text-ink-subtle" :class="labelEvery > 1 ? 'left-1/2 -translate-x-1/2 whitespace-nowrap' : 'left-0 w-full truncate text-center'" :title="l">{{ l }}</span>
          <div v-if="hover === i" class="pointer-events-none absolute bottom-full z-10 mb-1 min-w-36 rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-lg" :class="i > labels.length / 2 ? 'right-0' : 'left-0'">
            <p class="mb-1 font-medium text-ink">{{ l }}</p>
            <p v-for="s in colored" :key="s.name" class="flex items-center gap-2">
              <span class="h-2 w-2 rounded-sm" :style="{ background: s.color }" /><span class="text-ink-muted">{{ s.name }}</span><span class="ml-auto font-medium tabular-nums text-ink">{{ format(s.values[i] ?? 0) }}</span>
            </p>
            <p v-if="stacked && series.length > 1" class="mt-1 border-t border-line pt-1 text-right font-medium text-ink">{{ $t('common.total') }}: {{ format(totals[i]!) }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
