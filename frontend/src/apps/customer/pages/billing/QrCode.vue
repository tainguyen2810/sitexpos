<script setup lang="ts">
import { computed } from 'vue'
import qrcode from 'qrcode-generator'

/** VietQR payload rendered as an inline SVG (no v-html, no network). */
const props = defineProps<{ payload: string; size?: number; label: string }>()
const matrix = computed(() => {
  const qr = qrcode(0, 'M')
  qr.addData(props.payload, 'Byte')
  qr.make()
  const n = qr.getModuleCount()
  const cells: { x: number; y: number }[] = []
  for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) if (qr.isDark(y, x)) cells.push({ x, y })
  return { n, cells }
})
</script>

<template>
  <svg :width="size ?? 220" :height="size ?? 220" :viewBox="`-2 -2 ${matrix.n + 4} ${matrix.n + 4}`" role="img" :aria-label="label" class="rounded-lg bg-white" shape-rendering="crispEdges" data-testid="vietqr">
    <rect x="-2" y="-2" :width="matrix.n + 4" :height="matrix.n + 4" fill="#fff" />
    <rect v-for="c in matrix.cells" :key="`${c.x}-${c.y}`" :x="c.x" :y="c.y" width="1" height="1" fill="#000" />
  </svg>
</template>
