<script setup lang="ts">
import { useId } from 'vue'

defineProps<{ label?: string; hint?: string; error?: string; rows?: number; placeholder?: string; disabled?: boolean }>()
const model = defineModel<string>({ default: '' })
const id = useId()
</script>

<template>
  <div class="space-y-1.5">
    <label v-if="label" :for="id" class="block text-sm font-medium text-ink">{{ label }}</label>
    <textarea
      :id="id"
      v-model="model"
      :rows="rows ?? 3"
      :placeholder="placeholder"
      :disabled="disabled"
      :aria-invalid="!!error"
      class="block w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 disabled:bg-surface-muted"
    />
    <p v-if="error" class="text-xs text-danger-600">{{ error }}</p>
    <p v-else-if="hint" class="text-xs text-ink-muted">{{ hint }}</p>
  </div>
</template>
