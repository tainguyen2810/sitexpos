<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppIcon, { type IconName } from '@/shared/ui/AppIcon.vue'

export interface NavItem {
  label: string
  to: string
  icon: IconName
  /** Small counter on the right (e.g. items waiting for the user). Hidden when 0/empty. */
  badge?: number | null
  badgeTone?: 'brand' | 'danger'
  /** Match the route exactly (used for "/" in the admin app). */
  exact?: boolean
}

export interface NavGroup {
  /** Empty label = no heading (first group). */
  label: string
  items: NavItem[]
}

defineProps<{ groups: NavGroup[]; brandSuffix?: string }>()

const route = useRoute()
const mobileOpen = ref(false)
watch(() => route.fullPath, () => (mobileOpen.value = false))
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <div v-if="mobileOpen" class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" @click="mobileOpen = false" />

    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-line bg-surface transition-transform lg:translate-x-0"
      :class="mobileOpen ? 'translate-x-0 shadow-xl' : '-translate-x-full'"
    >
      <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-line px-5">
        <span class="grid size-8 place-items-center rounded-lg bg-brand-600 text-xs font-bold text-white shadow-xs">SX</span>
        <span class="text-sm font-semibold tracking-tight text-ink">SiteX Post</span>
        <span v-if="brandSuffix" class="rounded-md bg-brand-50 px-1.5 py-0.5 text-[11px] font-semibold text-brand-700">{{ brandSuffix }}</span>
        <button type="button" class="ml-auto rounded-md p-1 text-ink-muted hover:bg-slate-100 lg:hidden" :aria-label="$t('nav.closeMenu')" @click="mobileOpen = false">
          <AppIcon name="x" :size="18" />
        </button>
      </div>
      <div v-if="$slots['sidebar-top']" class="px-3 pt-3"><slot name="sidebar-top" /></div>
      <nav class="flex-1 overflow-y-auto px-3 py-3" :aria-label="$t('nav.main')">
        <div v-for="g in groups" :key="g.label || 'root'" class="mb-3 last:mb-0">
          <p v-if="g.label" class="mb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-ink-subtle">{{ g.label }}</p>
          <RouterLink
            v-for="item in g.items"
            :key="item.to"
            :to="item.to"
            class="group flex items-center gap-3 rounded-md px-3 py-1.5 text-sm font-medium text-ink-muted transition-colors hover:bg-slate-50 hover:text-ink"
            :active-class="item.exact ? '' : '!bg-brand-50 !font-semibold !text-brand-700'"
            :exact-active-class="item.exact ? '!bg-brand-50 !font-semibold !text-brand-700' : ''"
          >
            <AppIcon :name="item.icon" :size="18" class="shrink-0 opacity-80 group-hover:opacity-100" />
            <span class="truncate">{{ item.label }}</span>
            <span
              v-if="item.badge"
              class="ml-auto rounded-full px-1.5 text-[11px] font-semibold tabular-nums"
              :class="item.badgeTone === 'danger' ? 'bg-danger-100 text-danger-700' : 'bg-brand-100 text-brand-700'"
            >{{ item.badge > 99 ? '99+' : item.badge }}</span>
          </RouterLink>
        </div>
      </nav>
      <div v-if="$slots['sidebar-bottom']" class="border-t border-line p-3"><slot name="sidebar-bottom" /></div>
    </aside>

    <div class="lg:pl-64">
      <slot name="banner" />
      <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur sm:px-6 lg:px-8">
        <button type="button" class="-m-1 rounded-md p-2 text-ink-muted hover:bg-slate-100 lg:hidden" :aria-label="$t('nav.openMenu')" @click="mobileOpen = true">
          <AppIcon name="menu" />
        </button>
        <div class="min-w-0 flex-1"><slot name="topbar" /></div>
        <div class="flex shrink-0 items-center gap-2 sm:gap-3"><slot name="topbar-right" /></div>
      </header>
      <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <slot />
      </main>
      <slot name="floating" />
    </div>
  </div>
</template>
