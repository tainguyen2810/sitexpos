<script setup lang="ts">
import { useId } from 'vue'

defineProps<{
  label?: string
  options: { value: string; label: string }[]
  error?: string
  disabled?: boolean
}>()

const model = defineModel<string>({ default: '' })
const id = useId()
</script>

<template>
  <div class="space-y-1.5">
    <label v-if="label" :for="id" class="block text-sm font-medium text-ink">{{ label }}</label>
    <select
      :id="id"
      v-model="model"
      :disabled="disabled"
      class="block w-full rounded-lg border-0 bg-surface py-2 pl-3 pr-8 text-sm text-ink shadow-xs ring-1 ring-inset ring-line-strong focus:outline-none focus:ring-2 focus:ring-brand-600 disabled:bg-surface-muted disabled:text-ink-muted"
    >
      <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
    </select>
    <p v-if="error" class="text-xs text-danger-600">{{ error }}</p>
  </div>
</template>
