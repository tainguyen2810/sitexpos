<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  adminAiApi, formatPeakWindows, formatPerMtok, formatTokens, formatUsd, parsePeakWindows, usdToMicro,
  type AiModel, type AiPrice, type AiProvider, type Capability,
  type ConnectionTestResult, type ModelTestResult, type ReasoningEffort, type ResponseFormatMode,
} from '@/shared/api/ai'
import { ApiError } from '@/shared/api/client'
import { aiErrorMessage } from '@/shared/components/ai/aiErrors'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useToast } from '@/shared/composables/useToast'
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
import AppIcon from '@/shared/ui/AppIcon.vue'
import DropdownMenu from '@/shared/ui/DropdownMenu.vue'
import SearchInput from '@/shared/ui/SearchInput.vue'
import SwitchButton from '@/shared/ui/SwitchButton.vue'
import { MODEL_CAPS as CAPS, PROVIDER_ENV as ENV, providerAvatarClass, providerInitials, providerRank } from '../components/ai/providerUi'

/**
 * "Providers & models": the one place to manage every AI provider — API keys, connection tests,
 * models, prices and test calls. The "AI settings" page only shows providers that already have a key.
 */
const { t } = useI18n()
const toast = useToast()

const providers = useAsync(() => adminAiApi.providers())
const models = useAsync(() => adminAiApi.models())

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

// ---- list: providers with their models, keyed providers first ----
const search = ref('')
const expanded = ref<Record<string, boolean>>({})
const isOpen = (p: AiProvider) => expanded.value[p.id] ?? p.has_api_key
function toggleOpen(p: AiProvider) {
  expanded.value = { ...expanded.value, [p.id]: !isOpen(p) }
}
const rows = computed(() => {
  const q = search.value.trim().toLowerCase()
  const byProvider = new Map<string, AiModel[]>()
  for (const m of models.data.value ?? []) byProvider.set(m.provider.id, [...(byProvider.get(m.provider.id) ?? []), m])
  return [...(providers.data.value ?? [])]
    .sort((a, b) => providerRank(a) - providerRank(b) || a.name.localeCompare(b.name))
    .map((p) => {
      const all = byProvider.get(p.id) ?? []
      if (!q || `${p.name} ${p.code}`.toLowerCase().includes(q)) return { p, items: all, forced: false }
      const items = all.filter((m) => `${m.display_name} ${m.model_key}`.toLowerCase().includes(q))
      return items.length ? { p, items, forced: true } : null
    })
    .filter((r): r is { p: AiProvider; items: AiModel[]; forced: boolean } => r !== null)
})
const keyedCount = computed(() => (providers.data.value ?? []).filter((p) => p.has_api_key).length)

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

// Provider settings (timeout, retries, delete custom)
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
  await models.run()
}
const modelDeleting = ref<AiModel | null>(null)
async function deleteModel() {
  const m = modelDeleting.value
  if (!m) return
  await guarded(() => adminAiApi.deleteModel(m.id), () => Promise.all([models.run(), providers.run()]))
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
function openModel(p: AiProvider) {
  modelForm.reset()
  modelDraft.value = { provider_id: p.id, model_name: '', display_name: '', tier: 'standard' }
  modelOpen.value = true
}
async function createModel() {
  const ok = await modelForm.submit(() => adminAiApi.createModel({ ...modelDraft.value, capabilities: { structured_output: true } }))
  if (!ok) return
  modelOpen.value = false
  expanded.value = { ...expanded.value, [modelDraft.value.provider_id]: true }
  toast.success(t('common.saved'))
  await Promise.all([models.run(), providers.run()])
}
const tierBadge: Record<string, 'success' | 'brand' | 'warning'> = { economy: 'success', standard: 'brand', premium: 'warning' }
const providerOptions = computed(() => (providers.data.value ?? []).map((p) => ({ value: p.id, label: p.name })))
const tierOptions = computed(() => (['economy', 'standard', 'premium'] as const).map((v) => ({ value: v, label: t(`aiAdmin.tiers.${v}`) })))
</script>

<template>
  <div class="space-y-6">
    <PageHeader :title="$t('aiAdmin.ui.providersTitle')" :description="$t('aiAdmin.ui.providersSubtitle')" :crumbs="[{ label: 'AI' }, { label: $t('aiAdmin.ui.providersTitle') }]">
      <template #actions>
        <AppButton variant="secondary" @click="$router.push('/ai/settings')">{{ $t('aiAdmin.ui.goSettings') }}</AppButton>
        <AppButton @click="openCustom"><AppIcon name="plus" :size="16" />{{ $t('aiAdmin.addCustomProvider') }}</AppButton>
      </template>
    </PageHeader>

    <section class="rounded-xl bg-surface shadow-xs ring-1 ring-line">
      <div class="flex flex-col gap-3 border-b border-line px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-ink-muted">{{ $t('aiAdmin.ui.providersSummary', { keyed: keyedCount, total: providers.data.value?.length ?? 0 }) }}</p>
        <div class="sm:w-72"><SearchInput v-model="search" :placeholder="$t('aiAdmin.ui.searchProviders')" /></div>
      </div>

      <LoadingState v-if="(providers.loading.value && !providers.data.value) || (models.loading.value && !models.data.value)" />
      <ErrorState v-else-if="providers.error.value" :error="providers.error.value" @retry="providers.run" />
      <ErrorState v-else-if="models.error.value" :error="models.error.value" @retry="models.run" />
      <EmptyState v-else-if="!rows.length" icon="search" :title="$t('aiAdmin.ui.noMatch')" />
      <ul v-else class="divide-y divide-line" data-testid="provider-list">
        <li v-for="{ p, items, forced } in rows" :key="p.id" :data-provider="p.code">
          <!-- Provider row -->
          <div class="grid grid-cols-1 gap-3 px-5 py-4 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-center md:gap-6">
            <button type="button" class="flex min-w-0 items-center gap-3 text-left" :aria-expanded="forced || isOpen(p)" :aria-label="$t('aiAdmin.ui.expand', { name: p.name })" @click="toggleOpen(p)">
              <AppIcon name="chevronRight" :size="16" class="shrink-0 text-ink-subtle transition-transform" :class="forced || isOpen(p) ? 'rotate-90' : ''" />
              <span class="grid size-9 shrink-0 place-items-center rounded-lg text-xs font-bold ring-1 ring-inset" :class="providerAvatarClass(p)" aria-hidden="true">{{ providerInitials(p.name) }}</span>
              <span class="min-w-0">
                <span class="flex flex-wrap items-center gap-2"><span class="font-semibold text-ink">{{ p.name }}</span><AppBadge v-if="p.is_custom" tone="brand">{{ $t('aiAdmin.custom') }}</AppBadge></span>
                <span class="block truncate font-mono text-xs text-ink-subtle" :title="p.base_url ?? ''">{{ p.base_url || p.code }}</span>
              </span>
            </button>
            <div>
              <template v-if="p.has_api_key">
                <AppBadge v-if="!p.is_enabled" dot>{{ $t('aiAdmin.ui.pPaused') }}</AppBadge>
                <AppBadge v-else-if="p.circuit.state === 'closed'" tone="success" dot>{{ $t('aiAdmin.ui.pInUse') }}</AppBadge>
                <AppBadge v-else :tone="p.circuit.state === 'open' ? 'danger' : 'warning'" dot>{{ $t(`aiAdmin.circuitStates.${p.circuit.state}`) }}</AppBadge>
              </template>
              <AppBadge v-else-if="p.driver === 'fake'" dot>{{ $t('aiAdmin.ui.pTestOnly') }}</AppBadge>
              <AppBadge v-else tone="warning" dot>{{ $t('aiAdmin.ui.pNoKey') }}</AppBadge>
              <p class="mt-1 text-xs text-ink-muted">{{ $t('aiAdmin.ui.modelsCount', { n: items.length }) }}<template v-if="p.driver === 'fake'"> · {{ $t('aiAdmin.ui.pFakeHint') }}</template></p>
            </div>
            <div class="text-sm" data-testid="key-source">
              <p v-if="p.api_key_source === 'database'" class="text-ink"><span class="font-mono">•••• {{ p.api_key_last4 }}</span> <span class="text-xs text-ink-subtle">{{ $t('aiAdmin.ui.keyDb') }}</span></p>
              <p v-else-if="p.api_key_source === 'env'" class="text-ink">{{ $t('aiAdmin.ui.keyEnv', { env: ENV[p.code] ?? 'API key' }) }}</p>
              <p v-else-if="p.driver === 'fake'" class="text-ink-muted">{{ $t('aiAdmin.ui.keyNotNeeded') }}</p>
              <p v-else class="text-ink-subtle">{{ $t('aiAdmin.noKey') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 md:justify-end">
              <template v-if="p.driver !== 'fake'">
                <AppButton v-if="!p.has_api_key" size="sm" @click="openKey(p)">{{ $t('aiAdmin.ui.enterKey') }}</AppButton>
                <AppButton v-else size="sm" variant="secondary" @click="openKey(p)">{{ $t('aiAdmin.ui.changeKey') }}</AppButton>
              </template>
              <AppButton v-if="p.has_api_key" size="sm" variant="secondary" :loading="connTesting === p.id" @click="testConnection(p)">{{ $t('aiAdmin.testConnection') }}</AppButton>
              <AppButton v-if="p.has_api_key && !p.is_enabled" size="sm" @click="toggleProvider(p)">{{ $t('aiAdmin.enable') }}</AppButton>
              <button v-if="p.circuit.state !== 'closed'" type="button" class="rounded-lg bg-warning-50 px-2.5 py-1.5 text-xs font-semibold text-warning-700 ring-1 ring-inset ring-warning-600/25 hover:bg-warning-100" @click="resetCircuit(p)">{{ $t('aiAdmin.ui.resetConnection') }}</button>
              <button type="button" class="rounded-md p-1.5 text-ink-subtle ring-1 ring-inset ring-line hover:bg-slate-100 hover:text-ink" :aria-label="$t('aiAdmin.ui.settingsOf', { name: p.name })" :title="$t('aiAdmin.ui.settingsOf', { name: p.name })" @click="openSettings(p)"><AppIcon name="settings" :size="16" /></button>
            </div>
          </div>

          <!-- Models of this provider -->
          <div v-if="forced || isOpen(p)" class="border-t border-line bg-surface-muted/50 px-5 py-4">
            <p v-if="!p.has_api_key && p.driver !== 'fake'" class="mb-3 flex items-center gap-2 text-xs text-warning-700"><AppIcon name="alert" :size="14" />{{ $t('aiAdmin.ui.needKeyFirst', { name: p.name }) }}</p>
            <div v-if="items.length" class="overflow-x-auto rounded-lg bg-surface ring-1 ring-line">
              <table class="w-full min-w-[820px] table-fixed text-sm" data-testid="model-list">
                <colgroup><col class="w-[30%]" /><col class="w-[11%]" /><col class="w-[19%]" /><col class="w-[16%]" /><col class="w-[9%]" /><col /></colgroup>
                <thead class="border-b border-line text-left text-xs font-semibold text-ink-muted">
                  <tr>
                    <th scope="col" class="py-2.5 pl-4 pr-3">{{ $t('aiAdmin.ui.colModel') }}</th>
                    <th scope="col" class="px-3">{{ $t('aiAdmin.tier') }}</th>
                    <th scope="col" class="px-3">{{ $t('aiAdmin.capabilitiesTitle') }}</th>
                    <th scope="col" class="px-3 text-right">{{ $t('aiAdmin.priceInOut') }}</th>
                    <th scope="col" class="px-3">{{ $t('aiAdmin.ui.modelUsable') }}</th>
                    <th scope="col" class="pl-3 pr-4"><span class="sr-only">{{ $t('aiAdmin.ui.actions') }}</span></th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-line">
                  <tr v-for="m in items" :key="m.id" class="hover:bg-surface-muted/50">
                    <td class="py-3 pl-4 pr-3"><p class="font-medium" :class="m.is_enabled ? 'text-ink' : 'text-ink-subtle'">{{ m.display_name }}</p><p class="font-mono text-xs text-ink-subtle">{{ m.model_key }}</p></td>
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
                    <td class="px-3"><SwitchButton size="sm" :checked="m.is_enabled" :label="$t('aiAdmin.ui.toggleModel', { name: m.display_name })" @toggle="toggleModel(m)" /></td>
                    <td class="pl-3 pr-4">
                      <div class="flex items-center justify-end gap-1">
                        <AppButton v-if="!m.current_price" size="sm" @click="openPrices(m)">{{ $t('aiAdmin.ui.addPriceCta') }}</AppButton>
                        <AppButton v-else size="sm" variant="ghost" class="!text-brand-700" @click="openPrices(m)">{{ $t('aiAdmin.prices') }}</AppButton>
                        <AppButton size="sm" variant="ghost" class="!text-brand-700" :disabled="!p.has_api_key" :title="p.has_api_key ? '' : $t('aiAdmin.ui.needKeyToTest')" @click="openTest(m)">{{ $t('aiAdmin.testModel') }}</AppButton>
                        <DropdownMenu :label="$t('aiAdmin.ui.moreActions')">
                          <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-ink hover:bg-slate-50" @click="openEditModel(m)">{{ $t('common.edit') }}</button>
                          <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-danger-600 hover:bg-danger-50" @click="modelDeleting = m">{{ $t('common.delete') }}</button>
                        </DropdownMenu>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-else class="text-sm text-ink-muted">{{ $t('aiAdmin.ui.noModelsFor') }}</p>
            <button type="button" class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:underline" @click="openModel(p)"><AppIcon name="plus" :size="14" />{{ $t('aiAdmin.ui.addModelFor', { name: p.name }) }}</button>
          </div>
        </li>
      </ul>
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

    <!-- Provider settings -->
    <AppModal :open="!!settingsProvider" :title="$t('aiAdmin.ui.settingsOf', { name: settingsProvider?.name ?? '' })" width="sm" @close="settingsProvider = null">
      <form id="provider-settings-form" class="space-y-4" novalidate @submit.prevent="saveSettings">
        <p v-if="settingsForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ settingsForm.formError.value }}</p>
        <AppInput v-model="settingsDraft.timeout" type="number" :label="$t('aiAdmin.ui.timeoutLabel')" :hint="$t('aiAdmin.ui.timeoutHint')" :error="settingsForm.errors.timeout_seconds" />
        <AppInput v-model="settingsDraft.retries" type="number" :label="$t('aiAdmin.ui.retriesLabel')" :hint="$t('aiAdmin.ui.retriesHint')" :error="settingsForm.errors.max_retries" />
        <p class="rounded-lg px-3 py-2.5 text-xs text-ink-muted ring-1 ring-line">{{ $t('aiAdmin.ui.advancedHint') }}</p>
      </form>
      <template #footer>
        <AppButton v-if="settingsProvider?.is_custom && !settingsProvider.models_count" variant="ghost" class="mr-auto !text-danger-600 hover:!bg-danger-50" @click="providerDeleting = settingsProvider; settingsProvider = null">{{ $t('aiAdmin.ui.deleteProvider') }}</AppButton>
        <AppButton variant="secondary" @click="settingsProvider = null">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="provider-settings-form" :loading="settingsForm.submitting.value">{{ $t('common.save') }}</AppButton>
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
  </div>
</template>
