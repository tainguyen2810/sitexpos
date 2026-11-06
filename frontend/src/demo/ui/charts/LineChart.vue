<script setup lang="ts">
import { computed, ref } from 'vue'
import ChartLegend from './ChartLegend.vue'
import { niceMax, seriesColor, type Series } from './palette'

const props = withDefaults(defineProps<{ labels: string[]; series: Series[]; height?: number; format?: (v: number) => string; area?: boolean; invert?: boolean }>(), {
  height: 220,
  format: (v: number) => new Intl.NumberFormat('vi-VN').format(Math.round(v)),
  area: false,
  invert: false,
})

const W = 640
const pad = { l: 44, r: 12, t: 12, b: 26 }
const H = computed(() => props.height)
const colored = computed(() => props.series.map((s, i) => ({ ...s, color: s.color ?? seriesColor(i) })))
const max = computed(() => niceMax(Math.max(1, ...props.series.flatMap((s) => s.values))))
const min = computed(() => (props.invert ? Math.max(0, Math.min(...props.series.flatMap((s) => s.values)) - 1) : 0))
const x = (i: number) => pad.l + (props.labels.length <= 1 ? 0 : (i / (props.labels.length - 1)) * (W - pad.l - pad.r))
const y = (v: number) => {
  const t = (v - min.value) / (max.value - min.value || 1)
  const h = H.value - pad.t - pad.b
  return props.invert ? pad.t + t * h : H.value - pad.b - t * h
}
const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map((f) => min.value + f * (max.value - min.value)))
const paths = computed(() =>
  colored.value.map((s) => {
    const d = s.values.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ')
    const areaD = `${d} L${x(s.values.length - 1).toFixed(1)},${H.value - pad.b} L${x(0).toFixed(1)},${H.value - pad.b} Z`
    return { ...s, d, areaD }
  }),
)
const xLabelEvery = computed(() => Math.max(1, Math.ceil(props.labels.length / 7)))

const hover = ref<number | null>(null)
const svg = ref<SVGSVGElement | null>(null)
function onMove(e: PointerEvent) {
  const rect = svg.value?.getBoundingClientRect()
  if (!rect) return
  const px = ((e.clientX - rect.left) / rect.width) * W
  const idx = Math.round(((px - pad.l) / (W - pad.l - pad.r)) * (props.labels.length - 1))
  hover.value = Math.max(0, Math.min(props.labels.length - 1, idx))
}
const tipLeft = computed(() => (hover.value === null ? 0 : (x(hover.value) / W) * 100))
</script>

<template>
  <div>
    <ChartLegend v-if="series.length > 1" :items="colored" class="mb-2" />
    <div class="relative">
      <svg ref="svg" :viewBox="`0 0 ${W} ${H}`" class="w-full touch-none select-none" :style="{ height: `${H}px` }" preserveAspectRatio="none" role="img" :aria-label="series.map((s) => s.name).join(', ')" @pointermove="onMove" @pointerleave="hover = null">
        <g>
          <line v-for="t in ticks" :key="t" :x1="pad.l" :x2="W - pad.r" :y1="y(t)" :y2="y(t)" stroke="#e2e8f0" stroke-width="1" vector-effect="non-scaling-stroke" />
        </g>
        <template v-for="p in paths" :key="p.name">
          <path v-if="area" :d="p.areaD" :fill="p.color" fill-opacity="0.08" />
          <path :d="p.d" fill="none" :stroke="p.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
        </template>
        <line v-if="hover !== null" :x1="x(hover)" :x2="x(hover)" :y1="pad.t" :y2="H - pad.b" stroke="#94a3b8" stroke-dasharray="3 3" vector-effect="non-scaling-stroke" />
      </svg>
      <!-- HTML overlays keep text crisp despite preserveAspectRatio="none" -->
      <div class="pointer-events-none absolute inset-0 text-[10px] text-ink-subtle">
        <span v-for="t in ticks" :key="t" class="absolute left-0 w-10 -translate-y-1/2 text-right tabular-nums" :style="{ top: `${(y(t) / H) * 100}%` }">{{ format(t) }}</span>
        <template v-for="(l, i) in labels" :key="i">
          <span v-if="i % xLabelEvery === 0 || i === labels.length - 1" class="absolute bottom-0 -translate-x-1/2 whitespace-nowrap" :style="{ left: `${(x(i) / W) * 100}%` }">{{ l }}</span>
        </template>
        <template v-if="hover !== null">
          <span v-for="p in paths" :key="p.name" class="absolute h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-white" :style="{ left: `${tipLeft}%`, top: `${(y(p.values[hover] ?? 0) / H) * 100}%`, background: p.color }" />
          <div class="absolute top-1 z-10 min-w-36 rounded-lg border border-line bg-surface px-3 py-2 text-xs text-ink shadow-lg" :style="tipLeft > 60 ? { right: `${100 - tipLeft + 2}%` } : { left: `${tipLeft + 2}%` }">
            <p class="mb-1 font-medium">{{ labels[hover] }}</p>
            <p v-for="p in paths" :key="p.name" class="flex items-center gap-2">
              <span class="h-2 w-2 rounded-sm" :style="{ background: p.color }" />
              <span class="text-ink-muted">{{ p.name }}</span>
              <span class="ml-auto font-medium tabular-nums">{{ format(p.values[hover] ?? 0) }}</span>
            </p>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
