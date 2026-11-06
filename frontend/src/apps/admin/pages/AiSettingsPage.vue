<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  adminAiApi, formatPeakWindows, formatPerMtok, formatTokens, formatUsd, parsePeakWindows, usdToMicro,
  type AiBudget, type AiModel, type AiPrice, type AiProvider, type AiRoute, type BudgetAction, type Capability,
  type ConnectionTestResult, type ModelTestResult, type ReasoningEffort, type ResponseFormatMode,
} from '@/shared/api/ai'
import { ApiError } from '@/shared/api/client'
import { aiErrorMessage } from '@/shared/components/ai/aiErrors'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Tabs from '@/shared/ui/Tabs.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import DropdownMenu from '@/shared/ui/DropdownMenu.vue'
import SearchInput from '@/shared/ui/SearchInput.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'

type Tab = 'providers' | 'models' | 'routes' | 'budgets'

const { t } = useI18n()
const toast = useToast()
const session = useSessionStore()
const canManage = computed(() => session.user?.platform_permissions.includes('platform.ai.manage') ?? false)
const tabs = computed<Tab[]>(() => (canManage.value ? ['providers', 'models', 'routes', 'budgets'] : ['budgets']))
const tab = ref<string>(canManage.value ? 'providers' : 'budgets')

const providers = useAsync(() => (canManage.value ? adminAiApi.providers() : Promise.resolve([] as AiProvider[])))
const models = useAsync(() => (canManage.value ? adminAiApi.models() : Promise.resolve([] as AiModel[])))
const routes = useAsync(() => (canManage.value ? adminAiApi.routes() : Promise.resolve([] as AiRoute[])))
const budgets = useAsync(() => adminAiApi.budgets())

function fail(e: unknown) {
  toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
}
async function guarded(fn: () => Promise<unknown>, reload: () => Promise<unknown>) {
  try {
    await fn()
    toast.success(t('common.saved'))
  } catch (e) {
    fail(e)
  } finally {
    await reload()
  }
}

const ENV: Record<string, string> = { openai: 'OPENAI_API_KEY', gemini: 'GEMINI_API_KEY', anthropic: 'ANTHROPIC_API_KEY', deepseek: 'DEEPSEEK_API_KEY', fake: 'SITEX_AI_FAKE_PROVIDER=true' }
const CAPS: Capability[] = ['structured_output', 'web_search', 'image_generation', 'temperature', 'vision']
const circuitText = (s: string) => (s === 'closed' ? 'text-success-700' : s === 'open' ? 'text-danger-700' : 'text-warning-700')

// ---- overview (summary cards + tab counts) ----
const list = <T,>(a: { data: { value: T[] | null } }) => a.data.value ?? []
const enabledProviders = computed(() => list(providers).filter((p) => p.is_enabled))
const readyProviders = computed(() => enabledProviders.value.filter((p) => p.has_api_key || p.driver === 'fake'))
const enabledModels = computed(() => list(models).filter((m) => m.is_enabled))
const unpricedModels = computed(() => enabledModels.value.filter((m) => !m.current_price))
const routedTasks = computed(() => list(routes).filter((r) => r.primary_model))
const mainBudget = computed(() => {
  const all = list(budgets)
  return all.find((b) => b.scope === 'global' && b.period === 'month') ?? all.find((b) => b.scope === 'global') ?? null
})
const tabItems = computed(() => {
  const counts: Record<Tab, number | undefined> = {
    providers: providers.data.value?.length, models: models.data.value?.length, routes: routes.data.value?.length, budgets: budgets.data.value?.length,
  }
  return tabs.value.map((k) => ({ key: k, label: t(`aiAdmin.tabs.${k}`), count: counts[k] }))
})

const AVATAR = ['bg-sky-50 text-sky-700 ring-sky-600/15', 'bg-emerald-50 text-emerald-700 ring-emerald-600/15', 'bg-violet-50 text-violet-700 ring-violet-600/15', 'bg-amber-50 text-amber-700 ring-amber-600/20', 'bg-rose-50 text-rose-700 ring-rose-600/15', 'bg-indigo-50 text-indigo-700 ring-indigo-600/15']
function initials(name: string) {
  const w = name.trim().split(/\s+/)
  return (w.length > 1 ? (w[0]?.[0] ?? '') + (w[1]?.[0] ?? '') : name.slice(0, 2)).toUpperCase()
}
function avatarClass(p: AiProvider) {
  if (!p.is_enabled) return 'bg-slate-100 text-slate-500 ring-slate-500/15'
  let h = 0
  for (const c of p.code) h = (h * 31 + c.charCodeAt(0)) >>> 0
  return AVATAR[h % AVATAR.length]
}
const needsKey = (p: AiProvider) => p.driver !== 'fake' && !p.has_api_key
const advancedOpen = ref<Set<string>>(new Set())
function toggleAdvanced(id: string) {
  const next = new Set(advancedOpen.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  advancedOpen.value = next
}

// ---- providers ----
const toggleProvider = (p: AiProvider) => guarded(() => adminAiApi.updateProvider(p.id, { is_enabled: !p.is_enabled }), providers.run)
const resetCircuit = (p: AiProvider) => guarded(() => adminAiApi.resetCircuit(p.id), providers.run)
const saveProviderNumber = (p: AiProvider, field: 'timeout_seconds' | 'max_retries', value: string) =>
  guarded(() => adminAiApi.updateProvider(p.id, { [field]: Number(value) }), providers.run)

// API key: write-only; the server never returns it.
const keyProvider = ref<AiProvider | null>(null)
const keyForm = useApiForm()
const keyDraft = ref('')
function openKey(p: AiProvider) {
  keyForm.reset()
  keyDraft.value = ''
  keyProvider.value = p
}
async function saveKey(clear = false) {
  const p = keyProvider.value
  if (!p) return
  const ok = await keyForm.submit(() => adminAiApi.updateProvider(p.id, clear ? { clear_api_key: true } : { api_key: keyDraft.value.trim() }))
  keyDraft.value = ''
  if (!ok) return
  keyProvider.value = null
  toast.success(t('common.saved'))
  await providers.run()
}

// Test Connection (free model-list call)
const connTesting = ref<string | null>(null)
const connResult = ref<{ provider: AiProvider; result: ConnectionTestResult } | null>(null)
async function testConnection(p: AiProvider) {
  connTesting.value = p.id
  try {
    connResult.value = { provider: p, result: await adminAiApi.testConnection(p.id) }
  } catch (e) {
    fail(e)
  } finally {
    connTesting.value = null
  }
}

// Custom OpenAI-compatible provider
const customOpen = ref(false)
const customForm = useApiForm()
const customDraft = ref({ name: '', code: '', base_url: '', api_key: '', response_format: 'json_object' as ResponseFormatMode })
function openCustom() {
  customForm.reset()
  customDraft.value = { name: '', code: '', base_url: 'https://', api_key: '', response_format: 'json_object' }
  customOpen.value = true
}
async function createCustom() {
  const d = customDraft.value
  const ok = await customForm.submit(() => adminAiApi.createProvider({
    name: d.name.trim(), code: d.code.trim().toLowerCase(), base_url: d.base_url.trim(), response_format: d.response_format,
    ...(d.api_key.trim() ? { api_key: d.api_key.trim() } : {}),
  }))
  customDraft.value.api_key = ''
  if (!ok) return
  customOpen.value = false
  toast.success(t('common.saved'))
  await providers.run()
}
const providerDeleting = ref<AiProvider | null>(null)
async function deleteProvider() {
  const p = providerDeleting.value
  if (!p) return
  await guarded(() => adminAiApi.deleteProvider(p.id), providers.run)
  providerDeleting.value = null
}
const responseFormatOptions = computed(() => (['json_schema', 'json_object', 'none'] as const).map((v) => ({ value: v, label: t(`aiAdmin.responseFormats.${v}`) })))

// ---- models & prices ----
const toggleModel = (m: AiModel) => guarded(() => adminAiApi.updateModel(m.id, { is_enabled: !m.is_enabled }), models.run)
const priceModel = ref<AiModel | null>(null)
const priceList = ref<AiPrice[]>([])
const priceForm = useApiForm()
const emptyPrice = () => ({ effective_from: new Date().toISOString().slice(0, 16), input: '', cached: '', cache_write: '', output: '', search: '', image: '', source_url: '',
  timeOfDay: false, off_input: '', off_cached: '', off_output: '', windows: '' })
const priceDraft = ref(emptyPrice())
async function openPrices(m: AiModel) {
  priceModel.value = m
  priceForm.reset()
  priceDraft.value = emptyPrice()
  try {
    priceList.value = await adminAiApi.prices(m.id)
  } catch (e) {
    fail(e)
  }
}
async function addPrice() {
  const m = priceModel.value
  if (!m) return
  const d = priceDraft.value
  let windows = null
  if (d.timeOfDay) {
    try {
      windows = parsePeakWindows(d.windows)
    } catch (e) {
      priceForm.errors.peak_windows = t('aiAdmin.peakWindowsInvalid', { line: e instanceof Error ? e.message : '' })
      return
    }
  }
  const offPeak = d.timeOfDay
    ? { offpeak_input_per_mtok: usdToMicro(d.off_input || 0), offpeak_cached_input_per_mtok: usdToMicro(d.off_cached || 0), offpeak_output_per_mtok: usdToMicro(d.off_output || 0), peak_windows: windows }
    : {}
  const ok = await priceForm.submit(() => adminAiApi.createPrice(m.id, {
    ...offPeak,
    effective_from: d.effective_from.replace('T', ' ') + ':00',
    input_per_mtok: usdToMicro(d.input || 0),
    cached_input_per_mtok: usdToMicro(d.cached || 0),
    cache_write_per_mtok: usdToMicro(d.cache_write || 0),
    output_per_mtok: usdToMicro(d.output || 0),
    per_1k_searches: usdToMicro(d.search || 0),
    per_image: usdToMicro(d.image || 0),
    source_url: d.source_url || null,
  }))
  if (!ok) return
  toast.success(t('common.saved'))
  await openPrices(m)
  await models.run()
}
async function deletePrice(p: AiPrice) {
  const m = priceModel.value
  if (!m) return
  try {
    await adminAiApi.deletePrice(m.id, p.id)
    await openPrices(m)
  } catch (e) {
    fail(e)
  }
}

// Edit model (Model ID, capabilities, reasoning effort) and delete unused models
const editModel = ref<AiModel | null>(null)
const editForm = useApiForm()
const editDraft = ref({ model_name: '', display_name: '', tier: 'standard', caps: {} as Partial<Record<Capability, boolean>>, reasoning: '' as ReasoningEffort | '' })
function openEditModel(m: AiModel) {
  editForm.reset()
  editModel.value = m
  editDraft.value = {
    model_name: m.model_name, display_name: m.display_name, tier: m.tier,
    caps: Object.fromEntries(CAPS.map((c) => [c, !!m.capabilities[c]])), reasoning: m.capabilities.reasoning_effort ?? '',
  }
}
async function saveModel() {
  const m = editModel.value
  if (!m) return
  const d = editDraft.value
  const ok = await editForm.submit(() => adminAiApi.updateModel(m.id, {
    ...(d.model_name.trim() !== m.model_name ? { model_name: d.model_name.trim() } : {}),
    display_name: d.display_name, tier: d.tier, capabilities: { ...d.caps, reasoning_effort: d.reasoning || null },
  }))
  if (!ok) return
  editModel.value = null
  toast.success(t('common.saved'))
  await Promise.all([models.run(), routes.run()])
}
const modelDeleting = ref<AiModel | null>(null)
async function deleteModel() {
  const m = modelDeleting.value
  if (!m) return
  await guarded(() => adminAiApi.deleteModel(m.id), models.run)
  modelDeleting.value = null
  editModel.value = null
}
const effortOptions = computed(() => [{ value: '', label: t('aiAdmin.efforts.default') }, ...(['none', 'low', 'high', 'max'] as const).map((v) => ({ value: v, label: t(`aiAdmin.efforts.${v}`) }))])

// Test Model: real, paid (tiny) call
const testModel = ref<AiModel | null>(null)
const testDraft = ref({ prompt: '', structured: false })
const testRunning = ref(false)
const testResult = ref<ModelTestResult | null>(null)
function openTest(m: AiModel) {
  testModel.value = m
  testDraft.value = { prompt: '', structured: false }
  testResult.value = null
}
async function runTest() {
  const m = testModel.value
  if (!m) return
  testRunning.value = true
  try {
    testResult.value = await adminAiApi.testModel(m.id, { prompt: testDraft.value.prompt.trim() || undefined, structured: testDraft.value.structured })
  } catch (e) {
    fail(e)
  } finally {
    testRunning.value = false
  }
  await models.run()
}

const modelOpen = ref(false)
const modelForm = useApiForm()
const modelDraft = ref({ provider_id: '', model_name: '', display_name: '', tier: 'standard' })
function openModel() {
  modelForm.reset()
  modelDraft.value = { provider_id: providers.data.value?.[0]?.id ?? '', model_name: '', display_name: '', tier: 'standard' }
  modelOpen.value = true
}
async function createModel() {
  const ok = await modelForm.submit(() => adminAiApi.createModel({ ...modelDraft.value, capabilities: { structured_output: true } }))
  if (!ok) return
  modelOpen.value = false
  toast.success(t('common.saved'))
  await models.run()
}
const modelSearch = ref('')
const modelProvider = ref('')
const modelStatus = ref('')
const filteredModels = computed(() => {
  const q = modelSearch.value.trim().toLowerCase()
  return list(models).filter((m) => (!q || `${m.display_name} ${m.model_key}`.toLowerCase().includes(q))
    && (!modelProvider.value || m.provider.id === modelProvider.value)
    && (!modelStatus.value || (modelStatus.value === 'on') === m.is_enabled))
})
const modelGroups = computed(() => {
  const groups = new Map<string, { name: string; items: AiModel[] }>()
  for (const m of filteredModels.value) {
    const g = groups.get(m.provider.id) ?? { name: m.provider.name, items: [] }
    g.items.push(m)
    groups.set(m.provider.id, g)
  }
  return [...groups.entries()].map(([id, g]) => ({ id, ...g }))
})
const modelsFiltered = computed(() => !!(modelSearch.value || modelProvider.value || modelStatus.value))
function clearModelFilters() {
  modelSearch.value = ''
  modelProvider.value = ''
  modelStatus.value = ''
}
const statusFilterOptions = computed(() => [{ value: 'on', label: t('aiAdmin.enabled') }, { value: 'off', label: t('aiAdmin.disabled') }])
const tierBadge: Record<string, 'success' | 'brand' | 'warning'> = { economy: 'success', standard: 'brand', premium: 'warning' }
const providerOptions = computed(() => (providers.data.value ?? []).map((p) => ({ value: p.id, label: p.name })))
const tierOptions = computed(() => (['economy', 'standard', 'premium'] as const).map((v) => ({ value: v, label: t(`aiAdmin.tiers.${v}`) })))

// ---- routes ----
const routeEdit = ref<AiRoute | null>(null)
const routeForm = useApiForm()
const routeDraft = ref({ primary: '', economy: '', fallbacks: [] as string[], max_output_tokens: '', max_attempts: '', allow_paid_retry: false, priority: 'batch', is_enabled: true })
const modelOptions = computed(() => (models.data.value ?? []).map((m) => ({ value: m.id, label: `${m.model_key}${m.is_enabled ? '' : ' (off)'}` })))
function openRoute(r: AiRoute) {
  routeForm.reset()
  routeEdit.value = r
  routeDraft.value = {
    primary: r.primary_model?.id ?? '', economy: r.economy_model?.id ?? '', fallbacks: r.fallback_models.map((m) => m.id),
    max_output_tokens: String(r.max_output_tokens), max_attempts: String(r.max_attempts), allow_paid_retry: r.allow_paid_retry,
    priority: r.priority, is_enabled: r.is_enabled,
  }
}
function toggleFallback(id: string) {
  const list = routeDraft.value.fallbacks
  routeDraft.value.fallbacks = list.includes(id) ? list.filter((x) => x !== id) : [...list, id]
}
async function saveRoute() {
  const r = routeEdit.value
  if (!r) return
  const d = routeDraft.value
  const ok = await routeForm.submit(() => adminAiApi.updateRoute(r.id, {
    primary_model_id: d.primary, economy_model_id: d.economy || null, fallback_model_ids: d.fallbacks.filter((x) => x !== d.primary),
    max_output_tokens: Number(d.max_output_tokens), max_attempts: Number(d.max_attempts), allow_paid_retry: d.allow_paid_retry,
    priority: d.priority, is_enabled: d.is_enabled,
  }))
  if (!ok) return
  routeEdit.value = null
  toast.success(t('common.saved'))
  await routes.run()
}

// ---- budgets ----
const budgetOpen = ref(false)
const budgetEditing = ref(false)
const budgetForm = useApiForm()
const budgetDraft = ref({ scope: 'global' as 'global' | 'workspace', workspace_id: '', period: 'month' as 'day' | 'month', limit: '', alert: '80', action: 'pause_all' as BudgetAction })
function openBudget(b?: AiBudget) {
  budgetForm.reset()
  budgetEditing.value = !!b
  budgetDraft.value = b
    ? { scope: b.scope, workspace_id: b.workspace?.id ?? '', period: b.period, limit: String(b.limit_micro_usd / 1_000_000), alert: String(b.alert_threshold_pct), action: b.action_on_exceed }
    : { scope: 'global', workspace_id: '', period: 'month', limit: '', alert: '80', action: 'pause_all' }
  budgetOpen.value = true
}
async function saveBudget() {
  const d = budgetDraft.value
  const ok = await budgetForm.submit(() => adminAiApi.upsertBudget({
    scope: d.scope, ...(d.scope === 'workspace' ? { workspace_id: d.workspace_id.trim().toLowerCase() } : {}),
    period: d.period, limit_micro_usd: usdToMicro(d.limit || 0), alert_threshold_pct: Number(d.alert), action_on_exceed: d.action,
  }))
  if (!ok) return
  budgetOpen.value = false
  toast.success(t('common.saved'))
  await budgets.run()
}
const deleting = ref<AiBudget | null>(null)
async function deleteBudget() {
  const b = deleting.value
  if (!b) return
  await guarded(() => adminAiApi.deleteBudget(b.id), budgets.run)
  deleting.value = null
}
const scopeOptions = computed(() => (['global', 'workspace'] as const).map((v) => ({ value: v, label: t(`aiAdmin.scopes.${v}`) })))
const periodOptions = computed(() => (['day', 'month'] as const).map((v) => ({ value: v, label: t(`aiAdmin.periods.${v}`) })))
const actionOptions = computed(() => (['alert', 'pause_batch', 'pause_all'] as const).map((v) => ({ value: v, label: t(`aiAdmin.actions.${v}`) })))
function budgetState(b: AiBudget): { tone: 'success' | 'warning' | 'danger'; bar: string; key: 'safe' | 'near' | 'over' } {
  if (b.usage.used_pct >= 100) return { tone: 'danger', bar: 'bg-danger-600', key: 'over' }
  if (b.usage.used_pct >= b.alert_threshold_pct) return { tone: 'warning', bar: 'bg-warning-500', key: 'near' }
  return { tone: 'success', bar: 'bg-brand-600', key: 'safe' }
}
const priorityOptions = computed(() => (['interactive', 'batch'] as const).map((v) => ({ value: v, label: t(`aiAdmin.priorities.${v}`) })))
</script>


<template>
  <div class="space-y-6">
    <PageHeader :title="$t('aiAdmin.settingsTitle')" :description="$t('aiAdmin.ui.subtitle')" :crumbs="[{ label: 'AI' }, { label: $t('aiAdmin.settingsTitle') }]" />

    <!-- Overview -->
    <dl v-if="canManage" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="ai-overview">
      <div class="rounded-xl bg-surface p-5 shadow-xs ring-1 ring-line">
        <dt class="text-sm font-medium text-ink-muted">{{ $t('aiAdmin.ui.sumProviders') }}</dt>
        <dd class="mt-2 flex items-baseline gap-1.5"><span class="text-3xl font-semibold tracking-tight tabular-nums">{{ readyProviders.length }}</span><span class="text-sm text-ink-subtle">/ {{ list(providers).length }}</span></dd>
        <p v-if="enabledProviders.length > readyProviders.length" class="mt-1 text-xs text-warning-700">{{ $t('aiAdmin.ui.sumProvidersMissingKey', { n: enabledProviders.length - readyProviders.length }) }}</p>
        <p v-else-if="!readyProviders.length" class="mt-1 text-xs text-warning-700">{{ $t('aiAdmin.ui.sumProvidersNone') }}</p>
        <p v-else class="mt-1 text-xs text-success-700">{{ $t('aiAdmin.ui.sumProvidersOk') }}</p>
      </div>
      <div class="rounded-xl bg-surface p-5 shadow-xs ring-1 ring-line">
        <dt class="text-sm font-medium text-ink-muted">{{ $t('aiAdmin.ui.sumModels') }}</dt>
        <dd class="mt-2 flex items-baseline gap-1.5"><span class="text-3xl font-semibold tracking-tight tabular-nums">{{ enabledModels.length }}</span><span class="text-sm text-ink-subtle">/ {{ list(models).length }}</span></dd>
        <p v-if="unpricedModels.length" class="mt-1 text-xs text-warning-700">{{ $t('aiAdmin.ui.sumModelsNoPrice', { n: unpricedModels.length }) }}</p>
        <p v-else class="mt-1 text-xs text-ink-muted">{{ $t('aiAdmin.ui.sumModelsOk') }}</p>
      </div>
      <div class="rounded-xl bg-surface p-5 shadow-xs ring-1 ring-line">
        <dt class="text-sm font-medium text-ink-muted">{{ $t('aiAdmin.ui.sumRoutes') }}</dt>
        <dd class="mt-2 flex items-baseline gap-1.5"><span class="text-3xl font-semibold tracking-tight tabular-nums">{{ routedTasks.length }}</span><span class="text-sm text-ink-subtle">/ {{ list(routes).length }}</span></dd>
        <p v-if="list(routes).length > routedTasks.length" class="mt-1 text-xs text-danger-700">{{ $t('aiAdmin.ui.sumRoutesMissing', { n: list(routes).length - routedTasks.length }) }}</p>
        <p v-else class="mt-1 text-xs text-success-700">{{ $t('aiAdmin.ui.sumRoutesOk') }}</p>
      </div>
      <div class="rounded-xl bg-surface p-5 shadow-xs ring-1 ring-line">
        <dt class="text-sm font-medium text-ink-muted">{{ $t('aiAdmin.ui.sumBudget') }}<template v-if="mainBudget"> · {{ $t(`aiAdmin.periods.${mainBudget.period}`) }}</template></dt>
        <template v-if="mainBudget">
          <dd class="mt-2 flex items-baseline gap-1.5"><span class="text-3xl font-semibold tracking-tight tabular-nums">{{ formatUsd(mainBudget.usage.spent_micro_usd, 2) }}</span><span class="text-sm text-ink-subtle">/ {{ formatUsd(mainBudget.limit_micro_usd, 2) }}</span></dd>
          <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full" :class="budgetState(mainBudget).bar" :style="{ width: Math.min(100, mainBudget.usage.used_pct) + '%' }" /></div>
        </template>
        <template v-else>
          <dd class="mt-2 text-3xl font-semibold tracking-tight text-ink-subtle">—</dd>
          <p class="mt-1 text-xs text-warning-700">{{ $t('aiAdmin.ui.sumBudgetNone') }}</p>
        </template>
      </div>
    </dl>

    <section class="rounded-xl bg-surface shadow-xs ring-1 ring-line">
      <div class="px-5"><Tabs v-model="tab" :tabs="tabItems" /></div>

      <!-- Tab intro + primary action -->
      <div class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex gap-3 rounded-lg bg-surface-muted px-4 py-3 text-sm text-ink-muted ring-1 ring-line lg:max-w-3xl">
          <AppIcon name="info" :size="18" class="mt-0.5 shrink-0 text-brand-600" />
          <p>{{ $t(`aiAdmin.ui.intro.${tab}`) }}</p>
        </div>
        <div class="shrink-0">
          <AppButton v-if="tab === 'providers'" @click="openCustom"><AppIcon name="plus" :size="16" />{{ $t('aiAdmin.addCustomProvider') }}</AppButton>
          <AppButton v-else-if="tab === 'models'" @click="openModel"><AppIcon name="plus" :size="16" />{{ $t('aiAdmin.addModel') }}</AppButton>
          <AppButton v-else-if="tab === 'budgets' && canManage" @click="openBudget()"><AppIcon name="plus" :size="16" />{{ $t('aiAdmin.addBudget') }}</AppButton>
        </div>
      </div>

      <!-- Providers -->
      <div v-if="tab === 'providers'" class="px-5 pb-5">
        <LoadingState v-if="providers.loading.value && !providers.data.value" />
        <ErrorState v-else-if="providers.error.value" :error="providers.error.value" @retry="providers.run" />
        <ul v-else class="grid grid-cols-1 items-start gap-4 xl:grid-cols-2" data-testid="provider-list">
          <li v-for="p in providers.data.value ?? []" :key="p.id" class="flex flex-col rounded-xl bg-surface ring-1 ring-line" :data-provider="p.code">
            <div class="flex items-start gap-3 p-4">
              <span class="grid size-10 shrink-0 place-items-center rounded-lg text-sm font-bold ring-1 ring-inset" :class="avatarClass(p)" aria-hidden="true">{{ initials(p.name) }}</span>
              <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span class="font-semibold text-ink">{{ p.name }}</span>
                  <span class="font-mono text-xs text-ink-subtle">{{ p.code }}</span>
                  <AppBadge v-if="p.is_custom" tone="brand">{{ $t('aiAdmin.custom') }}</AppBadge>
                </p>
                <p v-if="p.base_url" class="mt-0.5 truncate font-mono text-xs text-ink-subtle" :title="p.base_url">{{ p.base_url }}</p>
                <p class="mt-1 flex items-center gap-1.5 text-xs" :class="needsKey(p) ? 'text-warning-700' : p.is_enabled ? 'text-success-700' : 'text-ink-muted'">
                  <span class="size-1.5 rounded-full" :class="needsKey(p) ? 'bg-warning-500' : p.is_enabled ? 'bg-success-500' : 'bg-slate-300'" />
                  {{ needsKey(p) ? $t('aiAdmin.ui.needsKey') : p.is_enabled ? $t('aiAdmin.ui.ready', { n: p.models_count }) : $t('aiAdmin.ui.off', { n: p.models_count }) }}
                </p>
              </div>
              <div class="flex items-center gap-2">
                <span class="text-xs text-ink-muted">{{ p.is_enabled ? $t('aiAdmin.enabled') : $t('aiAdmin.disabled') }}</span>
                <button
                  type="button" role="switch" :aria-checked="p.is_enabled" :aria-label="$t('aiAdmin.ui.toggleProvider', { name: p.name })"
                  class="relative inline-flex h-6 w-11 shrink-0 rounded-full ring-1 ring-inset ring-slate-900/5 transition-colors duration-200"
                  :class="p.is_enabled ? 'bg-brand-600' : 'bg-slate-200'"
                  @click="toggleProvider(p)"
                ><span class="absolute top-0.5 size-5 rounded-full bg-white shadow transition-all" :class="p.is_enabled ? 'left-[22px]' : 'left-0.5'" /></button>
              </div>
            </div>

            <dl class="grid grid-cols-1 border-y border-line bg-surface-muted text-xs sm:grid-cols-3 sm:divide-x sm:divide-line">
              <div class="px-4 py-2.5">
                <dt class="text-ink-muted">{{ $t('aiAdmin.apiKey') }}</dt>
                <dd class="mt-0.5 font-medium" data-testid="key-source">
                  <span v-if="p.api_key_source === 'database'" class="text-ink">{{ $t('aiAdmin.keyStored', { last4: p.api_key_last4 ?? '' }) }}</span>
                  <span v-else-if="p.api_key_source === 'env'" class="text-ink">{{ $t('aiAdmin.ui.keyEnv', { env: ENV[p.code] ?? 'API key' }) }}</span>
                  <span v-else-if="p.driver === 'fake'" class="text-ink-muted">{{ $t('aiAdmin.ui.keyNotNeeded') }}</span>
                  <span v-else class="text-warning-700">{{ $t('aiAdmin.noKey') }}</span>
                </dd>
              </div>
              <div class="px-4 py-2.5">
                <dt class="text-ink-muted">{{ $t('aiAdmin.ui.connection') }}</dt>
                <dd class="mt-0.5 font-medium" :class="circuitText(p.circuit.state)">
                  {{ $t(`aiAdmin.circuitStates.${p.circuit.state}`) }}<template v-if="p.circuit.failures"> · {{ $t('aiAdmin.failures', { n: p.circuit.failures }) }}</template>
                </dd>
              </div>
              <div class="px-4 py-2.5">
                <dt class="text-ink-muted">{{ $t('aiAdmin.ui.timeoutRetries') }}</dt>
                <dd class="mt-0.5 font-medium text-ink">{{ $t('aiAdmin.ui.timeoutValue', { s: p.timeout_seconds, n: p.max_retries }) }}</dd>
              </div>
            </dl>

            <div v-if="advancedOpen.has(p.id)" class="grid grid-cols-2 gap-4 border-b border-line px-4 py-3 text-sm" data-testid="provider-advanced">
              <label class="block text-xs font-medium text-ink">{{ $t('aiAdmin.timeout') }}
                <input type="number" min="5" max="180" :value="p.timeout_seconds" class="mt-1 block w-full rounded-lg border-0 px-3 py-1.5 text-sm text-ink shadow-xs ring-1 ring-inset ring-line-strong focus:outline-none focus:ring-2 focus:ring-brand-600" @change="saveProviderNumber(p, 'timeout_seconds', ($event.target as HTMLInputElement).value)" />
              </label>
              <label class="block text-xs font-medium text-ink">{{ $t('aiAdmin.maxRetries') }}
                <input type="number" min="0" max="3" :value="p.max_retries" class="mt-1 block w-full rounded-lg border-0 px-3 py-1.5 text-sm text-ink shadow-xs ring-1 ring-inset ring-line-strong focus:outline-none focus:ring-2 focus:ring-brand-600" @change="saveProviderNumber(p, 'max_retries', ($event.target as HTMLInputElement).value)" />
              </label>
              <p class="col-span-2 text-xs text-ink-muted">{{ $t('aiAdmin.ui.advancedHint') }}</p>
            </div>

            <div class="mt-auto flex flex-wrap items-center gap-2 px-4 py-3">
              <template v-if="p.driver !== 'fake'">
                <AppButton v-if="needsKey(p)" size="sm" @click="openKey(p)">{{ $t('aiAdmin.ui.enterKey') }}</AppButton>
                <AppButton v-else size="sm" variant="secondary" @click="openKey(p)">{{ $t('aiAdmin.ui.changeKey') }}</AppButton>
              </template>
              <AppButton v-if="!needsKey(p)" size="sm" variant="secondary" :loading="connTesting === p.id" @click="testConnection(p)">{{ $t('aiAdmin.testConnection') }}</AppButton>
              <button v-if="p.circuit.state !== 'closed'" type="button" class="rounded-lg bg-warning-50 px-2.5 py-1.5 text-xs font-semibold text-warning-700 ring-1 ring-inset ring-warning-600/25 hover:bg-warning-100" @click="resetCircuit(p)">{{ $t('aiAdmin.ui.resetConnection') }}</button>
              <AppButton v-if="p.is_custom && !p.models_count" size="sm" variant="ghost" class="!text-danger-600 hover:!bg-danger-50" @click="providerDeleting = p">{{ $t('common.delete') }}</AppButton>
              <button type="button" class="ml-auto inline-flex items-center gap-1 rounded-md px-2 py-1.5 text-xs font-medium text-ink-muted hover:bg-slate-100 hover:text-ink" :aria-expanded="advancedOpen.has(p.id)" @click="toggleAdvanced(p.id)">
                {{ $t('aiAdmin.ui.advanced') }}<AppIcon :name="advancedOpen.has(p.id) ? 'chevronUp' : 'chevronDown'" :size="14" />
              </button>
            </div>
          </li>
        </ul>
      </div>

      <!-- Models -->
      <div v-if="tab === 'models'">
        <div class="flex flex-col gap-2 px-5 pb-4 sm:flex-row sm:items-center">
          <SearchInput v-model="modelSearch" :placeholder="$t('aiAdmin.ui.searchModels')" />
          <FilterSelect v-model="modelProvider" :label="$t('ai.provider')" :options="[{ value: '', label: $t('aiAdmin.ui.all') }, ...providerOptions]" />
          <FilterSelect v-model="modelStatus" :label="$t('aiAdmin.ui.status')" :options="[{ value: '', label: $t('aiAdmin.ui.all') }, ...statusFilterOptions]" />
          <button v-if="modelsFiltered" type="button" class="text-sm text-brand-700 hover:underline" @click="clearModelFilters">{{ $t('common.clearFilters') }}</button>
        </div>
        <LoadingState v-if="models.loading.value && !models.data.value" />
        <ErrorState v-else-if="models.error.value" :error="models.error.value" @retry="models.run" />
        <EmptyState v-else-if="!filteredModels.length" icon="cpu" :title="modelsFiltered ? $t('aiAdmin.ui.noMatch') : $t('aiAdmin.ui.noModels')" />
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[880px] text-sm" data-testid="model-list">
            <thead class="border-y border-line bg-surface-muted text-left text-xs font-semibold text-ink-muted">
              <tr>
                <th scope="col" class="py-2.5 pl-5 pr-3">{{ $t('aiAdmin.ui.colModel') }}</th>
                <th scope="col" class="px-3">{{ $t('aiAdmin.tier') }}</th>
                <th scope="col" class="px-3">{{ $t('aiAdmin.capabilitiesTitle') }}</th>
                <th scope="col" class="px-3 text-right">{{ $t('aiAdmin.priceInOut') }}</th>
                <th scope="col" class="px-3">{{ $t('aiAdmin.ui.status') }}</th>
                <th scope="col" class="pl-3 pr-5"><span class="sr-only">{{ $t('aiAdmin.ui.actions') }}</span></th>
              </tr>
            </thead>
            <tbody v-for="g in modelGroups" :key="g.id" class="divide-y divide-line border-b border-line last:border-b-0">
              <tr class="bg-surface-muted/60"><th scope="colgroup" colspan="6" class="py-1.5 pl-5 text-left text-xs font-semibold text-ink-muted">{{ g.name }}</th></tr>
              <tr v-for="m in g.items" :key="m.id" class="hover:bg-surface-muted/50">
                <td class="py-3 pl-5 pr-3"><p class="font-medium text-ink">{{ m.display_name }}</p><p class="font-mono text-xs text-ink-subtle">{{ m.model_key }}</p></td>
                <td class="px-3"><AppBadge :tone="tierBadge[m.tier]">{{ $t(`aiAdmin.tiers.${m.tier}`) }}</AppBadge></td>
                <td class="px-3">
                  <div class="flex flex-wrap gap-1">
                    <span v-for="c in CAPS.filter((c) => m.capabilities[c])" :key="c" class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-ink-muted">{{ $t(`aiAdmin.capabilities.${c}`) }}</span>
                  </div>
                </td>
                <td class="px-3 text-right tabular-nums">
                  <template v-if="m.current_price">
                    <span class="font-medium text-ink">{{ formatPerMtok(m.current_price.input_per_mtok) }} / {{ formatPerMtok(m.current_price.output_per_mtok) }}</span>
                    <AppBadge v-if="m.scheduled_prices" tone="brand" class="ml-1">{{ $t('aiAdmin.ui.scheduled', { n: m.scheduled_prices }) }}</AppBadge>
                    <span v-if="m.current_price.peak_windows?.length" class="block text-xs text-ink-subtle">{{ $t('aiAdmin.offPeakShort', { in: formatPerMtok(m.current_price.offpeak_input_per_mtok), out: formatPerMtok(m.current_price.offpeak_output_per_mtok) }) }}</span>
                  </template>
                  <AppBadge v-else tone="warning">{{ $t('aiAdmin.noPrice') }}</AppBadge>
                </td>
                <td class="px-3"><AppBadge :tone="m.is_enabled ? 'success' : 'neutral'" dot>{{ m.is_enabled ? $t('aiAdmin.enabled') : $t('aiAdmin.disabled') }}</AppBadge></td>
                <td class="pl-3 pr-5">
                  <div class="flex items-center justify-end gap-1">
                    <AppButton v-if="!m.current_price" size="sm" @click="openPrices(m)">{{ $t('aiAdmin.ui.addPriceCta') }}</AppButton>
                    <AppButton v-else size="sm" variant="ghost" class="!text-brand-700" @click="openPrices(m)">{{ $t('aiAdmin.prices') }}</AppButton>
                    <AppButton size="sm" variant="ghost" class="!text-brand-700" @click="openTest(m)">{{ $t('aiAdmin.testModel') }}</AppButton>
                    <DropdownMenu :label="$t('aiAdmin.ui.moreActions')">
                      <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-ink hover:bg-slate-50" @click="openEditModel(m)">{{ $t('common.edit') }}</button>
                      <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-ink hover:bg-slate-50" @click="toggleModel(m)">{{ m.is_enabled ? $t('aiAdmin.disable') : $t('aiAdmin.enable') }}</button>
                      <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-danger-600 hover:bg-danger-50" @click="modelDeleting = m">{{ $t('common.delete') }}</button>
                    </DropdownMenu>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Routes -->
      <div v-if="tab === 'routes'" class="border-t border-line">
        <LoadingState v-if="routes.loading.value && !routes.data.value" />
        <ErrorState v-else-if="routes.error.value" :error="routes.error.value" @retry="routes.run" />
        <ul v-else class="divide-y divide-line" data-testid="route-list">
          <li v-for="r in routes.data.value ?? []" :key="r.id" class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:gap-6" :class="r.is_enabled ? '' : 'bg-surface-muted/60'">
            <div class="lg:w-72 lg:shrink-0">
              <p class="flex flex-wrap items-center gap-2 font-medium text-ink">{{ r.description || r.task_key }} <AppBadge v-if="!r.is_enabled">{{ $t('aiAdmin.disabled') }}</AppBadge></p>
              <p class="mt-0.5 font-mono text-xs text-ink-subtle">{{ r.task_key }} · {{ $t(`aiAdmin.priorities.${r.priority}`) }} · {{ $t('aiAdmin.ui.maxTokens', { n: r.max_output_tokens.toLocaleString('vi-VN') }) }}</p>
            </div>
            <div class="flex flex-1 flex-wrap items-center gap-1.5 text-xs">
              <template v-if="r.primary_model">
                <span class="inline-flex items-center gap-1 rounded-md bg-brand-50 px-2 py-1 font-medium text-brand-700 ring-1 ring-inset ring-brand-600/20" :title="$t('aiAdmin.primary')"><AppIcon name="star" :size="12" />{{ r.primary_model.model_key }}</span>
                <template v-for="f in r.fallback_models" :key="f.id">
                  <AppIcon name="chevronRight" :size="14" class="text-ink-subtle" />
                  <span class="rounded-md bg-surface px-2 py-1 text-ink-muted ring-1 ring-inset ring-line" :title="$t('aiAdmin.fallbacks')">{{ f.model_key }}</span>
                </template>
              </template>
              <AppBadge v-else tone="danger">{{ $t('aiAdmin.ui.noPrimary') }}</AppBadge>
              <span v-if="r.economy_model" class="ml-1 text-ink-subtle">· {{ $t('aiAdmin.ui.economyModel', { m: r.economy_model.model_key }) }}</span>
            </div>
            <div class="flex items-center gap-4">
              <span class="inline-flex items-center gap-1 text-xs" :class="r.allow_paid_retry ? 'text-warning-700' : 'text-success-700'">
                <AppIcon :name="r.allow_paid_retry ? 'alert' : 'shieldCheck'" :size="14" />{{ r.allow_paid_retry ? $t('aiAdmin.ui.paidRetryOn') : $t('aiAdmin.ui.paidRetryOff') }}
              </span>
              <AppButton size="sm" variant="secondary" @click="openRoute(r)">{{ $t('common.edit') }}</AppButton>
            </div>
          </li>
        </ul>
      </div>

      <!-- Budgets -->
      <div v-if="tab === 'budgets'" class="px-5 pb-5">
        <LoadingState v-if="budgets.loading.value && !budgets.data.value" />
        <ErrorState v-else-if="budgets.error.value" :error="budgets.error.value" @retry="budgets.run" />
        <EmptyState v-else-if="!(budgets.data.value ?? []).length" icon="coins" :title="$t('aiAdmin.ui.noBudgetTitle')" :description="$t('aiAdmin.ui.noBudgetDesc')" />
        <ul v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="budget-list">
          <li v-for="b in budgets.data.value ?? []" :key="b.id" class="rounded-xl p-5 ring-1 ring-line">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="font-semibold text-ink">{{ b.scope === 'global' ? $t('aiAdmin.scopes.global') : (b.workspace?.name ?? b.workspace?.id) }} · {{ $t(`aiAdmin.periods.${b.period}`) }}</p>
                <p class="mt-0.5 text-xs text-ink-muted">{{ $t('aiAdmin.ui.onExceed', { action: $t(`aiAdmin.actions.${b.action_on_exceed}`), pct: b.alert_threshold_pct }) }}</p>
              </div>
              <AppBadge :tone="budgetState(b).tone">{{ $t(`aiAdmin.ui.budgetStates.${budgetState(b).key}`) }}</AppBadge>
            </div>
            <p class="mt-4 flex items-baseline gap-1.5"><span class="text-2xl font-semibold tracking-tight tabular-nums text-ink">{{ formatUsd(b.usage.spent_micro_usd, 2) }}</span><span class="text-sm text-ink-subtle">/ {{ formatUsd(b.limit_micro_usd, 2) }} · {{ b.usage.used_pct }}%</span></p>
            <div class="relative mt-2">
              <div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full transition-all" :class="budgetState(b).bar" :style="{ width: Math.min(100, b.usage.used_pct) + '%' }" /></div>
              <div class="absolute -top-1 h-4 w-0.5 rounded bg-warning-500" :style="{ left: Math.min(100, b.alert_threshold_pct) + '%' }" :title="$t('aiAdmin.alertAt') + ' ' + b.alert_threshold_pct + '%'" />
            </div>
            <p v-if="b.usage.reserved_micro_usd" class="mt-2 text-xs text-ink-muted">{{ $t('aiAdmin.reserved', { amount: formatUsd(b.usage.reserved_micro_usd) }) }}</p>
            <div v-if="canManage" class="mt-4 flex gap-2">
              <AppButton size="sm" variant="secondary" @click="openBudget(b)">{{ $t('common.edit') }}</AppButton>
              <AppButton size="sm" variant="ghost" class="!text-danger-600 hover:!bg-danger-50" @click="deleting = b">{{ $t('common.delete') }}</AppButton>
            </div>
          </li>
        </ul>
      </div>
    </section>
    <!-- Price versions -->
    <AppModal :open="!!priceModel" :title="$t('aiAdmin.pricesOf', { name: priceModel?.display_name ?? '' })" width="lg" @close="priceModel = null">
      <h3 class="text-sm font-semibold text-ink">{{ $t('aiAdmin.ui.priceHistory') }}</h3>
      <p class="mt-0.5 text-xs text-ink-muted">{{ $t('aiAdmin.priceLocked') }}</p>
      <p v-if="!priceList.length" class="mt-3 rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-700 ring-1 ring-inset ring-warning-600/20">{{ $t('aiAdmin.noPrice') }}</p>
      <ul v-else class="mt-3 divide-y divide-line rounded-lg text-sm ring-1 ring-line">
        <li v-for="p in priceList" :key="p.id" class="flex flex-wrap items-center gap-3 px-3 py-2">
          <span class="font-medium">{{ $t('aiAdmin.priceVersion', { v: p.version }) }}</span>
          <AppBadge :tone="p.state === 'effective' ? 'success' : 'brand'">{{ $t(`aiAdmin.priceStates.${p.state}`) }}</AppBadge>
          <span class="text-xs text-ink-muted">{{ formatDateTime(p.effective_from) }}</span>
          <span class="flex-1 text-right text-xs">{{ formatPerMtok(p.input_per_mtok) }} / {{ formatPerMtok(p.output_per_mtok) }}<template v-if="p.per_1k_searches"> · {{ formatPerMtok(p.per_1k_searches) }}/1K</template>
            <template v-if="p.peak_windows?.length"><br /><span class="text-ink-muted">{{ $t('aiAdmin.offPeakShort', { in: formatPerMtok(p.offpeak_input_per_mtok), out: formatPerMtok(p.offpeak_output_per_mtok) }) }} · {{ $t('aiAdmin.peakUtc') }} {{ formatPeakWindows(p.peak_windows).replace(/\n/g, ', ') }}</span></template>
          </span>
          <a v-if="p.source_url" :href="p.source_url" target="_blank" rel="noopener noreferrer" class="text-xs text-brand-600 underline">src</a>
          <AppButton v-if="p.state === 'scheduled'" size="sm" variant="ghost" @click="deletePrice(p)">{{ $t('common.delete') }}</AppButton>
        </li>
      </ul>
      <h3 class="mt-6 border-t border-line pt-4 text-sm font-semibold text-ink">{{ $t('aiAdmin.addPrice') }}</h3>
      <form id="price-form" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2" novalidate @submit.prevent="addPrice">
        <p v-if="priceForm.formError.value" class="sm:col-span-2 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ priceForm.formError.value }}</p>
        <AppInput v-model="priceDraft.effective_from" type="datetime-local" :label="$t('aiAdmin.effectiveFrom')" :error="priceForm.errors.effective_from" />
        <AppInput v-model="priceDraft.source_url" :label="$t('aiAdmin.sourceUrl')" :error="priceForm.errors.source_url" />
        <AppInput v-model="priceDraft.input" inputmode="decimal" :label="$t('aiAdmin.inputPrice')" :error="priceForm.errors.input_per_mtok" />
        <AppInput v-model="priceDraft.output" inputmode="decimal" :label="$t('aiAdmin.outputPrice')" :error="priceForm.errors.output_per_mtok" />
        <AppInput v-model="priceDraft.cached" inputmode="decimal" :label="$t('aiAdmin.cachedPrice')" />
        <AppInput v-model="priceDraft.cache_write" inputmode="decimal" :label="$t('aiAdmin.cacheWritePrice')" />
        <AppInput v-model="priceDraft.search" inputmode="decimal" :label="$t('aiAdmin.searchPrice')" />
        <AppInput v-model="priceDraft.image" inputmode="decimal" :label="$t('aiAdmin.imagePrice')" />
        <label class="flex items-start gap-2 rounded-lg bg-surface-muted p-3 text-sm ring-1 ring-line sm:col-span-2"><input v-model="priceDraft.timeOfDay" type="checkbox" class="mt-0.5" data-testid="time-of-day" />
          <span>{{ $t('aiAdmin.timeOfDay') }}<br /><span class="text-xs text-ink-muted">{{ $t('aiAdmin.timeOfDayHint') }}</span></span></label>
        <template v-if="priceDraft.timeOfDay">
          <AppInput v-model="priceDraft.off_input" inputmode="decimal" :label="$t('aiAdmin.offInputPrice')" :error="priceForm.errors.offpeak_input_per_mtok" />
          <AppInput v-model="priceDraft.off_output" inputmode="decimal" :label="$t('aiAdmin.offOutputPrice')" :error="priceForm.errors.offpeak_output_per_mtok" />
          <AppInput v-model="priceDraft.off_cached" inputmode="decimal" :label="$t('aiAdmin.offCachedPrice')" />
          <label class="text-sm sm:col-span-2">{{ $t('aiAdmin.peakWindows') }}
            <textarea v-model="priceDraft.windows" rows="2" class="mt-1 block w-full rounded-lg border-0 px-3 py-2 font-mono text-sm shadow-xs ring-1 ring-inset ring-line-strong focus:outline-none focus:ring-2 focus:ring-brand-600" placeholder="1-5 01:00-04:00&#10;1-5 06:00-10:00" data-testid="peak-windows" />
            <span class="text-xs text-ink-muted">{{ $t('aiAdmin.peakWindowsHint') }}</span>
            <span v-if="priceForm.errors.peak_windows" class="block text-xs text-danger-600" role="alert">{{ priceForm.errors.peak_windows }}</span>
          </label>
        </template>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="priceModel = null">{{ $t('common.close') }}</AppButton>
        <AppButton type="submit" form="price-form" :loading="priceForm.submitting.value">{{ $t('aiAdmin.addPrice') }}</AppButton>
      </template>
    </AppModal>

    <!-- New model -->
    <AppModal :open="modelOpen" :title="$t('aiAdmin.addModel')" width="sm" @close="modelOpen = false">
      <form id="model-form" class="space-y-3" novalidate @submit.prevent="createModel">
        <p v-if="modelForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ modelForm.formError.value }}</p>
        <AppSelect v-model="modelDraft.provider_id" :label="$t('ai.provider')" :options="providerOptions" />
        <AppInput v-model="modelDraft.model_name" :label="$t('aiAdmin.modelName')" :error="modelForm.errors.model_name" />
        <AppInput v-model="modelDraft.display_name" :label="$t('aiAdmin.displayName')" :error="modelForm.errors.display_name" />
        <AppSelect v-model="modelDraft.tier" :label="$t('aiAdmin.tier')" :options="tierOptions" />
        <p class="text-xs text-ink-muted">{{ $t('aiAdmin.newModelHint') }}</p>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="modelOpen = false">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="model-form" :loading="modelForm.submitting.value">{{ $t('common.confirm') }}</AppButton>
      </template>
    </AppModal>

    <!-- Route -->
    <AppModal :open="!!routeEdit" :title="$t('aiAdmin.editRoute', { task: routeEdit?.task_key ?? '' })" @close="routeEdit = null">
      <form id="route-form" class="space-y-3" novalidate @submit.prevent="saveRoute">
        <p v-if="routeForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ routeForm.formError.value }}</p>
        <AppSelect v-model="routeDraft.primary" :label="$t('aiAdmin.primary')" :options="modelOptions" :error="routeForm.errors.primary_model_id" />
        <AppSelect v-model="routeDraft.economy" :label="$t('aiAdmin.economy')" :options="[{ value: '', label: $t('aiAdmin.none') }, ...modelOptions]" />
        <fieldset>
          <legend class="text-sm font-medium">{{ $t('aiAdmin.fallbacks') }}</legend>
          <p class="text-xs text-ink-muted">{{ $t('aiAdmin.ui.fallbackHint') }}</p>
          <div class="mt-1.5 grid max-h-44 grid-cols-1 gap-1 overflow-y-auto rounded-lg p-2 ring-1 ring-line sm:grid-cols-2">
            <label v-for="o in modelOptions.filter((o) => o.value !== routeDraft.primary)" :key="o.value" class="flex items-center gap-2 rounded px-1.5 py-1 text-xs hover:bg-surface-muted">
              <input type="checkbox" :checked="routeDraft.fallbacks.includes(o.value)" @change="toggleFallback(o.value)" />
              {{ o.label }}<span v-if="routeDraft.fallbacks.includes(o.value)" class="ml-auto rounded bg-brand-50 px-1.5 font-medium text-brand-700">#{{ routeDraft.fallbacks.indexOf(o.value) + 1 }}</span>
            </label>
          </div>
        </fieldset>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <AppInput v-model="routeDraft.max_output_tokens" type="number" :label="$t('aiAdmin.maxOutput')" :error="routeForm.errors.max_output_tokens" />
          <AppInput v-model="routeDraft.max_attempts" type="number" :label="$t('aiAdmin.maxAttempts')" :error="routeForm.errors.max_attempts" />
          <AppSelect v-model="routeDraft.priority" :label="$t('aiAdmin.priority')" :options="priorityOptions" />
        </div>
        <div class="divide-y divide-line rounded-lg px-3 ring-1 ring-line">
          <Toggle v-model="routeDraft.allow_paid_retry" :label="$t('aiAdmin.allowPaidRetry')" :description="$t('aiAdmin.allowPaidRetryHint')" />
          <Toggle v-model="routeDraft.is_enabled" :label="$t('aiAdmin.ui.routeEnabled')" />
        </div>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="routeEdit = null">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="route-form" :loading="routeForm.submitting.value">{{ $t('common.save') }}</AppButton>
      </template>
    </AppModal>

    <!-- Budget -->
    <AppModal :open="budgetOpen" :title="budgetEditing ? $t('aiAdmin.ui.editBudget') : $t('aiAdmin.addBudget')" width="sm" @close="budgetOpen = false">
      <form id="budget-form" class="space-y-3" novalidate @submit.prevent="saveBudget">
        <p v-if="budgetForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ budgetForm.formError.value }}</p>
        <AppSelect v-model="budgetDraft.scope" :label="$t('aiAdmin.budgetScope')" :options="scopeOptions" />
        <AppInput v-if="budgetDraft.scope === 'workspace'" v-model="budgetDraft.workspace_id" :label="$t('aiAdmin.workspaceIdHint')" :error="budgetForm.errors.workspace_id" />
        <AppSelect v-model="budgetDraft.period" :label="$t('aiAdmin.period')" :options="periodOptions" />
        <AppInput v-model="budgetDraft.limit" inputmode="decimal" :label="$t('aiAdmin.limit')" :error="budgetForm.errors.limit_micro_usd" />
        <AppInput v-model="budgetDraft.alert" type="number" :label="$t('aiAdmin.alertAt')" :error="budgetForm.errors.alert_threshold_pct" />
        <AppSelect v-model="budgetDraft.action" :label="$t('aiAdmin.action')" :options="actionOptions" />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="budgetOpen = false">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="budget-form" :loading="budgetForm.submitting.value">{{ $t('common.save') }}</AppButton>
      </template>
    </AppModal>
    <!-- API key -->
    <AppModal :open="!!keyProvider" :title="$t('aiAdmin.keyTitle', { name: keyProvider?.name ?? '' })" width="sm" @close="keyProvider = null">
      <form id="key-form" class="space-y-3" novalidate autocomplete="off" @submit.prevent="saveKey()">
        <p v-if="keyForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ keyForm.formError.value }}</p>
        <AppInput v-model="keyDraft" type="password" autocomplete="new-password" :label="$t('aiAdmin.apiKey')" :error="keyForm.errors.api_key" />
        <p class="flex gap-2 text-xs text-ink-muted"><AppIcon name="lock" :size="14" class="mt-0.5 shrink-0" />{{ $t('aiAdmin.keySecurity') }}</p>
        <p v-if="keyProvider?.api_key_source === 'database'" class="text-xs">{{ $t('aiAdmin.keyStored', { last4: keyProvider.api_key_last4 ?? '' }) }}</p>
      </form>
      <template #footer>
        <AppButton v-if="keyProvider?.api_key_source === 'database'" variant="ghost" class="mr-auto !text-danger-600 hover:!bg-danger-50" :loading="keyForm.submitting.value" @click="saveKey(true)">{{ $t('aiAdmin.clearKey') }}</AppButton>
        <AppButton variant="secondary" @click="keyProvider = null">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="key-form" :disabled="keyDraft.trim().length < 8" :loading="keyForm.submitting.value">{{ $t('common.save') }}</AppButton>
      </template>
    </AppModal>

    <!-- Connection test result -->
    <AppModal :open="!!connResult" :title="$t('aiAdmin.connTitle', { name: connResult?.provider.name ?? '' })" width="sm" @close="connResult = null">
      <div v-if="connResult" class="space-y-3 text-sm" data-testid="conn-result">
        <p class="flex items-center gap-2">
          <AppBadge :tone="connResult.result.ok ? 'success' : 'danger'">{{ connResult.result.ok ? $t('aiAdmin.connOk') : $t('aiAdmin.connFailed') }}</AppBadge>
          <span v-if="connResult.result.latency_ms !== null" class="text-xs text-ink-muted">{{ connResult.result.latency_ms }} ms</span>
        </p>
        <p v-if="!connResult.result.ok" class="rounded-lg bg-danger-50 px-3 py-2 text-danger-600">{{ aiErrorMessage({ code: connResult.result.error_code ?? 'provider_error', message: connResult.result.error_message }) }}</p>
        <template v-else>
          <p>{{ $t('aiAdmin.connModelsFound', { n: connResult.result.models_found }) }}</p>
          <ul v-if="connResult.result.models.length" class="divide-y divide-line rounded-lg border border-line">
            <li v-for="m in connResult.result.models" :key="m.model_name" class="flex items-center justify-between px-3 py-1.5 font-mono text-xs">
              {{ m.model_name }}<AppBadge :tone="m.available ? 'success' : 'warning'">{{ m.available ? $t('aiAdmin.modelAvailable') : $t('aiAdmin.modelMissing') }}</AppBadge>
            </li>
          </ul>
        </template>
      </div>
      <template #footer><AppButton variant="secondary" @click="connResult = null">{{ $t('common.close') }}</AppButton></template>
    </AppModal>

    <!-- Custom OpenAI-compatible provider -->
    <AppModal :open="customOpen" :title="$t('aiAdmin.addCustomProvider')" width="sm" @close="customOpen = false">
      <form id="custom-form" class="space-y-3" novalidate autocomplete="off" @submit.prevent="createCustom">
        <p v-if="customForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ customForm.formError.value }}</p>
        <AppInput v-model="customDraft.name" :label="$t('aiAdmin.providerName')" :error="customForm.errors.name" />
        <AppInput v-model="customDraft.code" :label="$t('aiAdmin.providerCode')" :error="customForm.errors.code" />
        <AppInput v-model="customDraft.base_url" :label="$t('aiAdmin.baseUrl')" :error="customForm.errors.base_url" />
        <p class="text-xs text-ink-muted">{{ $t('aiAdmin.baseUrlHint') }}</p>
        <AppInput v-model="customDraft.api_key" type="password" autocomplete="new-password" :label="$t('aiAdmin.apiKey')" :error="customForm.errors.api_key" />
        <AppSelect v-model="customDraft.response_format" :label="$t('aiAdmin.responseFormat')" :options="responseFormatOptions" />
        <p class="text-xs text-ink-muted">{{ $t('aiAdmin.customHint') }}</p>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="customOpen = false">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="custom-form" :loading="customForm.submitting.value">{{ $t('common.confirm') }}</AppButton>
      </template>
    </AppModal>

    <!-- Edit model -->
    <AppModal :open="!!editModel" :title="$t('aiAdmin.editModel', { name: editModel?.display_name ?? '' })" @close="editModel = null">
      <form id="edit-model-form" class="space-y-3" novalidate @submit.prevent="saveModel">
        <p v-if="editForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ editForm.formError.value }}</p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <AppInput v-model="editDraft.model_name" :label="$t('aiAdmin.modelName')" :error="editForm.errors.model_name" />
          <AppInput v-model="editDraft.display_name" :label="$t('aiAdmin.displayName')" :error="editForm.errors.display_name" />
          <AppSelect v-model="editDraft.tier" :label="$t('aiAdmin.tier')" :options="tierOptions" />
          <AppSelect v-model="editDraft.reasoning" :label="$t('aiAdmin.reasoningEffort')" :options="effortOptions" />
        </div>
        <p class="text-xs text-ink-muted">{{ $t('aiAdmin.modelIdHint') }}</p>
        <fieldset>
          <legend class="text-sm font-medium">{{ $t('aiAdmin.capabilitiesTitle') }}</legend>
          <div class="mt-2 flex flex-wrap gap-2">
            <label v-for="c in CAPS" :key="c" class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs ring-1 ring-line hover:bg-surface-muted"><input v-model="editDraft.caps[c]" type="checkbox" /> {{ $t(`aiAdmin.capabilities.${c}`) }}</label>
          </div>
        </fieldset>
      </form>
      <template #footer>
        <AppButton variant="ghost" class="mr-auto !text-danger-600 hover:!bg-danger-50" @click="modelDeleting = editModel">{{ $t('common.delete') }}</AppButton>
        <AppButton variant="secondary" @click="editModel = null">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="edit-model-form" :loading="editForm.submitting.value">{{ $t('common.save') }}</AppButton>
      </template>
    </AppModal>

    <!-- Test model -->
    <AppModal :open="!!testModel" :title="$t('aiAdmin.testModelTitle', { name: testModel?.model_key ?? '' })" @close="testModel = null">
      <div class="space-y-3 text-sm">
        <p class="flex gap-2 rounded-lg bg-warning-50 px-3 py-2 text-warning-700 ring-1 ring-inset ring-warning-600/20"><AppIcon name="alert" :size="16" class="mt-0.5 shrink-0" />{{ $t('aiAdmin.testModelPaid') }}</p>
        <AppInput v-model="testDraft.prompt" :label="$t('aiAdmin.testPrompt')" :placeholder="$t('aiAdmin.testPromptPlaceholder')" />
        <label class="flex items-center gap-2"><input v-model="testDraft.structured" type="checkbox" /> {{ $t('aiAdmin.testStructured') }}</label>
        <div v-if="testResult" class="space-y-2 rounded-lg border border-line p-3" data-testid="model-test-result">
          <p class="flex flex-wrap items-center gap-2">
            <AppBadge :tone="testResult.ok ? 'success' : 'danger'">{{ testResult.ok ? $t('aiAdmin.testOk') : $t('aiAdmin.testFailed') }}</AppBadge>
            <span class="text-xs text-ink-muted">{{ testResult.latency_ms }} ms</span>
            <span v-if="testResult.ok" class="text-xs">{{ $t('aiAdmin.testCost', { cost: formatUsd(testResult.cost_micro_usd), in: formatTokens(testResult.usage.input_tokens), out: formatTokens(testResult.usage.output_tokens) }) }}</span>
          </p>
          <pre v-if="testResult.ok" class="max-h-48 overflow-auto whitespace-pre-wrap rounded bg-surface-muted p-2 text-xs">{{ testResult.json ? JSON.stringify(testResult.json, null, 2) : testResult.text }}</pre>
          <p v-else class="text-danger-600">{{ aiErrorMessage({ code: testResult.error_code, message: testResult.error_message }) }}</p>
        </div>
      </div>
      <template #footer>
        <AppButton variant="secondary" @click="testModel = null">{{ $t('common.close') }}</AppButton>
        <AppButton :loading="testRunning" @click="runTest">{{ $t('aiAdmin.runTest') }}</AppButton>
      </template>
    </AppModal>

    <ConfirmDialog :open="!!providerDeleting" :title="$t('common.delete')" :message="$t('aiAdmin.deleteProvider', { name: providerDeleting?.name ?? '' })" danger @close="providerDeleting = null" @confirm="deleteProvider" />
    <ConfirmDialog :open="!!modelDeleting" :title="$t('common.delete')" :message="$t('aiAdmin.deleteModel', { name: modelDeleting?.model_key ?? '' })" danger @close="modelDeleting = null" @confirm="deleteModel" />
    <ConfirmDialog :open="!!deleting" :title="$t('common.delete')" :message="$t('aiAdmin.deleteBudget')" danger @close="deleting = null" @confirm="deleteBudget" />
  </div>
</template>
