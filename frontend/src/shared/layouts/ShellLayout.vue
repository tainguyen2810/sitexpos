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
  <div class="min-h-screen bg-slate-50">
    <!-- Mobile drawer backdrop -->
    <div v-if="mobileOpen" class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" @click="mobileOpen = false" />

    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-line bg-surface transition-transform lg:translate-x-0"
      :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-line px-5">
        <span class="grid size-8 place-items-center rounded-lg bg-brand-600 text-xs font-bold text-white shadow-xs">SX</span>
        <span class="text-sm font-semibold tracking-tight text-ink">SiteX Post</span>
        <span v-if="brandSuffix" class="ml-auto rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-ink-muted">{{ brandSuffix }}</span>
      </div>
      <div class="px-3 pt-4"><slot name="sidebar-top" /></div>
      <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4" :aria-label="brandSuffix ?? 'main'">
        <template v-for="item in nav" :key="item.label">
          <RouterLink
            v-if="item.to && !item.soon"
            :to="item.to"
            class="group flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-ink-muted transition-colors hover:bg-slate-50 hover:text-brand-700"
            :active-class="item.to === '/' ? '' : '!bg-brand-50 !font-semibold !text-brand-700'"
            :exact-active-class="item.to === '/' ? '!bg-brand-50 !font-semibold !text-brand-700' : ''"
          >
            <AppIcon :name="item.icon" class="shrink-0 opacity-75 group-hover:opacity-100" />
            {{ item.label }}
          </RouterLink>
          <span v-else class="flex cursor-not-allowed items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-ink-subtle" :title="$t('common.comingSoon')">
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
      <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface/90 px-4 shadow-xs backdrop-blur sm:px-6 lg:px-8">
        <button type="button" class="-m-1 rounded-md p-2 text-ink-muted hover:bg-slate-100 lg:hidden" :aria-label="$t('nav.openMenu')" @click="mobileOpen = true">
          <AppIcon name="menu" />
        </button>
        <div class="min-w-0 flex-1"><slot name="topbar" /></div>
        <slot name="topbar-right" />
      </header>
      <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <slot />
      </main>
    </div>
  </div>
</template>
