<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { analyticsApi, classTone, defaultPeriod, idempotencyKey, num, pct, refreshTone, severityTone } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDate } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import StatCard from '@/shared/ui/StatCard.vue'
import TrendChart from './TrendChart.vue'
import { useAnalytics } from './useAnalytics'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { workspaceId, canEdit, toastError } = useAnalytics()
const articleId = computed(() => String(route.params.articleId))
const period = ref(defaultPeriod(new Date(), 3, 90))
const { data, error, loading, run } = useAsync(() => analyticsApi.article(workspaceId.value, articleId.value, period.value))
const confirmRefresh = ref(false)
const starting = ref(false)
const openRefresh = computed(() => data.value?.data.refresh_jobs.find((j) => !['completed', 'conflict', 'failed', 'cancelled', 'seo_blocked', 'monitoring'].includes(j.status)) ?? null)

async function startRefresh() {
  starting.value = true
  try {
    const res = await analyticsApi.createRefresh(workspaceId.value, articleId.value, null, idempotencyKey('refresh'))
    confirmRefresh.value = false
    await router.push({ name: 'analytics-refresh-job', params: { workspaceId: workspaceId.value, refreshId: res.data.id } })
  } catch (e) {
    toastError(e)
  } finally {
    starting.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader
      :title="data?.data.article.title ?? t('an.article.title')"
      :description="t('an.article.subtitle')"
      :crumbs="[{ label: t('an.nav.articles'), to: `/w/${workspaceId}/analytics/articles` }, { label: data?.data.article.title ?? '…' }]"
    />
    <LoadingState v-if="loading && !data" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <template v-else-if="data">
      <div class="mb-4 flex flex-wrap items-center gap-2">
        <AppBadge v-if="data.data.snapshot" :tone="classTone(data.data.snapshot.classification)">{{ t(`an.class.${data.data.snapshot.classification}`) }}</AppBadge>
        <AppBadge v-for="l in data.data.snapshot?.labels ?? []" :key="l" tone="neutral">{{ t(`an.labels.${l}`, l) }}</AppBadge>
        <span class="text-sm text-ink-muted">{{ data.data.article.primary_keyword }}</span>
        <div class="ml-auto flex gap-2">
          <RouterLink v-if="openRefresh" :to="{ name: 'analytics-refresh-job', params: { workspaceId, refreshId: openRefresh.id } }" class="text-sm font-medium text-brand-700 hover:underline">
            {{ t('an.article.openRefresh') }}
          </RouterLink>
          <AppButton v-else-if="canEdit && data.data.article.status === 'APPROVED'" size="sm" @click="confirmRefresh = true">{{ t('an.article.refresh') }}</AppButton>
        </div>
      </div>
      <p v-if="data.data.mapping_note" class="mb-4 rounded-lg bg-warning-50 p-3 text-sm text-warning-700">{{ data.data.mapping_note }}</p>
      <ul v-for="r in data.data.snapshot?.reasons ?? []" :key="r" class="mb-1 text-sm text-ink-muted">
        <li>• {{ r }}</li>
      </ul>

      <div class="my-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
        <StatCard :label="t('an.metrics.clicks')" :value="num(data.data.totals.clicks)" icon="activity" />
        <StatCard :label="t('an.metrics.impressions')" :value="num(data.data.totals.impressions)" icon="eye" />
        <StatCard :label="t('an.metrics.ctr')" :value="pct(data.data.totals.ctr)" icon="target" />
        <StatCard :label="t('an.metrics.position')" :value="num(data.data.totals.position, 1)" icon="trending" />
      </div>
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <AppCard :title="t('an.article.trend')" class="lg:col-span-2">
          <EmptyState v-if="!data.data.series.length" icon="chart" :title="t('an.common.noData')" />
          <TrendChart v-else :series="data.data.series" />
        </AppCard>
        <AppCard :title="t('an.article.urls')">
          <EmptyState v-if="!data.data.urls.length" icon="link" :title="t('an.article.noUrls')" />
          <ul v-else class="space-y-2 text-sm">
            <li v-for="u in data.data.urls" :key="u.url" class="break-all">
              {{ u.url }} <AppBadge v-if="u.is_current" tone="success">{{ t('an.article.current') }}</AppBadge>
              <span class="block text-xs text-ink-muted">{{ t(`an.article.source.${u.source}`, u.source) }}</span>
            </li>
          </ul>
        </AppCard>
        <AppCard :title="t('an.article.queries')" :subtitle="data.data.queries_period_end ? `${t('an.common.until')} ${formatDate(data.data.queries_period_end)}` : undefined" :padded="false" class="lg:col-span-2">
          <EmptyState v-if="!data.data.queries.length" icon="search" :title="t('an.common.noData')" />
          <div v-else class="overflow-x-auto">
            <table class="data-table min-w-full text-sm">
              <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
                <tr>
                  <th class="px-4 py-2">{{ t('an.metrics.query') }}</th>
                  <th class="px-4 py-2 text-right">{{ t('an.metrics.clicks') }}</th>
                  <th class="px-4 py-2 text-right">{{ t('an.metrics.impressions') }}</th>
                  <th class="px-4 py-2 text-right">{{ t('an.metrics.ctr') }}</th>
                  <th class="px-4 py-2 text-right">{{ t('an.metrics.position') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-line">
                <tr v-for="q in data.data.queries" :key="q.query">
                  <td class="px-4 py-2">{{ q.query }}</td>
                  <td class="px-4 py-2 text-right tabular-nums">{{ num(q.clicks) }}</td>
                  <td class="px-4 py-2 text-right tabular-nums">{{ num(q.impressions) }}</td>
                  <td class="px-4 py-2 text-right tabular-nums">{{ pct(q.ctr) }}</td>
                  <td class="px-4 py-2 text-right tabular-nums">{{ num(q.position, 1) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </AppCard>
        <div class="space-y-6">
          <AppCard :title="t('an.nav.opportunities')">
            <EmptyState v-if="!data.data.opportunities.length" icon="zap" :title="t('an.opportunities.empty')" />
            <ul v-else class="space-y-2 text-sm">
              <li v-for="o in data.data.opportunities" :key="o.id">
                <AppBadge :tone="severityTone(o.severity)">{{ t(`an.severity.${o.severity}`) }}</AppBadge>
                {{ t(`an.rules.${o.rule_code}.name`) }}
              </li>
            </ul>
          </AppCard>
          <AppCard :title="t('an.nav.refresh')">
            <EmptyState v-if="!data.data.refresh_jobs.length" icon="refresh" :title="t('an.refresh.empty')" />
            <ul v-else class="space-y-2 text-sm">
              <li v-for="j in data.data.refresh_jobs" :key="j.id">
                <RouterLink :to="{ name: 'analytics-refresh-job', params: { workspaceId, refreshId: j.id } }" class="hover:underline">
                  <AppBadge :tone="refreshTone(j.status)">{{ t(`an.refresh.status.${j.status}`) }}</AppBadge>
                  {{ formatDate(j.created_at) }}
                </RouterLink>
              </li>
            </ul>
          </AppCard>
          <AppCard :title="t('an.nav.experiments')">
            <EmptyState v-if="!data.data.experiments.length" icon="activity" :title="t('an.experiments.empty')" />
            <ul v-else class="space-y-2 text-sm">
              <li v-for="e in data.data.experiments" :key="e.id">
                {{ t(`an.experiments.change.${e.change_type}`) }} · {{ formatDate(e.change_date) }} ·
                <span class="text-ink-muted">{{ e.outcome ? t(`an.experiments.outcome.${e.outcome}`) : t(`an.experiments.status.${e.status}`) }}</span>
              </li>
            </ul>
          </AppCard>
        </div>
      </div>
      <p class="mt-4 text-xs text-ink-muted">{{ data.meta.note }}</p>
    </template>
    <ConfirmDialog
      :open="confirmRefresh"
      :title="t('an.article.refresh')"
      :message="t('an.article.refreshConfirm')"
      :confirm-label="t('an.article.refreshStart')"
      :loading="starting"
      @close="confirmRefresh = false"
      @confirm="startRefresh"
    />
  </div>
</template>
