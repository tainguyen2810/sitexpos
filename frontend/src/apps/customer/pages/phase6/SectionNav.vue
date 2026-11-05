<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWorkspaceStore } from '../../stores/workspace'
import { SECTION_NAV, sectionLocaleNs, type Phase6Section } from './sectionNav'

const props = defineProps<{ section: Phase6Section; active: string }>()
const { t } = useI18n()
const ws = useWorkspaceStore()
const items = computed(() => SECTION_NAV[props.section])
const ns = computed(() => sectionLocaleNs(props.section))
</script>

<template>
  <nav class="-mt-2 mb-6 flex gap-1 overflow-x-auto border-b border-line" :aria-label="t(`p6.${ns}.title`)">
    <RouterLink
      v-for="i in items"
      :key="i.key"
      :to="{ name: i.route, params: { workspaceId: ws.current!.id } }"
      class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
      :class="props.active === i.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:text-ink'"
      :data-testid="`p6-nav-${i.key}`"
    >
      {{ t(`p6.${ns}.nav.${i.key}`) }}
    </RouterLink>
  </nav>
</template>
