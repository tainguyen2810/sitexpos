<script setup lang="ts">
defineProps<{ title: string; subtitle?: string }>()
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-slate-50 px-4 py-10">
    <div class="mb-6 flex items-center gap-2.5 text-lg font-semibold tracking-tight text-ink">
      <span class="grid size-9 place-items-center rounded-lg bg-brand-600 text-sm text-white shadow-xs">SX</span>
      SiteX Post
    </div>
    <div class="w-full max-w-md rounded-2xl bg-surface shadow-xs ring-1 ring-line p-6 sm:p-8">
      <h1 class="text-xl font-semibold text-ink">{{ title }}</h1>
      <p v-if="subtitle" class="mt-1 text-sm text-ink-muted">{{ subtitle }}</p>
      <div class="mt-6"><slot /></div>
    </div>
    <div v-if="$slots.footer" class="mt-4 text-sm text-ink-muted"><slot name="footer" /></div>
  </div>
</template>
