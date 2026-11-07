<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { WRITER_SETTING_GROUPS, isBoolKey, isMoneyKey, writerAdminApi, type AdminRun, type WriterSettingKey, type WriterSettings } from '@/shared/api/writerAdmin'
import { formatUsd } from '@/shared/api/writer'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useCursorList } from '@/shared/composables/useCursorList'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import StatCard from '@/shared/ui/StatCard.vue'
import Tabs from '@/shared/ui/Tabs.vue'
import Toggle from '@/shared/ui/Toggle.vue'

type Tab = 'settings' | 'workspace' | 'routing' | 'costs' | 'runs' | 'failures' | 'abuse'
const { t, te } = useI18n()
const toast = useToast()
const session = useSessionStore()
const has = (p: string) => session.user?.platform_permissions.includes(p) ?? false
const errMsg = (e: unknown) => (e instanceof ApiError && e.message ? e.message : t('errors.generic'))

const allowed = computed<Tab[]>(() => [
  ...(has('platform.ai.manage') ? (['settings', 'workspace', 'routing', 'abuse'] as const) : []),
  ...(has('platform.ai.view_costs') ? (['costs'] as const) : []),
  ...(has('platform.jobs.manage') ? (['runs', 'failures'] as const) : []),
])
const tab = ref<string>(allowed.value[0] ?? 'costs')
const tabs = computed(() => allowed.value.map((k) => ({ key: k, label: t(`writerAdmin.tabs.${k}`) })))
const label = (k: string) => (te(`writerAdmin.keys.${k}`) ? t(`writerAdmin.keys.${k}`) : k)

// ---- global settings (limits, research, quality policy, abuse thresholds) ----
const settings = useAsync(() => writerAdminApi.settings(), { immediate: false })
const draft = reactive<Record<string, string | boolean>>({})
function toDraft(values: WriterSettings, target: Record<string, string | boolean>) {
  for (const [k, v] of Object.entries(values)) if (!Array.isArray(v)) target[k] = typeof v === 'boolean' ? v : isMoneyKey(k) ? String(Number(v) / 1_000_000) : String(v)
}
function fromDraft(src: Record<string, string | boolean>, keys: readonly string[]): Partial<WriterSettings> {
  const out: Record<string, number | boolean> = {}
  for (const k of keys) {
    const v = src[k]
    if (v === undefined || v === '') continue
    out[k] = isBoolKey(k) ? Boolean(v) : isMoneyKey(k) ? Math.round(Number(v) * 1_000_000) : Number(v)
  }
  return out as Partial<WriterSettings>
}
// Platform source block list: one domain per line (FACT-CHECK-POLICY §7).
const blockedText = ref('')
watch(() => settings.data.value, (d) => {
  if (!d) return
  toDraft(d.values, draft)
  blockedText.value = (d.values.blocked_source_domains ?? []).join('\n')
})
const fieldErrors = reactive<Record<string, string | undefined>>({})
const saving = ref(false)
async function saveSettings() {
  saving.value = true
  for (const k of Object.keys(fieldErrors)) fieldErrors[k] = undefined
  try {
    const blocked = blockedText.value.split(/[\n,]+/).map((x) => x.trim()).filter(Boolean)
    await writerAdminApi.updateSettings({ ...fromDraft(draft, Object.values(WRITER_SETTING_GROUPS).flat()), blocked_source_domains: blocked })
    toast.success(t('common.saved'))
    await settings.run()
  } catch (e) {
    if (e instanceof ApiError) for (const [k, m] of Object.entries(e.details)) fieldErrors[k] = m[0]
    toast.error(errMsg(e))
  } finally {
    saving.value = false
  }
}

// ---- per-workspace overrides ----
const wsId = ref('')
const wsData = useAsync(() => writerAdminApi.workspaceSettings(wsId.value.trim()), { immediate: false })
const wsDraft = reactive<Record<string, string | boolean>>({})
watch(() => wsData.data.value, (d) => d && toDraft(d.values, wsDraft))
async function saveWorkspace() {
  saving.value = true
  try {
    await writerAdminApi.updateWorkspaceSettings(wsId.value.trim(), fromDraft(wsDraft, wsData.data.value?.workspace_keys ?? []))
    toast.success(t('common.saved'))
    await wsData.run()
  } catch (e) {
    toast.error(errMsg(e))
  } finally {
    saving.value = false
  }
}

const routing = useAsync(() => writerAdminApi.routing(), { immediate: false })
const days = ref('30')
const costs = useAsync(() => writerAdminApi.costs(Number(days.value)), { immediate: false })
watch(days, () => void costs.run())
const runStatus = ref('')
const runs = useCursorList((cursor) => writerAdminApi.runs({ cursor, status: runStatus.value }))
const summary = ref<Record<string, number>>({})
watch(runStatus, () => void runs.reload())
watch(() => runs.items.value, async () => {
  const r = await writerAdminApi.runs({ per_page: 1 }).catch(() => null)
  summary.value = r?.summary ?? {}
})
const failures = useAsync(() => writerAdminApi.failures(7), { immediate: false })
const abuse = useAsync(() => writerAdminApi.abuse(), { immediate: false })

const loaded = reactive<Partial<Record<Tab, boolean>>>({})
watch(tab, (k) => {
  if (loaded[k as Tab]) return
  loaded[k as Tab] = true
  if (k === 'settings') void settings.run()
  if (k === 'routing') void routing.run()
  if (k === 'costs') void costs.run()
  if (k === 'runs') void runs.reload()
  if (k === 'failures') void failures.run()
  if (k === 'abuse') void abuse.run()
}, { immediate: true })

const statusOptions = computed(() => [{ value: '', label: t('common.all') }, ...['running', 'retry_scheduled', 'succeeded', 'failed', 'canceled'].map((s) => ({ value: s, label: t(`writer.runStatus.${s}`) }))])
const dayOptions = [{ value: '7', label: '7d' }, { value: '30', label: '30d' }, { value: '90', label: '90d' }]
const runTone = (s: string) => (s === 'succeeded' ? 'success' : s === 'failed' ? 'danger' : s === 'canceled' ? 'neutral' : 'brand') as 'success' | 'danger' | 'neutral' | 'brand'
const duration = (r: AdminRun) => (r.started_at && r.finished_at ? `${Math.round((new Date(r.finished_at).getTime() - new Date(r.started_at).getTime()) / 1000)}s` : '—')
const maxDay = computed(() => Math.max(1, ...(costs.data.value?.by_day ?? []).map((d) => d.cost_micro_usd)))
</script>

<template>
  <div>
    <PageHeader :title="t('writerAdmin.title')" :description="t('writerAdmin.subtitle')" />
    <EmptyState v-if="!allowed.length" icon="lock" :title="t('errors.forbidden')" />
    <template v-else>
      <Tabs v-model="tab" :tabs="tabs" class="mb-4" />

      <!-- Settings: limits + research + quality policy + abuse thresholds -->
      <template v-if="tab === 'settings'">
        <LoadingState v-if="settings.loading.value && !settings.data.value" />
        <ErrorState v-else-if="settings.error.value" :error="settings.error.value" @retry="settings.run" />
        <div v-else-if="settings.data.value" class="space-y-4">
          <AppCard v-for="(keys, group) in WRITER_SETTING_GROUPS" :key="group">
            <h3 class="mb-3 text-sm font-semibold">{{ t(`writerAdmin.groups.${group}`) }}</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <template v-for="k in keys" :key="k">
                <Toggle v-if="isBoolKey(k)" v-model="draft[k] as boolean" :label="label(k)" />
                <AppInput
v-else v-model="draft[k] as string" type="number" :label="label(k) + (isMoneyKey(k) ? ' (USD)' : '')" :error="fieldErrors[k]"
                  :hint="t('writerAdmin.default', { v: isMoneyKey(k) ? formatUsd(Number(settings.data.value.defaults[k as WriterSettingKey])) : String(settings.data.value.defaults[k as WriterSettingKey]) })" />
              </template>
            </div>
          </AppCard>
          <AppCard>
            <label for="blocked-sources" class="mb-1 block text-sm font-semibold">{{ t('writerAdmin.blockedSources') }}</label>
            <p class="mb-2 text-xs text-ink-muted">{{ t('writerAdmin.blockedSourcesHint') }}</p>
            <textarea id="blocked-sources" v-model="blockedText" rows="5" class="w-full rounded-lg border border-line px-3 py-2 font-mono text-sm" data-testid="blocked-sources" />
            <p v-if="fieldErrors.blocked_source_domains || Object.keys(fieldErrors).some((k) => k.startsWith('blocked_source_domains.'))" class="mt-1 text-xs text-danger-600">
              {{ fieldErrors.blocked_source_domains ?? Object.entries(fieldErrors).find(([k, v]) => k.startsWith('blocked_source_domains.') && v)?.[1] }}
            </p>
          </AppCard>
          <p class="text-xs text-ink-muted">{{ t('writerAdmin.policyNote') }}</p>
          <div class="flex justify-end"><AppButton :loading="saving" data-testid="save-writer-settings" @click="saveSettings">{{ t('common.save') }}</AppButton></div>
        </div>
      </template>

      <template v-else-if="tab === 'workspace'">
        <AppCard>
          <form class="flex flex-wrap items-end gap-2" @submit.prevent="wsData.run()">
            <AppInput v-model="wsId" class="min-w-0 flex-1" :label="t('admin.workspaceId')" />
            <AppButton type="submit" :disabled="wsId.trim().length < 10">{{ t('common.open') }}</AppButton>
          </form>
          <LoadingState v-if="wsData.loading.value" class="mt-4" />
          <ErrorState v-else-if="wsData.error.value" :error="wsData.error.value" class="mt-4" @retry="wsData.run" />
          <template v-else-if="wsData.data.value">
            <p class="mt-4 text-sm font-medium">{{ wsData.data.value.workspace.name }}</p>
            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <template v-for="k in wsData.data.value.workspace_keys" :key="k">
                <Toggle v-if="isBoolKey(k)" v-model="wsDraft[k] as boolean" :label="label(k)" />
                <AppInput
v-else v-model="wsDraft[k] as string" type="number" :label="label(k) + (isMoneyKey(k) ? ' (USD)' : '')"
                  :hint="k in wsData.data.value.overrides ? t('writerAdmin.overridden') : t('writerAdmin.inherited')" />
              </template>
            </div>
            <div class="mt-4 flex justify-end"><AppButton :loading="saving" @click="saveWorkspace">{{ t('common.save') }}</AppButton></div>
          </template>
        </AppCard>
      </template>

      <template v-else-if="tab === 'routing'">
        <AppCard :padded="false">
          <p class="border-b border-line px-5 py-3 text-xs text-ink-muted">{{ t('writerAdmin.routingNote') }}
            <RouterLink to="/ai/settings" class="font-medium text-brand-600 underline">{{ t('nav.aiSettings') }}</RouterLink></p>
          <LoadingState v-if="routing.loading.value" class="p-5" />
          <ErrorState v-else-if="routing.error.value" :error="routing.error.value" class="p-5" @retry="routing.run" />
          <div v-else class="overflow-x-auto">
            <table class="data-table w-full min-w-[40rem] text-left text-sm" data-testid="writer-routing">
              <thead class="border-b border-line text-xs text-ink-muted"><tr><th class="px-5 py-2">{{ t('writerAdmin.task') }}</th><th class="px-2">{{ t('writerAdmin.primary') }}</th><th class="px-2">{{ t('writerAdmin.economy') }}</th><th class="px-2">{{ t('writerAdmin.fallbacks') }}</th><th class="px-5">{{ t('common.status') }}</th></tr></thead>
              <tbody class="divide-y divide-line">
                <tr v-for="r in routing.data.value ?? []" :key="r.task_key">
                  <td class="px-5 py-2"><span class="font-mono text-xs">{{ r.task_key }}</span><span class="block text-xs text-ink-muted">{{ t(`writerAdmin.taskLabel.${r.task_key.replace(/\./g, '_')}`, '') }}</span></td>
                  <td class="px-2">{{ r.primary_model?.display_name ?? '—' }}</td>
                  <td class="px-2">{{ r.economy_model?.display_name ?? '—' }}</td>
                  <td class="px-2 tabular-nums">{{ r.fallback_model_ids?.length ?? 0 }}</td>
                  <td class="px-5"><AppBadge :tone="!r.configured ? 'danger' : r.is_enabled ? 'success' : 'warning'">{{ !r.configured ? t('writerAdmin.notConfigured') : r.is_enabled ? t('writerAdmin.enabled') : t('writerAdmin.disabled') }}</AppBadge></td>
                </tr>
              </tbody>
            </table>
          </div>
        </AppCard>
      </template>

      <template v-else-if="tab === 'costs'">
        <div class="mb-4 flex"><FilterSelect v-model="days" :label="t('writerAdmin.period')" :options="dayOptions" /></div>
        <LoadingState v-if="costs.loading.value && !costs.data.value" />
        <ErrorState v-else-if="costs.error.value" :error="costs.error.value" @retry="costs.run" />
        <div v-else-if="costs.data.value" class="space-y-4">
          <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <StatCard :label="t('writerAdmin.totalCost')" :value="formatUsd(costs.data.value.total_cost_micro_usd)" icon="coins" />
            <StatCard :label="t('writerAdmin.articles')" :value="costs.data.value.articles" icon="file" />
            <StatCard :label="t('writerAdmin.avgPerArticle')" :value="formatUsd(costs.data.value.avg_cost_per_article_micro_usd)" icon="chart" />
          </div>
          <AppCard>
            <h3 class="mb-3 text-sm font-semibold">{{ t('writerAdmin.byDay') }}</h3>
            <p v-if="!costs.data.value.by_day.length" class="text-sm text-ink-muted">{{ t('common.noData') }}</p>
            <div v-else class="flex h-32 items-end gap-1" role="img" :aria-label="t('writerAdmin.byDay')">
              <div v-for="d in costs.data.value.by_day" :key="d.day" class="min-w-1 flex-1 rounded-t bg-brand-500" :style="{ height: `${Math.max(2, (d.cost_micro_usd / maxDay) * 100)}%` }" :title="`${d.day}: ${formatUsd(d.cost_micro_usd)} · ${d.articles}`" />
            </div>
          </AppCard>
          <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <AppCard>
              <h3 class="mb-2 text-sm font-semibold">{{ t('writerAdmin.byTask') }}</h3>
              <ul class="space-y-1 text-xs"><li v-for="r in costs.data.value.by_task" :key="r.task_key" class="flex justify-between gap-2"><span class="truncate font-mono">{{ r.task_key }}</span><span class="tabular-nums">{{ r.calls }} · {{ formatUsd(r.cost_micro_usd) }}</span></li></ul>
            </AppCard>
            <AppCard>
              <h3 class="mb-2 text-sm font-semibold">{{ t('writerAdmin.byModel') }}</h3>
              <ul class="space-y-1 text-xs"><li v-for="r in costs.data.value.by_model" :key="r.model ?? 'none'" class="flex justify-between gap-2"><span class="truncate">{{ r.model ?? '—' }}</span><span class="tabular-nums">{{ r.calls }} · {{ formatUsd(r.cost_micro_usd) }}</span></li></ul>
            </AppCard>
            <AppCard>
              <h3 class="mb-2 text-sm font-semibold">{{ t('writerAdmin.byWorkspace') }}</h3>
              <ul class="space-y-1 text-xs"><li v-for="r in costs.data.value.by_workspace" :key="r.workspace_id" class="flex justify-between gap-2">
                <RouterLink :to="`/workspaces/${r.workspace_id}`" class="truncate text-brand-600 underline">{{ r.name ?? r.workspace_id }}</RouterLink><span class="tabular-nums">{{ r.articles }} · {{ formatUsd(r.cost_micro_usd) }}</span></li></ul>
            </AppCard>
          </div>
        </div>
      </template>

      <template v-else-if="tab === 'runs'">
        <div class="mb-4 flex flex-wrap gap-2">
          <AppBadge v-for="(n, s) in summary" :key="s" :tone="runTone(String(s))">{{ t(`writer.runStatus.${s}`, String(s)) }} (24h): {{ n }}</AppBadge>
        </div>
        <AppCard :padded="false">
          <div class="flex items-center gap-2 border-b border-line px-5 py-3"><FilterSelect v-model="runStatus" :label="t('common.status')" :options="statusOptions" /></div>
          <LoadingState v-if="runs.loading.value && !runs.loaded.value" class="p-5" />
          <ErrorState v-else-if="runs.error.value" :error="runs.error.value" class="p-5" @retry="runs.reload" />
          <EmptyState v-else-if="!runs.items.value.length" icon="activity" :title="t('common.noData')" />
          <div v-else class="overflow-x-auto">
            <table class="data-table w-full min-w-[44rem] text-left text-sm" data-testid="writer-runs">
              <thead class="border-b border-line text-xs text-ink-muted"><tr><th class="px-5 py-2">{{ t('writerAdmin.type') }}</th><th class="px-2">{{ t('common.status') }}</th><th class="px-2">{{ t('writerAdmin.step') }}</th><th class="px-2">Workspace</th><th class="px-2">{{ t('writerAdmin.duration') }}</th><th class="px-5 text-right">{{ t('common.createdAt') }}</th></tr></thead>
              <tbody class="divide-y divide-line">
                <tr v-for="r in runs.items.value" :key="r.id">
                  <td class="px-5 py-2 font-mono text-xs">{{ r.type }}</td>
                  <td class="px-2"><AppBadge :tone="runTone(r.status)">{{ t(`writer.runStatus.${r.status}`, r.status) }}</AppBadge><p v-if="r.error" class="max-w-[14rem] truncate text-xs text-danger-600" :title="r.error.message ?? ''">{{ r.error.code }}</p></td>
                  <td class="px-2 text-xs">{{ r.current_step ?? '—' }} <span v-if="r.attempts > 1" class="text-ink-muted">×{{ r.attempts }}</span></td>
                  <td class="px-2 text-xs"><RouterLink :to="`/workspaces/${r.workspace_id}`" class="text-brand-600 underline">{{ r.workspace_name ?? r.workspace_id }}</RouterLink></td>
                  <td class="px-2 text-xs tabular-nums">{{ duration(r) }}</td>
                  <td class="px-5 text-right text-xs text-ink-muted">{{ formatDateTime(r.created_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="runs.next.value" class="border-t border-line p-3 text-center"><AppButton variant="ghost" size="sm" :loading="runs.loadingMore.value" @click="runs.more">{{ t('common.loadMore') }}</AppButton></div>
        </AppCard>
      </template>

      <template v-else-if="tab === 'failures'">
        <LoadingState v-if="failures.loading.value && !failures.data.value" />
        <ErrorState v-else-if="failures.error.value" :error="failures.error.value" @retry="failures.run" />
        <div v-else-if="failures.data.value" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <AppCard>
            <h3 class="mb-2 text-sm font-semibold">{{ t('writerAdmin.workflowErrors') }}</h3>
            <p v-if="!failures.data.value.workflow_errors.length" class="text-sm text-ink-muted">{{ t('common.noData') }}</p>
            <ul class="space-y-1 text-xs"><li v-for="(r, i) in failures.data.value.workflow_errors" :key="i" class="flex justify-between gap-2"><span class="font-mono">{{ r.type }} · {{ r.error_code ?? '—' }}</span><span>{{ r.count }}</span></li></ul>
          </AppCard>
          <AppCard>
            <h3 class="mb-2 text-sm font-semibold">{{ t('writerAdmin.aiErrors') }}</h3>
            <p v-if="!failures.data.value.ai_call_errors.length" class="text-sm text-ink-muted">{{ t('common.noData') }}</p>
            <ul class="space-y-1 text-xs"><li v-for="(r, i) in failures.data.value.ai_call_errors" :key="i" class="flex justify-between gap-2"><span class="font-mono">{{ r.task_key }} · {{ r.error_code ?? '—' }}</span><span>{{ r.count }}</span></li></ul>
          </AppCard>
          <AppCard>
            <h3 class="mb-2 text-sm font-semibold">{{ t('writerAdmin.factCheck') }}</h3>
            <p class="text-sm">{{ t('writerAdmin.partialRuns', { n: failures.data.value.fact_check_partial_runs }) }}</p>
            <ul class="mt-2 space-y-1 text-xs"><li v-for="(r, i) in failures.data.value.verdicts" :key="i" class="flex justify-between gap-2"><span>{{ t(`writer.verdict.${r.verdict}`, r.verdict) }} · {{ t(`writer.reviewer.${r.reviewer_type}`, r.reviewer_type) }}</span><span>{{ r.count }}</span></li></ul>
          </AppCard>
          <AppCard>
            <h3 class="mb-2 text-sm font-semibold">{{ t('writerAdmin.sources') }}</h3>
            <ul class="space-y-1 text-xs"><li v-for="(n, s) in failures.data.value.source_retrieval" :key="s" class="flex justify-between"><span>{{ t(`writer.research.retrieval.${s}`, String(s)) }}</span><span>{{ n }}</span></li></ul>
            <p class="mt-3 text-sm">{{ t('writerAdmin.quality', { avg: failures.data.value.quality.avg_score, below: failures.data.value.quality.below_policy }) }}</p>
          </AppCard>
        </div>
      </template>

      <template v-else-if="tab === 'abuse'">
        <AppCard :padded="false">
          <LoadingState v-if="abuse.loading.value" class="p-5" />
          <ErrorState v-else-if="abuse.error.value" :error="abuse.error.value" class="p-5" @retry="abuse.run" />
          <EmptyState v-else-if="!abuse.data.value?.length" icon="shieldCheck" :title="t('writerAdmin.noAbuse')" />
          <ul v-else class="divide-y divide-line" data-testid="writer-abuse">
            <li v-for="(s, i) in abuse.data.value" :key="i" class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
              <AppBadge tone="danger">{{ t(`writerAdmin.signal.${s.signal}`, s.signal) }}</AppBadge>
              <RouterLink :to="`/workspaces/${s.workspace_id}`" class="font-medium text-brand-600 underline">{{ s.workspace_name ?? s.workspace_id }}</RouterLink>
              <span class="ml-auto text-xs text-ink-muted">{{ s.value }} / {{ s.threshold }}</span>
            </li>
          </ul>
        </AppCard>
      </template>
    </template>
  </div>
</template>
