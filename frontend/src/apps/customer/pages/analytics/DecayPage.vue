<script setup lang="ts">
import { ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { analyticsApi, DECAY_STATUSES, decayTone, num, type DecayEvent, type DecayStatus } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import Drawer from '@/shared/ui/Drawer.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AnalyticsNav from './AnalyticsNav.vue'
import { useAnalytics } from './useAnalytics'

/**
 * Decay is a hypothesis until a person confirms it: the system only ever
 * marks DECAY_SUSPECTED; NEEDS_INVESTIGATION / CONFIRMED_ISSUE / NO_ACTION
 * are human decisions recorded with a note.
 */
const { t } = useI18n()
const { workspaceId, siteId, onSite, canEdit, toast, toastError } = useAnalytics()
const status = ref('')
const { data, error, loading, run } = useAsync(() => analyticsApi.decay(workspaceId.value, { site_id: siteId.value || undefined, status: status.value || undefined }))
onSite(run)
const selected = shallowRef<DecayEvent | null>(null)
const note = ref('')
const saving = ref(false)

async function decide(s: DecayStatus) {
  if (!selected.value) return
  saving.value = true
  try {
    selected.value = (await analyticsApi.decideDecay(workspaceId.value, selected.value.id, s, note.value || undefined)).data
    toast.success(t('an.common.saved'))
    note.value = ''
    await run()
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
function metric(o: Record<string, unknown> | null | undefined, k: string): string {
  const v = o?.[k]
  return typeof v === 'number' ? num(v, k === 'position' ? 1 : 0) : '—'
}
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.decay.subtitle')" />
    <AnalyticsNav active="decay" all-sites>
      <FilterSelect v-model="status" :label="t('an.common.status')" :options="[{ value: '', label: t('an.decay.openOnly') }, ...DECAY_STATUSES.map((s) => ({ value: s, label: t(`an.decay.status.${s}`) }))]" @update:model-value="run" />
    </AnalyticsNav>
    <AppCard :padded="false">
      <LoadingState v-if="loading && !data" />
      <ErrorState v-else-if="error" :error="error" @retry="run" />
      <EmptyState v-else-if="!data?.data.length" icon="trending" :title="t('an.decay.empty')" />
      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
            <tr>
              <th class="px-4 py-2">{{ t('an.metrics.page') }}</th>
              <th class="px-4 py-2">{{ t('an.common.status') }}</th>
              <th class="px-4 py-2 text-right">{{ t('an.decay.decline') }}</th>
              <th class="px-4 py-2">{{ t('an.decay.detected') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="d in data.data" :key="d.id" class="cursor-pointer hover:bg-surface-muted" @click="selected = d">
              <td class="max-w-md truncate px-4 py-2">{{ d.page_url }} <AppBadge v-if="d.is_demo">DEMO</AppBadge></td>
              <td class="px-4 py-2"><AppBadge :tone="decayTone(d.status)">{{ t(`an.decay.status.${d.status}`) }}</AppBadge></td>
              <td class="px-4 py-2 text-right tabular-nums text-danger-600">{{ num(d.decline_pct, 1) }}%</td>
              <td class="px-4 py-2">{{ formatDateTime(d.detected_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppCard>

    <Drawer :open="!!selected" :title="t('an.decay.detail')" :subtitle="selected?.page_url" @close="selected = null">
      <div v-if="selected" class="space-y-4 text-sm">
        <p class="rounded-lg bg-surface-muted p-3 text-xs text-ink-muted">{{ t('an.decay.hypothesis') }}</p>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="text-left text-xs uppercase text-ink-muted">
              <tr><th /><th class="text-right">{{ t('an.decay.recent') }}</th><th class="text-right">{{ t('an.decay.previous') }}</th><th class="text-right">{{ t('an.decay.baseline') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="k in ['clicks', 'impressions', 'position']" :key="k">
                <td class="py-1 text-ink-muted">{{ t(`an.metrics.${k}`) }}</td>
                <td class="text-right tabular-nums">{{ metric(selected.recent, k) }}</td>
                <td class="text-right tabular-nums">{{ metric(selected.previous, k) }}</td>
                <td class="text-right tabular-nums">{{ metric(selected.baseline, k) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-for="block in ['signals', 'seasonal', 'query_changes', 'url_changes', 'content_changes'] as const" :key="block">
          <h3 class="mb-1 font-medium">{{ t(`an.decay.blocks.${block}`) }}</h3>
          <pre v-if="selected[block] && (Array.isArray(selected[block]) ? (selected[block] as unknown[]).length : Object.keys(selected[block] as object).length)" class="max-h-48 overflow-auto rounded-lg bg-surface-muted p-3 text-xs">{{ JSON.stringify(selected[block], null, 2) }}</pre>
          <p v-else class="text-xs text-ink-muted">{{ t('an.common.noData') }}</p>
        </div>
        <p v-if="selected.decision_note" class="text-xs">{{ t('an.common.note') }}: {{ selected.decision_note }} ({{ formatDateTime(selected.decided_at) }})</p>
        <div v-if="canEdit" class="space-y-2 border-t border-line pt-4">
          <label class="block text-xs text-ink-muted" for="decay-note">{{ t('an.common.note') }}</label>
          <textarea id="decay-note" v-model="note" rows="2" maxlength="500" class="w-full rounded-lg border border-line bg-surface p-2 text-sm" />
          <div class="flex flex-wrap gap-2">
            <AppButton v-for="s in DECAY_STATUSES.filter((x) => x !== selected!.status)" :key="s" size="sm" variant="secondary" :loading="saving" @click="decide(s)">
              {{ t(`an.decay.mark.${s}`) }}
            </AppButton>
          </div>
        </div>
      </div>
    </Drawer>
  </div>
</template>
