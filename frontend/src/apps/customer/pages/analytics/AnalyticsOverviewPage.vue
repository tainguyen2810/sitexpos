<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { analyticsApi, defaultPeriod, healthTone, num, pct, type Filters } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDate } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import StatCard from '@/shared/ui/StatCard.vue'
import AnalyticsNav from './AnalyticsNav.vue'
import PeriodFilters from './PeriodFilters.vue'
import RankedTable from './RankedTable.vue'
import TrendChart from './TrendChart.vue'
import { useAnalytics } from './useAnalytics'

const { t } = useI18n()
const { workspaceId, siteId, onSite, sites } = useAnalytics()
const filters = ref<Filters>({ ...defaultPeriod(), search_type: 'web' })
const { data, error, loading, run } = useAsync(() => analyticsApi.overview(workspaceId.value, { ...filters.value, site_id: siteId.value || undefined }))
onSite(run)
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.overview.subtitle')" />
    <AnalyticsNav active="overview" all-sites />
    <PeriodFilters v-model="filters" class="mb-4" @apply="run" />

    <LoadingState v-if="loading && !data" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <template v-else-if="data">
      <EmptyState v-if="!data.data.has_data" icon="chart" :title="t('an.common.noData')" :description="sites.length ? t('an.overview.noDataHint') : t('an.overview.noSites')">
        <RouterLink :to="{ name: 'analytics-connections', params: { workspaceId } }" class="text-sm font-medium text-brand-700 hover:underline">{{ t('an.overview.connect') }}</RouterLink>
      </EmptyState>
      <template v-else>
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <StatCard :label="t('an.metrics.clicks')" :value="num(data.data.totals.clicks)" icon="activity" :delta="data.data.change.clicks" />
          <StatCard :label="t('an.metrics.impressions')" :value="num(data.data.totals.impressions)" icon="eye" :delta="data.data.change.impressions" />
          <StatCard :label="t('an.metrics.ctr')" :value="pct(data.data.totals.ctr)" icon="target" />
          <StatCard :label="t('an.metrics.position')" :value="num(data.data.totals.position, 1)" icon="trending" />
          <StatCard
            :label="t('an.nav.opportunities')"
            :value="data.data.opportunities_open"
            icon="zap"
            :to="`/w/${workspaceId}/analytics/opportunities`"
          />
        </div>
        <div class="grid gap-6 lg:grid-cols-3">
          <AppCard :title="t('an.overview.trend')" :subtitle="`${formatDate(data.data.period.from)} – ${formatDate(data.data.period.to)}`" class="lg:col-span-2">
            <TrendChart :series="data.data.series" />
          </AppCard>
          <AppCard :title="t('an.nav.health')">
            <div v-if="data.data.health" class="flex items-center gap-3">
              <span class="text-4xl font-semibold tabular-nums">{{ data.data.health.score ?? '—' }}</span>
              <AppBadge :tone="healthTone(data.data.health.score)">/100</AppBadge>
            </div>
            <p v-else class="text-sm text-ink-muted">{{ siteId ? t('an.common.noData') : t('an.overview.pickSite') }}</p>
            <p class="mt-3 text-xs text-ink-muted">{{ t('an.health.disclaimer') }}</p>
          </AppCard>
          <AppCard :title="t('an.overview.topQueries')" :padded="false" class="lg:col-span-3">
            <EmptyState v-if="!data.data.top_queries.length" icon="search" :title="t('an.common.noData')" />
            <RankedTable v-else :rows="data.data.top_queries" :label="t('an.metrics.query')" />
          </AppCard>
          <AppCard :title="t('an.overview.topPages')" :padded="false" class="lg:col-span-3">
            <EmptyState v-if="!data.data.top_pages.length" icon="file" :title="t('an.common.noData')" />
            <RankedTable v-else :rows="data.data.top_pages" :label="t('an.metrics.page')" link />
          </AppCard>
        </div>
      </template>
      <p class="mt-4 text-xs text-ink-muted" data-testid="gsc-note">{{ data.meta.note }} {{ t('an.common.latestDate') }}: {{ data.meta.latest_date ? formatDate(data.meta.latest_date) : t('an.common.noData') }}</p>
    </template>
  </div>
</template>
