<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppIcon, { type IconName } from '@/shared/ui/AppIcon.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'

export interface NavItem {
  label: string
  to?: string
  icon: IconName
  soon?: boolean
}

defineProps<{ nav: NavItem[]; brandSuffix?: string }>()

const route = useRoute()
const mobileOpen = ref(false)
watch(() => route.fullPath, () => (mobileOpen.value = false))
</script>

<template>
  <div class="min-h-screen bg-surface-muted">
    <!-- Mobile drawer backdrop -->
    <div v-if="mobileOpen" class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" @click="mobileOpen = false" />

    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-line bg-surface transition-transform lg:translate-x-0"
      :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex h-16 items-center gap-2 border-b border-line px-5 font-bold text-ink">
        <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-sm text-white">SX</span>
        <span>SiteX Post</span>
        <AppBadge v-if="brandSuffix" tone="brand" class="ml-auto">{{ brandSuffix }}</AppBadge>
      </div>
      <div class="px-3 pt-4"><slot name="sidebar-top" /></div>
      <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" :aria-label="brandSuffix ?? 'main'">
        <template v-for="item in nav" :key="item.label">
          <RouterLink
            v-if="item.to && !item.soon"
            :to="item.to"
            class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-ink-muted hover:bg-surface-muted hover:text-ink"
            :active-class="item.to === '/' ? '' : '!bg-brand-50 !text-brand-700'"
            :exact-active-class="item.to === '/' ? '!bg-brand-50 !text-brand-700' : ''"
          >
            <AppIcon :name="item.icon" />
            {{ item.label }}
          </RouterLink>
          <span v-else class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-ink-subtle" :title="$t('common.comingSoon')">
            <AppIcon :name="item.icon" />
            {{ item.label }}
            <AppBadge class="ml-auto">{{ $t('common.comingSoon') }}</AppBadge>
          </span>
        </template>
      </nav>
      <div class="border-t border-line p-3"><slot name="sidebar-bottom" /></div>
    </aside>

    <div class="lg:pl-64">
      <slot name="banner" />
      <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur sm:px-6">
        <button type="button" class="rounded-lg p-2 text-ink-muted hover:bg-surface-muted lg:hidden" :aria-label="$t('nav.openMenu')" @click="mobileOpen = true">
          <AppIcon name="menu" />
        </button>
        <div class="min-w-0 flex-1"><slot name="topbar" /></div>
        <slot name="topbar-right" />
      </header>
      <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:py-8">
        <slot />
      </main>
    </div>
  </div>
</template>
