<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWorkspaceStore } from '../../../stores/workspace'

const props = defineProps<{ active: 'library' | 'wizard' | 'bulk' | 'presets' | 'history' }>()
const { t } = useI18n()
const ws = useWorkspaceStore()
const items = computed(() => {
  const canEdit = ws.can('content.edit') && !ws.current?.impersonating
  return [
    { key: 'library', route: 'writer' },
    ...(canEdit ? [{ key: 'wizard', route: 'writer-new' }, { key: 'bulk', route: 'writer-bulk' }] : []),
    { key: 'presets', route: 'writer-presets' },
    { key: 'history', route: 'writer-history' },
  ]
})
</script>

<template>
  <nav class="-mt-2 mb-6 flex gap-1 overflow-x-auto border-b border-line" :aria-label="t('writer.title')">
    <RouterLink
      v-for="i in items"
      :key="i.key"
      :to="{ name: i.route, params: { workspaceId: ws.current!.id } }"
      class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
      :class="props.active === i.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:text-ink'"
    >
      {{ t(`writer.nav.${i.key}`) }}
    </RouterLink>
  </nav>
</template>
