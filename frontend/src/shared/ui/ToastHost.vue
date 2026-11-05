<script setup lang="ts">
import { useToast } from '@/shared/composables/useToast'
import AppIcon from './AppIcon.vue'

const { toasts, dismiss } = useToast()
</script>

<template>
  <div class="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end" aria-live="polite">
    <div
      v-for="toast in toasts"
      :key="toast.id"
      class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border px-4 py-3 text-sm shadow-lg"
      :class="toast.tone === 'error' ? 'border-red-200 bg-danger-50 text-danger-600' : 'border-line bg-surface text-ink'"
    >
      <AppIcon :name="toast.tone === 'error' ? 'alert' : 'check'" class="mt-0.5 shrink-0" />
      <p class="flex-1">{{ toast.message }}</p>
      <button type="button" class="text-ink-subtle hover:text-ink" :aria-label="$t('common.close')" @click="dismiss(toast.id)"><AppIcon name="x" :size="16" /></button>
    </div>
  </div>
</template>
