<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  adminAiApi, formatPerMtok, formatUsd, usdToMicro,
  type AiBudget, type AiModel, type AiPrice, type AiProvider, type AiRoute, type BudgetAction, type Capability,
} from '@/shared/api/ai'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'

type Tab = 'providers' | 'models' | 'routes' | 'budgets'

const { t } = useI18n()
const toast = useToast()
const session = useSessionStore()
const canManage = computed(() => session.user?.platform_permissions.includes('platform.ai.manage') ?? false)
const tabs = computed<Tab[]>(() => (canManage.value ? ['providers', 'models', 'routes', 'budgets'] : ['budgets']))
const tab = ref<Tab>(canManage.value ? 'providers' : 'budgets')

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

const ENV: Record<string, string> = { openai: 'OPENAI_API_KEY', gemini: 'GEMINI_API_KEY', anthropic: 'ANTHROPIC_API_KEY', fake: 'SITEX_AI_FAKE_PROVIDER=true' }
const CAPS: Capability[] = ['structured_output', 'web_search', 'image_generation', 'temperature', 'vision']
const circuitTone = (s: string) => (s === 'closed' ? 'success' : s === 'open' ? 'danger' : 'warning')

// ---- providers ----
const toggleProvider = (p: AiProvider) => guarded(() => adminAiApi.updateProvider(p.id, { is_enabled: !p.is_enabled }), providers.run)
const resetCircuit = (p: AiProvider) => guarded(() => adminAiApi.resetCircuit(p.id), providers.run)
const saveProviderNumber = (p: AiProvider, field: 'timeout_seconds' | 'max_retries', value: string) =>
  guarded(() => adminAiApi.updateProvider(p.id, { [field]: Number(value) }), providers.run)

// ---- models & prices ----
const toggleModel = (m: AiModel) => guarded(() => adminAiApi.updateModel(m.id, { is_enabled: !m.is_enabled }), models.run)
const priceModel = ref<AiModel | null>(null)
const priceList = ref<AiPrice[]>([])
const priceForm = useApiForm()
const priceDraft = ref({ effective_from: '', input: '', cached: '', cache_write: '', output: '', search: '', image: '', source_url: '' })
async function openPrices(m: AiModel) {
  priceModel.value = m
  priceForm.reset()
  priceDraft.value = { effective_from: new Date().toISOString().slice(0, 16), input: '', cached: '', cache_write: '', output: '', search: '', image: '', source_url: '' }
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
  const ok = await priceForm.submit(() => adminAiApi.createPrice(m.id, {
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
const budgetForm = useApiForm()
const budgetDraft = ref({ scope: 'global' as 'global' | 'workspace', workspace_id: '', period: 'month' as 'day' | 'month', limit: '', alert: '80', action: 'pause_all' as BudgetAction })
function openBudget(b?: AiBudget) {
  budgetForm.reset()
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
const priorityOptions = computed(() => (['interactive', 'batch'] as const).map((v) => ({ value: v, label: t(`aiAdmin.priorities.${v}`) })))
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold">{{ $t('aiAdmin.settingsTitle') }}</h1>
      <p class="mt-1 text-sm text-ink-muted">{{ $t('aiAdmin.settingsSubtitle') }}</p>
    </div>

    <div class="flex flex-wrap gap-2 border-b border-line" role="tablist">
      <button
        v-for="k in tabs" :key="k" type="button" role="tab" :aria-selected="tab === k"
        class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
        :class="tab === k ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:text-ink'"
        @click="tab = k"
      >{{ $t(`aiAdmin.tabs.${k}`) }}</button>
    </div>

    <!-- Providers -->
    <AppCard v-if="tab === 'providers'" :padded="false">
      <LoadingState v-if="providers.loading.value" class="p-5" />
      <ErrorState v-else-if="providers.error.value" :error="providers.error.value" class="p-5" @retry="providers.run" />
      <ul v-else class="divide-y divide-line" data-testid="provider-list">
        <li v-for="p in providers.data.value ?? []" :key="p.id" class="flex flex-wrap items-center gap-4 px-5 py-4">
          <div class="min-w-48 flex-1">
            <p class="font-medium">{{ p.name }} <span class="font-mono text-xs text-ink-muted">{{ p.code }}</span></p>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-xs">
              <AppBadge :tone="p.has_api_key ? 'success' : 'warning'">{{ p.has_api_key ? $t('aiAdmin.hasKey') : $t('aiAdmin.noKey') }}</AppBadge>
              <span v-if="!p.has_api_key" class="text-ink-muted">{{ $t('aiAdmin.keyHint', { env: ENV[p.code] ?? 'API key' }) }}</span>
            </p>
          </div>
          <div class="text-xs">
            <p class="text-ink-muted">{{ $t('aiAdmin.circuit') }}</p>
            <AppBadge :tone="circuitTone(p.circuit.state)">{{ $t(`aiAdmin.circuitStates.${p.circuit.state}`) }}</AppBadge>
            <span v-if="p.circuit.failures" class="ml-1 text-ink-muted">{{ $t('aiAdmin.failures', { n: p.circuit.failures }) }}</span>
          </div>
          <label class="text-xs text-ink-muted">{{ $t('aiAdmin.timeout') }}
            <input type="number" min="5" max="180" :value="p.timeout_seconds" class="mt-1 block w-20 rounded border border-line px-2 py-1 text-sm text-ink" @change="saveProviderNumber(p, 'timeout_seconds', ($event.target as HTMLInputElement).value)" />
          </label>
          <label class="text-xs text-ink-muted">{{ $t('aiAdmin.maxRetries') }}
            <input type="number" min="0" max="3" :value="p.max_retries" class="mt-1 block w-16 rounded border border-line px-2 py-1 text-sm text-ink" @change="saveProviderNumber(p, 'max_retries', ($event.target as HTMLInputElement).value)" />
          </label>
          <div class="flex gap-2">
            <AppButton v-if="p.circuit.state !== 'closed'" size="sm" variant="secondary" @click="resetCircuit(p)">{{ $t('aiAdmin.resetCircuit') }}</AppButton>
            <AppButton size="sm" :variant="p.is_enabled ? 'secondary' : 'primary'" @click="toggleProvider(p)">{{ p.is_enabled ? $t('aiAdmin.disable') : $t('aiAdmin.enable') }}</AppButton>
          </div>
        </li>
      </ul>
    </AppCard>

    <!-- Models -->
    <AppCard v-if="tab === 'models'" :padded="false">
      <template #actions><AppButton size="sm" @click="openModel">{{ $t('aiAdmin.addModel') }}</AppButton></template>
      <LoadingState v-if="models.loading.value" class="p-5" />
      <ErrorState v-else-if="models.error.value" :error="models.error.value" class="p-5" @retry="models.run" />
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[820px] text-sm" data-testid="model-list">
          <thead class="text-left text-xs text-ink-muted"><tr><th class="px-5 py-2">Model</th><th class="px-3 py-2">{{ $t('aiAdmin.tier') }}</th><th class="px-3 py-2"></th><th class="px-3 py-2">{{ $t('aiAdmin.priceInOut') }}</th><th class="px-5 py-2"></th></tr></thead>
          <tbody class="divide-y divide-line">
            <tr v-for="m in models.data.value ?? []" :key="m.id">
              <td class="px-5 py-3"><p class="font-medium">{{ m.display_name }}</p><p class="font-mono text-xs text-ink-muted">{{ m.model_key }}</p></td>
              <td class="px-3 py-3">{{ $t(`aiAdmin.tiers.${m.tier}`) }}</td>
              <td class="px-3 py-3"><div class="flex flex-wrap gap-1"><AppBadge v-for="c in CAPS.filter((c) => m.capabilities[c])" :key="c">{{ $t(`aiAdmin.capabilities.${c}`) }}</AppBadge></div></td>
              <td class="px-3 py-3">
                <span v-if="m.current_price">{{ formatPerMtok(m.current_price.input_per_mtok) }} / {{ formatPerMtok(m.current_price.output_per_mtok) }}</span>
                <span v-else class="text-warning-600">{{ $t('aiAdmin.noPrice') }}</span>
                <AppBadge v-if="m.scheduled_prices" tone="brand" class="ml-1">+{{ m.scheduled_prices }}</AppBadge>
              </td>
              <td class="px-5 py-3">
                <div class="flex justify-end gap-2">
                  <AppButton size="sm" variant="ghost" @click="openPrices(m)">{{ $t('aiAdmin.prices') }}</AppButton>
                  <AppButton size="sm" :variant="m.is_enabled ? 'secondary' : 'primary'" @click="toggleModel(m)">{{ m.is_enabled ? $t('aiAdmin.disable') : $t('aiAdmin.enable') }}</AppButton>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppCard>

    <!-- Routes -->
    <AppCard v-if="tab === 'routes'" :padded="false">
      <LoadingState v-if="routes.loading.value" class="p-5" />
      <ErrorState v-else-if="routes.error.value" :error="routes.error.value" class="p-5" @retry="routes.run" />
      <ul v-else class="divide-y divide-line" data-testid="route-list">
        <li v-for="r in routes.data.value ?? []" :key="r.id" class="flex flex-wrap items-center gap-4 px-5 py-4">
          <div class="min-w-48 flex-1">
            <p class="font-mono text-sm font-medium">{{ r.task_key }} <AppBadge v-if="!r.is_enabled">{{ $t('aiAdmin.disabled') }}</AppBadge></p>
            <p class="text-xs text-ink-muted">{{ r.description }} · {{ r.queue }} · {{ $t(`aiAdmin.priorities.${r.priority}`) }}</p>
          </div>
          <div class="text-xs">
            <p><span class="text-ink-muted">{{ $t('aiAdmin.primary') }}:</span> {{ r.primary_model?.model_key ?? '—' }}</p>
            <p v-if="r.economy_model"><span class="text-ink-muted">{{ $t('aiAdmin.economy') }}:</span> {{ r.economy_model.model_key }}</p>
            <p><span class="text-ink-muted">{{ $t('aiAdmin.fallbacks') }}:</span> {{ r.fallback_models.map((m) => m.model_key).join(' → ') || '—' }}</p>
          </div>
          <AppBadge :tone="r.allow_paid_retry ? 'warning' : 'success'">{{ $t('aiAdmin.allowPaidRetry') }}: {{ r.allow_paid_retry ? $t('common.yes') : $t('common.no') }}</AppBadge>
          <AppButton size="sm" variant="secondary" @click="openRoute(r)">{{ $t('common.edit') }}</AppButton>
        </li>
      </ul>
    </AppCard>

    <!-- Budgets -->
    <AppCard v-if="tab === 'budgets'" :padded="false">
      <template v-if="canManage" #actions><AppButton size="sm" @click="openBudget()">{{ $t('aiAdmin.addBudget') }}</AppButton></template>
      <LoadingState v-if="budgets.loading.value" class="p-5" />
      <ErrorState v-else-if="budgets.error.value" :error="budgets.error.value" class="p-5" @retry="budgets.run" />
      <p v-else-if="!(budgets.data.value ?? []).length" class="p-5 text-sm text-ink-muted">{{ $t('aiAdmin.noData') }}</p>
      <ul v-else class="divide-y divide-line" data-testid="budget-list">
        <li v-for="b in budgets.data.value ?? []" :key="b.id" class="flex flex-wrap items-center gap-4 px-5 py-4">
          <div class="min-w-48 flex-1">
            <p class="font-medium">{{ b.scope === 'global' ? $t('aiAdmin.scopes.global') : (b.workspace?.name ?? b.workspace?.id) }} · {{ $t(`aiAdmin.periods.${b.period}`) }}</p>
            <p class="text-xs text-ink-muted">{{ $t(`aiAdmin.actions.${b.action_on_exceed}`) }} · {{ $t('aiAdmin.alertAt') }} {{ b.alert_threshold_pct }}%</p>
          </div>
          <div class="w-64">
            <div class="h-2 rounded-full bg-surface-muted"><div class="h-2 rounded-full" :class="b.usage.used_pct >= 100 ? 'bg-danger-600' : b.usage.used_pct >= b.alert_threshold_pct ? 'bg-warning-600' : 'bg-brand-500'" :style="{ width: Math.min(100, b.usage.used_pct) + '%' }" /></div>
            <p class="mt-1 text-xs">{{ $t('aiAdmin.usedOf', { spent: formatUsd(b.usage.spent_micro_usd), limit: formatUsd(b.limit_micro_usd), pct: b.usage.used_pct }) }}<span v-if="b.usage.reserved_micro_usd" class="text-ink-muted"> · {{ $t('aiAdmin.reserved', { amount: formatUsd(b.usage.reserved_micro_usd) }) }}</span></p>
          </div>
          <div v-if="canManage" class="flex gap-2">
            <AppButton size="sm" variant="secondary" @click="openBudget(b)">{{ $t('common.edit') }}</AppButton>
            <AppButton size="sm" variant="ghost" @click="deleting = b">{{ $t('common.delete') }}</AppButton>
          </div>
        </li>
      </ul>
    </AppCard>

    <!-- Price versions -->
    <AppModal :open="!!priceModel" :title="$t('aiAdmin.pricesOf', { name: priceModel?.display_name ?? '' })" @close="priceModel = null">
      <p class="text-xs text-ink-muted">{{ $t('aiAdmin.priceLocked') }}</p>
      <ul class="mt-3 divide-y divide-line rounded-lg border border-line text-sm">
        <li v-for="p in priceList" :key="p.id" class="flex flex-wrap items-center gap-3 px-3 py-2">
          <span class="font-medium">{{ $t('aiAdmin.priceVersion', { v: p.version }) }}</span>
          <AppBadge :tone="p.state === 'effective' ? 'success' : 'brand'">{{ $t(`aiAdmin.priceStates.${p.state}`) }}</AppBadge>
          <span class="text-xs text-ink-muted">{{ formatDateTime(p.effective_from) }}</span>
          <span class="flex-1 text-right text-xs">{{ formatPerMtok(p.input_per_mtok) }} / {{ formatPerMtok(p.output_per_mtok) }}<template v-if="p.per_1k_searches"> · {{ formatPerMtok(p.per_1k_searches) }}/1K</template></span>
          <a v-if="p.source_url" :href="p.source_url" target="_blank" rel="noopener noreferrer" class="text-xs text-brand-600 underline">src</a>
          <AppButton v-if="p.state === 'scheduled'" size="sm" variant="ghost" @click="deletePrice(p)">{{ $t('common.delete') }}</AppButton>
        </li>
      </ul>
      <form id="price-form" class="mt-4 grid gap-3 sm:grid-cols-2" novalidate @submit.prevent="addPrice">
        <p v-if="priceForm.formError.value" class="sm:col-span-2 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ priceForm.formError.value }}</p>
        <AppInput v-model="priceDraft.effective_from" type="datetime-local" :label="$t('aiAdmin.effectiveFrom')" :error="priceForm.errors.effective_from" />
        <AppInput v-model="priceDraft.source_url" :label="$t('aiAdmin.sourceUrl')" :error="priceForm.errors.source_url" />
        <AppInput v-model="priceDraft.input" inputmode="decimal" :label="$t('aiAdmin.inputPrice')" :error="priceForm.errors.input_per_mtok" />
        <AppInput v-model="priceDraft.output" inputmode="decimal" :label="$t('aiAdmin.outputPrice')" :error="priceForm.errors.output_per_mtok" />
        <AppInput v-model="priceDraft.cached" inputmode="decimal" :label="$t('aiAdmin.cachedPrice')" />
        <AppInput v-model="priceDraft.cache_write" inputmode="decimal" :label="$t('aiAdmin.cacheWritePrice')" />
        <AppInput v-model="priceDraft.search" inputmode="decimal" :label="$t('aiAdmin.searchPrice')" />
        <AppInput v-model="priceDraft.image" inputmode="decimal" :label="$t('aiAdmin.imagePrice')" />
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
          <div class="mt-1 grid max-h-40 gap-1 overflow-y-auto rounded-lg border border-line p-2 sm:grid-cols-2">
            <label v-for="o in modelOptions.filter((o) => o.value !== routeDraft.primary)" :key="o.value" class="flex items-center gap-2 text-xs">
              <input type="checkbox" :checked="routeDraft.fallbacks.includes(o.value)" @change="toggleFallback(o.value)" />
              {{ o.label }}<span v-if="routeDraft.fallbacks.includes(o.value)" class="text-ink-muted">#{{ routeDraft.fallbacks.indexOf(o.value) + 1 }}</span>
            </label>
          </div>
        </fieldset>
        <div class="grid gap-3 sm:grid-cols-3">
          <AppInput v-model="routeDraft.max_output_tokens" type="number" :label="$t('aiAdmin.maxOutput')" :error="routeForm.errors.max_output_tokens" />
          <AppInput v-model="routeDraft.max_attempts" type="number" :label="$t('aiAdmin.maxAttempts')" :error="routeForm.errors.max_attempts" />
          <AppSelect v-model="routeDraft.priority" :label="$t('aiAdmin.priority')" :options="priorityOptions" />
        </div>
        <label class="flex items-start gap-2 text-sm"><input v-model="routeDraft.allow_paid_retry" type="checkbox" class="mt-1" /> <span>{{ $t('aiAdmin.allowPaidRetry') }}<br /><span class="text-xs text-ink-muted">{{ $t('aiAdmin.allowPaidRetryHint') }}</span></span></label>
        <label class="flex items-center gap-2 text-sm"><input v-model="routeDraft.is_enabled" type="checkbox" /> {{ $t('aiAdmin.enabled') }}</label>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="routeEdit = null">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="route-form" :loading="routeForm.submitting.value">{{ $t('common.save') }}</AppButton>
      </template>
    </AppModal>

    <!-- Budget -->
    <AppModal :open="budgetOpen" :title="$t('aiAdmin.addBudget')" width="sm" @close="budgetOpen = false">
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
