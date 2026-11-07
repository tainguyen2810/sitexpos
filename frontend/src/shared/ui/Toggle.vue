<script setup lang="ts">
defineProps<{ label: string; description?: string; disabled?: boolean }>()
const model = defineModel<boolean>({ default: false })
</script>

<template>
  <label class="flex cursor-pointer items-start justify-between gap-4 py-2" :class="disabled ? 'cursor-not-allowed opacity-60' : ''">
    <span>
      <span class="block text-sm font-medium text-ink">{{ label }}</span>
      <span v-if="description" class="block text-xs text-ink-muted">{{ description }}</span>
    </span>
    <button
      type="button"
      role="switch"
      :aria-checked="model"
      :aria-label="label"
      :disabled="disabled"
      class="relative mt-0.5 inline-flex h-6 w-11 shrink-0 rounded-full ring-1 ring-inset ring-slate-900/5 transition-colors duration-200"
      :class="model ? 'bg-brand-600' : 'bg-slate-200'"
      @click="model = !model"
    >
      <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all" :class="model ? 'left-[22px]' : 'left-0.5'" />
    </button>
  </label>
</template>
