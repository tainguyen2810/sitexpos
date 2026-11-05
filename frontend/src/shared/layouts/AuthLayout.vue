<script setup lang="ts">
defineProps<{ title: string; subtitle?: string }>()
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-surface-muted px-4 py-10">
    <div class="mb-6 flex items-center gap-2 text-lg font-bold text-ink">
      <span class="grid h-9 w-9 place-items-center rounded-lg bg-brand-600 text-white">SX</span>
      SiteX Post
    </div>
    <div class="w-full max-w-md rounded-2xl border border-line bg-surface p-6 shadow-sm sm:p-8">
      <h1 class="text-xl font-semibold text-ink">{{ title }}</h1>
      <p v-if="subtitle" class="mt-1 text-sm text-ink-muted">{{ subtitle }}</p>
      <div class="mt-6"><slot /></div>
    </div>
    <div v-if="$slots.footer" class="mt-4 text-sm text-ink-muted"><slot name="footer" /></div>
  </div>
</template>
