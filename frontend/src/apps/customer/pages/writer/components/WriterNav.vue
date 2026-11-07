<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import SubNav from '@/shared/ui/SubNav.vue'
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
  <SubNav
    :items="[...main, ...more].map((i) => ({ key: i.key, label: 'icon' in i ? `${i.icon} ${t(`writer.nav.${i.key}`)}` : t(`writer.nav.${i.key}`), to: { name: i.route, params: { workspaceId: ws.current!.id } } }))"
    :active="props.active"
    :label="t('writer.title')"
    :max="main.length"
  />
</template>
