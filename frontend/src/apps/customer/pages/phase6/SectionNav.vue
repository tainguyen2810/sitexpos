<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWorkspaceStore } from '../../stores/workspace'

const props = defineProps<{ section: 'media' | 'seo' | 'publishing'; active: string }>()
const { t } = useI18n()
const ws = useWorkspaceStore()
const items = computed(() => {
  const map: Record<string, { key: string; route: string }[]> = {
    media: [
      { key: 'studio', route: 'media' },
      { key: 'generator', route: 'media-generate' },
      { key: 'library', route: 'media-library' },
      { key: 'articles', route: 'media-article' },
    ],
    seo: [
      { key: 'audit', route: 'seo' },
      { key: 'metadata', route: 'seo-metadata' },
      { key: 'links', route: 'seo-links' },
    ],
    publishing: [
      { key: 'center', route: 'publishing' },
      { key: 'calendar', route: 'publishing-calendar' },
      { key: 'history', route: 'publishing-history' },
      { key: 'conflicts', route: 'publishing-conflicts' },
      { key: 'settings', route: 'publishing-settings' },
    ],
  }
  return map[props.section]!
})
</script>

<template>
  <nav class="-mt-2 mb-6 flex gap-1 overflow-x-auto border-b border-line" :aria-label="t(`p6.${section}.title`)">
    <RouterLink
      v-for="i in items"
      :key="i.key"
      :to="{ name: i.route, params: { workspaceId: ws.current!.id } }"
      class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
      :class="props.active === i.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:text-ink'"
      :data-testid="`p6-nav-${i.key}`"
    >
      {{ t(`p6.${section}.nav.${i.key}`) }}
    </RouterLink>
  </nav>
</template>
