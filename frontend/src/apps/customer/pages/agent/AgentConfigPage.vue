<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { agentApi, blockedAtLevel, LEVELS, needsApproval, type AgentConfig, type ToolName } from '@/shared/api/agent'
import { micro } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import AnalyticsNav from '../analytics/AnalyticsNav.vue'
import { useAnalytics } from '../analytics/useAnalytics'

/**
 * Agent settings per website: on/off, autonomy level (default 1, level 3
 * needs an explicit confirmation), allowed / forbidden tools, budgets and
 * the publish window. The policy engine on the server has the last word.
 */
const { t } = useI18n()
const { workspaceId, siteId, sites, onSite, canEdit, canConfigure, toast, toastError } = useAnalytics()
const tools = useAsync(() => agentApi.tools(workspaceId.value))
const cfg = useAsync(() => (siteId.value ? agentApi.config(workspaceId.value, siteId.value) : Promise.resolve(null)))
onSite(cfg.run)
const form = ref<Partial<AgentConfig>>({})
const spendUsd = ref('')
watch(cfg.data, (d) => {
  if (!d) return
  form.value = { ...d.data, allowed_actions: [...d.data.allowed_actions], forbidden_actions: [...d.data.forbidden_actions], approval_requirements: { ...d.data.approval_requirements } }
  spendUsd.value = (d.data.max_monthly_ai_spend_micro_usd / 1_000_000).toString()
})
const config = computed(() => cfg.data.value?.data ?? null)
const saving = ref(false)
const level3Open = ref(false)
const stopOpen = ref(false)
const stopReason = ref('')

async function save(confirmLevel3 = false) {
  if (!config.value) return
  if (form.value.autonomy_level === 3 && !config.value.level3_enabled && !confirmLevel3) {
    level3Open.value = true
    return
  }
  saving.value = true
  try {
    const f = form.value
    cfg.data.value = await agentApi.updateConfig(workspaceId.value, siteId.value, {
      enabled: f.enabled,
      autonomy_level: f.autonomy_level,
      confirm_level3: confirmLevel3 || undefined,
      allowed_actions: f.allowed_actions,
      forbidden_actions: f.forbidden_actions,
      max_daily_tasks: Number(f.max_daily_tasks),
      max_monthly_ai_spend_micro_usd: Math.round(Number(spendUsd.value || 0) * 1_000_000),
      max_monthly_refreshes: Number(f.max_monthly_refreshes),
      publish_window_start: Number(f.publish_window_start),
      publish_window_end: Number(f.publish_window_end),
      timezone: f.timezone,
      approval_requirements: f.approval_requirements,
      lock_version: config.value.lock_version,
    })
    level3Open.value = false
    toast.success(t('an.common.saved'))
  } catch (e) {
    toastError(e)
    void cfg.run()
  } finally {
    saving.value = false
  }
}
async function emergency(stop: boolean) {
  saving.value = true
  try {
    cfg.data.value = await agentApi.emergencyStop(workspaceId.value, siteId.value, stop, stopReason.value || undefined)
    stopOpen.value = false
    toast.success(stop ? t('ag.config.stopped') : t('ag.config.resumed'))
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
function toggleList(list: 'allowed_actions' | 'forbidden_actions', tool: ToolName) {
  const cur = new Set(form.value[list] ?? [])
  if (cur.has(tool)) cur.delete(tool)
  else cur.add(tool)
  form.value = { ...form.value, [list]: [...cur] }
}
</script>

<template>
  <div>
    <PageHeader :title="t('ag.title')" :description="t('ag.config.subtitle')" />
    <AnalyticsNav active="config" section="agent" />
    <EmptyState v-if="!sites.length" icon="globe" :title="t('an.connections.noSites')" />
    <LoadingState v-else-if="cfg.loading.value && !cfg.data.value" />
    <ErrorState v-else-if="cfg.error.value" :error="cfg.error.value" @retry="cfg.run" />
    <template v-else-if="config">
      <div v-if="config.platform.kill_switch" class="mb-4 rounded-lg bg-danger-50 p-3 text-sm text-danger-600" data-testid="kill-switch">{{ t('ag.config.killSwitch') }}</div>
      <div v-if="config.emergency_stop" class="mb-4 flex flex-wrap items-center gap-3 rounded-lg bg-danger-50 p-3 text-sm text-danger-600" data-testid="emergency-banner">
        {{ t('ag.config.emergencyOn') }}
        <AppButton v-if="canConfigure" size="sm" variant="secondary" :loading="saving" @click="emergency(false)">{{ t('ag.config.resume') }}</AppButton>
      </div>
      <div class="mb-6 flex flex-wrap items-center gap-3">
        <AppBadge :tone="config.enabled && !config.emergency_stop ? 'success' : 'neutral'">{{ config.enabled ? t('ag.config.on') : t('ag.config.off') }}</AppBadge>
        <span class="text-sm">{{ t('ag.config.effective') }}: <strong>{{ t(`ag.levels.${config.effective_level}.name`) }}</strong></span>
        <span v-if="config.effective_level !== config.autonomy_level" class="text-xs text-warning-600">{{ t('ag.config.capped', { max: config.platform.max_autonomy_level }) }}</span>
        <AppButton v-if="canEdit && !config.emergency_stop" class="ml-auto" size="sm" variant="danger" data-testid="emergency-stop" @click="stopOpen = true">{{ t('ag.config.emergencyStop') }}</AppButton>
      </div>

      <form class="grid grid-cols-1 gap-6 lg:grid-cols-3" @submit.prevent="save()">
        <AppCard :title="t('ag.config.autonomy')" class="lg:col-span-2">
          <Toggle v-model="form.enabled" :label="t('ag.config.enabled')" :description="t('ag.config.enabledHint')" :disabled="!canConfigure" />
          <fieldset class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2" :disabled="!canConfigure">
            <legend class="sr-only">{{ t('ag.config.autonomy') }}</legend>
            <label v-for="l in LEVELS" :key="l" class="flex cursor-pointer gap-3 rounded-lg border p-3 text-sm" :class="form.autonomy_level === l ? 'border-brand-500 bg-brand-50' : 'border-line'">
              <input v-model="form.autonomy_level" type="radio" :value="l" class="mt-1" :data-testid="`level-${l}`" />
              <span>
                <span class="block font-medium">{{ t(`ag.levels.${l}.name`) }}<AppBadge v-if="l === 1" class="ml-2">{{ t('ag.config.default') }}</AppBadge></span>
                <span class="block text-xs text-ink-muted">{{ t(`ag.levels.${l}.desc`) }}</span>
              </span>
            </label>
          </fieldset>
          <p v-if="config.level3_enabled" class="mt-3 text-xs text-warning-600">{{ t('ag.config.level3Since', { at: formatDateTime(config.level3_enabled_at) }) }}</p>
        </AppCard>
        <AppCard :title="t('ag.config.limits')">
          <div class="space-y-3 text-sm">
            <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('ag.config.maxDaily') }}</span><input v-model="form.max_daily_tasks" type="number" min="0" max="200" :disabled="!canConfigure" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
            <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('ag.config.maxSpend') }}</span><input v-model="spendUsd" type="number" min="0" step="0.01" :disabled="!canConfigure" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
            <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('ag.config.maxRefreshes') }}</span><input v-model="form.max_monthly_refreshes" type="number" min="0" max="200" :disabled="!canConfigure" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
            <div class="mb-6 flex flex-wrap items-center gap-3">
              <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('ag.config.windowStart') }}</span><input v-model="form.publish_window_start" type="number" min="0" max="23" :disabled="!canConfigure" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
              <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('ag.config.windowEnd') }}</span><input v-model="form.publish_window_end" type="number" min="0" max="24" :disabled="!canConfigure" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
            </div>
            <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('ag.config.timezone') }}</span><input v-model="form.timezone" :disabled="!canConfigure" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
          </div>
        </AppCard>
        <AppCard :title="t('ag.config.tools')" :subtitle="t('ag.config.toolsHint')" class="lg:col-span-3" :padded="false">
          <LoadingState v-if="tools.loading.value && !tools.data.value" />
          <ErrorState v-else-if="tools.error.value" :error="tools.error.value" @retry="tools.run" />
          <div v-else-if="tools.data.value" class="overflow-x-auto">
            <table class="data-table min-w-full text-sm" data-testid="tool-table">
              <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
                <tr>
                  <th class="px-4 py-2">{{ t('ag.config.tool') }}</th>
                  <th class="px-4 py-2">{{ t('ag.config.minLevel') }}</th>
                  <th class="px-4 py-2">{{ t('ag.config.atLevel') }}</th>
                  <th class="px-4 py-2 text-right">{{ t('an.opportunities.cost') }}</th>
                  <th class="px-4 py-2">{{ t('ag.config.allow') }}</th>
                  <th class="px-4 py-2">{{ t('ag.config.forbid') }}</th>
                  <th class="px-4 py-2">{{ t('ag.config.alwaysApprove') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-line">
                <tr v-for="tool in tools.data.value.data" :key="tool.name">
                  <td class="px-4 py-2">
                    <span class="font-medium">{{ t(`ag.tools.${tool.name}`) }}</span>
                    <p class="text-xs text-ink-muted">{{ tool.description }}</p>
                  </td>
                  <td class="px-4 py-2">{{ tool.min_level }}</td>
                  <td class="px-4 py-2">
                    <AppBadge v-if="config.platform.forbidden_tools.includes(tool.name)" tone="danger">{{ t('ag.config.platformForbidden') }}</AppBadge>
                    <AppBadge v-else-if="blockedAtLevel(tool, form.autonomy_level ?? 1)" tone="danger">{{ t('ag.decision.deny') }}</AppBadge>
                    <AppBadge v-else-if="needsApproval(tool, form.autonomy_level ?? 1, config)" tone="warning">{{ t('ag.decision.require_approval') }}</AppBadge>
                    <AppBadge v-else tone="success">{{ t('ag.decision.allow') }}</AppBadge>
                  </td>
                  <td class="px-4 py-2 text-right tabular-nums">{{ micro(tool.cost) }}</td>
                  <td class="px-4 py-2"><input type="checkbox" :checked="form.allowed_actions?.includes(tool.name)" :disabled="!canConfigure" :aria-label="t('ag.config.allow')" @change="toggleList('allowed_actions', tool.name)" /></td>
                  <td class="px-4 py-2"><input type="checkbox" :checked="form.forbidden_actions?.includes(tool.name)" :disabled="!canConfigure" :aria-label="t('ag.config.forbid')" @change="toggleList('forbidden_actions', tool.name)" /></td>
                  <td class="px-4 py-2">
                    <input
                      type="checkbox"
                      :checked="!!form.approval_requirements?.[tool.name]"
                      :disabled="!canConfigure"
                      :aria-label="t('ag.config.alwaysApprove')"
                      @change="form.approval_requirements = { ...form.approval_requirements, [tool.name]: ($event.target as HTMLInputElement).checked }"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
            <p class="px-4 py-3 text-xs text-ink-muted">{{ t('ag.config.allowHint') }} {{ t('ag.config.pipelineHint') }}</p>
          </div>
        </AppCard>
        <div v-if="canConfigure" class="flex justify-end lg:col-span-3">
          <AppButton type="submit" :loading="saving">{{ t('common.save') }}</AppButton>
        </div>
        <p v-else class="text-sm text-ink-muted lg:col-span-3">{{ t('ag.config.managersOnly') }}</p>
      </form>
    </template>

    <AppModal :open="level3Open" :title="t('ag.config.level3Title')" @close="level3Open = false">
      <div class="space-y-3 text-sm">
        <p>{{ t('ag.config.level3Body') }}</p>
        <ul class="list-disc pl-5 text-xs text-ink-muted">
          <li v-for="k in ['approved', 'gates', 'window', 'stop']" :key="k">{{ t(`ag.config.level3Points.${k}`) }}</li>
        </ul>
        <div class="flex justify-end gap-2">
          <AppButton variant="secondary" @click="level3Open = false">{{ t('common.cancel') }}</AppButton>
          <AppButton variant="danger" :loading="saving" data-testid="confirm-level3" @click="save(true)">{{ t('ag.config.level3Confirm') }}</AppButton>
        </div>
      </div>
    </AppModal>
    <ConfirmDialog :open="stopOpen" :title="t('ag.config.emergencyStop')" :message="t('ag.config.emergencyConfirm')" danger :loading="saving" @close="stopOpen = false" @confirm="emergency(true)" />
  </div>
</template>
