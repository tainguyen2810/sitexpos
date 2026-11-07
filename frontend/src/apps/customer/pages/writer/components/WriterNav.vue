<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWorkspaceStore } from '../../../stores/workspace'

const props = defineProps<{ active: 'library' | 'wizard' | 'bulk' | 'presets' | 'history' }>()
const { t } = useI18n()
const ws = useWorkspaceStore()
const canEdit = computed(() => ws.can('content.edit') && !ws.current?.impersonating)
// Main tabs: write → articles → styles. Rarely used pages sit on the right as small links.
const main = computed(() => [
  ...(canEdit.value ? [{ key: 'wizard', route: 'writer-new', icon: '✍️' }] : []),
  { key: 'library', route: 'writer', icon: '📄' },
  { key: 'presets', route: 'writer-presets', icon: '🎨' },
])
const more = computed(() => [...(canEdit.value ? [{ key: 'bulk', route: 'writer-bulk' }] : []), { key: 'history', route: 'writer-history' }])
</script>

<template>
  <nav class="-mt-2 mb-6 flex items-end gap-1 overflow-x-auto border-b border-line" :aria-label="t('writer.title')">
    <RouterLink
      v-for="i in main"
      :key="i.key"
      :to="{ name: i.route, params: { workspaceId: ws.current!.id } }"
      class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
      :class="props.active === i.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:text-ink'"
      :aria-current="props.active === i.key ? 'page' : undefined"
    >
      <span aria-hidden="true" class="mr-1">{{ i.icon }}</span>{{ t(`writer.nav.${i.key}`) }}
    </RouterLink>
    <span class="ml-auto flex items-center gap-1 whitespace-nowrap py-2 pl-4 text-xs text-ink-muted">
      <span>{{ t('writer.nav.more') }}</span>
      <template v-for="(i, n) in more" :key="i.key">
        <span v-if="n > 0" aria-hidden="true">·</span>
        <RouterLink
          :to="{ name: i.route, params: { workspaceId: ws.current!.id } }"
          class="hover:text-ink"
          :class="props.active === i.key ? 'font-medium text-brand-700' : ''"
        >{{ t(`writer.nav.${i.key}`) }}</RouterLink>
      </template>
    </span>
  </nav>
</template>
