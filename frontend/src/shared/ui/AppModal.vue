<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import AppIcon from './AppIcon.vue'

const props = defineProps<{ open: boolean; title: string; width?: 'sm' | 'md' }>()
const emit = defineEmits<{ close: [] }>()

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
}

watch(
  () => props.open,
  (open) => (open ? window.addEventListener('keydown', onKey) : window.removeEventListener('keydown', onKey)),
  { immediate: true },
)
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-4 sm:items-center" @click.self="emit('close')">
      <div role="dialog" aria-modal="true" :aria-label="title" class="w-full rounded-xl bg-surface shadow-xl" :class="width === 'sm' ? 'max-w-md' : 'max-w-lg'">
        <header class="flex items-center justify-between border-b border-line px-5 py-4">
          <h2 class="text-base font-semibold">{{ title }}</h2>
          <button type="button" class="rounded p-1 text-ink-muted hover:bg-surface-muted" :aria-label="$t('common.close')" @click="emit('close')">
            <AppIcon name="x" />
          </button>
        </header>
        <div class="px-5 py-4"><slot /></div>
        <footer v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 border-t border-line px-5 py-3"><slot name="footer" /></footer>
      </div>
    </div>
  </Teleport>
</template>
