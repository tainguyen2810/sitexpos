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

const { t, te } = useI18n()
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

// ---- overview (summary cards + tab counts) ----
const list = <T,>(a: { data: { value: T[] | null } }) => a.data.value ?? []
const enabledProviders = computed(() => list(providers).filter((p) => p.is_enabled))
const readyProviders = computed(() => enabledProviders.value.filter((p) => p.has_api_key || p.driver === 'fake'))
const enabledModels = computed(() => list(models).filter((m) => m.is_enabled))
const unpricedModels = computed(() => enabledModels.value.filter((m) => !m.current_price))
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
  const w = name.replace(/[^\p{L}\p{N}\s]/gu, ' ').trim().split(/\s+/)
  return (w.length > 1 ? (w[0]?.[0] ?? '') + (w[1]?.[0] ?? '') : (w[0] ?? name).slice(0, 2)).toUpperCase()
}
function avatarClass(p: AiProvider) {
  if (!p.is_enabled) return 'bg-slate-100 text-slate-500 ring-slate-500/15'
  let h = 0
  for (const c of p.code) h = (h * 31 + c.charCodeAt(0)) >>> 0
  return AVATAR[h % AVATAR.length]
}

// ---- providers ----
const toggleProvider = (p: AiProvider) => guarded(() => adminAiApi.updateProvider(p.id, { is_enabled: !p.is_enabled }), providers.run)
const resetCircuit = (p: AiProvider) => guarded(() => adminAiApi.resetCircuit(p.id), providers.run)

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
const routeDraft = ref({ chain: [] as string[], economy: '', max_output_tokens: '', max_attempts: '', allow_paid_retry: false, priority: 'batch', is_enabled: true })
const modelOptions = computed(() => (models.data.value ?? []).map((m) => ({ value: m.id, label: `${m.display_name} · ${m.provider.name}${m.is_enabled ? '' : ' (off)'}` })))
function openRoute(r: AiRoute) {
  routeForm.reset()
  routeEdit.value = r
  routeAdd.value = ''
  routeDraft.value = {
    chain: [r.primary_model?.id, ...r.fallback_models.map((m) => m.id)].filter((x): x is string => !!x),
    economy: r.economy_model?.id ?? '',
    max_output_tokens: String(r.max_output_tokens), max_attempts: String(r.max_attempts), allow_paid_retry: r.allow_paid_retry,
    priority: r.priority, is_enabled: r.is_enabled,
  }
}
// Ordered model chain editor: 1 = primary, the rest are fallbacks tried top-down.
const routeAdd = ref('')
function moveInChain(i: number, delta: number) {
  const c = [...routeDraft.value.chain]
  const j = i + delta
  if (j < 0 || j >= c.length) return
  ;[c[i], c[j]] = [c[j] as string, c[i] as string]
  routeDraft.value.chain = c
}
function removeFromChain(i: number) {
  routeDraft.value.chain = routeDraft.value.chain.filter((_, k) => k !== i)
}
function addToChain() {
  if (routeAdd.value && !routeDraft.value.chain.includes(routeAdd.value)) routeDraft.value.chain = [...routeDraft.value.chain, routeAdd.value]
  routeAdd.value = ''
}
const dragFrom = ref<number | null>(null)
function dropOn(i: number) {
  const from = dragFrom.value
  dragFrom.value = null
  if (from === null || from === i) return
  const c = [...routeDraft.value.chain]
  const [m] = c.splice(from, 1)
  c.splice(i, 0, m as string)
  routeDraft.value.chain = c
}
const addableModels = computed(() => list(models).filter((m) => !routeDraft.value.chain.includes(m.id))
  .map((m) => {
    const reason = skipReason(m, routeEdit.value?.task_key ?? '')
    return { value: m.id, label: `${m.display_name} · ${m.provider.name}${reason ? ` — ${t(`aiAdmin.ui.skip.${reason}`)}` : ''}` }
  }))
const draftEffective = computed(() => {
  const key = routeEdit.value?.task_key ?? ''
  return routeDraft.value.chain.map((id) => modelById.value.get(id)).find((m) => m && !skipReason(m, key)) ?? null
})
async function saveRoute() {
  const r = routeEdit.value
  if (!r) return
  const d = routeDraft.value
  const ok = await routeForm.submit(() => adminAiApi.updateRoute(r.id, {
    primary_model_id: d.chain[0] ?? '', economy_model_id: d.economy || null, fallback_model_ids: d.chain.slice(1),
    max_output_tokens: Number(d.max_output_tokens), max_attempts: Number(d.max_attempts), allow_paid_retry: d.allow_paid_retry,
    priority: d.priority, is_enabled: d.is_enabled,
  }))
  if (!ok) return
  routeEdit.value = null
  toast.success(t('common.saved'))
  await routes.run()
}

// ---- route readiness: which model will really run (mirrors AiRouter::eligible + AiGateway skips) ----
type SkipReason = 'provider_off' | 'no_key' | 'model_off' | 'no_price' | 'circuit_open' | 'wrong_kind'
const providerById = computed(() => new Map(list(providers).map((p) => [p.id, p])))
const modelById = computed(() => new Map(list(models).map((m) => [m.id, m])))
function skipReason(m: AiModel, taskKey: string): SkipReason | null {
  const p = providerById.value.get(m.provider.id)
  if (p && !p.is_enabled) return 'provider_off'
  if (p && !p.has_api_key) return 'no_key'
  if (!m.is_enabled) return 'model_off'
  const image = taskKey === 'media.image'
  if (image ? !m.capabilities.image_generation : m.kind !== 'text') return 'wrong_kind'
  if (!m.current_price) return 'no_price'
  if (p && p.circuit.state === 'open') return 'circuit_open'
  return null
}
type ChainItem = { id: string; name: string; reason: SkipReason | null; runs: boolean }
type RouteView = { route: AiRoute; chain: ChainItem[]; runIndex: number; status: 'primary' | 'fallback' | 'blocked' | 'off' }
const routeViews = computed<RouteView[]>(() => list(routes).map((r) => {
  const refs = [r.primary_model, ...r.fallback_models].filter((x): x is NonNullable<typeof x> => !!x)
  let runIndex = -1
  const chain = refs.map((ref, i) => {
    const m = modelById.value.get(ref.id)
    const reason = m ? skipReason(m, r.task_key) : 'model_off'
    if (!reason && runIndex < 0) runIndex = i
    return { id: ref.id, name: m?.display_name ?? ref.model_key, reason, runs: false }
  })
  const run = chain[runIndex]
  if (run) run.runs = true
  const status = !r.is_enabled ? 'off' : runIndex < 0 ? 'blocked' : runIndex === 0 ? 'primary' : 'fallback'
  return { route: r, chain, runIndex, status }
}))
const routeFilter = ref<'all' | 'primary' | 'fallback' | 'blocked'>('all')
const routeSearch = ref('')
const routeCounts = computed(() => {
  const c = { all: routeViews.value.length, primary: 0, fallback: 0, blocked: 0 }
  for (const v of routeViews.value) if (v.status !== 'off') c[v.status]++
  return c
})
function taskName(r: AiRoute) {
  const k = `aiAdmin.taskNames.${r.task_key.replace(/\./g, '_')}`
  return te(k) ? t(k) : (r.description || r.task_key)
}
function taskDesc(r: AiRoute) {
  const k = `aiAdmin.taskDescs.${r.task_key.replace(/\./g, '_')}`
  return te(k) ? t(k) : (te(`aiAdmin.taskNames.${r.task_key.replace(/\./g, '_')}`) ? (r.description ?? '') : '')
}
const GROUPS: Record<string, string> = { writer: 'writer', content: 'writer', verification: 'writer', research: 'research', keywords: 'research', topics: 'research', analytics: 'analytics', agent: 'analytics', copilot: 'analytics', media: 'media' }
const routeGroups = computed(() => {
  const q = routeSearch.value.trim().toLowerCase()
  const out = new Map<string, RouteView[]>()
  for (const v of routeViews.value) {
    if (routeFilter.value !== 'all' && v.status !== routeFilter.value) continue
    if (q && !`${taskName(v.route)} ${v.route.task_key} ${v.route.description ?? ''}`.toLowerCase().includes(q)) continue
    const g = GROUPS[v.route.task_key.split('.')[0] ?? ''] ?? 'other'
    out.set(g, [...(out.get(g) ?? []), v])
  }
  return ['writer', 'research', 'analytics', 'media', 'other'].filter((g) => out.has(g)).map((g) => ({ key: g, items: out.get(g) ?? [] }))
})
const readyProviderNames = computed(() => readyProviders.value.filter((p) => p.driver !== 'fake').map((p) => p.name))
const missingProviderNames = computed(() => list(providers).filter((p) => p.driver !== 'fake' && !p.has_api_key).map((p) => p.name))

// ---- providers grouped by state ----
const providerGroups = computed(() => {
  const all = list(providers)
  return [
    { key: 'active', items: all.filter((p) => p.is_enabled && p.has_api_key) },
    { key: 'off', items: all.filter((p) => !p.is_enabled && p.has_api_key) },
    { key: 'missing', items: all.filter((p) => !p.has_api_key) },
  ].filter((g) => g.items.length)
})
const runningOn = (p: AiProvider) => routeViews.value.filter((v) => v.status !== 'off' && v.status !== 'blocked' && modelById.value.get(v.chain[v.runIndex]?.id ?? '')?.provider.id === p.id).length
const settingsProvider = ref<AiProvider | null>(null)
const settingsDraft = ref({ timeout: '', retries: '' })
const settingsForm = useApiForm()
function openSettings(p: AiProvider) {
  settingsForm.reset()
  settingsDraft.value = { timeout: String(p.timeout_seconds), retries: String(p.max_retries) }
  settingsProvider.value = p
}
async function saveSettings() {
  const p = settingsProvider.value
  if (!p) return
  const ok = await settingsForm.submit(() => adminAiApi.updateProvider(p.id, { timeout_seconds: Number(settingsDraft.value.timeout), max_retries: Number(settingsDraft.value.retries) }))
  if (!ok) return
  settingsProvider.value = null
  toast.success(t('common.saved'))
  await providers.run()
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
        <dt class="text-sm font-medium text-ink-muted">{{ $t('aiAdmin.ui.sumRoutesRunnable') }}</dt>
        <dd class="mt-2 flex items-baseline gap-1.5"><span class="text-3xl font-semibold tracking-tight tabular-nums">{{ routeCounts.primary + routeCounts.fallback }}</span><span class="text-sm text-ink-subtle">/ {{ list(routes).length }}</span></dd>
        <p v-if="routeCounts.blocked" class="mt-1 text-xs text-danger-700">{{ $t('aiAdmin.ui.sumRoutesBlocked', { n: routeCounts.blocked }) }}<template v-if="routeCounts.fallback"> · {{ $t('aiAdmin.ui.sumRoutesFallback', { n: routeCounts.fallback }) }}</template></p>
        <p v-else-if="routeCounts.fallback" class="mt-1 text-xs text-warning-700">{{ $t('aiAdmin.ui.sumRoutesFallback', { n: routeCounts.fallback }) }}</p>
        <p v-else class="mt-1 text-xs text-success-700">{{ $t('aiAdmin.ui.sumRoutesAllPrimary') }}</p>
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
      <div v-if="tab === 'providers'">
        <LoadingState v-if="providers.loading.value && !providers.data.value" />
        <ErrorState v-else-if="providers.error.value" :error="providers.error.value" @retry="providers.run" />
        <div v-else data-testid="provider-list">
          <template v-for="g in providerGroups" :key="g.key">
            <p class="border-y border-line bg-surface-muted px-5 py-2 text-xs font-semibold text-ink-muted">{{ $t(`aiAdmin.ui.providerGroups.${g.key}`, { n: g.items.length }) }}</p>
            <ul class="divide-y divide-line">
              <li v-for="p in g.items" :key="p.id" class="grid grid-cols-1 gap-3 px-5 py-4 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1.2fr)_minmax(0,1fr)_auto] md:items-center md:gap-6" :data-provider="p.code">
                <div class="flex min-w-0 items-center gap-3">
                  <span class="grid size-9 shrink-0 place-items-center rounded-lg text-xs font-bold ring-1 ring-inset" :class="avatarClass(p)" aria-hidden="true">{{ initials(p.name) }}</span>
                  <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2"><span class="font-semibold text-ink">{{ p.name }}</span><AppBadge v-if="p.is_custom" tone="brand">{{ $t('aiAdmin.custom') }}</AppBadge></p>
                    <p class="truncate font-mono text-xs text-ink-subtle" :title="p.base_url ?? ''">{{ p.base_url || p.code }}</p>
                  </div>
                </div>
                <div>
                  <AppBadge v-if="g.key === 'active' && p.circuit.state === 'closed'" tone="success" dot>{{ $t('aiAdmin.ui.pActive') }}</AppBadge>
                  <AppBadge v-else-if="g.key === 'active'" :tone="p.circuit.state === 'open' ? 'danger' : 'warning'" dot>{{ $t(`aiAdmin.circuitStates.${p.circuit.state}`) }}</AppBadge>
                  <AppBadge v-else-if="g.key === 'off'" dot>{{ $t('aiAdmin.disabled') }}</AppBadge>
                  <AppBadge v-else dot>{{ $t('aiAdmin.ui.pMissing') }}</AppBadge>
                  <p class="mt-1 text-xs text-ink-muted">
                    <template v-if="g.key === 'active' && p.circuit.state !== 'closed'">{{ $t('aiAdmin.failures', { n: p.circuit.failures }) }} · {{ $t('aiAdmin.ui.pCircuitHint') }}</template>
                    <template v-else-if="g.key === 'active'">{{ $t('aiAdmin.ui.pRunning', { n: runningOn(p) }) }}</template>
                    <template v-else-if="p.driver === 'fake'">{{ $t('aiAdmin.ui.pFakeHint') }}</template>
                    <template v-else>{{ $t('aiAdmin.ui.pSkipped', { name: p.name }) }}</template>
                  </p>
                </div>
                <div class="text-sm" data-testid="key-source">
                  <p v-if="p.api_key_source === 'database'" class="text-ink"><span class="font-mono">•••• {{ p.api_key_last4 }}</span> <span class="text-xs text-ink-subtle">{{ $t('aiAdmin.ui.keyDb') }}</span></p>
                  <p v-else-if="p.api_key_source === 'env'" class="text-ink">{{ $t('aiAdmin.ui.keyEnv', { env: ENV[p.code] ?? 'API key' }) }}</p>
                  <p v-else-if="p.driver === 'fake'" class="text-ink-muted">{{ $t('aiAdmin.ui.keyNotNeeded') }}</p>
                  <p v-else class="text-ink-subtle">{{ $t('aiAdmin.noKey') }}</p>
                  <p class="mt-0.5 text-xs text-ink-muted">{{ $t('aiAdmin.ui.modelsCount', { n: p.models_count }) }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2 md:justify-end">
                  <template v-if="p.driver !== 'fake'">
                    <AppButton v-if="!p.has_api_key" size="sm" @click="openKey(p)">{{ $t('aiAdmin.ui.enterKey') }}</AppButton>
                    <AppButton v-else size="sm" variant="secondary" @click="openKey(p)">{{ $t('aiAdmin.ui.changeKey') }}</AppButton>
                  </template>
                  <AppButton v-if="p.has_api_key" size="sm" variant="secondary" :loading="connTesting === p.id" @click="testConnection(p)">{{ $t('aiAdmin.testConnection') }}</AppButton>
                  <AppButton v-if="g.key === 'off'" size="sm" @click="toggleProvider(p)">{{ $t('aiAdmin.enable') }}</AppButton>
                  <button v-if="p.circuit.state !== 'closed'" type="button" class="rounded-lg bg-warning-50 px-2.5 py-1.5 text-xs font-semibold text-warning-700 ring-1 ring-inset ring-warning-600/25 hover:bg-warning-100" @click="resetCircuit(p)">{{ $t('aiAdmin.ui.resetConnection') }}</button>
                  <button type="button" class="rounded-md p-1.5 text-ink-subtle ring-1 ring-inset ring-line hover:bg-slate-100 hover:text-ink" :aria-label="$t('aiAdmin.ui.settingsOf', { name: p.name })" :title="$t('aiAdmin.ui.settingsOf', { name: p.name })" @click="openSettings(p)"><AppIcon name="settings" :size="16" /></button>
                </div>
              </li>
            </ul>
          </template>
        </div>
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
      <div v-if="tab === 'routes'">
        <LoadingState v-if="routes.loading.value && !routes.data.value" />
        <ErrorState v-else-if="routes.error.value" :error="routes.error.value" @retry="routes.run" />
        <template v-else>
          <div v-if="routeCounts.fallback || routeCounts.blocked" class="mx-5 mb-4 flex flex-col gap-3 rounded-xl bg-warning-50 p-4 ring-1 ring-inset ring-warning-600/20 sm:flex-row sm:items-start" data-testid="route-readiness">
            <AppIcon name="alert" :size="20" class="mt-0.5 shrink-0 text-warning-600" />
            <div class="flex-1 text-sm text-warning-700">
              <p class="font-semibold">{{ readyProviderNames.length ? $t('aiAdmin.ui.rdOnly', { names: readyProviderNames.join(', ') }) : $t('aiAdmin.ui.rdNone') }}</p>
              <p class="mt-1">{{ $t('aiAdmin.ui.rdBody', { fb: routeCounts.fallback, bl: routeCounts.blocked }) + (missingProviderNames.length ? ' ' + $t('aiAdmin.ui.rdFix', { names: missingProviderNames.join(', ') }) : '') }}</p>
            </div>
            <AppButton size="sm" variant="secondary" class="shrink-0" @click="tab = 'providers'">{{ $t('aiAdmin.ui.rdGo') }}</AppButton>
          </div>

          <div class="flex flex-col gap-2 px-5 pb-4 lg:flex-row lg:items-center">
            <div class="flex flex-wrap gap-2" role="group" :aria-label="$t('aiAdmin.ui.status')">
              <button
                v-for="f in (['all', 'primary', 'fallback', 'blocked'] as const)" :key="f" type="button" :aria-pressed="routeFilter === f"
                class="rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset transition-colors"
                :class="routeFilter === f ? 'bg-ink text-white ring-ink' : { all: 'text-ink-muted ring-line-strong', primary: 'text-success-700 ring-success-600/30', fallback: 'text-warning-700 ring-warning-600/30', blocked: 'text-danger-700 ring-danger-600/30' }[f] + ' bg-surface hover:bg-surface-muted'"
                @click="routeFilter = f"
              >{{ $t(`aiAdmin.ui.rf.${f}`) }} · {{ routeCounts[f] }}</button>
            </div>
            <div class="lg:ml-auto lg:w-64"><SearchInput v-model="routeSearch" :placeholder="$t('aiAdmin.ui.searchTasks')" /></div>
          </div>

          <EmptyState v-if="!routeGroups.length" icon="search" :title="$t('aiAdmin.ui.noTaskMatch')" />
          <div v-else data-testid="route-list">
            <div class="hidden border-y border-line bg-surface-muted px-5 py-2 text-xs font-semibold text-ink-muted lg:grid lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1.6fr)_auto] lg:gap-6">
              <span>{{ $t('aiAdmin.taskKey') }}</span><span>{{ $t('aiAdmin.ui.runsOn') }}</span><span>{{ $t('aiAdmin.ui.tryOrder') }}</span><span class="w-12" />
            </div>
            <section v-for="g in routeGroups" :key="g.key">
              <h3 class="flex items-center gap-2 border-b border-line px-5 pb-2 pt-4 text-sm font-semibold text-ink">{{ $t(`aiAdmin.ui.groups.${g.key}`) }} <span class="text-xs font-normal text-ink-subtle">{{ $t('aiAdmin.ui.tasksCount', { n: g.items.length }) }}</span></h3>
              <ul class="divide-y divide-line">
                <li
                  v-for="v in g.items" :key="v.route.id"
                  class="grid grid-cols-1 gap-3 border-l-4 px-5 py-3 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1.6fr)_auto] lg:items-center lg:gap-6"
                  :class="{ primary: 'border-success-500', fallback: 'border-warning-500', blocked: 'border-danger-500 bg-danger-50/40', off: 'border-line bg-surface-muted/60' }[v.status]"
                  :data-task="v.route.task_key"
                >
                  <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 font-medium text-ink">
                      {{ taskName(v.route) }}
                      <AppBadge v-if="v.route.priority === 'interactive'" tone="brand">{{ $t('aiAdmin.priorities.interactive') }}</AppBadge>
                      <AppBadge v-if="!v.route.is_enabled">{{ $t('aiAdmin.disabled') }}</AppBadge>
                      <span v-if="v.route.allow_paid_retry" class="inline-flex items-center gap-1 text-xs font-normal text-warning-700" :title="$t('aiAdmin.allowPaidRetryHint')"><AppIcon name="alert" :size="12" />{{ $t('aiAdmin.ui.paidRetryOn') }}</span>
                    </p>
                    <p v-if="taskDesc(v.route)" class="mt-0.5 text-xs text-ink-muted">{{ taskDesc(v.route) }}</p>
                    <p class="mt-0.5 font-mono text-[11px] text-ink-subtle">{{ v.route.task_key }}</p>
                  </div>
                  <div>
                    <template v-if="v.status === 'blocked'">
                      <p class="flex items-center gap-1.5 font-medium text-danger-700"><span class="size-2 rounded-full bg-danger-500" />{{ $t('aiAdmin.ui.cannotRun') }}</p>
                      <p class="text-xs text-danger-700">{{ v.chain.length ? $t('aiAdmin.ui.cannotRunHint') : $t('aiAdmin.ui.noPrimary') }}</p>
                    </template>
                    <template v-else-if="v.status === 'off'">
                      <p class="text-ink-muted">{{ $t('aiAdmin.ui.routeOff') }}</p>
                    </template>
                    <template v-else>
                      <p class="flex items-center gap-1.5 font-medium text-ink"><span class="size-2 rounded-full bg-success-500" />{{ v.chain[v.runIndex]?.name }}</p>
                      <p v-if="v.status === 'primary'" class="text-xs text-success-700">✓ {{ $t('aiAdmin.ui.isPrimary') }}</p>
                      <p v-else class="text-xs text-warning-700">↪ {{ $t('aiAdmin.ui.isFallback', { n: v.runIndex + 1 }) }}</p>
                    </template>
                  </div>
                  <div>
                    <ol class="flex flex-wrap items-center gap-1 text-xs">
                      <li
                        v-for="(c, i) in v.chain" :key="c.id"
                        class="rounded-md px-1.5 py-0.5 ring-1 ring-inset"
                        :class="c.runs ? 'bg-success-50 font-medium text-success-700 ring-success-600/30' : c.reason ? 'bg-surface-muted text-ink-subtle line-through ring-line' : 'bg-surface text-ink-muted ring-line'"
                        :title="c.reason ? $t(`aiAdmin.ui.skip.${c.reason}`) : ''"
                      ><span class="mr-1 opacity-70">{{ i + 1 }}</span>{{ c.name }}</li>
                    </ol>
                    <p v-if="v.route.economy_model" class="mt-1 text-xs text-ink-subtle">{{ $t('aiAdmin.ui.economyModel', { m: modelById.get(v.route.economy_model.id)?.display_name ?? v.route.economy_model.model_key }) }}</p>
                  </div>
                  <div class="lg:text-right">
                    <AppButton size="sm" :variant="v.status === 'blocked' ? 'primary' : 'secondary'" @click="openRoute(v.route)">{{ $t('common.edit') }}</AppButton>
                  </div>
                </li>
              </ul>
            </section>
            <div class="flex flex-wrap items-center gap-x-5 gap-y-1 border-t border-line px-5 py-3 text-xs text-ink-muted">
              <span class="flex items-center gap-1.5"><span class="rounded-md bg-success-50 px-1.5 text-success-700 ring-1 ring-inset ring-success-600/30">1 …</span>{{ $t('aiAdmin.ui.legendRun') }}</span>
              <span class="flex items-center gap-1.5"><span class="rounded-md bg-surface-muted px-1.5 text-ink-subtle line-through ring-1 ring-inset ring-line">2 …</span>{{ $t('aiAdmin.ui.legendSkip') }}</span>
            </div>
          </div>
        </template>
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
    <AppModal :open="!!routeEdit" :title="$t('aiAdmin.ui.editRouteTitle', { name: routeEdit ? taskName(routeEdit) : '' })" width="lg" @close="routeEdit = null">
      <form id="route-form" class="space-y-5" novalidate @submit.prevent="saveRoute">
        <p v-if="routeForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ routeForm.formError.value }}</p>
        <p class="-mt-2 font-mono text-xs text-ink-subtle">{{ routeEdit?.task_key }}</p>
        <fieldset>
          <legend class="text-sm font-semibold text-ink">{{ $t('aiAdmin.ui.chainTitle') }}</legend>
          <p class="text-xs text-ink-muted">{{ $t('aiAdmin.ui.chainHint') }}</p>
          <ol class="mt-3 space-y-2" data-testid="route-chain">
            <li
              v-for="(id, i) in routeDraft.chain" :key="id" draggable="true"
              class="flex items-center gap-3 rounded-lg bg-surface px-3 py-2 ring-1 ring-inset"
              :class="draftEffective?.id === id ? 'ring-success-600/40' : 'ring-line'"
              @dragstart="dragFrom = i" @dragover.prevent @drop.prevent="dropOn(i)"
            >
              <span class="cursor-grab select-none text-ink-subtle" aria-hidden="true">⋮⋮</span>
              <span class="grid size-6 shrink-0 place-items-center rounded-full text-xs font-semibold" :class="i === 0 ? 'bg-brand-600 text-white' : 'bg-slate-100 text-ink-muted'">{{ i + 1 }}</span>
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium" :class="modelById.get(id) && skipReason(modelById.get(id)!, routeEdit?.task_key ?? '') ? 'text-ink-subtle' : 'text-ink'">
                  {{ modelById.get(id)?.display_name ?? id }}
                  <span v-if="i === 0" class="ml-1 rounded bg-brand-50 px-1.5 text-[11px] font-medium text-brand-700">{{ $t('aiAdmin.primary') }}</span>
                </p>
                <p class="text-xs text-ink-muted">{{ modelById.get(id)?.provider.name }}</p>
              </div>
              <span class="hidden text-xs sm:block" :class="draftEffective?.id === id ? 'text-success-700' : 'text-ink-subtle'">
                <template v-if="draftEffective?.id === id">✓ {{ $t('aiAdmin.ui.willRun') }}</template>
                <template v-else-if="modelById.get(id) && skipReason(modelById.get(id)!, routeEdit?.task_key ?? '')">{{ $t(`aiAdmin.ui.skip.${skipReason(modelById.get(id)!, routeEdit?.task_key ?? '')}`) }}</template>
              </span>
              <span class="flex gap-1">
                <button type="button" class="rounded p-1 text-ink-subtle ring-1 ring-inset ring-line hover:bg-slate-100 hover:text-ink disabled:opacity-30" :disabled="i === 0" :aria-label="$t('aiAdmin.ui.moveUp')" @click="moveInChain(i, -1)"><AppIcon name="chevronUp" :size="14" /></button>
                <button type="button" class="rounded p-1 text-ink-subtle ring-1 ring-inset ring-line hover:bg-slate-100 hover:text-ink disabled:opacity-30" :disabled="i === routeDraft.chain.length - 1" :aria-label="$t('aiAdmin.ui.moveDown')" @click="moveInChain(i, 1)"><AppIcon name="chevronDown" :size="14" /></button>
                <button type="button" class="rounded p-1 text-danger-600 ring-1 ring-inset ring-line hover:bg-danger-50" :aria-label="$t('aiAdmin.ui.removeFromChain')" @click="removeFromChain(i)"><AppIcon name="x" :size="14" /></button>
              </span>
            </li>
          </ol>
          <p v-if="routeForm.errors.primary_model_id" class="mt-1 text-xs text-danger-600">{{ routeForm.errors.primary_model_id }}</p>
          <div class="mt-2 flex gap-2">
            <div class="flex-1"><AppSelect v-model="routeAdd" :options="[{ value: '', label: $t('aiAdmin.ui.addToChain') }, ...addableModels]" /></div>
            <AppButton variant="secondary" :disabled="!routeAdd" @click="addToChain"><AppIcon name="plus" :size="16" />{{ $t('aiAdmin.ui.add') }}</AppButton>
          </div>
          <p v-if="draftEffective" class="mt-3 rounded-lg bg-success-50 px-3 py-2 text-sm text-success-700 ring-1 ring-inset ring-success-600/20">{{ $t('aiAdmin.ui.previewRun', { name: draftEffective.display_name }) }}</p>
          <p v-else class="mt-3 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-700 ring-1 ring-inset ring-danger-600/15">{{ $t('aiAdmin.ui.previewNone') }}</p>
        </fieldset>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <AppSelect v-model="routeDraft.economy" :label="$t('aiAdmin.ui.economyLabel')" :options="[{ value: '', label: $t('aiAdmin.none') }, ...modelOptions]" />
          <AppInput v-model="routeDraft.max_output_tokens" type="number" :label="$t('aiAdmin.ui.maxOutputLabel')" :error="routeForm.errors.max_output_tokens" />
          <AppSelect v-model="routeDraft.priority" :label="$t('aiAdmin.ui.priorityLabel')" :options="priorityOptions" />
        </div>
        <details class="rounded-lg ring-1 ring-line">
          <summary class="cursor-pointer px-3 py-2 text-sm font-medium text-ink-muted">{{ $t('aiAdmin.ui.advanced') }}</summary>
          <div class="space-y-3 border-t border-line px-3 py-3">
            <AppInput v-model="routeDraft.max_attempts" type="number" :label="$t('aiAdmin.maxAttempts')" :hint="$t('aiAdmin.ui.maxAttemptsHint')" :error="routeForm.errors.max_attempts" />
            <Toggle v-model="routeDraft.allow_paid_retry" :label="$t('aiAdmin.allowPaidRetry')" :description="$t('aiAdmin.allowPaidRetryHint')" />
          </div>
        </details>
        <div class="rounded-lg px-3 ring-1 ring-line"><Toggle v-model="routeDraft.is_enabled" :label="$t('aiAdmin.ui.routeEnabled')" /></div>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="routeEdit = null">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="route-form" :disabled="!routeDraft.chain.length" :loading="routeForm.submitting.value">{{ $t('common.save') }}</AppButton>
      </template>
    </AppModal>

    <!-- Provider settings -->
    <AppModal :open="!!settingsProvider" :title="$t('aiAdmin.ui.settingsOf', { name: settingsProvider?.name ?? '' })" width="sm" @close="settingsProvider = null">
      <form id="provider-settings-form" class="space-y-4" novalidate @submit.prevent="saveSettings">
        <p v-if="settingsForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ settingsForm.formError.value }}</p>
        <AppInput v-model="settingsDraft.timeout" type="number" :label="$t('aiAdmin.ui.timeoutLabel')" :hint="$t('aiAdmin.ui.timeoutHint')" :error="settingsForm.errors.timeout_seconds" />
        <AppInput v-model="settingsDraft.retries" type="number" :label="$t('aiAdmin.ui.retriesLabel')" :hint="$t('aiAdmin.ui.retriesHint')" :error="settingsForm.errors.max_retries" />
        <div v-if="settingsProvider" class="divide-y divide-line rounded-lg px-3 ring-1 ring-line">
          <div class="flex items-center justify-between gap-4 py-2.5">
            <div><p class="text-sm font-medium text-ink">{{ $t('aiAdmin.ui.enableProvider') }}</p><p class="text-xs text-ink-muted">{{ $t('aiAdmin.ui.enableProviderHint') }}</p></div>
            <button
              type="button" role="switch" :aria-checked="settingsProvider.is_enabled" :aria-label="$t('aiAdmin.ui.toggleProvider', { name: settingsProvider.name })"
              class="relative inline-flex h-6 w-11 shrink-0 rounded-full ring-1 ring-inset ring-slate-900/5 transition-colors"
              :class="settingsProvider.is_enabled ? 'bg-brand-600' : 'bg-slate-200'"
              @click="toggleProvider(settingsProvider).then(() => (settingsProvider = list(providers).find((x) => x.id === settingsProvider?.id) ?? null))"
            ><span class="absolute top-0.5 size-5 rounded-full bg-white shadow transition-all" :class="settingsProvider.is_enabled ? 'left-[22px]' : 'left-0.5'" /></button>
          </div>
          <p class="py-2.5 text-xs text-ink-muted">{{ $t('aiAdmin.ui.advancedHint') }}</p>
        </div>
      </form>
      <template #footer>
        <AppButton v-if="settingsProvider?.is_custom && !settingsProvider.models_count" variant="ghost" class="mr-auto !text-danger-600 hover:!bg-danger-50" @click="providerDeleting = settingsProvider; settingsProvider = null">{{ $t('aiAdmin.ui.deleteProvider') }}</AppButton>
        <AppButton variant="secondary" @click="settingsProvider = null">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="provider-settings-form" :loading="settingsForm.submitting.value">{{ $t('common.save') }}</AppButton>
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
