<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { analyticsApi, defaultPeriod, num, pct, type Filters } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import AnalyticsNav from './AnalyticsNav.vue'
import PeriodFilters from './PeriodFilters.vue'
import RankedTable from './RankedTable.vue'
import { useAnalytics } from './useAnalytics'

const { t } = useI18n()
const { workspaceId, siteId, onSite } = useAnalytics()
const filters = ref<Filters>({ ...defaultPeriod(), search_type: 'web', sort: 'clicks', limit: 200 })
const mappedOnly = ref(false)
const scope = () => ({ ...filters.value, site_id: siteId.value || undefined })
const pages = useAsync(() => analyticsApi.pages(workspaceId.value, { ...scope(), mapped_only: mappedOnly.value }))
const devices = useAsync(() => analyticsApi.dimensions(workspaceId.value, scope(), 'device'))
const countries = useAsync(() => analyticsApi.dimensions(workspaceId.value, scope(), 'country'))
function reload() {
  void pages.run()
  void devices.run()
  void countries.run()
}
onSite(reload)
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.pages.subtitle')" />
    <AnalyticsNav active="pages" all-sites>
      <Toggle v-model="mappedOnly" :label="t('an.pages.mappedOnly')" class="ml-auto" @update:model-value="pages.run" />
    </AnalyticsNav>
    <PeriodFilters v-model="filters" sort search class="mb-4" @apply="reload" />
    <AppCard :padded="false" class="mb-6">
      <LoadingState v-if="pages.loading.value && !pages.data.value" />
      <ErrorState v-else-if="pages.error.value" :error="pages.error.value" @retry="pages.run" />
      <EmptyState v-else-if="!pages.data.value?.data.length" icon="file" :title="t('an.common.noData')" />
      <RankedTable v-else :rows="pages.data.value.data" :label="t('an.metrics.page')" link />
    </AppCard>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <AppCard v-for="d in [{ key: 'device', s: devices }, { key: 'country', s: countries }]" :key="d.key" :title="t(`an.pages.by.${d.key}`)" :padded="false">
        <LoadingState v-if="d.s.loading.value && !d.s.data.value" />
        <ErrorState v-else-if="d.s.error.value" :error="d.s.error.value" @retry="d.s.run" />
        <EmptyState v-else-if="!d.s.data.value?.data.length" icon="chart" :title="t('an.common.noData')" />
        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
              <tr>
                <th class="px-4 py-2">{{ t(`an.filters.${d.key}`) }}</th>
                <th class="px-4 py-2 text-right">{{ t('an.metrics.clicks') }}</th>
                <th class="px-4 py-2 text-right">{{ t('an.metrics.impressions') }}</th>
                <th class="px-4 py-2 text-right">{{ t('an.metrics.ctr') }}</th>
                <th class="px-4 py-2 text-right">{{ t('an.metrics.position') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="r in d.s.data.value.data.slice(0, 15)" :key="r.value">
                <td class="px-4 py-2 uppercase">{{ d.key === 'device' ? t(`an.device.${r.value}`, r.value) : r.value }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ num(r.clicks) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ num(r.impressions) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ pct(r.ctr) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ num(r.position, 1) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>
    </div>
  </div>
</template>
