<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { num } from '@/shared/api/analytics'

/**
 * Inline SVG line chart for daily clicks / impressions (two independent
 * scales). Plots only reported days; no smoothing, no projection.
 */
const props = withDefaults(defineProps<{ series: { date: string; clicks: number; impressions: number }[]; height?: number }>(), { height: 180 })
const { t } = useI18n()
const W = 720
const pad = 24

const maxClicks = computed(() => Math.max(1, ...props.series.map((p) => p.clicks)))
const maxImpr = computed(() => Math.max(1, ...props.series.map((p) => p.impressions)))
function x(i: number): number {
  return props.series.length < 2 ? W / 2 : pad + (i * (W - 2 * pad)) / (props.series.length - 1)
}
function y(v: number, max: number): number {
  return props.height - pad - (v / max) * (props.height - 2 * pad)
}
const clicksPath = computed(() => props.series.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.clicks, maxClicks.value).toFixed(1)}`).join(' '))
const imprPath = computed(() => props.series.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.impressions, maxImpr.value).toFixed(1)}`).join(' '))
const label = computed(() => `${t('an.metrics.clicks')} / ${t('an.metrics.impressions')}: ${props.series.length} ${t('an.common.days')}`)
</script>

<template>
  <div>
    <svg :viewBox="`0 0 ${W} ${height}`" class="h-auto w-full" role="img" :aria-label="label" data-testid="trend-chart">
      <line :x1="pad" :x2="W - pad" :y1="height - pad" :y2="height - pad" class="stroke-line" stroke-width="1" />
      <path :d="imprPath" fill="none" class="stroke-ink-muted" stroke-width="1.5" stroke-dasharray="4 3" />
      <path :d="clicksPath" fill="none" class="stroke-brand-600" stroke-width="2" />
      <text v-if="series.length" :x="pad" :y="height - 6" class="fill-ink-muted text-[10px]">{{ series[0]!.date }}</text>
      <text v-if="series.length > 1" :x="W - pad" :y="height - 6" text-anchor="end" class="fill-ink-muted text-[10px]">{{ series[series.length - 1]!.date }}</text>
    </svg>
    <div class="mt-2 flex flex-wrap gap-4 text-xs text-ink-muted">
      <span class="flex items-center gap-1"><span class="inline-block h-0.5 w-4 bg-brand-600" />{{ t('an.metrics.clicks') }} ({{ t('an.common.max') }} {{ num(maxClicks) }})</span>
      <span class="flex items-center gap-1"><span class="inline-block h-0.5 w-4 border-t border-dashed border-ink-muted" />{{ t('an.metrics.impressions') }} ({{ t('an.common.max') }} {{ num(maxImpr) }})</span>
    </div>
  </div>
</template>
