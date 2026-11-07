<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { billingApi, formatCredits, formatMoney, idempotencyKey, RESOURCES, type Interval, type PlanView, type Quote } from '@/shared/api/billing'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDate } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import BillingNav from './BillingNav.vue'
import { useBilling } from './useBilling'

const { t } = useI18n()
const router = useRouter()
const { workspaceId, canManage, toast, toastError } = useBilling()
const { data, error, loading, run } = useAsync(async () => {
  const [plans, overview] = await Promise.all([billingApi.plans(workspaceId.value), billingApi.overview(workspaceId.value)])
  if (!provider.value && plans.providers.length) provider.value = plans.providers[0]!.code
  return { plans, overview }
})
const interval = ref<Interval>('month')
const provider = ref('')
const busy = ref<string | null>(null)
const quote = shallowRef<{ plan: PlanView; quote: Quote } | null>(null)

const current = computed(() => data.value?.overview.subscription ?? null)
const hasDemo = computed(() => data.value?.plans.plans.some((p) => p.is_demo) ?? false)
function price(p: PlanView): number | null {
  const v = p.current_version
  return v ? (interval.value === 'year' ? v.yearly_price_minor : v.monthly_price_minor) : null
}
function isCurrent(p: PlanView) {
  return current.value?.plan?.code === p.code && current.value.interval === interval.value
}
function limit(n: number | undefined) {
  return n === undefined ? null : n < 0 ? t('bill.common.unlimited') : n === 0 ? t('bill.common.notIncluded') : String(n)
}
async function guarded(key: string, fn: () => Promise<void>) {
  busy.value = key
  try {
    await fn()
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = null
  }
}
function goPay(orderId: string) {
  return router.push({ name: 'billing-checkout', params: { workspaceId: workspaceId.value, orderId } })
}
function choose(p: PlanView) {
  return guarded(p.code, async () => {
    const live = current.value && current.value.status !== 'EXPIRED' && !current.value.plan?.is_free
    if (live) {
      quote.value = { plan: p, quote: await billingApi.quote(workspaceId.value, p.code, interval.value) }
      return
    }
    if (p.is_free) {
      await billingApi.subscribeFree(workspaceId.value, p.code)
      toast.success(t('bill.plans.freeActivated'))
      await run()
      return
    }
    const order = await billingApi.checkout(workspaceId.value, { plan_code: p.code, interval: interval.value, provider: provider.value || null, idempotency_key: idempotencyKey() })
    await goPay(order.id)
  })
}
function trial(p: PlanView) {
  return guarded(`trial-${p.code}`, async () => {
    await billingApi.trial(workspaceId.value, p.code)
    toast.success(t('bill.plans.trialStarted'))
    await run()
  })
}
function confirmChange() {
  const q = quote.value
  if (!q) return
  return guarded('confirm', async () => {
    const res = await billingApi.change(workspaceId.value, { plan_code: q.plan.code, interval: interval.value, provider: provider.value || null })
    quote.value = null
    if (res.order) return void (await goPay(res.order.id))
    toast.success(t(res.change.status === 'scheduled' ? 'bill.plans.changeScheduled' : 'bill.plans.changeApplied'))
    await run()
  })
}
</script>

<template>
  <div>
    <PageHeader :title="t('bill.title')" :description="t('bill.plans.subtitle')" />
    <BillingNav active="plans" />
    <LoadingState v-if="loading && !data" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <template v-else-if="data">
      <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="inline-flex rounded-lg border border-line p-1" role="group">
          <button v-for="i in (['month', 'year'] as const)" :key="i" type="button" class="rounded-md px-3 py-1.5 text-sm font-medium" :class="interval === i ? 'bg-brand-600 text-white' : 'text-ink-muted'" :data-testid="`interval-${i}`" @click="interval = i">
            {{ i === 'month' ? t('bill.plans.monthly') : t('bill.plans.yearly') }}
          </button>
        </div>
        <div v-if="data.plans.providers.length > 1" class="w-64"><AppSelect v-model="provider" :label="t('bill.plans.provider')" :options="data.plans.providers.map((p) => ({ value: p.code, label: p.name }))" /></div>
      </div>
      <p v-if="hasDemo" class="mb-4 rounded-lg bg-warning-50 px-4 py-2 text-sm text-warning-600">{{ t('bill.plans.demoNotice') }}</p>
      <p v-if="!canManage" class="mb-4 text-sm text-ink-muted">{{ t('bill.common.ownerOnly') }}</p>

      <EmptyState v-if="!data.plans.plans.length" icon="card" :title="t('bill.plans.noPlans')" :description="t('bill.plans.noPlansHint')" />
      <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <AppCard v-for="p in data.plans.plans" :key="p.id" :class="isCurrent(p) ? 'ring-2 ring-brand-500' : ''" :data-testid="`plan-${p.code}`">
          <div class="flex items-start justify-between gap-2">
            <h3 class="text-lg font-semibold">{{ p.name }}</h3>
            <div class="flex flex-col items-end gap-1">
              <AppBadge v-if="isCurrent(p)" tone="brand">{{ t('bill.plans.current') }}</AppBadge>
              <AppBadge v-if="p.is_demo" tone="warning">{{ t('bill.common.demo') }}</AppBadge>
            </div>
          </div>
          <p v-if="p.description" class="mt-1 text-sm text-ink-muted">{{ p.description }}</p>
          <p class="mt-4 text-2xl font-semibold tabular-nums">
            <template v-if="price(p) === null"><span class="text-base text-ink-muted">{{ t('bill.plans.notSold') }}</span></template>
            <template v-else>{{ price(p) === 0 ? t('bill.overview.free') : formatMoney(price(p), p.current_version?.currency) }}<span v-if="price(p)" class="text-sm font-normal text-ink-muted">{{ interval === 'year' ? t('bill.common.perYear') : t('bill.common.perMonth') }}</span></template>
          </p>
          <p v-if="p.current_version?.included_credits_mc" class="mt-1 text-sm text-brand-700">{{ t('bill.plans.included', { n: formatCredits(p.current_version.included_credits_mc) }) }}</p>
          <ul class="mt-4 space-y-1 text-sm">
            <template v-for="r in RESOURCES" :key="r">
              <li v-if="limit(p.current_version?.entitlements[r]?.limit) !== null" class="flex justify-between gap-2">
                <span class="text-ink-muted">{{ t(`bill.resources.${r}`) }}</span><span class="tabular-nums">{{ limit(p.current_version?.entitlements[r]?.limit) }}</span>
              </li>
            </template>
            <template v-for="(on, f) in p.current_version?.features ?? {}" :key="f">
              <li v-if="on" class="text-success-600">✓ {{ t(`bill.features.${f}`) }}</li>
            </template>
            <li v-if="p.current_version?.support_level" class="text-ink-muted">{{ t(`bill.support.${p.current_version.support_level}`) }}</li>
          </ul>
          <div v-if="canManage && price(p) !== null && !isCurrent(p)" class="mt-5 space-y-2">
            <AppButton block :loading="busy === p.code" :data-testid="`choose-${p.code}`" @click="choose(p)">
              {{ current && current.status !== 'EXPIRED' && !current.plan?.is_free ? t('bill.plans.change') : p.is_free ? t('bill.plans.chooseFree') : t('bill.plans.subscribe') }}
            </AppButton>
            <AppButton v-if="p.trial?.eligible" block variant="secondary" :loading="busy === `trial-${p.code}`" @click="trial(p)">{{ t('bill.plans.startTrial') }} · {{ t('bill.plans.trial', { n: p.current_version?.trial_days }) }}</AppButton>
            <p v-else-if="p.current_version?.trial_days && p.trial?.reason" class="text-center text-xs text-ink-muted">{{ t(`bill.plans.trialReason.${p.trial.reason}`) }}</p>
          </div>
        </AppCard>
      </div>
    </template>

    <AppModal :open="!!quote" :title="t('bill.plans.confirmTitle')" width="md" @close="quote = null">
      <div v-if="quote" class="space-y-3 text-sm" data-testid="quote">
        <p class="font-medium">{{ current?.plan?.name }} → {{ quote.plan.name }} ({{ t(`bill.interval.${interval}`) }})</p>
        <p v-if="quote.quote.kind === 'upgrade'">{{ t('bill.plans.upgradeNow') }}</p>
        <p v-else>{{ t('bill.plans.downgradeLater', { date: formatDate(quote.quote.effective_at) }) }}</p>
        <dl v-if="quote.quote.kind === 'upgrade'" class="grid grid-cols-2 gap-2">
          <dt class="text-ink-muted">{{ t('bill.plans.amountDue') }}</dt><dd class="text-right text-lg font-semibold tabular-nums" data-testid="quote-amount">{{ formatMoney(quote.quote.amount_minor, quote.quote.currency) }}</dd>
          <dt class="text-ink-muted">{{ t('bill.plans.newPeriodEnd') }}</dt><dd class="text-right">{{ formatDate(quote.quote.new_period_end) }}</dd>
        </dl>
        <p v-if="quote.quote.proration" class="text-ink-muted">{{ t('bill.plans.prorated', { days: quote.quote.proration.remaining_days }) }}</p>
        <p class="text-ink-muted">{{ t(`bill.plans.creditsPolicy.${quote.quote.credits_policy}`) }}</p>
      </div>
      <template #footer>
        <AppButton variant="secondary" @click="quote = null">{{ t('common.cancel') }}</AppButton>
        <AppButton :loading="busy === 'confirm'" data-testid="confirm-change" @click="confirmChange">{{ t('bill.plans.confirm') }}</AppButton>
      </template>
    </AppModal>
  </div>
</template>
