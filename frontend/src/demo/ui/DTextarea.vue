<script setup lang="ts">
import { useId } from 'vue'

defineProps<{ label?: string; error?: string; hint?: string; rows?: number; placeholder?: string; maxlength?: number }>()
const model = defineModel<string>({ default: '' })
const id = useId()
</script>

<template>
  <div class="space-y-1.5">
    <label v-if="label" :for="id" class="block text-sm font-medium text-ink">{{ label }}</label>
    <textarea
      :id="id"
      v-model="model"
      :rows="rows ?? 4"
      :placeholder="placeholder"
      :maxlength="maxlength"
      :aria-invalid="!!error"
      class="block w-full rounded-lg border bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-subtle focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
      :class="error ? 'border-danger-600' : 'border-line'"
    />
    <div class="flex justify-between gap-2 text-xs">
      <p v-if="error" class="text-danger-600">{{ error }}</p>
      <p v-else-if="hint" class="text-ink-muted">{{ hint }}</p>
      <span v-if="maxlength" class="ml-auto text-ink-subtle">{{ model.length }}/{{ maxlength }}</span>
    </div>
  </div>
</template>
