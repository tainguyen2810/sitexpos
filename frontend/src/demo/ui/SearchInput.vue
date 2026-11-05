<script setup lang="ts">
import AppIcon from '@/shared/ui/AppIcon.vue'

defineProps<{ placeholder?: string }>()
const model = defineModel<string>({ default: '' })
</script>

<template>
  <label class="relative block min-w-0 flex-1 sm:max-w-xs">
    <span class="sr-only">{{ placeholder ?? 'Tìm kiếm' }}</span>
    <AppIcon name="search" :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-subtle" />
    <input
      v-model="model"
      type="search"
      :placeholder="placeholder ?? 'Tìm kiếm…'"
      class="block w-full rounded-lg border border-line bg-surface py-2 pl-9 pr-3 text-sm placeholder:text-ink-subtle focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
    />
  </label>
</template>
