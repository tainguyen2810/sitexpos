<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'
import { billingApi, formatMoney, statusTone, type PaymentOrder } from '@/shared/api/billing'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import BillingNav from './BillingNav.vue'
import QrCode from './QrCode.vue'
import { useBilling } from './useBilling'

const { t } = useI18n()
const route = useRoute()
const { workspaceId, canManage, toast, toastError } = useBilling()
const order = shallowRef<PaymentOrder | null>(null)
const loading = ref(true)
const error = shallowRef<unknown>(null)
const notFound = ref(false)
const busy = ref(false)
const confirmCancel = ref(false)
const now = ref(Date.now())
let poll: ReturnType<typeof setInterval> | undefined
let tick: ReturnType<typeof setInterval> | undefined

const orderId = computed(() => String(route.params.orderId))
const open = computed(() => order.value !== null && ['PENDING', 'PROCESSING'].includes(order.value.status))
const remaining = computed(() => {
  if (!order.value?.expires_at) return null
  const ms = new Date(order.value.expires_at).getTime() - now.value
  if (ms <= 0) return '00:00'
  const h = Math.floor(ms / 3_600_000)
  const m = Math.floor((ms % 3_600_000) / 60_000)
  const s = Math.floor((ms % 60_000) / 1000)
  return `${h > 0 ? `${h}:` : ''}${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
})

async function load(silent = false) {
  if (!silent) loading.value = true
  error.value = null
  try {
    // The status shown here always comes from the server — never from the client.
    order.value = await billingApi.order(workspaceId.value, orderId.value)
    if (!open.value) stopPolling()
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) notFound.value = true
    else if (!silent) error.value = e
  } finally {
    loading.value = false
  }
}
function stopPolling() {
  if (poll) clearInterval(poll)
  poll = undefined
}
onMounted(async () => {
  await load()
  if (open.value) poll = setInterval(() => void load(true), 8000)
  tick = setInterval(() => (now.value = Date.now()), 1000)
})
onBeforeUnmount(() => {
  stopPolling()
  if (tick) clearInterval(tick)
})

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    toast.success(t('bill.checkout.copied'))
  } catch {
    /* clipboard unavailable — text stays selectable */
  }
}
async function transferred() {
  busy.value = true
  try {
    order.value = await billingApi.transferred(workspaceId.value, orderId.value)
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = false
  }
}
async function cancel() {
  confirmCancel.value = false
  busy.value = true
  try {
    order.value = await billingApi.cancelOrder(workspaceId.value, orderId.value)
    stopPolling()
    toast.success(t('bill.checkout.cancelled'))
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="order ? t('bill.checkout.title', { ref: order.reference }) : t('bill.title')" :description="t('bill.checkout.subtitle')" />
    <BillingNav active="orders" />
    <LoadingState v-if="loading" />
    <EmptyState v-else-if="notFound" icon="receipt" :title="t('bill.checkout.notFound')" />
    <ErrorState v-else-if="error" :error="error" @retry="load()" />
    <div v-else-if="order" class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
      <AppCard>
        <div class="mb-4 flex flex-wrap items-center gap-2">
          <AppBadge :tone="statusTone(order.status)" data-testid="order-status">{{ t(`bill.orderStatus.${order.status}`) }}</AppBadge>
          <span class="text-sm text-ink-muted">{{ t(`bill.purpose.${order.purpose}`) }} · {{ order.description }}</span>
        </div>

        <div v-if="order.status === 'PAID' || order.status === 'PARTIALLY_REFUNDED'" class="rounded-lg bg-success-50 px-4 py-6 text-center" data-testid="paid">
          <p class="text-lg font-semibold text-success-600">{{ t('bill.checkout.paid') }}</p>
          <p class="mt-1 text-sm text-ink-muted">{{ t('bill.checkout.paidHint') }}</p>
          <div class="mt-4 flex justify-center gap-2">
            <RouterLink :to="{ name: 'billing', params: { workspaceId } }"><AppButton variant="secondary">{{ t('bill.checkout.back') }}</AppButton></RouterLink>
            <RouterLink :to="{ name: 'billing-orders', params: { workspaceId }, query: { tab: 'documents' } }"><AppButton>{{ t('bill.checkout.viewDocuments') }}</AppButton></RouterLink>
          </div>
        </div>
        <p v-else-if="order.status === 'EXPIRED'" class="rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-600">{{ t('bill.checkout.expired') }}</p>
        <p v-else-if="!open" class="text-sm text-ink-muted">{{ t(`bill.orderStatus.${order.status}`) }}</p>

        <template v-else-if="order.instructions">
          <p v-if="!order.instructions.configured" class="rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-600">{{ t('bill.checkout.notConfigured') }}</p>
          <div class="grid grid-cols-1 gap-6 md:grid-cols-[auto_minmax(0,1fr)]">
            <div v-if="order.instructions.qr_payload" class="flex flex-col items-center gap-2">
              <QrCode :payload="order.instructions.qr_payload" :label="t('bill.checkout.scan')" />
              <p class="max-w-[220px] text-center text-xs text-ink-muted">{{ t('bill.checkout.scan') }}</p>
            </div>
            <dl class="space-y-3 text-sm">
              <div><dt class="text-ink-muted">{{ t('bill.checkout.bank') }}</dt><dd class="font-medium">{{ order.instructions.bank_name ?? order.instructions.bank_bin ?? '—' }}</dd></div>
              <div><dt class="text-ink-muted">{{ t('bill.checkout.account') }}</dt><dd class="flex items-center gap-2 font-mono">{{ order.instructions.account_no ?? '—' }}<AppButton v-if="order.instructions.account_no" size="sm" variant="ghost" @click="copy(order.instructions.account_no)">{{ t('bill.checkout.copy') }}</AppButton></dd></div>
              <div><dt class="text-ink-muted">{{ t('bill.checkout.accountName') }}</dt><dd class="font-medium">{{ order.instructions.account_name ?? '—' }}</dd></div>
              <div><dt class="text-ink-muted">{{ t('bill.checkout.amount') }}</dt><dd class="flex items-center gap-2 text-lg font-semibold tabular-nums" data-testid="amount">{{ formatMoney(order.amount_minor, order.currency) }}<AppButton size="sm" variant="ghost" @click="copy(String(order.amount_minor))">{{ t('bill.checkout.copy') }}</AppButton></dd></div>
              <div>
                <dt class="text-ink-muted">{{ t('bill.checkout.memo') }}</dt>
                <dd class="flex items-center gap-2"><span class="rounded bg-brand-50 px-2 py-1 font-mono text-base font-semibold text-brand-700" data-testid="memo">{{ order.instructions.memo }}</span><AppButton size="sm" variant="ghost" @click="copy(order.instructions.memo)">{{ t('bill.checkout.copy') }}</AppButton></dd>
                <p class="mt-1 text-xs text-ink-muted">{{ t('bill.checkout.memoHint') }}</p>
              </div>
            </dl>
          </div>
        </template>
      </AppCard>

      <AppCard v-if="open">
        <p v-if="remaining" class="text-sm text-ink-muted">{{ t('bill.checkout.expiresIn') }}</p>
        <p v-if="remaining" class="text-3xl font-semibold tabular-nums" data-testid="countdown">{{ remaining }}</p>
        <p class="mt-4 flex items-center gap-2 text-sm text-ink-muted"><span class="h-2 w-2 animate-pulse rounded-full bg-brand-500" />{{ t('bill.checkout.waiting') }}</p>
        <p v-if="order.instructions?.confirmation === 'manual_review'" class="mt-2 text-xs text-ink-muted">{{ t('bill.checkout.manualReview') }}</p>
        <div v-if="canManage" class="mt-6 space-y-2">
          <AppButton v-if="order.status === 'PENDING'" block :loading="busy" data-testid="transferred" @click="transferred">{{ t('bill.checkout.transferred') }}</AppButton>
          <p class="text-xs text-ink-muted">{{ t('bill.checkout.transferredHint') }}</p>
          <AppButton block variant="ghost" @click="confirmCancel = true">{{ t('bill.checkout.cancelOrder') }}</AppButton>
        </div>
      </AppCard>
    </div>
    <ConfirmDialog :open="confirmCancel" :title="t('bill.checkout.cancelOrder')" :message="t('bill.checkout.cancelOrder') + '?'" danger @close="confirmCancel = false" @confirm="cancel" />
  </div>
</template>
