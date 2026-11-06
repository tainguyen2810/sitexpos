<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWorkspaceStore } from '../../stores/workspace'
import { AGENT_NAV, ANALYTICS_NAV, useAnalytics } from './useAnalytics'

/** Section tabs + website selector shared by every Phase 8 page. */
const props = withDefaults(defineProps<{ active: string; section?: 'analytics' | 'agent'; siteSelect?: boolean; allSites?: boolean }>(), {
  section: 'analytics',
  siteSelect: true,
  allSites: false,
})
const { t } = useI18n()
const ws = useWorkspaceStore()
const { sites, siteId, loadSites } = useAnalytics()
const items = computed(() =>
  props.section === 'agent'
    ? AGENT_NAV.map((i) => ({ key: i.key, route: i.route, label: t(`ag.nav.${i.key}`) }))
    : ANALYTICS_NAV.filter((i) => !('configure' in i) || ws.can('sites.manage')).map((i) => ({ key: i.key, route: i.route, label: t(`an.nav.${i.key}`) })),
)
onMounted(() => void loadSites())
</script>

<template>
  <div class="-mt-2 mb-6 space-y-3">
    <nav class="flex gap-1 overflow-x-auto border-b border-line" :aria-label="section === 'agent' ? t('ag.title') : t('an.title')">
      <RouterLink
        v-for="i in items"
        :key="i.key"
        :to="{ name: i.route, params: { workspaceId: ws.current!.id }, query: siteId ? { site: siteId } : {} }"
        class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
        :class="props.active === i.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:text-ink'"
        :data-testid="`an-nav-${i.key}`"
      >
        {{ i.label }}
      </RouterLink>
    </nav>
    <div v-if="siteSelect && sites.length" class="flex flex-wrap items-center gap-2 text-sm">
      <label for="an-site" class="text-ink-muted">{{ t('an.common.site') }}</label>
      <select id="an-site" v-model="siteId" class="rounded-lg border border-line bg-surface px-3 py-1.5 text-sm" data-testid="an-site-select">
        <option v-if="allSites" value="">{{ t('an.common.allSites') }}</option>
        <option v-for="s in sites" :key="s.id" :value="s.id">{{ s.name }}</option>
      </select>
      <slot />
    </div>
  </div>
</template>
