<script setup lang="ts">
import { computed, useId } from 'vue'

// Procedural SVG illustration used for every demo image (no external downloads,
// licence CC0). AI images always carry a visible "Ảnh AI" label.
const props = withDefaults(defineProps<{ hue: number; motif: number; ai?: boolean; title?: string; ratio?: string }>(), { ai: false, title: '', ratio: '16 / 10' })
const id = useId()
const c1 = computed(() => `hsl(${props.hue} 70% 62%)`)
const c2 = computed(() => `hsl(${(props.hue + 40) % 360} 65% 42%)`)
const c3 = computed(() => `hsl(${(props.hue + 200) % 360} 60% 88%)`)
</script>

<template>
  <div class="relative overflow-hidden rounded-lg bg-surface-muted" :style="{ aspectRatio: ratio }">
    <svg viewBox="0 0 160 100" preserveAspectRatio="xMidYMid slice" class="h-full w-full" role="img" :aria-label="title || 'Ảnh minh họa demo'">
      <defs>
        <linearGradient :id="`g${id}`" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" :stop-color="c1" />
          <stop offset="1" :stop-color="c2" />
        </linearGradient>
      </defs>
      <rect width="160" height="100" :fill="`url(#g${id})`" />
      <g v-if="motif === 0" fill="none" :stroke="c3" stroke-opacity="0.7">
        <circle cx="120" cy="30" r="14" :fill="c3" fill-opacity="0.85" />
        <path d="M0 80 Q40 55 80 75 T160 65 V100 H0Z" :fill="c3" fill-opacity="0.35" stroke="none" />
        <path d="M0 90 Q50 70 100 88 T160 82 V100 H0Z" fill="#fff" fill-opacity="0.25" stroke="none" />
      </g>
      <g v-else-if="motif === 1">
        <path d="M0 100 L45 40 L75 75 L105 30 L160 100Z" :fill="c3" fill-opacity="0.55" />
        <path d="M0 100 L30 70 L60 90 L95 60 L160 100Z" fill="#fff" fill-opacity="0.3" />
        <circle cx="30" cy="25" r="8" fill="#fff" fill-opacity="0.8" />
      </g>
      <g v-else-if="motif === 2" fill="#fff">
        <rect x="20" y="50" width="16" height="35" rx="2" fill-opacity="0.5" />
        <rect x="44" y="35" width="16" height="50" rx="2" fill-opacity="0.65" />
        <rect x="68" y="58" width="16" height="27" rx="2" fill-opacity="0.45" />
        <rect x="92" y="25" width="16" height="60" rx="2" fill-opacity="0.75" />
        <rect x="116" y="42" width="16" height="43" rx="2" fill-opacity="0.55" />
      </g>
      <g v-else-if="motif === 3" :fill="c3">
        <rect x="18" y="45" width="70" height="30" rx="6" fill-opacity="0.8" />
        <rect x="24" y="35" width="58" height="16" rx="5" fill-opacity="0.6" />
        <rect x="22" y="75" width="4" height="10" fill-opacity="0.8" />
        <rect x="80" y="75" width="4" height="10" fill-opacity="0.8" />
        <circle cx="122" cy="40" r="12" fill="#fff" fill-opacity="0.5" />
        <rect x="112" y="55" width="20" height="30" rx="3" fill="#fff" fill-opacity="0.4" />
      </g>
      <g v-else fill="none" stroke="#fff" stroke-opacity="0.45" stroke-width="1.2">
        <circle v-for="n in 6" :key="n" cx="80" cy="50" :r="n * 9" />
      </g>
    </svg>
    <span v-if="ai" class="absolute left-2 top-2 rounded bg-slate-900/75 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">Ảnh AI · minh họa</span>
    <span class="absolute bottom-1.5 right-2 text-[9px] font-medium uppercase tracking-wider text-white/80">demo</span>
  </div>
</template>
