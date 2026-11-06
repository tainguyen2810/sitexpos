<script setup lang="ts">
import { ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { analyticsApi, micro, OPPORTUNITY_DECISIONS, OPPORTUNITY_STATUSES, RULES, SEVERITIES, severityTone, type Opportunity } from '@/shared/api/analytics'
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
 * 15 deterministic opportunity rules. Every card shows evidence, the
 * detection rule, severity, impact basis, confidence and the recommended
 * action. Impact is never a traffic forecast.
 */
const { t } = useI18n()
const { workspaceId, siteId, onSite, canEdit, toast, toastError } = useAnalytics()
const status = ref('open')
const severity = ref('')
const rule = ref('')
const { data, error, loading, run } = useAsync(() =>
  analyticsApi.opportunities(workspaceId.value, { site_id: siteId.value || undefined, status: status.value || undefined, severity: severity.value || undefined, rule_code: rule.value || undefined }),
)
onSite(run)
const selected = shallowRef<Opportunity | null>(null)
const note = ref('')
const saving = ref(false)

async function decide(s: string) {
  if (!selected.value) return
  saving.value = true
  try {
    selected.value = (await analyticsApi.decideOpportunity(workspaceId.value, selected.value.id, s, note.value || undefined)).data
    toast.success(t('an.common.saved'))
    note.value = ''
    await run()
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.opportunities.subtitle')" />
    <AnalyticsNav active="opportunities" all-sites>
      <FilterSelect v-model="status" :label="t('an.common.status')" :options="[{ value: '', label: t('an.filters.allStatuses') }, ...OPPORTUNITY_STATUSES.map((s) => ({ value: s, label: t(`an.opportunities.status.${s}`) }))]" @update:model-value="run" />
      <FilterSelect v-model="severity" :label="t('an.opportunities.severity')" :options="[{ value: '', label: t('an.filters.allSeverities') }, ...SEVERITIES.map((s) => ({ value: s, label: t(`an.severity.${s}`) }))]" @update:model-value="run" />
      <FilterSelect v-model="rule" :label="t('an.opportunities.rule')" :options="[{ value: '', label: t('an.filters.allRules') }, ...RULES.map((r) => ({ value: r, label: t(`an.rules.${r}.name`) }))]" @update:model-value="run" />
    </AnalyticsNav>
    <AppCard :padded="false">
      <LoadingState v-if="loading && !data" />
      <ErrorState v-else-if="error" :error="error" @retry="run" />
      <EmptyState v-else-if="!data?.data.length" icon="zap" :title="t('an.opportunities.empty')" :description="t('an.opportunities.emptyHint')" />
      <ul v-else class="divide-y divide-line">
        <li v-for="o in data.data" :key="o.id">
          <button type="button" class="w-full px-4 py-3 text-left hover:bg-surface-muted" :data-testid="`opp-${o.id}`" @click="selected = o">
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <AppBadge :tone="severityTone(o.severity)">{{ t(`an.severity.${o.severity}`) }}</AppBadge>
              <span class="font-medium">{{ t(`an.rules.${o.rule_code}.name`) }}</span>
              <span class="truncate text-ink-muted">{{ o.target_label }}</span>
              <AppBadge v-if="o.is_demo">DEMO</AppBadge>
              <span class="ml-auto text-xs text-ink-muted">{{ t('an.opportunities.confidence') }} {{ o.confidence }}/100</span>
            </div>
            <p class="mt-1 text-xs text-ink-muted">{{ t(`an.actions.${o.recommended_action}`, o.recommended_action) }} · {{ t(`an.opportunities.status.${o.status}`) }}</p>
          </button>
        </li>
      </ul>
    </AppCard>

    <Drawer :open="!!selected" :title="selected ? t(`an.rules.${selected.rule_code}.name`) : ''" @close="selected = null">
      <div v-if="selected" class="space-y-4 text-sm">
        <p class="text-ink-muted">{{ t(`an.rules.${selected.rule_code}.desc`) }}</p>
        <dl class="grid grid-cols-3 gap-x-3 gap-y-2">
          <dt class="text-ink-muted">{{ t('an.opportunities.target') }}</dt><dd class="col-span-2 break-all">{{ selected.target_label }}</dd>
          <dt class="text-ink-muted">{{ t('an.opportunities.severity') }}</dt><dd class="col-span-2"><AppBadge :tone="severityTone(selected.severity)">{{ t(`an.severity.${selected.severity}`) }}</AppBadge></dd>
          <dt class="text-ink-muted">{{ t('an.opportunities.impact') }}</dt><dd class="col-span-2">{{ t(`an.impact.${selected.impact}`, selected.impact) }} — {{ selected.impact_basis }}</dd>
          <dt class="text-ink-muted">{{ t('an.opportunities.confidence') }}</dt><dd class="col-span-2">{{ selected.confidence }}/100 — {{ selected.confidence_explanation }}</dd>
          <dt class="text-ink-muted">{{ t('an.opportunities.detection') }}</dt><dd class="col-span-2">{{ selected.detection_rule }} <span class="text-xs text-ink-muted">({{ selected.rule_version }})</span></dd>
          <dt class="text-ink-muted">{{ t('an.opportunities.action') }}</dt><dd class="col-span-2">{{ t(`an.actions.${selected.recommended_action}`, selected.recommended_action) }}</dd>
          <dt class="text-ink-muted">{{ t('an.opportunities.cost') }}</dt><dd class="col-span-2">{{ micro(selected.estimated_ai_cost_micro_usd) }} <span class="text-xs text-ink-muted">({{ t('an.common.estimate') }})</span></dd>
          <dt class="text-ink-muted">{{ t('an.opportunities.detected') }}</dt><dd class="col-span-2">{{ formatDateTime(selected.first_detected_at) }} → {{ formatDateTime(selected.last_detected_at) }} ({{ selected.detections }}×)</dd>
          <dt class="text-ink-muted">{{ t('an.common.status') }}</dt><dd class="col-span-2">{{ t(`an.opportunities.status.${selected.status}`) }}<span v-if="selected.review_note"> — {{ selected.review_note }}</span></dd>
        </dl>
        <div>
          <h3 class="mb-1 font-medium">{{ t('an.common.evidence') }}</h3>
          <pre class="max-h-64 overflow-auto rounded-lg bg-surface-muted p-3 text-xs" data-testid="opp-evidence">{{ JSON.stringify(selected.evidence, null, 2) }}</pre>
        </div>
        <div v-if="canEdit" class="space-y-2 border-t border-line pt-4">
          <label class="block text-xs text-ink-muted" for="opp-note">{{ t('an.common.note') }}</label>
          <textarea id="opp-note" v-model="note" rows="2" maxlength="500" class="w-full rounded-lg border border-line bg-surface p-2 text-sm" />
          <div class="flex flex-wrap gap-2">
            <AppButton v-for="s in OPPORTUNITY_DECISIONS.filter((x) => x !== selected!.status)" :key="s" size="sm" :variant="s === 'dismissed' ? 'secondary' : 'primary'" :loading="saving" @click="decide(s)">
              {{ t(`an.opportunities.decide.${s}`) }}
            </AppButton>
          </div>
        </div>
      </div>
    </Drawer>
  </div>
</template>
