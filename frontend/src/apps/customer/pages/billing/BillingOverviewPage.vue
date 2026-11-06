<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { billingApi, formatCredits, formatMoney, idempotencyKey, RESOURCES, statusTone, type Resource } from '@/shared/api/billing'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDate } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import StatCard from '@/shared/ui/StatCard.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import BillingNav from './BillingNav.vue'
import { useBilling } from './useBilling'

const { t } = useI18n()
const router = useRouter()
const { workspaceId, canManage, toast, toastError } = useBilling()
const { data, error, loading, run } = useAsync(() => billingApi.overview(workspaceId.value))
const busy = ref(false)
const confirmCancel = ref(false)
const cancelReason = ref('')

const sub = computed(() => data.value?.subscription ?? null)
const ent = computed(() => data.value?.entitlements ?? null)
const limitRows = computed(() =>
  RESOURCES.filter((r) => ent.value?.limits[r] !== undefined).map((r: Resource) => {
    const e = ent.value!.limits[r]!
    return { key: r, limit: e.limit, period: e.period, used: ent.value!.usage[r] ?? null }
  }),
)

async function act(fn: () => Promise<unknown>, okKey: string) {
  busy.value = true
  try {
    await fn()
    toast.success(t(okKey))
    await run()
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = false
  }
}
async function renew() {
  busy.value = true
  try {
    const order = await billingApi.renew(workspaceId.value, { idempotency_key: idempotencyKey() })
    await router.push({ name: 'billing-checkout', params: { workspaceId: workspaceId.value, orderId: order.id } })
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = false
  }
}
async function doCancel() {
  confirmCancel.value = false
  await act(() => billingApi.cancel(workspaceId.value, cancelReason.value), 'bill.overview.cancelled')
}
async function setReminders(on: boolean) {
  await act(() => billingApi.settings(workspaceId.value, on), 'common.saved')
}
</script>

<template>
  <div>
    <PageHeader :title="t('bill.title')" :description="t('bill.overview.subtitle')" />
    <BillingNav active="overview" />
    <LoadingState v-if="loading && !data" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <div v-else-if="data" class="space-y-6">
      <p v-if="ent && !ent.enforcing" class="rounded-lg bg-surface-muted px-4 py-2 text-sm text-ink-muted" data-testid="no-enforcement">{{ t('bill.overview.noEnforcement') }}</p>
      <p v-if="ent && ent.applies && !ent.paid_usage_allowed" class="rounded-lg bg-warning-50 px-4 py-2 text-sm text-warning-600" data-testid="paid-blocked">{{ t('bill.overview.paidUsageBlocked') }}</p>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard :label="t('bill.overview.currentPlan')" :value="sub?.plan?.name ?? ent?.plan_name ?? '—'" icon="card" :hint="sub ? t(`bill.status.${sub.status}`) : ent?.source === 'default_plan' ? t('bill.overview.defaultPlan') : undefined" />
        <StatCard :label="t('bill.overview.wallet')" :value="formatCredits(data.wallet.available_mc)" icon="coins" :hint="data.wallet.held_mc ? `${t('bill.overview.held')}: ${formatCredits(data.wallet.held_mc)}` : t('bill.common.credits')" />
        <StatCard :label="t('bill.overview.renewsOn')" :value="sub ? formatDate(sub.current_period_end) : '—'" icon="calendar" :hint="sub?.trial_ends_at ? `${t('bill.overview.trialEnds')}: ${formatDate(sub.trial_ends_at)}` : sub?.grace_ends_at ? `${t('bill.overview.graceEnds')}: ${formatDate(sub.grace_ends_at)}` : undefined" />
      </div>

      <AppCard v-if="!sub">
        <EmptyState icon="card" :title="t('bill.overview.noPlan')" :description="t('bill.overview.noPlanHint')">
          <AppButton @click="router.push({ name: 'billing-plans', params: { workspaceId } })">{{ t('bill.overview.choosePlan') }}</AppButton>
        </EmptyState>
      </AppCard>

      <AppCard v-else :title="sub.plan?.name ?? '—'">
        <template #actions>
          <AppBadge :tone="statusTone(sub.status)" data-testid="sub-status">{{ t(`bill.status.${sub.status}`) }}</AppBadge>
          <AppBadge v-if="sub.plan?.is_demo" tone="warning">{{ t('bill.common.demo') }}</AppBadge>
        </template>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          <div class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('bill.overview.price') }}</dt><dd>{{ sub.price_minor ? `${formatMoney(sub.price_minor, sub.currency)} · ${t(`bill.interval.${sub.interval}`)}` : t('bill.overview.free') }}</dd></div>
          <div class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('bill.overview.period') }}</dt><dd>{{ formatDate(sub.current_period_start) }} – {{ formatDate(sub.current_period_end) }}</dd></div>
        </dl>

        <div v-if="data.pending_change" class="mt-4 rounded-lg bg-brand-50 px-4 py-3 text-sm text-brand-700" data-testid="pending-change">
          <p class="font-medium">{{ t('bill.overview.pendingChange') }}</p>
          <p>{{ data.pending_change.kind === 'upgrade' ? t('bill.overview.pendingUpgrade', { plan: data.pending_change.to_plan?.name ?? '' }) : t('bill.overview.scheduledDowngrade', { plan: data.pending_change.to_plan?.name ?? '', date: formatDate(data.pending_change.effective_at) }) }}</p>
          <div v-if="canManage" class="mt-2 flex gap-2">
            <AppButton v-if="data.pending_change.payment_order_id" size="sm" @click="router.push({ name: 'billing-checkout', params: { workspaceId, orderId: data.pending_change.payment_order_id } })">{{ t('bill.orders.pay') }}</AppButton>
            <AppButton size="sm" variant="secondary" :loading="busy" @click="act(() => billingApi.cancelChange(workspaceId), 'bill.overview.changeCancelled')">{{ t('bill.overview.cancelChange') }}</AppButton>
          </div>
        </div>

        <div v-if="canManage" class="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">
          <AppButton v-if="sub.price_minor > 0 && ['ACTIVE', 'PAST_DUE', 'GRACE_PERIOD'].includes(sub.status)" :loading="busy" data-testid="renew" @click="renew">{{ t('bill.overview.renew') }}</AppButton>
          <AppButton variant="secondary" @click="router.push({ name: 'billing-plans', params: { workspaceId } })">{{ t('bill.overview.changePlan') }}</AppButton>
          <AppButton v-if="sub.status === 'CANCELLED'" variant="secondary" :loading="busy" @click="act(() => billingApi.resume(workspaceId), 'bill.overview.resumed')">{{ t('bill.overview.resume') }}</AppButton>
          <AppButton v-else-if="['ACTIVE', 'TRIALING', 'PAST_DUE', 'GRACE_PERIOD'].includes(sub.status)" variant="ghost" data-testid="cancel-sub" @click="confirmCancel = true">{{ t('bill.overview.cancel') }}</AppButton>
        </div>
        <div v-if="canManage && sub.price_minor > 0" class="mt-2">
          <Toggle :model-value="sub.renewal_reminders" :label="t('bill.overview.reminders')" :description="t('bill.overview.remindersHint')" :disabled="busy" @update:model-value="setReminders" />
        </div>
      </AppCard>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <AppCard :title="t('bill.overview.limits')">
          <p v-if="!limitRows.length" class="text-sm text-ink-muted">{{ t('bill.common.noData') }}</p>
          <ul v-else class="space-y-3">
            <li v-for="r in limitRows" :key="r.key" class="text-sm">
              <div class="mb-1 flex justify-between gap-2">
                <span>{{ t(`bill.resources.${r.key}`) }}</span>
                <span class="tabular-nums text-ink-muted">
                  <template v-if="r.used !== null">{{ r.used }} / </template>{{ r.limit < 0 ? t('bill.common.unlimited') : r.limit === 0 ? t('bill.common.notIncluded') : r.limit }}
                </span>
              </div>
              <ProgressBar v-if="r.used !== null && r.limit > 0" :value="r.used" :max="r.limit" tone="auto" :label="t(`bill.resources.${r.key}`)" />
            </li>
          </ul>
        </AppCard>
        <AppCard :title="t('bill.overview.features')">
          <ul class="">
            <li v-for="(on, key) in ent?.features ?? {}" :key="key" class="flex items-center gap-2">
              <AppBadge :tone="on ? 'success' : 'neutral'">{{ on ? '✓' : '—' }}</AppBadge>{{ t(`bill.features.${key}`) }}
            </li>
          </ul>
        </AppCard>
      </div>
    </div>

    <ConfirmDialog :open="confirmCancel" :title="t('bill.overview.cancelTitle')" :message="t('bill.overview.cancelMessage')" danger :loading="busy" @close="confirmCancel = false" @confirm="doCancel">
      <AppInput v-model="cancelReason" class="mt-3" :label="t('bill.overview.cancelReason')" />
    </ConfirmDialog>
  </div>
</template>
