<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import AppIcon from './AppIcon.vue'
import { initials } from '@/shared/utils/format'

defineProps<{ name: string; email: string; accountTo?: string }>()
const emit = defineEmits<{ logout: [] }>()

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
    <button type="button" class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-surface-muted" :aria-expanded="open" @click="open = !open">
      <span class="grid h-8 w-8 place-items-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">{{ initials(name) }}</span>
      <span class="hidden max-w-[10rem] truncate text-sm font-medium sm:block">{{ name }}</span>
      <AppIcon name="chevronDown" :size="16" class="text-ink-subtle" />
    </button>
    <div v-if="open" class="absolute right-0 z-30 mt-2 w-64 rounded-xl bg-surface p-2 shadow-lg ring-1 ring-slate-900/5">
      <div class="border-b border-line px-3 pb-2 pt-1">
        <p class="truncate text-sm font-medium">{{ name }}</p>
        <p class="truncate text-xs text-ink-muted">{{ email }}</p>
      </div>
      <RouterLink v-if="accountTo" :to="accountTo" class="mt-1 flex items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-surface-muted" @click="open = false">
        <AppIcon name="user" :size="16" /> {{ $t('nav.account') }}
      </RouterLink>
      <button type="button" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-danger-600 hover:bg-danger-50" @click="emit('logout')">
        <AppIcon name="logout" :size="16" /> {{ $t('auth.logout') }}
      </button>
    </div>
  </div>
</template>
