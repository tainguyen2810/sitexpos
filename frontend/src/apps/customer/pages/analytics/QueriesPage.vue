<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { analyticsApi, defaultPeriod, type Filters } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AnalyticsNav from './AnalyticsNav.vue'
import PeriodFilters from './PeriodFilters.vue'
import RankedTable from './RankedTable.vue'
import { useAnalytics } from './useAnalytics'

const { t } = useI18n()
const { workspaceId, siteId, onSite } = useAnalytics()
const filters = ref<Filters>({ ...defaultPeriod(), search_type: 'web', sort: 'clicks', limit: 200 })
const { data, error, loading, run } = useAsync(() => analyticsApi.queries(workspaceId.value, { ...filters.value, site_id: siteId.value || undefined }))
onSite(run)
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.queries.subtitle')" />
    <AnalyticsNav active="queries" all-sites />
    <PeriodFilters v-model="filters" sort search class="mb-4" @apply="run" />
    <AppCard :padded="false">
      <LoadingState v-if="loading && !data" />
      <ErrorState v-else-if="error" :error="error" @retry="run" />
      <EmptyState v-else-if="!data?.data.length" icon="search" :title="t('an.common.noData')" :description="t('an.queries.empty')" />
      <RankedTable v-else :rows="data.data" :label="t('an.metrics.query')" />
    </AppCard>
    <p v-if="data" class="mt-3 text-xs text-ink-muted">{{ data.meta.note }} {{ t('an.queries.anonymized') }}</p>
  </div>
</template>
