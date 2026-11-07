<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminAiApi, formatUsd, usdToMicro, type AiBudget, type AiModel, type AiProvider, type AiRoute, type BudgetAction } from '@/shared/api/ai'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
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
import SearchInput from '@/shared/ui/SearchInput.vue'
import SwitchButton from '@/shared/ui/SwitchButton.vue'
import { providerAvatarClass, providerInitials, providerRank } from '../components/ai/providerUi'

/**
 * "AI settings": which keyed providers are in use right now, which model each task runs on, and budgets.
 * Keys, models and prices live on the separate "Providers & models" page (AiProvidersPage).
 */
type Tab = 'routes' | 'budgets'

const { t, te } = useI18n()
const toast = useToast()
const session = useSessionStore()
const canManage = computed(() => session.user?.platform_permissions.includes('platform.ai.manage') ?? false)
const tabs = computed<Tab[]>(() => (canManage.value ? ['routes', 'budgets'] : ['budgets']))
const tab = ref<string>(canManage.value ? 'routes' : 'budgets')

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

// ---- overview (summary cards + tab counts) ----
const list = <T,>(a: { data: { value: T[] | null } }) => a.data.value ?? []
const enabledProviders = computed(() => list(providers).filter((p) => p.is_enabled))
const readyProviders = computed(() => enabledProviders.value.filter((p) => p.has_api_key))
const enabledModels = computed(() => list(models).filter((m) => m.is_enabled))
const unpricedModels = computed(() => enabledModels.value.filter((m) => !m.current_price))
const mainBudget = computed(() => {
  const all = list(budgets)
  return all.find((b) => b.scope === 'global' && b.period === 'month') ?? all.find((b) => b.scope === 'global') ?? null
})
const tabItems = computed(() => {
  const counts: Record<Tab, number | undefined> = { routes: routes.data.value?.length, budgets: budgets.data.value?.length }
  return tabs.value.map((k) => ({ key: k, label: t(`aiAdmin.tabs.${k}`), count: counts[k] }))
})

// ---- providers in use: only the ones that have an API key; switch them on/off as needed ----
const keyedProviders = computed(() => list(providers).filter((p) => p.has_api_key)
  .sort((a, b) => providerRank(a) - providerRank(b) || a.name.localeCompare(b.name)))
const keyedIds = computed(() => new Set(keyedProviders.value.map((p) => p.id)))
const switching = ref<string | null>(null)
async function toggleProvider(p: AiProvider) {
  switching.value = p.id
  await guarded(() => adminAiApi.updateProvider(p.id, { is_enabled: !p.is_enabled }), providers.run)
  switching.value = null
}
const resetCircuit = (p: AiProvider) => guarded(() => adminAiApi.resetCircuit(p.id), providers.run)

// ---- routes ----
const routeEdit = ref<AiRoute | null>(null)
const routeForm = useApiForm()
const routeDraft = ref({ chain: [] as string[], economy: '', max_output_tokens: '', max_attempts: '', allow_paid_retry: false, priority: 'batch', is_enabled: true })
// Pickers list only models of providers that have an API key (plus whatever the route already uses).
const pickable = (m: AiModel) => keyedIds.value.has(m.provider.id)
const modelOptions = computed(() => list(models).filter((m) => pickable(m) || m.id === routeDraft.value.economy)
  .map((m) => ({ value: m.id, label: `${m.display_name} · ${m.provider.name}${m.is_enabled ? '' : ' (off)'}` })))
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
const addableModels = computed(() => list(models).filter((m) => pickable(m) && !routeDraft.value.chain.includes(m.id))
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

const runningOn = (p: AiProvider) => routeViews.value.filter((v) => v.status !== 'off' && v.status !== 'blocked' && modelById.value.get(v.chain[v.runIndex]?.id ?? '')?.provider.id === p.id).length

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
    <PageHeader :title="$t('aiAdmin.settingsTitle')" :description="$t('aiAdmin.ui.subtitle')" :crumbs="[{ label: 'AI' }, { label: $t('aiAdmin.settingsTitle') }]">
      <template v-if="canManage" #actions>
        <AppButton variant="secondary" @click="$router.push('/ai/providers')"><AppIcon name="settings" :size="16" />{{ $t('aiAdmin.ui.manageProviders') }}</AppButton>
      </template>
    </PageHeader>

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

    <!-- Providers in use (keyed only) -->
    <section v-if="canManage" class="rounded-xl bg-surface shadow-xs ring-1 ring-line" data-testid="providers-in-use">
      <div class="flex flex-col gap-1 border-b border-line px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="text-base font-semibold text-ink">{{ $t('aiAdmin.ui.inUseTitle') }}</h2>
          <p class="mt-0.5 text-sm text-ink-muted">{{ $t('aiAdmin.ui.inUseHint') }}</p>
        </div>
      </div>
      <LoadingState v-if="providers.loading.value && !providers.data.value" />
      <ErrorState v-else-if="providers.error.value" :error="providers.error.value" @retry="providers.run" />
      <EmptyState v-else-if="!keyedProviders.length" icon="sparkles" :title="$t('aiAdmin.ui.noKeyedTitle')" :description="$t('aiAdmin.ui.noKeyedDesc')">
        <AppButton @click="$router.push('/ai/providers')">{{ $t('aiAdmin.ui.enterKey') }}</AppButton>
      </EmptyState>
      <ul v-else class="divide-y divide-line" data-testid="provider-list">
        <li v-for="p in keyedProviders" :key="p.id" class="flex flex-wrap items-center gap-x-6 gap-y-3 px-5 py-4" :data-provider="p.code">
          <div class="flex min-w-0 flex-1 items-center gap-3">
            <span class="grid size-9 shrink-0 place-items-center rounded-lg text-xs font-bold ring-1 ring-inset" :class="providerAvatarClass(p)" aria-hidden="true">{{ providerInitials(p.name) }}</span>
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-2">
                <span class="font-semibold text-ink">{{ p.name }}</span>
                <AppBadge v-if="!p.is_enabled" dot>{{ $t('aiAdmin.ui.pPaused') }}</AppBadge>
                <AppBadge v-else-if="p.circuit.state === 'closed'" tone="success" dot>{{ $t('aiAdmin.ui.pInUse') }}</AppBadge>
                <AppBadge v-else :tone="p.circuit.state === 'open' ? 'danger' : 'warning'" dot>{{ $t(`aiAdmin.circuitStates.${p.circuit.state}`) }}</AppBadge>
              </p>
              <p class="mt-0.5 text-xs text-ink-muted">
                <template v-if="!p.is_enabled">{{ $t('aiAdmin.ui.pSkipped', { name: p.name }) }}</template>
                <template v-else-if="p.circuit.state !== 'closed'">{{ $t('aiAdmin.failures', { n: p.circuit.failures }) }} · {{ $t('aiAdmin.ui.pCircuitHint') }}</template>
                <template v-else>{{ $t('aiAdmin.ui.pRunning', { n: runningOn(p) }) }} · {{ $t('aiAdmin.ui.modelsCount', { n: p.models_count }) }}</template>
              </p>
            </div>
          </div>
          <button v-if="p.circuit.state !== 'closed'" type="button" class="rounded-lg bg-warning-50 px-2.5 py-1.5 text-xs font-semibold text-warning-700 ring-1 ring-inset ring-warning-600/25 hover:bg-warning-100" @click="resetCircuit(p)">{{ $t('aiAdmin.ui.resetConnection') }}</button>
          <div class="flex items-center gap-3">
            <span class="text-sm" :class="p.is_enabled ? 'font-medium text-ink' : 'text-ink-muted'">{{ p.is_enabled ? $t('aiAdmin.ui.pInUse') : $t('aiAdmin.ui.pPaused') }}</span>
            <SwitchButton :checked="p.is_enabled" :loading="switching === p.id" :label="$t('aiAdmin.ui.toggleProvider', { name: p.name })" @toggle="toggleProvider(p)" />
          </div>
        </li>
      </ul>
      <p v-if="missingProviderNames.length && keyedProviders.length" class="m-5 mt-0 rounded-lg border border-dashed border-line-strong px-4 py-3 text-sm text-ink-muted">
        {{ $t('aiAdmin.ui.hiddenNoKey', { names: missingProviderNames.join(', ') }) }}
        <RouterLink to="/ai/providers" class="font-medium text-brand-700 hover:underline">{{ $t('aiAdmin.ui.goEnterKey') }} →</RouterLink>
      </p>
    </section>

    <section class="rounded-xl bg-surface shadow-xs ring-1 ring-line">
      <div class="px-5"><Tabs v-model="tab" :tabs="tabItems" /></div>

      <!-- Tab intro + primary action -->
      <div class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex gap-3 rounded-lg bg-surface-muted px-4 py-3 text-sm text-ink-muted ring-1 ring-line lg:max-w-3xl">
          <AppIcon name="info" :size="18" class="mt-0.5 shrink-0 text-brand-600" />
          <p>{{ $t(`aiAdmin.ui.intro.${tab}`) }}</p>
        </div>
        <div class="shrink-0">
          <AppButton v-if="tab === 'budgets' && canManage" @click="openBudget()"><AppIcon name="plus" :size="16" />{{ $t('aiAdmin.addBudget') }}</AppButton>
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
            <AppButton size="sm" variant="secondary" class="shrink-0" @click="$router.push('/ai/providers')">{{ $t('aiAdmin.ui.rdGo') }}</AppButton>
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

    <!-- Route -->
    <AppModal :open="!!routeEdit" :title="$t('aiAdmin.ui.editRouteTitle', { name: routeEdit ? taskName(routeEdit) : '' })" width="lg" @close="routeEdit = null">
      <form id="route-form" class="space-y-5" novalidate @submit.prevent="saveRoute">
        <p v-if="routeForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ routeForm.formError.value }}</p>
        <p class="-mt-2 font-mono text-xs text-ink-subtle">{{ routeEdit?.task_key }}</p>
        <fieldset>
          <legend class="text-sm font-semibold text-ink">{{ $t('aiAdmin.ui.chainTitle') }}</legend>
          <p class="text-xs text-ink-muted">{{ $t('aiAdmin.ui.chainHint') }} {{ $t('aiAdmin.ui.keyedOnlyHint') }}</p>
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

    <ConfirmDialog :open="!!deleting" :title="$t('common.delete')" :message="$t('aiAdmin.deleteBudget')" danger @close="deleting = null" @confirm="deleteBudget" />
  </div>
</template>
