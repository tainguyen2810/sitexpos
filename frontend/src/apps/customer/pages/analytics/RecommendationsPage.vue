<script setup lang="ts">
import { ref, shallowRef } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { analyticsApi, idempotencyKey, micro, RECOMMENDATION_STATUSES, RECOMMENDATION_TYPES, type Recommendation } from '@/shared/api/analytics'
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

const REFRESH_TYPES = ['add_missing_sections', 'update_outdated_facts', 'refresh_content', 'improve_article_structure']
const { t } = useI18n()
const router = useRouter()
const { workspaceId, siteId, onSite, canEdit, toast, toastError } = useAnalytics()
const status = ref('')
const type = ref('')
const source = ref('')
const { data, error, loading, run } = useAsync(() =>
  analyticsApi.recommendations(workspaceId.value, { site_id: siteId.value || undefined, status: status.value || undefined, type: type.value || undefined, source: source.value || undefined }),
)
onSite(run)
const selected = shallowRef<Recommendation | null>(null)
const note = ref('')
const saving = ref(false)

async function decide(s: 'approved' | 'rejected' | 'dismissed') {
  if (!selected.value) return
  saving.value = true
  try {
    selected.value = (await analyticsApi.decideRecommendation(workspaceId.value, selected.value.id, s, note.value || undefined)).data
    toast.success(t('an.common.saved'))
    note.value = ''
    await run()
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
async function startRefresh(r: Recommendation) {
  if (!r.article_id) return
  saving.value = true
  try {
    const job = (await analyticsApi.createRefresh(workspaceId.value, r.article_id, r.id, idempotencyKey('rec'))).data
    await router.push({ name: 'analytics-refresh-job', params: { workspaceId: workspaceId.value, refreshId: job.id } })
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
function priorityTone(p: number): 'danger' | 'warning' | 'brand' | 'neutral' {
  return p >= 80 ? 'danger' : p >= 60 ? 'warning' : p >= 40 ? 'brand' : 'neutral'
}
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.recommendations.subtitle')" />
    <AnalyticsNav active="recommendations" all-sites>
      <FilterSelect v-model="status" :label="t('an.common.status')" :options="[{ value: '', label: t('an.recommendations.openOnly') }, ...RECOMMENDATION_STATUSES.map((s) => ({ value: s, label: t(`an.recommendations.status.${s}`) }))]" @update:model-value="run" />
      <FilterSelect v-model="type" :label="t('an.recommendations.type')" :options="[{ value: '', label: t('an.filters.allTypes') }, ...RECOMMENDATION_TYPES.map((s) => ({ value: s, label: t(`an.actions.${s}`) }))]" @update:model-value="run" />
      <FilterSelect v-model="source" :label="t('an.recommendations.source')" :options="[{ value: '', label: t('an.filters.allSources') }, ...['rule', 'ai', 'agent'].map((s) => ({ value: s, label: t(`an.recommendations.sources.${s}`) }))]" @update:model-value="run" />
    </AnalyticsNav>
    <AppCard :padded="false">
      <LoadingState v-if="loading && !data" />
      <ErrorState v-else-if="error" :error="error" @retry="run" />
      <EmptyState v-else-if="!data?.data.length" icon="star" :title="t('an.recommendations.empty')" />
      <ul v-else class="divide-y divide-line">
        <li v-for="r in data.data" :key="r.id">
          <button type="button" class="w-full px-4 py-3 text-left hover:bg-surface-muted" @click="selected = r">
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <AppBadge :tone="priorityTone(r.priority)">{{ t('an.recommendations.priority') }} {{ r.priority }}</AppBadge>
              <span class="font-medium">{{ t(`an.actions.${r.type}`) }}</span>
              <span class="truncate text-ink-muted">{{ r.target_label }}</span>
              <AppBadge v-if="r.source === 'agent'" tone="brand">{{ t('an.recommendations.sources.agent') }}</AppBadge>
              <AppBadge v-if="r.is_demo">DEMO</AppBadge>
              <span class="ml-auto text-xs text-ink-muted">{{ t(`an.recommendations.status.${r.status}`) }}</span>
            </div>
            <p class="mt-1 line-clamp-2 text-xs text-ink-muted">{{ r.rationale }}</p>
          </button>
        </li>
      </ul>
    </AppCard>

    <Drawer :open="!!selected" :title="selected ? t(`an.actions.${selected.type}`) : ''" :subtitle="selected?.target_label" @close="selected = null">
      <div v-if="selected" class="space-y-4 text-sm">
        <dl class="grid grid-cols-3 gap-x-3 gap-y-2">
          <dt class="text-ink-muted">{{ t('an.recommendations.why') }}</dt><dd class="col-span-2">{{ selected.rationale }}</dd>
          <dt class="text-ink-muted">{{ t('an.recommendations.hypothesis') }}</dt><dd class="col-span-2">{{ selected.benefit_hypothesis }}</dd>
          <dt class="text-ink-muted">{{ t('an.recommendations.risk') }}</dt><dd class="col-span-2">{{ t(`an.risk.${selected.risk}`, selected.risk) }}<span v-if="selected.risk_notes"> — {{ selected.risk_notes }}</span></dd>
          <dt class="text-ink-muted">{{ t('an.opportunities.cost') }}</dt><dd class="col-span-2">{{ micro(selected.estimated_cost_micro_usd) }} <span class="text-xs text-ink-muted">({{ t('an.common.estimate') }})</span></dd>
          <dt class="text-ink-muted">{{ t('an.recommendations.approval') }}</dt><dd class="col-span-2">{{ t(`an.recommendations.approvalBy.${selected.required_approval}`, selected.required_approval) }}</dd>
          <dt class="text-ink-muted">{{ t('an.common.status') }}</dt><dd class="col-span-2">{{ t(`an.recommendations.status.${selected.status}`) }} <span v-if="selected.decided_at" class="text-xs text-ink-muted">{{ formatDateTime(selected.decided_at) }}</span></dd>
        </dl>
        <p class="rounded-lg bg-surface-muted p-3 text-xs text-ink-muted">{{ t('an.recommendations.noGuarantee') }}</p>
        <div>
          <h3 class="mb-1 font-medium">{{ t('an.common.evidence') }}</h3>
          <pre class="max-h-56 overflow-auto rounded-lg bg-surface-muted p-3 text-xs">{{ JSON.stringify(selected.evidence, null, 2) }}</pre>
        </div>
        <div v-if="selected.suggested_value">
          <h3 class="mb-1 font-medium">{{ t('an.recommendations.suggested') }}</h3>
          <pre class="max-h-40 overflow-auto rounded-lg bg-surface-muted p-3 text-xs">{{ JSON.stringify(selected.suggested_value, null, 2) }}</pre>
        </div>
        <div v-if="canEdit" class="space-y-2 border-t border-line pt-4">
          <textarea v-model="note" rows="2" maxlength="500" :aria-label="t('an.common.note')" :placeholder="t('an.common.note')" class="w-full rounded-lg border border-line bg-surface p-2 text-sm" />
          <div class="flex flex-wrap gap-2">
            <template v-if="selected.status === 'proposed'">
              <AppButton size="sm" :loading="saving" @click="decide('approved')">{{ t('an.recommendations.approve') }}</AppButton>
              <AppButton size="sm" variant="secondary" :loading="saving" @click="decide('rejected')">{{ t('an.recommendations.reject') }}</AppButton>
              <AppButton size="sm" variant="ghost" :loading="saving" @click="decide('dismissed')">{{ t('an.recommendations.dismiss') }}</AppButton>
            </template>
            <AppButton v-if="selected.status === 'approved' && selected.article_id && REFRESH_TYPES.includes(selected.type)" size="sm" :loading="saving" @click="startRefresh(selected)">
              {{ t('an.recommendations.startRefresh') }}
            </AppButton>
          </div>
        </div>
      </div>
    </Drawer>
  </div>
</template>
