<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { analyticsApi, CLASSES, classTone, num, pct, signedPct } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDate } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AnalyticsNav from './AnalyticsNav.vue'
import { useAnalytics } from './useAnalytics'

/**
 * Content intelligence: every page classified by explicit thresholds
 * (GROWING / STABLE / DECLINING / UNDERPERFORMING / INSUFFICIENT_DATA /
 * RECENTLY_PUBLISHED) with the reasons, plus topic-cluster coverage.
 */
const { t } = useI18n()
const { workspaceId, siteId, onSite } = useAnalytics()
const cls = ref('')
const content = useAsync(() => analyticsApi.content(workspaceId.value, siteId.value || undefined, cls.value || undefined))
const clusters = useAsync(() => (siteId.value ? analyticsApi.clusters(workspaceId.value, siteId.value) : Promise.resolve({ data: [] })))
onSite(() => Promise.all([content.run(), clusters.run()]))
const counts = computed(() => content.data.value?.meta.counts ?? {})
function pick(c: string) {
  cls.value = cls.value === c ? '' : c
  void content.run()
}
function change(cur: number, prev: number | null): number | null {
  return prev === null || prev === 0 ? null : ((cur - prev) / prev) * 100
}
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.content.subtitle')" />
    <AnalyticsNav active="content" />
    <div class="mb-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
      <button
        v-for="c in CLASSES"
        :key="c"
        type="button"
        class="rounded-xl border p-3 text-left transition"
        :class="cls === c ? 'border-brand-500 bg-brand-50' : 'border-line bg-surface hover:border-brand-200'"
        :data-testid="`class-${c}`"
        @click="pick(c)"
      >
        <AppBadge :tone="classTone(c)">{{ t(`an.class.${c}`) }}</AppBadge>
        <p class="mt-2 text-2xl font-semibold tabular-nums">{{ counts[c] ?? 0 }}</p>
        <p class="text-xs text-ink-muted">{{ t(`an.classHint.${c}`) }}</p>
      </button>
    </div>
    <div class="grid gap-6 lg:grid-cols-3">
      <AppCard :title="t('an.content.pages')" :subtitle="content.data.value?.meta.period_end ? `${t('an.common.until')} ${formatDate(content.data.value.meta.period_end)}` : undefined" :padded="false" class="lg:col-span-2">
        <LoadingState v-if="content.loading.value && !content.data.value" />
        <ErrorState v-else-if="content.error.value" :error="content.error.value" @retry="content.run" />
        <EmptyState v-else-if="!content.data.value?.data.length" icon="layers" :title="t('an.common.noData')" :description="t('an.content.empty')" />
        <ul v-else class="divide-y divide-line">
          <li v-for="s in content.data.value.data" :key="s.id" class="px-4 py-3 text-sm">
            <div class="flex flex-wrap items-center gap-2">
              <AppBadge :tone="classTone(s.classification)">{{ t(`an.class.${s.classification}`) }}</AppBadge>
              <AppBadge v-for="l in s.labels" :key="l">{{ t(`an.labels.${l}`, l) }}</AppBadge>
              <AppBadge v-if="s.is_demo">DEMO</AppBadge>
              <RouterLink v-if="s.article_id" :to="{ name: 'analytics-article', params: { workspaceId, articleId: s.article_id } }" class="truncate text-brand-700 hover:underline">{{ s.page_url }}</RouterLink>
              <span v-else class="truncate">{{ s.page_url }}</span>
            </div>
            <p class="mt-1 text-xs tabular-nums text-ink-muted">
              {{ num(s.clicks) }} {{ t('an.metrics.clicks') }} ({{ signedPct(change(s.clicks, s.prev_clicks)) }}) · {{ num(s.impressions) }} {{ t('an.metrics.impressions') }} · {{ t('an.metrics.ctr') }} {{ pct(s.ctr) }} ·
              {{ t('an.metrics.position') }} {{ num(s.position, 1) }} · {{ s.days_with_data }}/{{ s.window_days }} {{ t('an.common.days') }}
            </p>
            <ul class="mt-1 list-disc pl-5 text-xs text-ink-muted">
              <li v-for="r in s.reasons" :key="r">{{ r }}</li>
            </ul>
          </li>
        </ul>
      </AppCard>
      <AppCard :title="t('an.content.clusters')" :padded="false">
        <LoadingState v-if="clusters.loading.value && !clusters.data.value" />
        <ErrorState v-else-if="clusters.error.value" :error="clusters.error.value" @retry="clusters.run" />
        <EmptyState v-else-if="!clusters.data.value?.data.length" icon="target" :title="t('an.content.noClusters')" />
        <table v-else class="min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
            <tr><th class="px-4 py-2">{{ t('an.content.cluster') }}</th><th class="px-4 py-2 text-right">{{ t('an.content.ideas') }}</th><th class="px-4 py-2 text-right">{{ t('an.content.published') }}</th></tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="c in clusters.data.value.data" :key="c.name">
              <td class="px-4 py-2">{{ c.name }}</td>
              <td class="px-4 py-2 text-right tabular-nums">{{ c.ideas }}</td>
              <td class="px-4 py-2 text-right tabular-nums" :class="c.published === 0 ? 'text-warning-600' : ''">{{ c.published }}</td>
            </tr>
          </tbody>
        </table>
      </AppCard>
    </div>
  </div>
</template>
