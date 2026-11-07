<script setup lang="ts">
import { reactive, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney, ORDER_STATUSES, statusTone } from '@/shared/api/billing'
import { billingAdminApi, type AdminOrderDetail } from '@/shared/api/billingAdmin'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import Drawer from '@/shared/ui/Drawer.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AdminBillingNav from './AdminBillingNav.vue'
import { toInt, useAdminBilling } from './useAdminBilling'

const { t } = useI18n()
const { toast, toastError } = useAdminBilling()
const status = ref('')
const reference = ref('')
const list = useCursorList((cursor) => billingAdminApi.orders({ cursor, status: status.value || undefined, reference: reference.value.trim() || undefined }))
watch(status, () => void list.reload(), { immediate: true })
const detail = shallowRef<AdminOrderDetail | null>(null)
const busy = ref(false)
const manual = reactive({ open: false, bank_reference: '', amount: '', note: '' })
const refund = reactive({ open: false, amount: '', reason: '' })
const confirmCancel = ref(false)

async function open(id: string) {
  try {
    detail.value = await billingAdminApi.order(id)
  } catch (e) {
    toastError(e)
  }
}
async function guard(fn: () => Promise<unknown>) {
  busy.value = true
  try {
    await fn()
    toast.success(t('billAdmin.common.saved'))
    if (detail.value) await open(detail.value.id)
    await list.reload()
    return true
  } catch (e) {
    toastError(e)
    return false
  } finally {
    busy.value = false
  }
}
async function submitManual() {
  if (!detail.value) return
  const id = detail.value.id
  if (await guard(() => billingAdminApi.manualPayment(id, { bank_reference: manual.bank_reference.trim(), amount_minor: toInt(manual.amount) ?? 0, note: manual.note.trim() }))) manual.open = false
}
async function submitRefund() {
  if (!detail.value) return
  const id = detail.value.id
  if (await guard(() => billingAdminApi.requestRefund(id, { amount_minor: toInt(refund.amount) ?? 0, reason: refund.reason.trim() }))) refund.open = false
}
async function cancelOrder() {
  confirmCancel.value = false
  if (detail.value) {
    const id = detail.value.id
    await guard(() => billingAdminApi.cancelOrder(id))
  }
}
function openManual() {
  Object.assign(manual, { open: true, bank_reference: '', amount: String(detail.value?.amount_minor ?? ''), note: '' })
}
function openRefund() {
  const d = detail.value
  Object.assign(refund, { open: true, amount: d ? String(d.paid_amount_minor - d.refunded_amount_minor) : '', reason: '' })
}
</script>

<template>
  <div>
    <PageHeader :title="t('billAdmin.title')" :description="t('billAdmin.orders.subtitle')" />
    <AdminBillingNav active="orders" />
    <AppCard :padded="false">
      <template #actions>
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="list.reload">
          <AppInput v-model="reference" :placeholder="t('billAdmin.orders.searchRef')" />
          <div class="w-48"><AppSelect v-model="status" :options="[{ value: '', label: t('billAdmin.common.all') }, ...ORDER_STATUSES.map((s) => ({ value: s, label: t(`bill.orderStatus.${s}`) }))]" /></div>
          <AppButton type="submit" variant="secondary">{{ t('billAdmin.common.apply') }}</AppButton>
        </form>
      </template>
      <LoadingState v-if="list.loading.value" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload" />
      <EmptyState v-else-if="!list.items.value.length" icon="receipt" :title="t('billAdmin.common.empty')" />
      <div v-else class="overflow-x-auto">
        <table class="data-table min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted"><tr><th class="px-4 py-2">{{ t('bill.orders.reference') }}</th><th class="px-4 py-2">{{ t('billAdmin.common.workspace') }}</th><th class="px-4 py-2">{{ t('bill.orders.purpose') }}</th><th class="px-4 py-2 text-right">{{ t('bill.orders.amount') }}</th><th class="px-4 py-2">{{ t('bill.orders.status') }}</th><th class="px-4 py-2">{{ t('bill.orders.created') }}</th><th /></tr></thead>
          <tbody class="divide-y divide-line">
            <tr v-for="o in list.items.value" :key="o.id">
              <td class="px-4 py-2 font-mono">{{ o.reference }}</td>
              <td class="px-4 py-2">{{ o.workspace_name }}</td>
              <td class="px-4 py-2">{{ t(`bill.purpose.${o.purpose}`) }}</td>
              <td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(o.amount_minor, o.currency) }}</td>
              <td class="px-4 py-2"><AppBadge :tone="statusTone(o.status)">{{ t(`bill.orderStatus.${o.status}`) }}</AppBadge></td>
              <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ formatDateTime(o.created_at) }}</td>
              <td class="px-4 py-2 text-right"><AppButton size="sm" variant="ghost" @click="open(o.id)">{{ t('billAdmin.common.details') }}</AppButton></td>
            </tr>
          </tbody>
        </table>
        <div v-if="list.next.value" class="p-4 text-center"><AppButton variant="secondary" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton></div>
      </div>
    </AppCard>

    <Drawer :open="!!detail" :title="detail?.reference ?? ''" :subtitle="detail?.description ?? ''" @close="detail = null">
      <div v-if="detail" class="space-y-4 text-sm">
        <div class="flex flex-wrap items-center gap-2"><AppBadge :tone="statusTone(detail.status)">{{ t(`bill.orderStatus.${detail.status}`) }}</AppBadge><span>{{ formatMoney(detail.amount_minor, detail.currency) }}</span></div>
        <dl class="grid grid-cols-2 gap-2">
          <dt class="text-ink-muted">{{ t('billAdmin.orders.paid') }}</dt><dd class="tabular-nums">{{ formatMoney(detail.paid_amount_minor, detail.currency) }}</dd>
          <dt class="text-ink-muted">{{ t('billAdmin.orders.refunded') }}</dt><dd class="tabular-nums">{{ formatMoney(detail.refunded_amount_minor, detail.currency) }}</dd>
          <dt class="text-ink-muted">{{ t('bill.orders.created') }}</dt><dd>{{ formatDateTime(detail.created_at) }}</dd>
          <dt class="text-ink-muted">{{ t('bill.checkout.expiresIn') }}</dt><dd>{{ formatDateTime(detail.expires_at) }}</dd>
        </dl>
        <div class="flex flex-wrap gap-2">
          <AppButton v-if="['PENDING', 'PROCESSING', 'EXPIRED', 'FAILED'].includes(detail.status)" size="sm" data-testid="manual-payment" @click="openManual">{{ t('billAdmin.orders.manual') }}</AppButton>
          <AppButton v-if="['PAID', 'PARTIALLY_REFUNDED'].includes(detail.status)" size="sm" variant="secondary" @click="openRefund">{{ t('billAdmin.orders.refund') }}</AppButton>
          <AppButton v-if="['PENDING', 'PROCESSING'].includes(detail.status)" size="sm" variant="danger" @click="confirmCancel = true">{{ t('billAdmin.orders.cancel') }}</AppButton>
        </div>
        <div v-if="detail.transactions.length"><p class="mb-1 font-medium">{{ t('billAdmin.orders.transactions') }}</p>
          <ul class="space-y-1"><li v-for="tx in detail.transactions" :key="tx.id" class="rounded border border-line px-3 py-2">{{ tx.provider }} · {{ tx.external_id }} · {{ formatMoney(tx.amount_minor, tx.currency) }} · {{ t(`billAdmin.recon.matchStatus.${tx.match_status}`) }}</li></ul>
        </div>
        <div v-if="detail.refunds.length"><p class="mb-1 font-medium">{{ t('billAdmin.orders.refunds') }}</p>
          <ul class="space-y-1"><li v-for="r in detail.refunds" :key="r.id" class="rounded border border-line px-3 py-2">{{ formatMoney(r.amount_minor, r.currency) }} · {{ t(`billAdmin.refunds.statuses.${r.status}`) }} · {{ r.reason }}</li></ul>
        </div>
        <div v-if="detail.reconciliations.length"><p class="mb-1 font-medium">{{ t('billAdmin.orders.reconciliations') }}</p>
          <ul class="space-y-1 text-xs"><li v-for="r in detail.reconciliations" :key="r.id">{{ formatDateTime(r.created_at) }} · {{ r.result }}<span v-if="r.note"> · {{ r.note }}</span></li></ul>
        </div>
      </div>
    </Drawer>

    <AppModal :open="manual.open" :title="t('billAdmin.orders.manual')" width="md" @close="manual.open = false">
      <form id="manual-form" class="space-y-3" @submit.prevent="submitManual">
        <p class="text-sm text-ink-muted">{{ t('billAdmin.orders.manualHint') }}</p>
        <AppInput v-model="manual.bank_reference" :label="t('billAdmin.orders.bankRef')" required />
        <AppInput v-model="manual.amount" type="number" :label="t('billAdmin.orders.amount')" required />
        <AppInput v-model="manual.note" :label="t('billAdmin.common.note')" required />
      </form>
      <template #footer><AppButton variant="secondary" @click="manual.open = false">{{ t('common.cancel') }}</AppButton><AppButton type="submit" form="manual-form" :loading="busy" :disabled="manual.note.trim().length < 5">{{ t('common.confirm') }}</AppButton></template>
    </AppModal>
    <AppModal :open="refund.open" :title="t('billAdmin.orders.refund')" width="md" @close="refund.open = false">
      <form id="refund-form" class="space-y-3" @submit.prevent="submitRefund">
        <AppInput v-model="refund.amount" type="number" :label="t('billAdmin.orders.refundAmount')" required />
        <AppInput v-model="refund.reason" :label="t('billAdmin.common.reason')" required />
      </form>
      <template #footer><AppButton variant="secondary" @click="refund.open = false">{{ t('common.cancel') }}</AppButton><AppButton type="submit" form="refund-form" :loading="busy" :disabled="refund.reason.trim().length < 5">{{ t('common.confirm') }}</AppButton></template>
    </AppModal>
    <ConfirmDialog :open="confirmCancel" :title="t('billAdmin.orders.cancel')" :message="t('billAdmin.orders.cancel') + '?'" danger @close="confirmCancel = false" @confirm="cancelOrder" />
  </div>
</template>
