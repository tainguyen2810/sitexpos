<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { analyticsApi, classTone, defaultPeriod, num, pct, signedPct, type Filters } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import SearchInput from '@/shared/ui/SearchInput.vue'
import AnalyticsNav from './AnalyticsNav.vue'
import PeriodFilters from './PeriodFilters.vue'
import { useAnalytics } from './useAnalytics'

/** SiteX articles with their Search Console performance (exact URL mapping only). */
const { t } = useI18n()
const { workspaceId, siteId, onSite } = useAnalytics()
const filters = ref<Filters>({ ...defaultPeriod(), search_type: 'web' })
const search = ref('')
const { data, error, loading, run } = useAsync(() => analyticsApi.articles(workspaceId.value, { ...filters.value, site_id: siteId.value || undefined }))
onSite(run)
const rows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return (data.value?.data ?? []).filter((a) => !q || a.title.toLowerCase().includes(q) || (a.primary_keyword ?? '').toLowerCase().includes(q))
})
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.articles.subtitle')" />
    <AnalyticsNav active="articles" all-sites>
      <SearchInput v-model="search" :placeholder="t('an.articles.search')" class="ml-auto" />
    </AnalyticsNav>
    <PeriodFilters v-model="filters" class="mb-4" @apply="run" />
    <AppCard :padded="false">
      <LoadingState v-if="loading && !data" />
      <ErrorState v-else-if="error" :error="error" @retry="run" />
      <EmptyState v-else-if="!rows.length" icon="pen" :title="t('an.articles.empty')" />
      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
            <tr>
              <th class="px-4 py-2">{{ t('an.articles.article') }}</th>
              <th class="px-4 py-2">{{ t('an.articles.class') }}</th>
              <th class="px-4 py-2 text-right">{{ t('an.metrics.clicks') }}</th>
              <th class="px-4 py-2 text-right">{{ t('an.metrics.clicks_change') }}</th>
              <th class="px-4 py-2 text-right">{{ t('an.metrics.ctr') }}</th>
              <th class="px-4 py-2 text-right">{{ t('an.metrics.position') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="a in rows" :key="a.id">
              <td class="max-w-md px-4 py-2">
                <RouterLink :to="{ name: 'analytics-article', params: { workspaceId, articleId: a.id } }" class="font-medium text-brand-700 hover:underline">{{ a.title }}</RouterLink>
                <p class="text-xs text-ink-muted">{{ a.primary_keyword ?? '—' }}</p>
              </td>
              <td class="px-4 py-2">
                <AppBadge v-if="a.classification" :tone="classTone(a.classification.value)">{{ t(`an.class.${a.classification.value}`) }}</AppBadge>
                <span v-else class="text-xs text-ink-muted">{{ t('an.common.noData') }}</span>
              </td>
              <template v-if="a.performance">
                <td class="px-4 py-2 text-right tabular-nums">{{ num(a.performance.clicks) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ signedPct(a.performance.clicks_change) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ pct(a.performance.ctr) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ num(a.performance.position, 1) }}</td>
              </template>
              <td v-else colspan="4" class="px-4 py-2 text-right text-xs text-ink-muted">{{ t('an.articles.notMapped') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppCard>
  </div>
</template>
