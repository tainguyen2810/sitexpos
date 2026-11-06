<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { analyticsApi } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AnalyticsNav from './AnalyticsNav.vue'
import { useAnalytics } from './useAnalytics'

/** Thresholds for classification / rules and SEO Health weights (site managers only). */
const { t } = useI18n()
const { workspaceId, canConfigure, toast, toastError } = useAnalytics()
const { data, error, loading, run } = useAsync(() => analyticsApi.settings(workspaceId.value))
const thresholds = ref<Record<string, string>>({})
const weights = ref<Record<string, string>>({})
const backfill = ref('90')
const retention = ref('490')
const saving = ref(false)
watch(data, (d) => {
  if (!d) return
  thresholds.value = Object.fromEntries(Object.entries(d.data.thresholds).map(([k, v]) => [k, String(v)]))
  weights.value = Object.fromEntries(Object.entries(d.data.health_weights).map(([k, v]) => [k, String(v)]))
  backfill.value = String(d.data.backfill_days)
  retention.value = String(d.data.raw_retention_days)
})
const nums = (o: Record<string, string>) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && !Number.isNaN(Number(v))).map(([k, v]) => [k, Number(v)]))

async function save() {
  if (!data.value) return
  saving.value = true
  try {
    data.value = await analyticsApi.updateSettings(workspaceId.value, {
      thresholds: nums(thresholds.value),
      health_weights: nums(weights.value),
      backfill_days: Number(backfill.value),
      raw_retention_days: Number(retention.value),
      lock_version: data.value.data.lock_version,
    })
    toast.success(t('an.common.saved'))
  } catch (e) {
    toastError(e)
    void run()
  } finally {
    saving.value = false
  }
}
function reset() {
  if (!data.value) return
  thresholds.value = Object.fromEntries(Object.entries(data.value.data.default_thresholds).map(([k, v]) => [k, String(v)]))
  weights.value = Object.fromEntries(Object.entries(data.value.data.default_health_weights).map(([k, v]) => [k, String(v)]))
}
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.settings.subtitle')" />
    <AnalyticsNav active="settings" :site-select="false" />
    <LoadingState v-if="loading && !data" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <form v-else-if="data" class="grid grid-cols-1 gap-6 lg:grid-cols-2" @submit.prevent="save">
      <AppCard :title="t('an.settings.thresholds')" :subtitle="t('an.settings.thresholdsHint')">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label v-for="(_, k) in thresholds" :key="k" class="flex flex-col gap-1 text-sm">
            <span class="text-xs text-ink-muted">{{ t(`an.settings.t.${k}`, String(k)) }}</span>
            <input v-model="thresholds[k]" type="number" step="any" :disabled="!canConfigure" class="rounded-lg border border-line bg-surface px-2 py-1.5" />
            <span class="text-[11px] text-ink-subtle">{{ t('an.settings.default') }}: {{ data.data.default_thresholds[k] }}</span>
          </label>
        </div>
      </AppCard>
      <div class="space-y-6">
        <AppCard :title="t('an.settings.weights')" :subtitle="t('an.health.disclaimer')">
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label v-for="(_, k) in weights" :key="k" class="flex flex-col gap-1 text-sm">
              <span class="text-xs text-ink-muted">{{ t(`an.health.c.${k}`, String(k)) }}</span>
              <input v-model="weights[k]" type="number" min="0" max="100" :disabled="!canConfigure" class="rounded-lg border border-line bg-surface px-2 py-1.5" />
            </label>
          </div>
        </AppCard>
        <AppCard :title="t('an.settings.data')">
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="flex flex-col gap-1 text-sm">
              <span class="text-xs text-ink-muted">{{ t('an.settings.backfill') }}</span>
              <input v-model="backfill" type="number" min="7" max="480" :disabled="!canConfigure" class="rounded-lg border border-line bg-surface px-2 py-1.5" />
            </label>
            <label class="flex flex-col gap-1 text-sm">
              <span class="text-xs text-ink-muted">{{ t('an.settings.retention') }}</span>
              <input v-model="retention" type="number" min="90" max="1000" :disabled="!canConfigure" class="rounded-lg border border-line bg-surface px-2 py-1.5" />
            </label>
          </div>
        </AppCard>
        <div v-if="canConfigure" class="flex justify-end gap-2">
          <AppButton variant="secondary" @click="reset">{{ t('an.settings.reset') }}</AppButton>
          <AppButton type="submit" :loading="saving">{{ t('common.save') }}</AppButton>
        </div>
      </div>
    </form>
  </div>
</template>
