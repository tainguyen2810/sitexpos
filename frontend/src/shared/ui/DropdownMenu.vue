<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import AppIcon from './AppIcon.vue'

/**
 * Small "⋯" actions menu (Tailwind UI style). Items are rendered by the parent as
 * <button role="menuitem"> inside the default slot; `close` is passed to the slot.
 */
defineProps<{ label: string }>()
const open = ref(false)
const root = ref<HTMLElement | null>(null)

function onDocClick(e: MouseEvent) {
  if (root.value && !root.value.contains(e.target as Node)) open.value = false
}
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') open.value = false
}
watch(open, (v) => {
  if (v) {
    document.addEventListener('click', onDocClick)
    window.addEventListener('keydown', onKey)
  } else {
    document.removeEventListener('click', onDocClick)
    window.removeEventListener('keydown', onKey)
  }
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick)
  window.removeEventListener('keydown', onKey)
})
const close = () => (open.value = false)
</script>

<template>
  <div ref="root" class="relative inline-block text-left">
    <button
      type="button"
      class="rounded-md p-1.5 text-ink-subtle hover:bg-slate-100 hover:text-ink"
      :aria-label="label"
      :title="label"
      aria-haspopup="menu"
      :aria-expanded="open"
      @click="open = !open"
    >
      <AppIcon name="dots" :size="16" />
    </button>
    <div
      v-if="open"
      role="menu"
      class="absolute right-0 z-30 mt-1 w-48 origin-top-right rounded-lg bg-surface py-1 text-sm shadow-lg ring-1 ring-slate-900/5"
      @click="close"
    >
      <slot :close="close" />
    </div>
  </div>
</template>
