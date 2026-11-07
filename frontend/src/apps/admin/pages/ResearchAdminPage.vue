<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  researchAdminApi, LIMIT_KEYS, GLOBAL_KEYS,
  type AdminSource, type GlobalSettings, type LimitKey, type LimitRow, type Limits,
} from '@/shared/api/researchAdmin'
import { runTone, formatUsd } from '@/shared/api/research'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useCursorList } from '@/shared/composables/useCursorList'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Tabs from '@/shared/ui/Tabs.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'

type Tab = 'sources' | 'settings' | 'limits' | 'runs' | 'costs' | 'failures' | 'usage'
const { t } = useI18n()
const toast = useToast()
const session = useSessionStore()
const has = (p: string) => session.user?.platform_permissions.includes(p) ?? false
const err = (e: unknown) => toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))

const allowed = computed<Tab[]>(() => [
  ...(has('platform.ai.manage') ? (['sources', 'settings', 'limits'] as const) : []),
  ...(has('platform.jobs.manage') ? (['runs', 'failures'] as const) : []),
  ...(has('platform.ai.view_costs') ? (['costs', 'usage'] as const) : []),
])
const tab = ref<string>(allowed.value[0] ?? 'sources')
const tabs = computed(() => allowed.value.map((k) => ({ key: k, label: t(`researchAdmin.tabs.${k}`) })))
const loaded = reactive<Partial<Record<Tab, boolean>>>({})

// ---------- sources ----------
const sources = useAsync(() => researchAdminApi.sources(), { immediate: false })
const secretDraft = reactive<Record<string, string>>({})
const sourceDraft = reactive<Record<string, { rate: string; timeout: string; location: string }>>({})
watch(() => sources.data.value, (rows) => {
  for (const s of rows ?? []) {
    sourceDraft[s.source] = { rate: String(s.rate_limit_per_minute), timeout: String(s.timeout_seconds), location: String((s.config as { location_code?: number }).location_code ?? '') }
  }
})
const savingSource = ref<string | null>(null)
async function patchSource(s: AdminSource, data: Parameters<typeof researchAdminApi.updateSource>[1]) {
  savingSource.value = s.source
  try {
    const updated = await researchAdminApi.updateSource(s.source, data)
    sources.data.value = (sources.data.value ?? []).map((x) => (x.source === updated.source ? updated : x))
    toast.success(t('common.saved'))
  } catch (e) {
    err(e)
  } finally {
    savingSource.value = null
  }
}
function saveSource(s: AdminSource) {
  const d = sourceDraft[s.source]
  const data: Parameters<typeof researchAdminApi.updateSource>[1] = { rate_limit_per_minute: Number(d?.rate), timeout_seconds: Number(d?.timeout) }
  const secret = secretDraft[s.source]?.trim()
  if (secret) data.secret = secret
  if (s.source === 'dataforseo' && d?.location) data.config = { location_code: Number(d.location) }
  void patchSource(s, data).then(() => (secretDraft[s.source] = ''))
}
const clearing = ref<AdminSource | null>(null)
async function clearSecret() {
  if (clearing.value) await patchSource(clearing.value, { secret: null })
  clearing.value = null
}

// ---------- global settings ----------
const globals = useAsync(() => researchAdminApi.settings(), { immediate: false })
const globalDraft = ref<Record<string, string | boolean>>({})
watch(() => globals.data.value, (g) => {
  if (!g) return
  globalDraft.value = Object.fromEntries(GLOBAL_KEYS.map((k) => [k, typeof g[k] === 'boolean' ? (g[k] as boolean) : String(g[k])]))
})
const globalForm = useApiForm()
async function saveGlobals() {
  const payload: Partial<GlobalSettings> = {}
  for (const k of GLOBAL_KEYS) {
    const v = globalDraft.value[k]
    payload[k] = typeof v === 'boolean' ? v : k === 'user_agent' ? String(v) : Number(v)
  }
  const res = await globalForm.submit(() => researchAdminApi.updateSettings(payload))
  if (res) {
    globals.data.value = res
    toast.success(t('common.saved'))
  }
}
const isBool = (k: string) => k.endsWith('_enabled')

// ---------- limits ----------
const limits = useAsync(() => researchAdminApi.limits(), { immediate: false })
const limitDraft = ref<{ scope: 'global' | 'workspace'; workspace_id: string } & Record<LimitKey, string>>({ scope: 'workspace', workspace_id: '', ...Object.fromEntries(LIMIT_KEYS.map((k) => [k, ''])) } as never)
function editLimit(row: LimitRow | null) {
  const src: Limits = row ?? limits.data.value!.defaults
  limitDraft.value = { scope: row?.scope ?? 'workspace', workspace_id: row?.workspace?.id ?? '', ...Object.fromEntries(LIMIT_KEYS.map((k) => [k, String(src[k])])) } as never
}
watch(() => limits.data.value, (d) => d && editLimit(null))
const limitForm = useApiForm()
async function saveLimit() {
  const d = limitDraft.value
  const payload = { scope: d.scope, ...(d.scope === 'workspace' ? { workspace_id: d.workspace_id.trim() } : {}), ...Object.fromEntries(LIMIT_KEYS.map((k) => [k, Number(d[k])])) } as Limits & { scope: 'global' | 'workspace'; workspace_id?: string }
  const res = await limitForm.submit(() => researchAdminApi.upsertLimit(payload))
  if (res) {
    toast.success(t('common.saved'))
    await limits.run()
  }
}
const deletingLimit = ref<LimitRow | null>(null)
async function deleteLimit() {
  const row = deletingLimit.value
  deletingLimit.value = null
  if (!row) return
  try {
    await researchAdminApi.deleteLimit(row.id)
    await limits.run()
  } catch (e) {
    err(e)
  }
}
const scopeOptions = computed(() => [{ value: 'workspace', label: t('researchAdmin.workspace') }, { value: 'global', label: t('researchAdmin.global') }])

// ---------- runs / costs / failures / usage ----------
const runStatus = ref('')
const runs = useCursorList((cursor) => researchAdminApi.runs({ cursor, status: runStatus.value || undefined }))
const runStatusOptions = computed(() => [{ value: '', label: t('common.all') }, ...(['queued', 'running', 'succeeded', 'partial', 'failed', 'canceled'] as const).map((s) => ({ value: s, label: t(`research.run.status.${s}`) }))])
function onRunStatus(v: string) {
  runStatus.value = v
  void runs.reload()
}
const costs = useAsync(() => researchAdminApi.costs(), { immediate: false })
const failures = useAsync(() => researchAdminApi.failures(), { immediate: false })
const usage = useAsync(() => researchAdminApi.usage(), { immediate: false })

watch(tab, (v) => {
  const k = v as Tab
  if (loaded[k]) return
  loaded[k] = true
  const loaders: Record<Tab, () => unknown> = {
    sources: sources.run, settings: globals.run, limits: limits.run, runs: runs.reload, costs: costs.run, failures: failures.run, usage: usage.run,
  }
  void loaders[k]()
}, { immediate: true })
</script>

<template>
  <div>
    <PageHeader :title="t('researchAdmin.title')" :description="t('researchAdmin.subtitle')" />
    <EmptyState v-if="!allowed.length" icon="shield" :title="t('errors.forbidden')" />
    <template v-else>
      <div class="mb-4"><Tabs v-model="tab" :tabs="tabs" /></div>

      <!-- sources & providers -->
      <div v-if="tab === 'sources'">
        <LoadingState v-if="sources.loading.value && !sources.data.value" :rows="4" />
        <AppCard v-else-if="sources.error.value"><ErrorState :error="sources.error.value" @retry="sources.run" /></AppCard>
        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <AppCard v-for="s in sources.data.value ?? []" :key="s.source" :title="s.label" :subtitle="s.source">
            <template #actions>
              <AppBadge v-if="s.failure_count" tone="danger">{{ t('researchAdmin.failures', { n: s.failure_count }) }}</AppBadge>
              <AppBadge :tone="s.is_enabled ? 'success' : 'neutral'">{{ s.is_enabled ? t('researchAdmin.enabled') : '—' }}</AppBadge>
            </template>
            <div class="space-y-3">
              <Toggle :model-value="s.is_enabled" :label="t('researchAdmin.enabled')" :disabled="savingSource === s.source" @update:model-value="(v: boolean) => patchSource(s, { is_enabled: v })" />
              <div v-if="s.requires_credentials" class="space-y-1.5">
                <p class="text-sm font-medium">{{ t('researchAdmin.secret') }}: <AppBadge :tone="s.has_secret ? 'success' : 'warning'">{{ s.has_secret ? t('researchAdmin.secretSet') : t('researchAdmin.secretNotSet') }}</AppBadge></p>
                <p v-if="s.secret_managed_by === 'env'" class="text-xs text-ink-muted">{{ t('researchAdmin.secretEnv') }}</p>
                <template v-else>
                  <AppInput v-model="secretDraft[s.source]" type="password" autocomplete="new-password" :placeholder="t('researchAdmin.secretPlaceholder')" :hint="s.source === 'dataforseo' ? t('researchAdmin.dataforseoHint') : undefined" />
                  <AppButton v-if="s.has_secret" size="sm" variant="ghost" @click="clearing = s">{{ t('researchAdmin.clearSecret') }}</AppButton>
                </template>
              </div>
              <div v-if="sourceDraft[s.source]" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <AppInput v-model="sourceDraft[s.source]!.rate" type="number" :label="t('researchAdmin.rate')" />
                <AppInput v-model="sourceDraft[s.source]!.timeout" type="number" :label="t('researchAdmin.timeout')" />
                <AppInput v-if="s.source === 'dataforseo'" v-model="sourceDraft[s.source]!.location" type="number" label="location_code" />
              </div>
              <p v-if="s.last_error_code" class="text-xs text-danger-600">{{ t('researchAdmin.lastError', { code: s.last_error_code }) }} · {{ formatDateTime(s.last_failure_at) }}</p>
              <div class="flex flex-wrap justify-end gap-2">
                <AppButton v-if="s.failure_count" size="sm" variant="ghost" @click="patchSource(s, { reset_failures: true })">{{ t('researchAdmin.resetFailures') }}</AppButton>
                <AppButton size="sm" :loading="savingSource === s.source" @click="saveSource(s)">{{ t('common.save') }}</AppButton>
              </div>
            </div>
          </AppCard>
        </div>
      </div>

      <!-- global settings -->
      <AppCard v-else-if="tab === 'settings'">
        <LoadingState v-if="globals.loading.value && !globals.data.value" :rows="4" />
        <ErrorState v-else-if="globals.error.value" :error="globals.error.value" @retry="globals.run" />
        <form v-else class="grid grid-cols-1 gap-4 md:grid-cols-2" @submit.prevent="saveGlobals">
          <template v-for="k in GLOBAL_KEYS" :key="k">
            <Toggle v-if="isBool(k)" :model-value="Boolean(globalDraft[k])" :label="t(`researchAdmin.setting.${k}`)" @update:model-value="(v: boolean) => (globalDraft[k] = v)" />
            <AppInput v-else :model-value="String(globalDraft[k] ?? '')" :type="k === 'user_agent' ? 'text' : 'number'" :label="t(`researchAdmin.setting.${k}`)" :error="globalForm.errors[k]" @update:model-value="(v: string) => (globalDraft[k] = v)" />
          </template>
          <div class="flex justify-end md:col-span-2"><AppButton type="submit" :loading="globalForm.submitting.value">{{ t('common.save') }}</AppButton></div>
        </form>
      </AppCard>

      <!-- tenant limits -->
      <div v-else-if="tab === 'limits'" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <AppCard class="lg:col-span-2" :padded="false">
          <LoadingState v-if="limits.loading.value && !limits.data.value" class="p-5" />
          <ErrorState v-else-if="limits.error.value" :error="limits.error.value" class="p-5" @retry="limits.run" />
          <div v-else-if="limits.data.value" class="overflow-x-auto">
            <table class="data-table w-full text-sm">
              <thead class="bg-surface-muted text-left text-xs text-ink-muted">
                <tr>
                  <th class="px-3 py-2">{{ t('researchAdmin.workspace') }}</th>
                  <th v-for="k in LIMIT_KEYS" :key="k" class="px-3 py-2 text-right">{{ t(`researchAdmin.limit.${k}`) }}</th>
                  <th class="px-3 py-2" />
                </tr>
              </thead>
              <tbody class="divide-y divide-line">
                <tr class="text-ink-muted">
                  <td class="px-3 py-2 italic">{{ t('researchAdmin.defaults') }}</td>
                  <td v-for="k in LIMIT_KEYS" :key="k" class="px-3 py-2 text-right tabular-nums">{{ limits.data.value.defaults[k] }}</td>
                  <td />
                </tr>
                <tr v-for="r in limits.data.value.rows" :key="r.id">
                  <td class="px-3 py-2">{{ r.scope === 'global' ? t('researchAdmin.global') : (r.workspace?.name ?? r.workspace?.id) }}</td>
                  <td v-for="k in LIMIT_KEYS" :key="k" class="px-3 py-2 text-right tabular-nums">{{ r[k] }}</td>
                  <td class="whitespace-nowrap px-3 py-2 text-right">
                    <AppButton size="sm" variant="ghost" @click="editLimit(r)">{{ t('common.edit') }}</AppButton>
                    <AppButton size="sm" variant="ghost" @click="deletingLimit = r">{{ t('common.delete') }}</AppButton>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </AppCard>
        <AppCard :title="t('researchAdmin.addLimit')">
          <form class="space-y-3" @submit.prevent="saveLimit">
            <p v-if="limitForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ limitForm.formError.value }}</p>
            <AppSelect v-model="limitDraft.scope" :options="scopeOptions" />
            <AppInput v-if="limitDraft.scope === 'workspace'" v-model="limitDraft.workspace_id" :label="t('researchAdmin.workspaceId')" :error="limitForm.errors.workspace_id" required />
            <AppInput v-for="k in LIMIT_KEYS" :key="k" v-model="limitDraft[k]" type="number" :label="t(`researchAdmin.limit.${k}`)" :error="limitForm.errors[k]" />
            <div class="flex justify-end"><AppButton type="submit" :loading="limitForm.submitting.value">{{ t('common.save') }}</AppButton></div>
          </form>
        </AppCard>
      </div>

      <!-- run monitor -->
      <AppCard v-else-if="tab === 'runs'" :padded="false">
        <div class="border-b border-line px-5 py-3"><FilterSelect :model-value="runStatus" :label="t('common.status')" :options="runStatusOptions" @update:model-value="onRunStatus" /></div>
        <LoadingState v-if="runs.loading.value" class="p-5" />
        <ErrorState v-else-if="runs.error.value" :error="runs.error.value" class="p-5" @retry="runs.reload" />
        <p v-else-if="!runs.items.value.length" class="p-5 text-sm text-ink-muted">{{ t('research.run.empty') }}</p>
        <div v-else class="overflow-x-auto">
          <table class="data-table w-full text-sm">
            <thead class="bg-surface-muted text-left text-xs text-ink-muted">
              <tr><th class="px-3 py-2">{{ t('common.createdAt') }}</th><th class="px-3 py-2">{{ t('researchAdmin.workspace') }}</th><th class="px-3 py-2">{{ t('research.campaigns.title') }}</th><th class="px-3 py-2">{{ t('common.status') }}</th><th class="px-3 py-2">{{ t('research.run.warnings') }}</th><th class="px-3 py-2 text-right">{{ t('research.run.cost') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="r in runs.items.value" :key="r.id">
                <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(r.created_at) }}</td>
                <td class="px-3 py-2">{{ r.workspace?.name ?? '—' }}</td>
                <td class="px-3 py-2">{{ r.campaign_name ?? '—' }}</td>
                <td class="px-3 py-2"><AppBadge :tone="runTone(r.status)">{{ t(`research.run.status.${r.status}`) }}</AppBadge> <span v-if="r.error" class="text-xs text-danger-600">{{ r.error.code }}</span></td>
                <td class="px-3 py-2 text-xs text-ink-muted">{{ r.warnings.join(', ') || '—' }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ formatUsd(r.cost_micro_usd) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="runs.next.value" class="border-t border-line p-3 text-center"><AppButton variant="ghost" size="sm" :loading="runs.loadingMore.value" @click="runs.more">{{ t('common.loadMore') }}</AppButton></div>
      </AppCard>

      <!-- costs -->
      <div v-else-if="tab === 'costs'">
        <LoadingState v-if="costs.loading.value && !costs.data.value" :rows="3" />
        <AppCard v-else-if="costs.error.value"><ErrorState :error="costs.error.value" @retry="costs.run" /></AppCard>
        <template v-else-if="costs.data.value">
          <div class="space-y-3">
            <AppCard><p class="text-sm text-ink-muted">{{ t('researchAdmin.runs') }}</p><p class="text-2xl font-semibold tabular-nums">{{ costs.data.value.runs }}</p></AppCard>
            <AppCard><p class="text-sm text-ink-muted">{{ t('researchAdmin.total') }}</p><p class="text-2xl font-semibold tabular-nums">{{ formatUsd(costs.data.value.total_micro_usd) }}</p></AppCard>
            <AppCard><p class="text-sm text-ink-muted">{{ t('researchAdmin.avg') }}</p><p class="text-2xl font-semibold tabular-nums">{{ formatUsd(costs.data.value.avg_micro_usd_per_run) }}</p></AppCard>
            <AppCard><p class="text-sm text-ink-muted">{{ t('researchAdmin.max') }}</p><p class="text-2xl font-semibold tabular-nums">{{ formatUsd(costs.data.value.max_micro_usd_per_run) }}</p></AppCard>
          </div>
          <AppCard :title="t('researchAdmin.byWorkspace')" :padded="false">
            <div class="overflow-x-auto">
              <table class="data-table w-full text-sm">
                <tbody class="divide-y divide-line">
                  <tr v-for="w in costs.data.value.by_workspace" :key="w.workspace.id">
                    <td class="px-5 py-2">{{ w.workspace.name ?? w.workspace.id }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ w.runs }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ formatUsd(w.total_micro_usd) }}</td>
                    <td class="px-5 py-2 text-right tabular-nums text-ink-muted">{{ formatUsd(w.avg_micro_usd_per_run) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </AppCard>
        </template>
      </div>

      <!-- failures -->
      <div v-else-if="tab === 'failures'" class="space-y-6">
        <LoadingState v-if="failures.loading.value && !failures.data.value" :rows="3" />
        <AppCard v-else-if="failures.error.value"><ErrorState :error="failures.error.value" @retry="failures.run" /></AppCard>
        <template v-else-if="failures.data.value">
          <AppCard v-if="!failures.data.value.sources.length && !failures.data.value.recent.length"><p class="text-sm text-ink-muted">{{ t('researchAdmin.noFailures') }}</p></AppCard>
          <AppCard v-if="failures.data.value.sources.length" :title="t('researchAdmin.tabs.sources')">
            <ul class="space-y-1 text-sm">
              <li v-for="s in failures.data.value.sources" :key="s.source">{{ s.source }} — {{ t('researchAdmin.failures', { n: s.failure_count }) }} · {{ s.last_error_code }} · {{ formatDateTime(s.last_failure_at) }}</li>
            </ul>
          </AppCard>
          <AppCard v-if="failures.data.value.recent.length" :title="t('researchAdmin.tabs.failures')" :padded="false">
            <ul class="divide-y divide-line text-sm">
              <li v-for="f in failures.data.value.recent" :key="f.id" class="px-5 py-2">
                <p><strong>{{ f.label }}</strong> <span class="text-xs text-ink-muted">({{ f.type }})</span> · <span class="text-danger-600">{{ f.error_code }}</span><span v-if="f.http_status"> · HTTP {{ f.http_status }}</span></p>
                <p class="truncate text-xs text-ink-muted">{{ f.url }} · {{ formatDateTime(f.created_at) }}</p>
              </li>
            </ul>
          </AppCard>
        </template>
      </div>

      <!-- usage per tenant -->
      <AppCard v-else-if="tab === 'usage'" :title="usage.data.value ? t('researchAdmin.period', { period: usage.data.value.period }) : ''" :padded="false">
        <LoadingState v-if="usage.loading.value && !usage.data.value" class="p-5" />
        <ErrorState v-else-if="usage.error.value" :error="usage.error.value" class="p-5" @retry="usage.run" />
        <p v-else-if="!usage.data.value?.workspaces.length" class="p-5 text-sm text-ink-muted">{{ t('research.noData') }}</p>
        <div v-else class="overflow-x-auto">
          <table class="data-table w-full text-sm">
            <thead class="bg-surface-muted text-left text-xs text-ink-muted">
              <tr><th class="px-5 py-2">{{ t('researchAdmin.workspace') }}</th><th class="px-3 py-2 text-right">{{ t('researchAdmin.limit.max_runs_per_month') }}</th><th class="px-3 py-2 text-right">{{ t('research.overview.ideas') }}</th><th class="px-5 py-2 text-right">{{ t('research.overview.aiCost') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="w in usage.data.value?.workspaces ?? []" :key="w.workspace.id">
                <td class="px-5 py-2">{{ w.workspace.name ?? w.workspace.id }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ w.metrics.runs?.used ?? 0 }}/{{ w.limits.max_runs_per_month }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ w.metrics.ideas?.used ?? 0 }}</td>
                <td class="px-5 py-2 text-right tabular-nums">{{ formatUsd(w.metrics.ai_cost_micro_usd?.used ?? 0) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>
    </template>

    <ConfirmDialog :open="!!clearing" :title="t('researchAdmin.clearSecret')" :message="clearing?.label ?? ''" danger @close="clearing = null" @confirm="clearSecret" />
    <ConfirmDialog :open="!!deletingLimit" :title="t('common.delete')" :message="deletingLimit?.workspace?.name ?? t('researchAdmin.global')" danger @close="deletingLimit = null" @confirm="deleteLimit" />
  </div>
</template>
