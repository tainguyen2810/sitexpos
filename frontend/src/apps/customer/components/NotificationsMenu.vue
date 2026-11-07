<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import type { AttentionItem } from '../composables/useAttentionItems'

defineProps<{ items: AttentionItem[]; dashboardTo: string }>()
const open = ref(false)
const root = ref<HTMLElement | null>(null)
function onDoc(e: MouseEvent) {
  if (root.value && !root.value.contains(e.target as Node)) open.value = false
}
onMounted(() => document.addEventListener('click', onDoc))
onBeforeUnmount(() => document.removeEventListener('click', onDoc))
</script>

<template>
  <div ref="root" class="relative">
    <button type="button" class="relative rounded-full p-2 text-ink-muted hover:bg-slate-100 hover:text-ink" :aria-label="$t('shell.notifications.title')" :aria-expanded="open" data-testid="notifications" @click="open = !open">
      <AppIcon name="bell" />
      <span v-if="items.length" class="absolute right-1 top-1 grid min-w-4 place-items-center rounded-full bg-danger-600 px-1 text-[10px] font-bold leading-4 text-white">{{ items.length }}</span>
    </button>
    <div v-if="open" class="absolute right-0 z-30 mt-2 w-[min(22rem,calc(100vw-2rem))] rounded-xl bg-surface shadow-lg ring-1 ring-slate-900/5">
      <p class="border-b border-line px-4 py-3 text-sm font-semibold text-ink">{{ $t('shell.notifications.title') }}</p>
      <ul v-if="items.length" class="max-h-96 divide-y divide-line overflow-y-auto">
        <li v-for="i in items" :key="i.key">
          <RouterLink :to="i.to" class="flex gap-3 px-4 py-3 hover:bg-slate-50" @click="open = false">
            <span class="grid size-8 shrink-0 place-items-center rounded-lg" :class="i.tone"><AppIcon :name="i.icon" :size="16" /></span>
            <span class="min-w-0"><span class="block text-sm font-medium text-ink">{{ i.title }}</span><span class="block text-xs text-ink-muted">{{ i.desc }}</span></span>
          </RouterLink>
        </li>
      </ul>
      <p v-else class="px-4 py-6 text-center text-sm text-ink-muted">{{ $t('shell.notifications.empty') }}</p>
      <RouterLink :to="dashboardTo" class="block border-t border-line px-4 py-2.5 text-center text-sm font-medium text-brand-700 hover:bg-slate-50" @click="open = false">{{ $t('shell.notifications.viewAll') }}</RouterLink>
    </div>
  </div>
</template>
