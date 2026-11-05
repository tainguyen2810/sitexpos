<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{ values: number[]; width?: number; height?: number; color?: string }>(), { width: 80, height: 24, color: '#2a78d6' })
const d = computed(() => {
  const v = props.values
  if (v.length < 2) return ''
  const max = Math.max(...v)
  const min = Math.min(...v)
  return v.map((n, i) => `${i ? 'L' : 'M'}${((i / (v.length - 1)) * props.width).toFixed(1)},${(props.height - 2 - ((n - min) / (max - min || 1)) * (props.height - 4)).toFixed(1)}`).join(' ')
})
</script>

<template>
  <svg :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" aria-hidden="true">
    <path :d="d" fill="none" :stroke="color" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
  </svg>
</template>
