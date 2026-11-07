<script setup lang="ts">
import { useId } from 'vue'

defineProps<{
  label?: string
  type?: string
  error?: string
  hint?: string
  autocomplete?: string
  placeholder?: string
  required?: boolean
  disabled?: boolean
}>()

const model = defineModel<string>({ default: '' })
const id = useId()
</script>

<template>
  <div class="space-y-1.5">
    <label v-if="label" :for="id" class="block text-sm font-medium text-ink">{{ label }}</label>
    <input
      :id="id"
      v-model="model"
      :type="type ?? 'text'"
      :autocomplete="autocomplete"
      :placeholder="placeholder"
      :required="required"
      :disabled="disabled"
      :aria-invalid="!!error"
      :aria-describedby="error || hint ? `${id}-help` : undefined"
      class="block w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm text-ink shadow-xs ring-1 ring-inset placeholder:text-ink-subtle focus:outline-none focus:ring-2 focus:ring-brand-600 disabled:bg-surface-muted disabled:text-ink-muted"
      :class="error ? 'ring-danger-500' : 'ring-line-strong'"
    />
    <p v-if="error" :id="`${id}-help`" class="text-xs text-danger-600">{{ error }}</p>
    <p v-else-if="hint" :id="`${id}-help`" class="text-xs text-ink-muted">{{ hint }}</p>
  </div>
</template>
