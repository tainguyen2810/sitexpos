<script setup lang="ts">
defineProps<{ label: string; options: { value: string; label: string }[] }>()
const model = defineModel<string>({ default: '' })
</script>

<template>
  <label class="flex items-center gap-2 text-sm">
    <span class="sr-only">{{ label }}</span>
    <select
      v-model="model"
      :title="label"
      class="rounded-lg border-0 bg-surface py-2 pl-3 pr-8 text-sm shadow-xs ring-1 ring-inset focus:outline-none focus:ring-2 focus:ring-brand-600"
      :class="model ? 'text-brand-700 ring-brand-500' : 'text-ink ring-line-strong'"
    >
      <option v-for="o in options" :key="o.value" :value="o.value">{{ o.value === '' ? `${label}: ${o.label}` : o.label }}</option>
    </select>
  </label>
</template>
