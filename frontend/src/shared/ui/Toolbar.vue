<script setup lang="ts">
defineProps<{ count?: number; total?: number; filtered?: boolean }>()
defineEmits<{ clear: [] }>()
</script>

<template>
  <div class="flex flex-col gap-3 border-b border-line px-4 py-3 sm:px-5 lg:flex-row lg:items-center">
    <div class="flex flex-1 flex-wrap items-center gap-2"><slot /></div>
    <div class="flex flex-wrap items-center gap-2 text-sm text-ink-muted">
      <button v-if="filtered" type="button" class="text-brand-700 hover:underline" @click="$emit('clear')">{{ $t('common.clearFilters') }}</button>
      <span v-if="count !== undefined">{{ $t('common.results', { n: count }) }}</span>
      <slot name="right" />
    </div>
  </div>
</template>
