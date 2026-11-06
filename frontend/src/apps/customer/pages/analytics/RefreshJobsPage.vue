<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { analyticsApi, micro, REFRESH_STATUSES, refreshTone } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AnalyticsNav from './AnalyticsNav.vue'
import { useAnalytics } from './useAnalytics'

const { t } = useI18n()
const { workspaceId, siteId, onSite } = useAnalytics()
const status = ref('')
const { data, error, loading, run } = useAsync(() => analyticsApi.refreshJobs(workspaceId.value, { site_id: siteId.value || undefined, status: status.value || undefined }))
onSite(run)
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.refresh.subtitle')" />
    <AnalyticsNav active="refresh" all-sites>
      <FilterSelect v-model="status" :label="t('an.common.status')" :options="[{ value: '', label: t('an.filters.allStatuses') }, ...REFRESH_STATUSES.map((s) => ({ value: s, label: t(`an.refresh.status.${s}`) }))]" @update:model-value="run" />
    </AnalyticsNav>
    <ol class="mb-6 grid grid-cols-1 gap-2 text-xs text-ink-muted sm:grid-cols-2 lg:grid-cols-4" :aria-label="t('an.refresh.flowTitle')">
      <li v-for="(s, i) in ['check', 'brief', 'draft', 'factcheck', 'approve', 'seo', 'publish', 'monitor']" :key="s" class="rounded-lg border border-line bg-surface p-2">
        <span class="font-semibold text-ink">{{ i + 1 }}.</span> {{ t(`an.refresh.flow.${s}`) }}
      </li>
    </ol>
    <AppCard :padded="false">
      <LoadingState v-if="loading && !data" />
      <ErrorState v-else-if="error" :error="error" @retry="run" />
      <EmptyState v-else-if="!data?.data.length" icon="refresh" :title="t('an.refresh.empty')" :description="t('an.refresh.emptyHint')" />
      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
            <tr>
              <th class="px-4 py-2">{{ t('an.refresh.job') }}</th>
              <th class="px-4 py-2">{{ t('an.common.status') }}</th>
              <th class="px-4 py-2 text-right">{{ t('an.opportunities.cost') }}</th>
              <th class="px-4 py-2">{{ t('an.common.updatedAt') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="j in data.data" :key="j.id">
              <td class="px-4 py-2">
                <RouterLink :to="{ name: 'analytics-refresh-job', params: { workspaceId, refreshId: j.id } }" class="font-mono text-xs text-brand-700 hover:underline">{{ j.id }}</RouterLink>
                <AppBadge v-if="j.agent_task_id" tone="brand" class="ml-1">{{ t('an.refresh.byAgent') }}</AppBadge>
                <AppBadge v-if="j.is_demo" class="ml-1">DEMO</AppBadge>
              </td>
              <td class="px-4 py-2">
                <AppBadge :tone="refreshTone(j.status)">{{ t(`an.refresh.status.${j.status}`) }}</AppBadge>
                <p v-if="j.error_code" class="mt-1 text-xs text-danger-600">{{ t(`an.errors.${j.error_code}`, j.error_message ?? j.error_code) }}</p>
              </td>
              <td class="px-4 py-2 text-right tabular-nums">{{ micro(j.estimated_cost_micro_usd) }}</td>
              <td class="px-4 py-2">{{ formatDateTime(j.updated_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppCard>
  </div>
</template>
