<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { agentApi, RUN_KINDS, RUN_STATUSES, runTone, type RunKind } from '@/shared/api/agent'
import { idempotencyKey, micro } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AnalyticsNav from '../analytics/AnalyticsNav.vue'
import { useAnalytics } from '../analytics/useAnalytics'

const { t } = useI18n()
const { workspaceId, siteId, onSite, canEdit, toast, toastError } = useAnalytics()
const status = ref('')
const kind = ref<RunKind>('daily_monitoring')
const starting = ref(false)
const { data, error, loading, run } = useAsync(() => agentApi.runs(workspaceId.value, { site_id: siteId.value || undefined, status: status.value || undefined }))
onSite(run)

async function start() {
  if (!siteId.value) return
  starting.value = true
  try {
    await agentApi.startRun(workspaceId.value, siteId.value, kind.value, idempotencyKey('run'))
    toast.success(t('ag.runs.started'))
    await run()
  } catch (e) {
    toastError(e)
  } finally {
    starting.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('ag.title')" :description="t('ag.runs.subtitle')" />
    <AnalyticsNav active="runs" section="agent" all-sites>
      <FilterSelect v-model="status" :label="t('an.common.status')" :options="[{ value: '', label: t('an.filters.allStatuses') }, ...RUN_STATUSES.map((s) => ({ value: s, label: t(`ag.runStatus.${s}`) }))]" @update:model-value="run" />
    </AnalyticsNav>
    <AppCard v-if="canEdit && siteId" :title="t('ag.runs.start')" class="mb-6">
      <form class="flex flex-wrap items-end gap-3 text-sm" @submit.prevent="start">
        <label class="flex flex-col gap-1">
          <span class="text-xs text-ink-muted">{{ t('ag.runs.kind') }}</span>
          <select v-model="kind" class="rounded-lg border border-line bg-surface px-2 py-1.5">
            <option v-for="k in RUN_KINDS" :key="k" :value="k">{{ t(`ag.kinds.${k}`) }}</option>
          </select>
        </label>
        <AppButton type="submit" :loading="starting" data-testid="start-run">{{ t('ag.runs.startNow') }}</AppButton>
        <p class="text-xs text-ink-muted">{{ t('ag.runs.startHint') }}</p>
      </form>
    </AppCard>
    <AppCard :padded="false">
      <LoadingState v-if="loading && !data" />
      <ErrorState v-else-if="error" :error="error" @retry="run" />
      <EmptyState v-else-if="!data?.data.length" icon="cpu" :title="t('ag.runs.empty')" />
      <div v-else class="overflow-x-auto">
        <table class="data-table min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
            <tr>
              <th class="px-4 py-2">{{ t('ag.runs.kind') }}</th>
              <th class="px-4 py-2">{{ t('an.common.status') }}</th>
              <th class="px-4 py-2">{{ t('ag.runs.phase') }}</th>
              <th class="px-4 py-2">{{ t('ag.runs.level') }}</th>
              <th class="px-4 py-2 text-right">{{ t('ag.runs.cost') }}</th>
              <th class="px-4 py-2">{{ t('an.common.createdAt') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="r in data.data" :key="r.id">
              <td class="px-4 py-2">
                <RouterLink :to="{ name: 'agent-run', params: { workspaceId, runId: r.id } }" class="font-medium text-brand-700 hover:underline">{{ t(`ag.kinds.${r.kind}`) }}</RouterLink>
                <span class="ml-1 text-xs text-ink-muted">{{ t(`ag.trigger.${r.trigger}`, r.trigger) }}</span>
                <AppBadge v-if="r.is_demo" class="ml-1">DEMO</AppBadge>
              </td>
              <td class="px-4 py-2"><AppBadge :tone="runTone(r.status)">{{ t(`ag.runStatus.${r.status}`) }}</AppBadge></td>
              <td class="px-4 py-2">{{ t(`ag.phases.${r.phase}`) }}</td>
              <td class="px-4 py-2">{{ r.autonomy_level }}</td>
              <td class="px-4 py-2 text-right tabular-nums">{{ micro(r.actual_cost_micro_usd) }}</td>
              <td class="px-4 py-2">{{ formatDateTime(r.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppCard>
  </div>
</template>
