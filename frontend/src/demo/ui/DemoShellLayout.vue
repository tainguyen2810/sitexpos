<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppIcon, { type IconName } from '@/shared/ui/AppIcon.vue'
import DemoBanner from './DemoBanner.vue'

export interface NavGroup {
  label: string
  items: { label: string; to: string; icon: IconName; badge?: string | number }[]
}

defineProps<{ groups: NavGroup[]; brandSuffix?: string }>()
const route = useRoute()
const mobileOpen = ref(false)
watch(() => route.fullPath, () => (mobileOpen.value = false))
</script>

<template>
  <div class="min-h-screen bg-surface-muted">
    <div v-if="mobileOpen" class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" @click="mobileOpen = false" />
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-line bg-surface transition-transform lg:translate-x-0" :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'">
      <div class="flex h-16 shrink-0 items-center gap-2 border-b border-line px-5 font-bold text-ink">
        <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-sm text-white">SX</span>
        <span>SiteX Post</span>
        <span v-if="brandSuffix" class="ml-auto rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700">{{ brandSuffix }}</span>
        <button type="button" class="ml-auto rounded p-1 text-ink-muted lg:hidden" aria-label="Đóng menu" @click="mobileOpen = false"><AppIcon name="x" :size="18" /></button>
      </div>
      <div v-if="$slots['sidebar-top']" class="px-3 pt-3"><slot name="sidebar-top" /></div>
      <nav class="flex-1 overflow-y-auto px-3 py-3" aria-label="Điều hướng chính">
        <div v-for="g in groups" :key="g.label" class="mb-4">
          <p class="mb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-ink-subtle">{{ g.label }}</p>
          <RouterLink
            v-for="item in g.items"
            :key="item.to"
            :to="item.to"
            class="flex items-center gap-3 rounded-lg px-3 py-1.5 text-sm font-medium text-ink-muted hover:bg-surface-muted hover:text-ink"
            active-class="!bg-brand-50 !text-brand-700"
          >
            <AppIcon :name="item.icon" :size="18" />
            <span class="truncate">{{ item.label }}</span>
            <span v-if="item.badge" class="ml-auto rounded-full bg-brand-100 px-1.5 text-[11px] font-semibold text-brand-700">{{ item.badge }}</span>
          </RouterLink>
        </div>
      </nav>
      <div v-if="$slots['sidebar-bottom']" class="border-t border-line p-3"><slot name="sidebar-bottom" /></div>
    </aside>

    <div class="lg:pl-64">
      <DemoBanner />
      <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur sm:px-6">
        <button type="button" class="rounded-lg p-2 text-ink-muted hover:bg-surface-muted lg:hidden" aria-label="Mở menu" @click="mobileOpen = true"><AppIcon name="menu" /></button>
        <div class="min-w-0 flex-1"><slot name="topbar" /></div>
        <div class="flex items-center gap-2"><slot name="topbar-right" /></div>
      </header>
      <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:py-8">
        <slot />
      </main>
    </div>
  </div>
</template>
