<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { analyticsApi, CHANGE_TYPES, num, pct, type Experiment } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDate } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AnalyticsNav from './AnalyticsNav.vue'
import { useAnalytics } from './useAnalytics'

/**
 * Before/after comparisons of one change (never called an A/B test).
 * Outcomes are descriptive and list the external factors that may explain
 * the movement.
 */
const { t } = useI18n()
const { workspaceId, siteId, onSite, canEdit, toast, toastError } = useAnalytics()
const { data, error, loading, run } = useAsync(() => analyticsApi.experiments(workspaceId.value, siteId.value || undefined))
onSite(run)
const open = ref(false)
const saving = ref(false)
const form = ref({ page_url: '', change_type: 'seo_title', change_date: new Date().toISOString().slice(0, 10), note: '' })

async function create() {
  if (!siteId.value) return
  saving.value = true
  try {
    await analyticsApi.createExperiment(workspaceId.value, { site_id: siteId.value, ...form.value, note: form.value.note || undefined })
    toast.success(t('an.common.saved'))
    open.value = false
    await run()
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
async function cancel(e: Experiment) {
  try {
    await analyticsApi.cancelExperiment(workspaceId.value, e.id)
    await run()
  } catch (err) {
    toastError(err)
  }
}
function outcomeTone(o: string | null): 'success' | 'danger' | 'neutral' | 'warning' {
  return o === 'improved' ? 'success' : o === 'declined' ? 'danger' : o === 'insufficient_data' ? 'warning' : 'neutral'
}
function fmt(k: string, v: number | null | undefined): string {
  if (k === 'ctr') return pct(v ?? null)
  return num(v ?? null, k === 'position' ? 1 : 0)
}
const changeOptions = computed(() => CHANGE_TYPES.map((c) => ({ value: c, label: t(`an.experiments.change.${c}`) })))
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.experiments.subtitle')" />
    <AnalyticsNav active="experiments">
      <AppButton v-if="canEdit && siteId" size="sm" class="ml-auto" @click="open = true">{{ t('an.experiments.new') }}</AppButton>
    </AnalyticsNav>
    <p class="mb-4 rounded-lg bg-surface-muted p-3 text-xs text-ink-muted">{{ t('an.experiments.note') }}</p>
    <LoadingState v-if="loading && !data" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <EmptyState v-else-if="!data?.data.length" icon="activity" :title="t('an.experiments.empty')" />
    <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <AppCard v-for="e in data.data" :key="e.id" :title="t(`an.experiments.change.${e.change_type}`)" :subtitle="e.page_url">
        <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
          <AppBadge :tone="e.status === 'observing' ? 'brand' : 'neutral'">{{ t(`an.experiments.status.${e.status}`) }}</AppBadge>
          <AppBadge v-if="e.outcome" :tone="outcomeTone(e.outcome)">{{ t(`an.experiments.outcome.${e.outcome}`) }}</AppBadge>
          <AppBadge v-if="e.is_demo">DEMO</AppBadge>
          <span class="text-ink-muted">{{ t('an.experiments.changedOn') }} {{ formatDate(e.change_date) }}</span>
        </div>
        <p class="mb-2 text-xs text-ink-muted">
          {{ t('an.experiments.baseline') }}: {{ formatDate(e.baseline_start) }} – {{ formatDate(e.baseline_end) }} · {{ t('an.experiments.observation') }}: {{ e.observation_days }} {{ t('an.common.days') }} ({{ t('an.common.until') }} {{ formatDate(e.observation_end) }})
        </p>
        <div class="overflow-x-auto">
          <table class="data-table min-w-full text-sm">
            <thead class="text-left text-xs uppercase text-ink-muted">
              <tr><th /><th class="text-right">{{ t('an.experiments.before') }}</th><th class="text-right">{{ t('an.experiments.after') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="k in ['clicks', 'impressions', 'ctr', 'position']" :key="k">
                <td class="py-1 text-ink-muted">{{ t(`an.metrics.${k}`) }}</td>
                <td class="text-right tabular-nums">{{ e.baseline ? fmt(k, e.baseline[k]) : '—' }}</td>
                <td class="text-right tabular-nums">{{ e.observed ? fmt(k, e.observed[k]) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="e.outcome_note" class="mt-2 text-xs">{{ e.outcome_note }}</p>
        <p v-if="e.external_factors" class="mt-2 text-xs text-ink-muted">{{ t('an.experiments.factors') }}: {{ e.external_factors }}</p>
        <AppButton v-if="canEdit && e.status === 'observing'" size="sm" variant="ghost" class="mt-3" @click="cancel(e)">{{ t('an.experiments.cancel') }}</AppButton>
      </AppCard>
    </div>

    <AppModal :open="open" :title="t('an.experiments.new')" @close="open = false">
      <form class="space-y-3" @submit.prevent="create">
        <AppInput v-model="form.page_url" :label="t('an.experiments.pageUrl')" type="url" placeholder="https://" />
        <AppSelect v-model="form.change_type" :label="t('an.experiments.changeType')" :options="changeOptions" />
        <AppInput v-model="form.change_date" :label="t('an.experiments.changeDate')" type="date" />
        <AppInput v-model="form.note" :label="t('an.common.note')" />
        <p class="text-xs text-ink-muted">{{ t('an.experiments.note') }}</p>
        <div class="flex justify-end gap-2">
          <AppButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</AppButton>
          <AppButton type="submit" :loading="saving" :disabled="!form.page_url">{{ t('an.experiments.create') }}</AppButton>
        </div>
      </form>
    </AppModal>
  </div>
</template>
