<script setup lang="ts">
defineProps<{ tabs: { key: string; label: string; count?: number }[] }>()
const model = defineModel<string>({ required: true })
</script>

<template>
  <div class="-mb-px flex gap-1 overflow-x-auto border-b border-line" role="tablist">
    <button
      v-for="t in tabs"
      :key="t.key"
      type="button"
      role="tab"
      :aria-selected="model === t.key"
      class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
      :class="model === t.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:text-ink'"
      @click="model = t.key"
    >
      {{ t.label }}
      <span v-if="t.count !== undefined" class="ml-1 rounded-full bg-surface-muted px-1.5 text-xs text-ink-muted">{{ t.count }}</span>
    </button>
  </div>
</template>
