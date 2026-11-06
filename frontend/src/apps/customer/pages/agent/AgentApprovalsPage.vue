<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { agentApi, TASK_STATUSES } from '@/shared/api/agent'
import { useAsync } from '@/shared/composables/useAsync'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AnalyticsNav from '../analytics/AnalyticsNav.vue'
import { useAnalytics } from '../analytics/useAnalytics'
import AgentTaskCard from './AgentTaskCard.vue'
import { useTaskActions } from './useTaskActions'

/** Approval queue: every task the policy engine sent to a person. */
const { t } = useI18n()
const { workspaceId, siteId, onSite, canEdit } = useAnalytics()
const status = ref('awaiting_approval')
const { data, error, loading, run } = useAsync(() => agentApi.tasks(workspaceId.value, { site_id: siteId.value || undefined, status: status.value }))
onSite(run)
const { busy, act } = useTaskActions(run)
</script>

<template>
  <div>
    <PageHeader :title="t('ag.title')" :description="t('ag.approvals.subtitle')" />
    <AnalyticsNav active="approvals" section="agent" all-sites>
      <FilterSelect v-model="status" :label="t('an.common.status')" :options="TASK_STATUSES.map((s) => ({ value: s, label: t(`ag.taskStatus.${s}`) }))" @update:model-value="run" />
    </AnalyticsNav>
    <p class="mb-4 rounded-lg bg-surface-muted p-3 text-xs text-ink-muted">{{ t('ag.approvals.hint') }}</p>
    <AppCard :padded="false">
      <LoadingState v-if="loading && !data" />
      <ErrorState v-else-if="error" :error="error" @retry="run" />
      <EmptyState v-else-if="!data?.data.length" icon="check" :title="t('ag.approvals.empty')" />
      <div v-else class="divide-y divide-line">
        <AgentTaskCard
          v-for="task in data.data"
          :key="task.id"
          :task="task"
          :can-act="canEdit"
          :busy="busy === task.id"
          @approve="(n) => act(task, 'approve', n)"
          @reject="(n) => act(task, 'reject', n)"
          @retry="act(task, 'retry')"
        />
      </div>
    </AppCard>
  </div>
</template>
