<script setup lang="ts">
import AppIcon from '@/shared/ui/AppIcon.vue'

export interface Crumb {
  label: string
  to?: string
}
defineProps<{ title: string; description?: string; crumbs?: Crumb[] }>()
</script>

<template>
  <div class="mb-6">
    <nav v-if="crumbs?.length" aria-label="Breadcrumb" class="mb-2">
      <ol class="flex flex-wrap items-center gap-1 text-xs text-ink-muted">
        <li v-for="(c, i) in crumbs" :key="i" class="flex items-center gap-1">
          <RouterLink v-if="c.to && i < crumbs.length - 1" :to="c.to" class="hover:text-brand-700 hover:underline">{{ c.label }}</RouterLink>
          <span v-else :aria-current="i === crumbs.length - 1 ? 'page' : undefined" class="text-ink">{{ c.label }}</span>
          <AppIcon v-if="i < crumbs.length - 1" name="chevronRight" :size="12" class="text-ink-subtle" />
        </li>
      </ol>
    </nav>
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-xl font-semibold text-ink sm:text-2xl">{{ title }}</h1>
        <p v-if="description" class="mt-1 max-w-3xl text-sm text-ink-muted">{{ description }}</p>
      </div>
      <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
    </div>
  </div>
</template>
