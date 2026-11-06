<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'
import { micro, num } from '@/shared/api/analytics'
import { analyticsAdminApi, type PlatformPolicy } from '@/shared/api/analyticsAdmin'
import { TOOLS, type ToolName } from '@/shared/api/agent'
import { useAsync } from '@/shared/composables/useAsync'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import StatCard from '@/shared/ui/StatCard.vue'

/**
 * Super Admin — SEO Analytics ingestion health, SEO agent activity and the
 * platform agent policy (global kill switch, autonomy cap, forbidden
 * tools). Tenants and agents cannot change this policy.
 */
const { t } = useI18n()
const toast = useToast()
const session = useSessionStore()
const has = (p: string) => session.user?.platform_permissions.includes(p) ?? false
const days = ref(7)
const analytics = useAsync(() => analyticsAdminApi.analytics(days.value))
const agent = useAsync(() => analyticsAdminApi.agent(days.value))
const policy = useAsync(() => (has('platform.ai.manage') ? analyticsAdminApi.policy() : Promise.resolve(null)))
const form = ref<Partial<PlatformPolicy>>({})
const saving = ref(false)
const killOpen = ref(false)
watch(policy.data, (p) => {
  if (p) form.value = { ...p.data, forbidden_tools: [...(p.data.forbidden_tools ?? [])] }
})
function reload() {
  void analytics.run()
  void agent.run()
}
function toggleTool(tool: ToolName) {
  const s = new Set(form.value.forbidden_tools ?? [])
  if (s.has(tool)) s.delete(tool)
  else s.add(tool)
  form.value = { ...form.value, forbidden_tools: [...s] }
}
async function save(kill?: boolean) {
  if (!policy.data.value) return
  saving.value = true
  try {
    const f = form.value
    const res = await analyticsAdminApi.updatePolicy({
      kill_switch: kill ?? f.kill_switch,
      kill_reason: f.kill_reason ?? null,
      max_autonomy_level: Number(f.max_autonomy_level),
      forbidden_tools: f.forbidden_tools ?? [],
      max_tasks_per_run: Number(f.max_tasks_per_run),
      max_concurrent_runs: Number(f.max_concurrent_runs),
      task_timeout_seconds: Number(f.task_timeout_seconds),
      run_timeout_minutes: Number(f.run_timeout_minutes),
      lock_version: policy.data.value.data.lock_version,
    })
    policy.data.value = { data: res.data, meta: policy.data.value.meta }
    killOpen.value = false
    toast.success(t('anAdmin.saved'))
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : t('errors.generic'))
    void policy.run()
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('anAdmin.title')" :description="t('anAdmin.subtitle')" />
    <div class="mb-4 flex items-center gap-2 text-sm">
      <label for="an-days" class="text-ink-muted">{{ t('anAdmin.days') }}</label>
      <select id="an-days" v-model.number="days" class="rounded-lg border border-line bg-surface px-2 py-1.5" @change="reload">
        <option v-for="d in [1, 7, 30, 90]" :key="d" :value="d">{{ d }}</option>
      </select>
    </div>

    <h2 class="mb-3 text-base font-semibold">{{ t('anAdmin.analytics') }}</h2>
    <LoadingState v-if="analytics.loading.value && !analytics.data.value" />
    <ErrorState v-else-if="analytics.error.value" :error="analytics.error.value" @retry="analytics.run" />
    <template v-else-if="analytics.data.value">
      <div v-if="!analytics.data.value.data.oauth_configured" class="mb-4 rounded-lg bg-warning-50 p-3 text-sm text-warning-700">{{ t('anAdmin.oauthMissing') }}</div>
      <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <StatCard :label="t('anAdmin.properties')" :value="Object.values(analytics.data.value.data.properties).reduce((a, b) => a + Number(b), 0)" icon="globe" />
        <StatCard :label="t('anAdmin.stale')" :value="analytics.data.value.data.stale" icon="clock" />
        <StatCard :label="t('anAdmin.requests')" :value="num(analytics.data.value.data.api_requests)" icon="server" />
        <StatCard :label="t('anAdmin.rows')" :value="num(analytics.data.value.data.rows_upserted)" icon="layers" />
        <StatCard :label="t('anAdmin.copilot')" :value="`${analytics.data.value.data.copilot.queries} · ${micro(analytics.data.value.data.copilot.cost_micro_usd)}`" icon="sparkles" />
      </div>
      <div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <AppCard :title="t('anAdmin.syncRuns')">
          <ul class="space-y-1 text-sm">
            <li v-for="(n, s) in analytics.data.value.data.sync_runs" :key="s" class="flex justify-between"><span>{{ s }}</span><span class="tabular-nums">{{ n }}</span></li>
          </ul>
          <h3 class="mb-1 mt-4 text-sm font-medium">{{ t('anAdmin.refreshJobs') }}</h3>
          <ul class="space-y-1 text-sm">
            <li v-for="(n, s) in analytics.data.value.data.refresh_jobs" :key="s" class="flex justify-between"><span>{{ s }}</span><span class="tabular-nums">{{ n }}</span></li>
          </ul>
        </AppCard>
        <AppCard :title="t('anAdmin.errors')">
          <EmptyState v-if="!analytics.data.value.data.errors.length" icon="check" :title="t('anAdmin.noErrors')" />
          <ul v-else class="space-y-1 text-sm">
            <li v-for="e in analytics.data.value.data.errors" :key="e.error_code" class="flex justify-between"><span class="font-mono text-xs">{{ e.error_code }}</span><span class="tabular-nums">{{ e.n }}</span></li>
          </ul>
        </AppCard>
        <AppCard :title="t('anAdmin.failing')" :padded="false">
          <EmptyState v-if="!analytics.data.value.data.failing.length" icon="check" :title="t('anAdmin.noFailing')" />
          <ul v-else class="divide-y divide-line text-xs">
            <li v-for="f in analytics.data.value.data.failing" :key="f.site_id" class="px-4 py-2">
              <span class="font-mono">{{ f.workspace_id }}</span> · {{ f.last_error_code ?? '—' }} · {{ f.consecutive_failures }}× · {{ formatDateTime(f.last_sync_at) }}
            </li>
          </ul>
        </AppCard>
      </div>
    </template>

    <h2 class="mb-3 text-base font-semibold">{{ t('anAdmin.agent') }}</h2>
    <LoadingState v-if="agent.loading.value && !agent.data.value" />
    <ErrorState v-else-if="agent.error.value" :error="agent.error.value" @retry="agent.run" />
    <template v-else-if="agent.data.value">
      <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard :label="t('anAdmin.activeRuns')" :value="agent.data.value.data.active_runs" icon="cpu" />
        <StatCard :label="t('anAdmin.emergencyStops')" :value="agent.data.value.data.emergency_stops" icon="alert" />
        <StatCard :label="t('anAdmin.estimated')" :value="micro(agent.data.value.data.estimated_cost_micro_usd)" icon="coins" />
        <StatCard :label="t('anAdmin.level3Sites')" :value="agent.data.value.data.levels['3'] ?? 0" icon="shield" />
      </div>
      <div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <AppCard :title="t('anAdmin.runs')">
          <ul class="space-y-1 text-sm">
            <li v-for="(n, s) in agent.data.value.data.runs" :key="s" class="flex justify-between"><span>{{ s }}</span><span class="tabular-nums">{{ n }}</span></li>
          </ul>
          <h3 class="mb-1 mt-4 text-sm font-medium">{{ t('anAdmin.decisions') }}</h3>
          <ul class="space-y-1 text-sm">
            <li v-for="(n, s) in agent.data.value.data.decisions" :key="s" class="flex justify-between"><span>{{ s }}</span><span class="tabular-nums">{{ n }}</span></li>
          </ul>
        </AppCard>
        <AppCard :title="t('anAdmin.byTool')" :padded="false">
          <EmptyState v-if="!agent.data.value.data.tasks_by_tool.length" icon="list" :title="t('anAdmin.noTasks')" />
          <div v-else class="overflow-x-auto">
            <table class="min-w-full text-xs">
              <tbody class="divide-y divide-line">
                <tr v-for="r in agent.data.value.data.tasks_by_tool" :key="r.tool + r.status"><td class="px-4 py-1.5">{{ r.tool }}</td><td class="px-4 py-1.5">{{ r.status }}</td><td class="px-4 py-1.5 text-right tabular-nums">{{ r.n }}</td></tr>
              </tbody>
            </table>
          </div>
        </AppCard>
        <AppCard :title="t('anAdmin.failedRuns')" :padded="false">
          <EmptyState v-if="!agent.data.value.data.failed_runs.length" icon="check" :title="t('anAdmin.noFailing')" />
          <ul v-else class="divide-y divide-line text-xs">
            <li v-for="r in agent.data.value.data.failed_runs" :key="r.id" class="px-4 py-2"><span class="font-mono">{{ r.workspace_id }}</span> · {{ r.kind }} · {{ r.error_code ?? '—' }}</li>
          </ul>
        </AppCard>
      </div>
    </template>

    <AppCard v-if="has('platform.ai.manage')" :title="t('anAdmin.policy')" :subtitle="t('anAdmin.policyHint')">
      <LoadingState v-if="policy.loading.value && !policy.data.value" />
      <ErrorState v-else-if="policy.error.value" :error="policy.error.value" @retry="policy.run" />
      <form v-else-if="policy.data.value" class="space-y-4 text-sm" @submit.prevent="save()">
        <div class="flex flex-wrap items-center gap-3">
          <AppBadge :tone="policy.data.value.data.kill_switch ? 'danger' : 'success'" data-testid="admin-kill-state">
            {{ policy.data.value.data.kill_switch ? t('anAdmin.killOn') : t('anAdmin.killOff') }}
          </AppBadge>
          <AppButton v-if="!policy.data.value.data.kill_switch" size="sm" variant="danger" @click="killOpen = true">{{ t('anAdmin.killActivate') }}</AppButton>
          <AppButton v-else size="sm" variant="secondary" :loading="saving" @click="save(false)">{{ t('anAdmin.killRelease') }}</AppButton>
        </div>
        <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('anAdmin.killReason') }}</span><input v-model="form.kill_reason" maxlength="500" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('anAdmin.maxLevel') }}</span><input v-model="form.max_autonomy_level" type="number" min="0" max="3" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
          <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('anAdmin.maxTasks') }}</span><input v-model="form.max_tasks_per_run" type="number" min="1" max="50" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
          <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('anAdmin.maxConcurrent') }}</span><input v-model="form.max_concurrent_runs" type="number" min="1" max="500" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
          <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('anAdmin.taskTimeout') }}</span><input v-model="form.task_timeout_seconds" type="number" min="60" max="3600" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
          <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('anAdmin.runTimeout') }}</span><input v-model="form.run_timeout_minutes" type="number" min="5" max="720" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
        </div>
        <fieldset>
          <legend class="mb-1 text-xs text-ink-muted">{{ t('anAdmin.forbidden') }}</legend>
          <div class="flex flex-wrap gap-3">
            <label v-for="tool in TOOLS" :key="tool" class="flex items-center gap-1.5"><input type="checkbox" :checked="form.forbidden_tools?.includes(tool)" @change="toggleTool(tool)" /> {{ tool }}</label>
          </div>
        </fieldset>
        <div class="flex justify-end"><AppButton type="submit" :loading="saving">{{ t('common.save') }}</AppButton></div>
      </form>
    </AppCard>
    <ConfirmDialog :open="killOpen" :title="t('anAdmin.killActivate')" :message="t('anAdmin.killConfirm')" danger :loading="saving" @close="killOpen = false" @confirm="save(true)" />
  </div>
</template>
