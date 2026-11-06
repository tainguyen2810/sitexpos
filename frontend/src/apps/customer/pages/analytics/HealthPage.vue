<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { analyticsApi, HEALTH_COMPONENTS, healthTone, num } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDate } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import AnalyticsNav from './AnalyticsNav.vue'
import { useAnalytics } from './useAnalytics'

/** SEO Health Score: an internal checklist, not a Google score. */
const { t } = useI18n()
const { workspaceId, siteId, onSite } = useAnalytics()
const { data, error, loading, run } = useAsync(() => analyticsApi.health(workspaceId.value, siteId.value || undefined))
onSite(run)
const latest = computed(() => data.value?.data.latest ?? null)
const history = computed(() => [...(data.value?.data.history ?? [])].reverse())
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.health.subtitle')" />
    <AnalyticsNav active="health" />
    <LoadingState v-if="loading && !data" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <EmptyState v-else-if="!latest" icon="shield" :title="t('an.common.noData')" :description="t('an.health.empty')" />
    <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <AppCard :title="t('an.health.score')" :subtitle="formatDate(latest.date)">
        <div class="flex items-end gap-2">
          <span class="text-5xl font-semibold tabular-nums" data-testid="health-score">{{ latest.score ?? '—' }}</span>
          <span class="mb-1 text-ink-muted">/100</span>
          <AppBadge :tone="healthTone(latest.score)" class="mb-2 ml-2">{{ t(`an.health.band.${healthTone(latest.score)}`) }}</AppBadge>
        </div>
        <p class="mt-4 text-xs text-ink-muted" data-testid="health-disclaimer">{{ data!.data.disclaimer }}</p>
      </AppCard>
      <AppCard :title="t('an.health.components')" class="lg:col-span-2">
        <ul class="space-y-4 text-sm">
          <li v-for="k in HEALTH_COMPONENTS" :key="k">
            <div class="mb-1 flex flex-wrap justify-between gap-2">
              <span class="font-medium">{{ t(`an.health.c.${k}`) }} <span class="text-xs text-ink-muted">({{ t('an.health.weight') }} {{ latest.components[k]?.weight ?? latest.weights[k] ?? 0 }})</span></span>
              <span class="tabular-nums">{{ latest.components[k]?.available ? num(latest.components[k]!.value, 0) : t('an.common.noData') }}</span>
            </div>
            <ProgressBar v-if="latest.components[k]?.available" :value="latest.components[k]!.value ?? 0" :max="100" tone="brand" :label="t(`an.health.c.${k}`)" />
            <p class="mt-1 text-xs text-ink-muted">{{ latest.components[k]?.detail ?? t('an.common.noData') }}</p>
          </li>
        </ul>
      </AppCard>
      <AppCard :title="t('an.health.history')" class="lg:col-span-3">
        <div class="flex h-32 items-end gap-1 overflow-x-auto" role="img" :aria-label="t('an.health.history')">
          <div v-for="h in history" :key="h.date + h.site_id" class="flex h-full min-w-3 flex-1 flex-col justify-end" :title="`${formatDate(h.date)}: ${h.score}`">
            <div class="w-full rounded-t bg-brand-500" :style="{ height: `${Math.max(2, h.score ?? 0)}%` }" />
          </div>
        </div>
      </AppCard>
    </div>
  </div>
</template>
