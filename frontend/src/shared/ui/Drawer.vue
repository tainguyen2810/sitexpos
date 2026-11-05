<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import AppIcon from '@/shared/ui/AppIcon.vue'

const props = defineProps<{ open: boolean; title: string; subtitle?: string }>()
const emit = defineEmits<{ close: [] }>()
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
}
watch(() => props.open, (o) => (o ? window.addEventListener('keydown', onKey) : window.removeEventListener('keydown', onKey)), { immediate: true })
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex justify-end bg-slate-900/40" @click.self="emit('close')">
      <aside role="dialog" aria-modal="true" :aria-label="title" class="flex h-full w-full max-w-xl flex-col bg-surface shadow-xl">
        <header class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
          <div class="min-w-0">
            <h2 class="truncate text-base font-semibold">{{ title }}</h2>
            <p v-if="subtitle" class="truncate text-sm text-ink-muted">{{ subtitle }}</p>
          </div>
          <button type="button" class="rounded p-1 text-ink-muted hover:bg-surface-muted" :aria-label="$t('common.close')" @click="emit('close')"><AppIcon name="x" /></button>
        </header>
        <div class="flex-1 overflow-y-auto px-5 py-4"><slot /></div>
        <footer v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 border-t border-line px-5 py-3"><slot name="footer" /></footer>
      </aside>
    </div>
  </Teleport>
</template>
