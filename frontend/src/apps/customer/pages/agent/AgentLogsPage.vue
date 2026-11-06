<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { agentApi } from '@/shared/api/agent'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Tabs from '@/shared/ui/Tabs.vue'
import AnalyticsNav from '../analytics/AnalyticsNav.vue'
import { useAnalytics } from '../analytics/useAnalytics'

/** Append-only action log and the agent's memory (with retention). */
const { t } = useI18n()
const { workspaceId, siteId, onSite } = useAnalytics()
const tab = ref('logs')
const logs = useAsync(() => agentApi.logs(workspaceId.value, { site_id: siteId.value || undefined }))
const memories = useAsync(() => agentApi.memories(workspaceId.value, siteId.value || undefined))
onSite(() => Promise.all([logs.run(), memories.run()]))
</script>

<template>
  <div>
    <PageHeader :title="t('ag.title')" :description="t('ag.logs.subtitle')" />
    <AnalyticsNav active="logs" section="agent" all-sites />
    <Tabs v-model="tab" :tabs="[{ key: 'logs', label: t('ag.logs.tabLogs') }, { key: 'memory', label: t('ag.logs.tabMemory') }]" class="mb-4" />
    <AppCard v-if="tab === 'logs'" :padded="false">
      <LoadingState v-if="logs.loading.value && !logs.data.value" />
      <ErrorState v-else-if="logs.error.value" :error="logs.error.value" @retry="logs.run" />
      <EmptyState v-else-if="!logs.data.value?.data.length" icon="list" :title="t('ag.logs.empty')" />
      <ul v-else class="divide-y divide-line text-sm">
        <li v-for="l in logs.data.value.data" :key="l.id" class="px-4 py-2">
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-ink-muted">{{ formatDateTime(l.created_at) }}</span>
            <AppBadge :tone="l.level === 'error' ? 'danger' : l.level === 'warning' ? 'warning' : 'neutral'">{{ l.event }}</AppBadge>
            <span>{{ l.message }}</span>
            <RouterLink v-if="l.agent_run_id" :to="{ name: 'agent-run', params: { workspaceId, runId: l.agent_run_id } }" class="ml-auto text-xs text-brand-700 hover:underline">{{ t('ag.logs.openRun') }}</RouterLink>
            <span class="text-xs text-ink-muted">{{ t(`ag.actor.${l.actor_type}`) }}</span>
          </div>
        </li>
      </ul>
    </AppCard>
    <AppCard v-else :padded="false">
      <p class="border-b border-line px-4 py-3 text-xs text-ink-muted">{{ t('ag.logs.memoryHint') }}</p>
      <LoadingState v-if="memories.loading.value && !memories.data.value" />
      <ErrorState v-else-if="memories.error.value" :error="memories.error.value" @retry="memories.run" />
      <EmptyState v-else-if="!memories.data.value?.data.length" icon="info" :title="t('ag.logs.noMemory')" />
      <ul v-else class="divide-y divide-line text-sm">
        <li v-for="m in memories.data.value.data" :key="m.id" class="px-4 py-2">
          <div class="flex flex-wrap items-center gap-2">
            <AppBadge :tone="m.verified ? 'success' : 'neutral'">{{ m.verified ? t('ag.logs.verified') : t('ag.logs.unverified') }}</AppBadge>
            <AppBadge>{{ t(`ag.memoryKind.${m.kind}`, m.kind) }}</AppBadge>
            <span>{{ m.content }}</span>
            <span class="ml-auto text-xs text-ink-muted">{{ m.expires_at ? `${t('ag.logs.expires')} ${formatDateTime(m.expires_at)}` : '' }}</span>
          </div>
        </li>
      </ul>
    </AppCard>
  </div>
</template>
