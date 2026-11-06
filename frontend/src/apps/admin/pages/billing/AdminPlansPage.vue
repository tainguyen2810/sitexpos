<script setup lang="ts">
import { computed, reactive, ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { FEATURES, formatCredits, formatMoney, RESOURCES, type CreditPackage, type PlanVersion } from '@/shared/api/billing'
import { billingAdminApi, type CreditPolicy, type VersionInput } from '@/shared/api/billingAdmin'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Tabs from '@/shared/ui/Tabs.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import AdminBillingNav from './AdminBillingNav.vue'
import { toInt, useAdminBilling } from './useAdminBilling'

const { t } = useI18n()
const { toast, toastError } = useAdminBilling()
const tab = ref('plans')
const plans = useAsync(() => billingAdminApi.plans())
const packages = useAsync(() => billingAdminApi.packages())
const policies = useAsync(() => billingAdminApi.policies())
const busy = ref(false)

async function guard(fn: () => Promise<unknown>, reload: () => Promise<unknown>, ok = 'billAdmin.common.saved') {
  busy.value = true
  try {
    await fn()
    toast.success(t(ok))
    await reload()
    return true
  } catch (e) {
    toastError(e)
    return false
  } finally {
    busy.value = false
  }
}

// ---- plans
const planForm = reactive({ open: false, code: '', name: '', description: '', is_free: false, display_order: '0' })
async function createPlan() {
  const ok = await guard(() => billingAdminApi.createPlan({ code: planForm.code.trim(), name: planForm.name.trim(), description: planForm.description || null, is_free: planForm.is_free, display_order: toInt(planForm.display_order) ?? 0 }), plans.run)
  if (ok) planForm.open = false
}
function setStatus(id: string, status: string) {
  return guard(() => billingAdminApi.updatePlan(id, { status }), plans.run)
}
const toDelete = shallowRef<{ id: string; name: string } | null>(null)
async function deletePlan() {
  const p = toDelete.value
  toDelete.value = null
  if (p) await guard(() => billingAdminApi.deletePlan(p.id), plans.run)
}

// ---- versions
const ver = reactive({
  open: false, planId: '', versionId: '' as string, currency: 'VND', monthly: '', yearly: '', trial_days: '0', trial_credits_mc: '0', included_credits_mc: '0',
  support_level: '', notes: '', limits: {} as Record<string, string>, features: {} as Record<string, boolean>,
})
function openVersion(planId: string, base: PlanVersion | null, editDraft = false) {
  Object.assign(ver, {
    open: true, planId, versionId: editDraft && base ? base.id : '', currency: base?.currency ?? 'VND',
    monthly: base?.monthly_price_minor?.toString() ?? '', yearly: base?.yearly_price_minor?.toString() ?? '',
    trial_days: String(base?.trial_days ?? 0), trial_credits_mc: String(base?.trial_credits_mc ?? 0), included_credits_mc: String(base?.included_credits_mc ?? 0),
    support_level: base?.support_level ?? '', notes: base?.notes ?? '',
    limits: Object.fromEntries(RESOURCES.map((r) => [r, base?.entitlements[r]?.limit?.toString() ?? ''])),
    features: Object.fromEntries(FEATURES.map((f) => [f, base?.features[f] ?? false])),
  })
}
async function saveVersion() {
  const body: VersionInput = {
    currency: ver.currency.toUpperCase(), monthly_price_minor: toInt(ver.monthly), yearly_price_minor: toInt(ver.yearly),
    trial_days: toInt(ver.trial_days) ?? 0, trial_credits_mc: toInt(ver.trial_credits_mc) ?? 0, included_credits_mc: toInt(ver.included_credits_mc) ?? 0,
    support_level: ver.support_level || null, notes: ver.notes || null, feature_flags: { ...ver.features },
    entitlements: Object.fromEntries(RESOURCES.map((r) => [r, toInt(ver.limits[r])])),
  }
  const ok = await guard(() => (ver.versionId ? billingAdminApi.updateVersion(ver.versionId, body) : billingAdminApi.createVersion(ver.planId, body)), plans.run)
  if (ok) ver.open = false
}
const toPublish = shallowRef<PlanVersion | null>(null)
async function publish() {
  const v = toPublish.value
  toPublish.value = null
  if (v) await guard(() => billingAdminApi.publishVersion(v.id), plans.run)
}

// ---- packages
const pkg = reactive({ open: false, id: '', code: '', name: '', credits_mc: '', bonus_credits_mc: '0', price_minor: '', currency: 'VND', validity_days: '', status: 'active' })
function openPackage(p: CreditPackage | null) {
  Object.assign(pkg, {
    open: true, id: p?.id ?? '', code: p?.code ?? '', name: p?.name ?? '', credits_mc: p ? String(p.credits_mc) : '', bonus_credits_mc: String(p?.bonus_credits_mc ?? 0),
    price_minor: p ? String(p.price_minor) : '', currency: p?.currency ?? 'VND', validity_days: p?.validity_days?.toString() ?? '', status: p?.status ?? 'active',
  })
}
async function savePackage() {
  const body: Partial<CreditPackage> = {
    name: pkg.name, credits_mc: toInt(pkg.credits_mc) ?? 0, bonus_credits_mc: toInt(pkg.bonus_credits_mc) ?? 0, price_minor: toInt(pkg.price_minor) ?? 0,
    currency: pkg.currency.toUpperCase(), validity_days: toInt(pkg.validity_days), status: pkg.status,
  }
  const ok = await guard(() => (pkg.id ? billingAdminApi.updatePackage(pkg.id, body) : billingAdminApi.createPackage({ ...body, code: pkg.code.trim() })), packages.run)
  if (ok) pkg.open = false
}

// ---- policies
const pol = reactive({ open: false, micro_usd_per_credit: '1000', markup_bps: '0', min_charge_mc: '0', effective_from: '', notes: '' })
const example = computed(() => {
  const per = toInt(pol.micro_usd_per_credit) ?? 0
  const markup = toInt(pol.markup_bps) ?? 0
  if (per <= 0) return '—'
  const mc = Math.ceil((10_000 * (10_000 + markup) * 1000) / (10_000 * per)) // $0.01 = 10 000 µUSD
  return formatCredits(Math.max(mc, toInt(pol.min_charge_mc) ?? 0))
})
async function savePolicy() {
  const ok = await guard(
    () => billingAdminApi.createPolicy({ micro_usd_per_credit: toInt(pol.micro_usd_per_credit) ?? 0, markup_bps: toInt(pol.markup_bps) ?? 0, min_charge_mc: toInt(pol.min_charge_mc) ?? 0, effective_from: pol.effective_from || null, notes: pol.notes || null }),
    policies.run,
  )
  if (ok) pol.open = false
}
function policyActive(p: CreditPolicy, all: CreditPolicy[]) {
  const now = Date.now()
  const live = all.filter((x) => x.effective_from && new Date(x.effective_from).getTime() <= now).sort((a, b) => b.version - a.version)[0]
  return live?.id === p.id
}
function lim(n: number | undefined) {
  return n === undefined ? '—' : n < 0 ? '∞' : String(n)
}
</script>

<template>
  <div>
    <PageHeader :title="t('billAdmin.title')" :description="t('billAdmin.plans.subtitle')" />
    <AdminBillingNav active="plans" />
    <Tabs v-model="tab" class="mb-4" :tabs="[{ key: 'plans', label: t('billAdmin.plans.tabPlans') }, { key: 'packages', label: t('billAdmin.plans.tabPackages') }, { key: 'policies', label: t('billAdmin.plans.tabPolicies') }]" />

    <template v-if="tab === 'plans'">
      <div class="mb-4 flex justify-end"><AppButton data-testid="new-plan" @click="planForm.open = true">{{ t('billAdmin.plans.newPlan') }}</AppButton></div>
      <LoadingState v-if="plans.loading.value && !plans.data.value" />
      <ErrorState v-else-if="plans.error.value" :error="plans.error.value" @retry="plans.run" />
      <EmptyState v-else-if="!plans.data.value?.length" icon="card" :title="t('billAdmin.common.empty')" />
      <div v-else class="space-y-4">
        <AppCard v-for="p in plans.data.value" :key="p.id" :title="`${p.name} (${p.code})`" :subtitle="p.description ?? undefined">
          <template #actions>
            <AppBadge v-if="p.is_demo" tone="warning">{{ t('billAdmin.common.demo') }}</AppBadge>
            <AppBadge :tone="p.status === 'active' ? 'success' : 'neutral'">{{ t(`billAdmin.plans.statuses.${p.status}`) }}</AppBadge>
            <span class="text-xs text-ink-muted">{{ t('billAdmin.plans.live', { n: p.live_subscriptions }) }}</span>
          </template>
          <div class="mb-3 flex flex-wrap gap-2">
            <AppButton v-if="p.status !== 'active'" size="sm" :disabled="busy" @click="setStatus(p.id, 'active')">{{ t('billAdmin.plans.statuses.active') }}</AppButton>
            <AppButton v-if="p.status === 'active'" size="sm" variant="secondary" :disabled="busy" @click="setStatus(p.id, 'hidden')">{{ t('billAdmin.plans.statuses.hidden') }}</AppButton>
            <AppButton v-if="p.status !== 'archived'" size="sm" variant="ghost" :disabled="busy" @click="setStatus(p.id, 'archived')">{{ t('billAdmin.plans.statuses.archived') }}</AppButton>
            <AppButton size="sm" variant="secondary" @click="openVersion(p.id, p.versions[0] ?? null)">{{ t('billAdmin.plans.newVersion') }}</AppButton>
            <AppButton v-if="!p.live_subscriptions" size="sm" variant="danger" @click="toDelete = { id: p.id, name: p.name }">{{ t('billAdmin.plans.delete') }}</AppButton>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="text-left text-xs uppercase text-ink-muted">
                <tr><th class="py-1 pr-3">v</th><th class="py-1 pr-3">{{ t('billAdmin.plans.status') }}</th><th class="py-1 pr-3 text-right">{{ t('billAdmin.plans.monthly') }}</th><th class="py-1 pr-3 text-right">{{ t('billAdmin.plans.yearly') }}</th><th class="py-1 pr-3 text-right">{{ t('billAdmin.plans.included') }}</th><th class="py-1 pr-3">{{ t('bill.resources.websites') }}</th><th class="py-1 pr-3">{{ t('bill.resources.articles') }}</th><th /></tr>
              </thead>
              <tbody class="divide-y divide-line">
                <tr v-for="v in p.versions" :key="v.id">
                  <td class="py-2 pr-3">{{ v.version }}</td>
                  <td class="py-2 pr-3"><AppBadge :tone="v.status === 'published' ? 'success' : v.status === 'draft' ? 'warning' : 'neutral'">{{ t(`billAdmin.plans.versionStatus.${v.status}`) }}</AppBadge></td>
                  <td class="py-2 pr-3 text-right tabular-nums">{{ formatMoney(v.monthly_price_minor, v.currency) }}</td>
                  <td class="py-2 pr-3 text-right tabular-nums">{{ formatMoney(v.yearly_price_minor, v.currency) }}</td>
                  <td class="py-2 pr-3 text-right tabular-nums">{{ formatCredits(v.included_credits_mc) }}</td>
                  <td class="py-2 pr-3">{{ lim(v.entitlements.websites?.limit) }}</td>
                  <td class="py-2 pr-3">{{ lim(v.entitlements.articles?.limit) }}</td>
                  <td class="py-2 text-right">
                    <template v-if="v.status === 'draft'">
                      <AppButton size="sm" variant="ghost" @click="openVersion(p.id, v, true)">{{ t('billAdmin.plans.editVersion') }}</AppButton>
                      <AppButton size="sm" :data-testid="`publish-${v.id}`" @click="toPublish = v">{{ t('billAdmin.plans.publish') }}</AppButton>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </AppCard>
      </div>
    </template>

    <AppCard v-else-if="tab === 'packages'" :padded="false">
      <template #actions><AppButton size="sm" @click="openPackage(null)">{{ t('billAdmin.plans.packages.new') }}</AppButton></template>
      <LoadingState v-if="packages.loading.value && !packages.data.value" />
      <ErrorState v-else-if="packages.error.value" :error="packages.error.value" @retry="packages.run" />
      <EmptyState v-else-if="!packages.data.value?.length" icon="coins" :title="t('billAdmin.common.empty')" />
      <table v-else class="min-w-full text-sm">
        <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted"><tr><th class="px-4 py-2">{{ t('billAdmin.plans.packages.code') }}</th><th class="px-4 py-2">{{ t('billAdmin.plans.packages.name') }}</th><th class="px-4 py-2 text-right">Credits</th><th class="px-4 py-2 text-right">{{ t('billAdmin.plans.packages.price') }}</th><th class="px-4 py-2">{{ t('billAdmin.plans.packages.status') }}</th><th /></tr></thead>
        <tbody class="divide-y divide-line">
          <tr v-for="p in packages.data.value" :key="p.id">
            <td class="px-4 py-2 font-mono">{{ p.code }}</td>
            <td class="px-4 py-2">{{ p.name }} <AppBadge v-if="p.is_demo" tone="warning">{{ t('billAdmin.common.demo') }}</AppBadge></td>
            <td class="px-4 py-2 text-right tabular-nums">{{ formatCredits(p.credits_mc) }}<span v-if="p.bonus_credits_mc" class="text-success-600"> +{{ formatCredits(p.bonus_credits_mc) }}</span></td>
            <td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(p.price_minor, p.currency) }}</td>
            <td class="px-4 py-2">{{ t(`billAdmin.plans.packages.statuses.${p.status ?? 'active'}`) }}</td>
            <td class="px-4 py-2 text-right"><AppButton size="sm" variant="ghost" @click="openPackage(p)">{{ t('common.edit') }}</AppButton></td>
          </tr>
        </tbody>
      </table>
    </AppCard>

    <AppCard v-else :title="t('billAdmin.plans.tabPolicies')" :subtitle="t('billAdmin.plans.policies.subtitle')" :padded="false">
      <template #actions><AppButton size="sm" @click="pol.open = true">{{ t('billAdmin.plans.policies.new') }}</AppButton></template>
      <LoadingState v-if="policies.loading.value && !policies.data.value" />
      <ErrorState v-else-if="policies.error.value" :error="policies.error.value" @retry="policies.run" />
      <p v-else-if="!policies.data.value?.length" class="px-4 py-6 text-sm text-warning-600">{{ t('billAdmin.plans.policies.none') }}</p>
      <table v-else class="min-w-full text-sm">
        <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted"><tr><th class="px-4 py-2">{{ t('billAdmin.plans.policies.version') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.plans.policies.perCredit') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.plans.policies.markup') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.plans.policies.min') }}</th><th class="px-4 py-2">{{ t('billAdmin.plans.policies.effective') }}</th></tr></thead>
        <tbody class="divide-y divide-line">
          <tr v-for="p in policies.data.value" :key="p.id">
            <td class="px-4 py-2">{{ p.version }} <AppBadge v-if="policyActive(p, policies.data.value!)" tone="success">✓</AppBadge></td>
            <td class="px-4 py-2 text-right tabular-nums">{{ p.micro_usd_per_credit }}</td>
            <td class="px-4 py-2 text-right tabular-nums">{{ p.markup_bps }}</td>
            <td class="px-4 py-2 text-right tabular-nums">{{ p.min_charge_mc }}</td>
            <td class="px-4 py-2">{{ formatDateTime(p.effective_from) }}</td>
          </tr>
        </tbody>
      </table>
    </AppCard>

    <AppModal :open="planForm.open" :title="t('billAdmin.plans.newPlan')" width="md" @close="planForm.open = false">
      <form id="plan-form" class="space-y-3" @submit.prevent="createPlan">
        <AppInput v-model="planForm.code" :label="t('billAdmin.plans.code')" required placeholder="pro" />
        <AppInput v-model="planForm.name" :label="t('billAdmin.plans.name')" required />
        <AppInput v-model="planForm.description" :label="t('billAdmin.plans.description')" />
        <AppInput v-model="planForm.display_order" type="number" :label="t('billAdmin.plans.order')" />
        <Toggle v-model="planForm.is_free" :label="t('billAdmin.plans.isFree')" />
      </form>
      <template #footer><AppButton variant="secondary" @click="planForm.open = false">{{ t('common.cancel') }}</AppButton><AppButton type="submit" form="plan-form" :loading="busy">{{ t('common.save') }}</AppButton></template>
    </AppModal>

    <AppModal :open="ver.open" :title="ver.versionId ? t('billAdmin.plans.editVersion') : t('billAdmin.plans.newVersion')" width="xl" @close="ver.open = false">
      <form id="version-form" class="grid gap-4 md:grid-cols-2" @submit.prevent="saveVersion">
        <div class="space-y-3">
          <AppInput v-model="ver.currency" :label="t('billAdmin.plans.currency')" required />
          <AppInput v-model="ver.monthly" type="number" :label="t('billAdmin.plans.monthly')" />
          <AppInput v-model="ver.yearly" type="number" :label="t('billAdmin.plans.yearly')" />
          <AppInput v-model="ver.trial_days" type="number" :label="t('billAdmin.plans.trialDays')" />
          <AppInput v-model="ver.trial_credits_mc" type="number" :label="t('billAdmin.plans.trialCredits')" :hint="t('billAdmin.plans.mcHint')" />
          <AppInput v-model="ver.included_credits_mc" type="number" :label="t('billAdmin.plans.included')" :hint="t('billAdmin.plans.mcHint')" />
          <AppSelect v-model="ver.support_level" :label="t('billAdmin.plans.support')" :options="[{ value: '', label: '—' }, ...['community', 'email', 'priority', 'dedicated'].map((s) => ({ value: s, label: t(`bill.support.${s}`) }))]" />
          <AppInput v-model="ver.notes" :label="t('billAdmin.plans.notes')" />
        </div>
        <div class="space-y-3">
          <p class="text-sm font-medium">{{ t('billAdmin.plans.limits') }}</p>
          <div class="grid grid-cols-2 gap-2">
            <AppInput v-for="r in RESOURCES" :key="r" v-model="ver.limits[r]" type="number" :label="t(`bill.resources.${r}`)" />
          </div>
          <p class="text-sm font-medium">{{ t('billAdmin.plans.features') }}</p>
          <Toggle v-for="f in FEATURES" :key="f" v-model="ver.features[f]" :label="t(`bill.features.${f}`)" />
        </div>
      </form>
      <template #footer><AppButton variant="secondary" @click="ver.open = false">{{ t('common.cancel') }}</AppButton><AppButton type="submit" form="version-form" :loading="busy">{{ t('common.save') }}</AppButton></template>
    </AppModal>

    <AppModal :open="pkg.open" :title="t('billAdmin.plans.packages.new')" width="md" @close="pkg.open = false">
      <form id="pkg-form" class="space-y-3" @submit.prevent="savePackage">
        <AppInput v-if="!pkg.id" v-model="pkg.code" :label="t('billAdmin.plans.packages.code')" required />
        <AppInput v-model="pkg.name" :label="t('billAdmin.plans.packages.name')" required />
        <AppInput v-model="pkg.credits_mc" type="number" :label="t('billAdmin.plans.packages.credits')" :hint="t('billAdmin.plans.mcHint')" required />
        <AppInput v-model="pkg.bonus_credits_mc" type="number" :label="t('billAdmin.plans.packages.bonus')" />
        <AppInput v-model="pkg.price_minor" type="number" :label="t('billAdmin.plans.packages.price')" required />
        <AppInput v-model="pkg.currency" :label="t('billAdmin.plans.currency')" required />
        <AppInput v-model="pkg.validity_days" type="number" :label="t('billAdmin.plans.packages.validity')" />
        <AppSelect v-model="pkg.status" :label="t('billAdmin.plans.packages.status')" :options="['active', 'hidden', 'archived'].map((s) => ({ value: s, label: t(`billAdmin.plans.packages.statuses.${s}`) }))" />
      </form>
      <template #footer><AppButton variant="secondary" @click="pkg.open = false">{{ t('common.cancel') }}</AppButton><AppButton type="submit" form="pkg-form" :loading="busy">{{ t('common.save') }}</AppButton></template>
    </AppModal>

    <AppModal :open="pol.open" :title="t('billAdmin.plans.policies.new')" width="md" @close="pol.open = false">
      <form id="pol-form" class="space-y-3" @submit.prevent="savePolicy">
        <AppInput v-model="pol.micro_usd_per_credit" type="number" :label="t('billAdmin.plans.policies.perCredit')" required />
        <AppInput v-model="pol.markup_bps" type="number" :label="t('billAdmin.plans.policies.markup')" required />
        <AppInput v-model="pol.min_charge_mc" type="number" :label="t('billAdmin.plans.policies.min')" />
        <AppInput v-model="pol.effective_from" type="datetime-local" :label="t('billAdmin.plans.policies.effective')" />
        <AppInput v-model="pol.notes" :label="t('billAdmin.plans.notes')" />
        <p class="text-sm text-ink-muted">{{ t('billAdmin.plans.policies.example', { mc: example }) }}</p>
      </form>
      <template #footer><AppButton variant="secondary" @click="pol.open = false">{{ t('common.cancel') }}</AppButton><AppButton type="submit" form="pol-form" :loading="busy">{{ t('common.save') }}</AppButton></template>
    </AppModal>

    <ConfirmDialog :open="!!toPublish" :title="t('billAdmin.plans.publish')" :message="t('billAdmin.plans.publishConfirm', { v: toPublish?.version ?? '' })" @close="toPublish = null" @confirm="publish" />
    <ConfirmDialog :open="!!toDelete" :title="t('billAdmin.plans.delete')" :message="t('billAdmin.plans.deleteConfirm', { name: toDelete?.name ?? '' })" danger @close="toDelete = null" @confirm="deletePlan" />
  </div>
</template>
