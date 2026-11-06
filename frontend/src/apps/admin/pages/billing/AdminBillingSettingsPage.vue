<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { billingAdminApi, type BillingSettings, type ProviderConfig } from '@/shared/api/billingAdmin'
import { useAsync } from '@/shared/composables/useAsync'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import AdminBillingNav from './AdminBillingNav.vue'
import { toInt, useAdminBilling } from './useAdminBilling'

const { t } = useI18n()
const { toast, toastError } = useAdminBilling()
const form = reactive({
  enforcement: false, default_plan_code: '', currency: 'VND', trial_enabled: false, grace_days: '3', grace_allows_ai: false, proration_enabled: false,
  order_expiry_minutes: '1440', renewal_notice_days: '7, 3, 1', low_credit_threshold_mc: '50000', overpayment_policy: 'manual_review',
  usd_to_currency_rate: '', seller_name: '', seller_tax_code: '', seller_address: '',
})
const settings = useAsync(async () => {
  const r = await billingAdminApi.settings()
  fill(r.settings)
  return r
})
const providers = useAsync(() => billingAdminApi.providers())
const saving = ref(false)

function fill(s: BillingSettings) {
  Object.assign(form, {
    enforcement: s.enforcement === 'enforce', default_plan_code: s.default_plan_code ?? '', currency: s.currency, trial_enabled: !!s.trial_enabled,
    grace_days: String(s.grace_days), grace_allows_ai: !!s.grace_allows_ai, proration_enabled: !!s.proration_enabled, order_expiry_minutes: String(s.order_expiry_minutes),
    renewal_notice_days: (s.renewal_notice_days ?? []).join(', '), low_credit_threshold_mc: String(s.low_credit_threshold_mc), overpayment_policy: s.overpayment_policy,
    usd_to_currency_rate: s.usd_to_currency_rate?.toString() ?? '', seller_name: String(s.seller_name ?? ''), seller_tax_code: String(s.seller_tax_code ?? ''), seller_address: String(s.seller_address ?? ''),
  })
}
async function save() {
  saving.value = true
  try {
    const r = await billingAdminApi.updateSettings({
      enforcement: form.enforcement ? 'enforce' : 'off', default_plan_code: form.default_plan_code.trim() || null, currency: form.currency.toUpperCase(),
      trial_enabled: form.trial_enabled, grace_days: toInt(form.grace_days) ?? 0, grace_allows_ai: form.grace_allows_ai, proration_enabled: form.proration_enabled,
      order_expiry_minutes: toInt(form.order_expiry_minutes) ?? 1440, low_credit_threshold_mc: toInt(form.low_credit_threshold_mc) ?? 0,
      renewal_notice_days: form.renewal_notice_days.split(',').map((x) => toInt(x)).filter((x): x is number => x !== null && x > 0),
      overpayment_policy: form.overpayment_policy as BillingSettings['overpayment_policy'], usd_to_currency_rate: toInt(form.usd_to_currency_rate),
      seller_name: form.seller_name || null, seller_tax_code: form.seller_tax_code || null, seller_address: form.seller_address || null,
    })
    fill(r.settings)
    toast.success(t('billAdmin.common.saved'))
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}

const prov = reactive({ open: false, isNew: false, code: '', adapter: 'manual_bank', name: '', is_enabled: false, bank_name: '', bank_bin: '', account_no: '', account_name: '', secret: '', secretSet: false })
function openProvider(p: ProviderConfig | null) {
  const s = (p?.settings ?? {}) as Record<string, string | undefined>
  Object.assign(prov, {
    open: true, isNew: !p, code: p?.code ?? '', adapter: p?.adapter ?? 'manual_bank', name: p?.name ?? '', is_enabled: p?.is_enabled ?? false,
    bank_name: s.bank_name ?? '', bank_bin: s.bank_bin ?? '', account_no: s.account_no ?? '', account_name: s.account_name ?? '', secret: '', secretSet: p?.credentials_set.includes('webhook_secret') ?? false,
  })
}
async function saveProvider() {
  saving.value = true
  try {
    await billingAdminApi.upsertProvider(prov.code.trim(), {
      adapter: prov.adapter, name: prov.name, is_enabled: prov.is_enabled,
      settings: { bank_name: prov.bank_name || null, bank_bin: prov.bank_bin || null, account_no: prov.account_no || null, account_name: prov.account_name || null },
      // A blank secret keeps the stored one; it is never read back from the API.
      ...(prov.adapter === 'signed_webhook' && prov.secret ? { credentials: { webhook_secret: prov.secret } } : {}),
    })
    prov.secret = ''
    prov.open = false
    toast.success(t('billAdmin.common.saved'))
    await providers.run()
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('billAdmin.title')" :description="t('billAdmin.settings.subtitle')" />
    <AdminBillingNav active="settings" />
    <LoadingState v-if="settings.loading.value && !settings.data.value" />
    <ErrorState v-else-if="settings.error.value" :error="settings.error.value" @retry="settings.run" />
    <div v-else-if="settings.data.value" class="space-y-6">
      <p v-if="!settings.data.value.credit_policy_configured" class="rounded-lg bg-warning-50 px-4 py-2 text-sm text-warning-600">{{ t('billAdmin.settings.policyMissing') }}</p>
      <AppCard :title="t('billAdmin.settings.general')">
        <form class="grid gap-6 md:grid-cols-2" @submit.prevent="save">
          <div class="space-y-1">
            <Toggle v-model="form.enforcement" :label="t('billAdmin.settings.enforcement')" :description="t('billAdmin.settings.enforcementHint')" data-testid="enforcement" />
            <Toggle v-model="form.trial_enabled" :label="t('billAdmin.settings.trialEnabled')" />
            <Toggle v-model="form.grace_allows_ai" :label="t('billAdmin.settings.graceAllowsAi')" />
            <Toggle v-model="form.proration_enabled" :label="t('billAdmin.settings.proration')" />
            <AppSelect v-model="form.overpayment_policy" :label="t('billAdmin.settings.overpayment')" :options="['manual_review', 'accept_and_credit_difference'].map((x) => ({ value: x, label: t(`billAdmin.settings.overpaymentPolicies.${x}`) }))" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <AppInput v-model="form.default_plan_code" :label="t('billAdmin.settings.defaultPlan')" class="col-span-2" />
            <AppInput v-model="form.currency" :label="t('billAdmin.settings.currency')" />
            <AppInput v-model="form.grace_days" type="number" :label="t('billAdmin.settings.graceDays')" />
            <AppInput v-model="form.order_expiry_minutes" type="number" :label="t('billAdmin.settings.orderExpiry')" />
            <AppInput v-model="form.low_credit_threshold_mc" type="number" :label="t('billAdmin.settings.lowCredit')" />
            <AppInput v-model="form.renewal_notice_days" :label="t('billAdmin.settings.reminders')" class="col-span-2" />
            <AppInput v-model="form.usd_to_currency_rate" type="number" :label="t('billAdmin.settings.rate')" class="col-span-2" />
          </div>
          <div class="md:col-span-2">
            <p class="mb-2 text-sm font-medium">{{ t('billAdmin.settings.seller') }}</p>
            <div class="grid gap-3 md:grid-cols-3">
              <AppInput v-model="form.seller_name" :label="t('billAdmin.settings.sellerName')" />
              <AppInput v-model="form.seller_tax_code" :label="t('billAdmin.settings.sellerTax')" />
              <AppInput v-model="form.seller_address" :label="t('billAdmin.settings.sellerAddress')" />
            </div>
          </div>
          <div class="md:col-span-2 flex justify-end"><AppButton type="submit" :loading="saving" data-testid="save-settings">{{ t('common.save') }}</AppButton></div>
        </form>
      </AppCard>

      <AppCard :title="t('billAdmin.settings.providers')" :subtitle="t('billAdmin.settings.noScraping')">
        <template #actions><AppButton size="sm" @click="openProvider(null)">{{ t('billAdmin.settings.newProvider') }}</AppButton></template>
        <LoadingState v-if="providers.loading.value && !providers.data.value" />
        <ErrorState v-else-if="providers.error.value" :error="providers.error.value" @retry="providers.run" />
        <ul v-else class="divide-y divide-line">
          <li v-for="p in providers.data.value" :key="p.id" class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
            <div>
              <p class="font-medium">{{ p.name }} <span class="font-mono text-xs text-ink-muted">{{ p.code }}</span></p>
              <p class="text-xs text-ink-muted">{{ t(`billAdmin.settings.adapters.${p.adapter}`) }}</p>
              <p v-if="p.webhook_url" class="font-mono text-xs text-ink-muted">{{ t('billAdmin.settings.webhookUrl') }}: {{ p.webhook_url }}</p>
            </div>
            <div class="flex items-center gap-2">
              <AppBadge v-if="p.credentials_set.length" tone="brand">{{ t('billAdmin.settings.secretSet') }}</AppBadge>
              <AppBadge :tone="p.is_enabled ? 'success' : 'neutral'">{{ p.is_enabled ? t('billAdmin.settings.enabled') : '—' }}</AppBadge>
              <AppButton size="sm" variant="ghost" @click="openProvider(p)">{{ t('common.edit') }}</AppButton>
            </div>
          </li>
        </ul>
      </AppCard>
    </div>

    <AppModal :open="prov.open" :title="prov.isNew ? t('billAdmin.settings.newProvider') : prov.name" width="md" @close="prov.open = false">
      <form id="prov-form" class="space-y-3" @submit.prevent="saveProvider">
        <AppInput v-if="prov.isNew" v-model="prov.code" :label="t('billAdmin.settings.providerCode')" required />
        <AppSelect v-model="prov.adapter" :label="t('billAdmin.settings.adapter')" :options="(settings.data.value?.adapters ?? ['manual_bank']).map((a) => ({ value: a, label: t(`billAdmin.settings.adapters.${a}`) }))" />
        <AppInput v-model="prov.name" :label="t('billAdmin.plans.name')" required />
        <AppInput v-model="prov.bank_name" :label="t('billAdmin.settings.bankName')" />
        <AppInput v-model="prov.bank_bin" :label="t('billAdmin.settings.bankBin')" />
        <AppInput v-model="prov.account_no" :label="t('billAdmin.settings.accountNo')" />
        <AppInput v-model="prov.account_name" :label="t('billAdmin.settings.accountName')" />
        <AppInput v-if="prov.adapter === 'signed_webhook'" v-model="prov.secret" type="password" autocomplete="new-password" :label="t('billAdmin.settings.secret')" :hint="prov.secretSet ? t('billAdmin.settings.secretSet') : undefined" />
        <Toggle v-model="prov.is_enabled" :label="t('billAdmin.settings.enabled')" />
      </form>
      <template #footer><AppButton variant="secondary" @click="prov.open = false">{{ t('common.cancel') }}</AppButton><AppButton type="submit" form="prov-form" :loading="saving">{{ t('common.save') }}</AppButton></template>
    </AppModal>
  </div>
</template>
