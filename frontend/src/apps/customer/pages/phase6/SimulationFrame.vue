<script setup lang="ts">
import { computed } from 'vue'
import DOMPurify from 'dompurify'

/**
 * Renders article HTML in a sandboxed iframe (no scripts, no same-origin):
 * the preview is a simulation of the WordPress post, never the real theme.
 */
const props = defineProps<{ html: string; title: string }>()
const doc = computed(() => {
  const body = DOMPurify.sanitize(props.html, { USE_PROFILES: { html: true } })
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
body{font:16px/1.7 Georgia,serif;color:#1f2937;max-width:720px;margin:24px auto;padding:0 16px}
img{max-width:100%;height:auto;border-radius:6px}figure{margin:1.5em 0}figcaption{font-size:13px;color:#6b7280}
table{border-collapse:collapse;width:100%}td,th{border:1px solid #e5e7eb;padding:6px}a{color:#2563eb}
</style></head><body>${body}</body></html>`
})
</script>

<template>
  <iframe :srcdoc="doc" :title="title" sandbox="" referrerpolicy="no-referrer" class="h-[70vh] w-full rounded-lg border border-line bg-white" data-testid="simulation-frame" />
</template>
