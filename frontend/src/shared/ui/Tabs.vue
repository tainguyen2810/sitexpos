<script setup lang="ts">
defineProps<{ tabs: { key: string; label: string; count?: number }[] }>()
const model = defineModel<string>({ required: true })
</script>

<template>
  <div class="-mb-px flex gap-6 overflow-x-auto border-b border-line" role="tablist">
    <button
      v-for="t in tabs"
      :key="t.key"
      type="button"
      role="tab"
      :aria-selected="model === t.key"
      class="flex items-center gap-2 whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition-colors"
      :class="model === t.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:border-line-strong hover:text-ink'"
      @click="model = t.key"
    >
      {{ t.label }}
      <span v-if="t.count !== undefined" class="rounded-full px-2 py-0.5 text-xs font-medium" :class="model === t.key ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-ink-muted'">{{ t.count }}</span>
    </button>
  </div>
</template>
