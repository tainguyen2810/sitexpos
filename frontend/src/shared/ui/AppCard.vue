<script setup lang="ts">
withDefaults(defineProps<{ title?: string; subtitle?: string; padded?: boolean }>(), { title: undefined, subtitle: undefined, padded: true })
</script>

<template>
  <section class="rounded-xl bg-surface shadow-xs ring-1 ring-line">
    <header v-if="title || $slots.actions" class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4">
      <div>
        <h2 v-if="title" class="text-base font-semibold text-ink">{{ title }}</h2>
        <p v-if="subtitle" class="mt-0.5 text-sm text-ink-muted">{{ subtitle }}</p>
      </div>
      <div class="flex items-center gap-2"><slot name="actions" /></div>
    </header>
    <div :class="padded === false ? '' : 'p-5'"><slot /></div>
  </section>
</template>
