<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney, statusTone } from '@/shared/api/billing'
import { billingAdminApi, type PaymentTransaction } from '@/shared/api/billingAdmin'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AdminBillingNav from './AdminBillingNav.vue'
import { useAdminBilling } from './useAdminBilling'

const STATUSES = ['unmatched', 'mismatch', 'matched', 'ignored', 'rejected'] as const
const { t, te } = useI18n()
const { toast, toastError } = useAdminBilling()
const filter = ref('review')
const list = useCursorList((cursor) => billingAdminApi.transactions({ cursor, needs_review: filter.value === 'review' ? 1 : undefined, match_status: filter.value !== 'review' && filter.value ? filter.value : undefined }))
watch(filter, () => void list.reload(), { immediate: true })
const review = reactive({ open: false, mode: 'approve' as 'approve' | 'reject', tx: null as PaymentTransaction | null, orderId: '', note: '' })
const busy = ref(false)

function openReview(tx: PaymentTransaction, mode: 'approve' | 'reject') {
  Object.assign(review, { open: true, mode, tx, orderId: '', note: '' })
}
async function submit() {
  const tx = review.tx
  if (!tx) return
  busy.value = true
  try {
    if (review.mode === 'approve') await billingAdminApi.approveTransaction(tx.id, { note: review.note.trim(), ...(review.orderId.trim() ? { order_id: review.orderId.trim() } : {}) })
    else await billingAdminApi.rejectTransaction(tx.id, { note: review.note.trim() })
    toast.success(t(review.mode === 'approve' ? 'billAdmin.recon.approved' : 'billAdmin.recon.rejected'))
    review.open = false
    await list.reload()
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('billAdmin.title')" :description="t('billAdmin.recon.subtitle')" />
    <AdminBillingNav active="reconciliation" />
    <AppCard :padded="false">
      <template #actions>
        <div class="w-56"><AppSelect v-model="filter" :options="[{ value: 'review', label: t('billAdmin.recon.needsReview') }, { value: '', label: t('billAdmin.common.all') }, ...STATUSES.map((s) => ({ value: s, label: t(`billAdmin.recon.matchStatus.${s}`) }))]" /></div>
      </template>
      <LoadingState v-if="list.loading.value" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload" />
      <EmptyState v-else-if="!list.items.value.length" icon="check" :title="t('billAdmin.common.empty')" />
      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted"><tr><th class="px-4 py-2">{{ t('billAdmin.recon.occurred') }}</th><th class="px-4 py-2">{{ t('billAdmin.recon.external') }}</th><th class="px-4 py-2">{{ t('billAdmin.recon.memo') }}</th><th class="px-4 py-2 text-right">{{ t('bill.orders.amount') }}</th><th class="px-4 py-2">{{ t('bill.orders.status') }}</th><th /></tr></thead>
          <tbody class="divide-y divide-line">
            <tr v-for="tx in list.items.value" :key="tx.id">
              <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ formatDateTime(tx.occurred_at) }}</td>
              <td class="px-4 py-2 font-mono text-xs">{{ tx.provider }}<br />{{ tx.external_id }}</td>
              <td class="max-w-xs px-4 py-2 break-words">{{ tx.description ?? '—' }}</td>
              <td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(tx.amount_minor, tx.currency) }}</td>
              <td class="px-4 py-2">
                <AppBadge :tone="statusTone(tx.match_status)">{{ t(`billAdmin.recon.matchStatus.${tx.match_status}`) }}</AppBadge>
                <p v-if="tx.match_reason" class="mt-1 text-xs text-ink-muted">{{ te(`billAdmin.recon.reasons.${tx.match_reason}`) ? t(`billAdmin.recon.reasons.${tx.match_reason}`) : tx.match_reason }}</p>
              </td>
              <td class="px-4 py-2 text-right">
                <template v-if="['unmatched', 'mismatch'].includes(tx.match_status)">
                  <AppButton size="sm" :data-testid="`approve-${tx.id}`" @click="openReview(tx, 'approve')">{{ t('billAdmin.recon.approve') }}</AppButton>
                  <AppButton size="sm" variant="ghost" @click="openReview(tx, 'reject')">{{ t('billAdmin.recon.reject') }}</AppButton>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="list.next.value" class="p-4 text-center"><AppButton variant="secondary" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton></div>
      </div>
    </AppCard>
    <AppModal :open="review.open" :title="review.mode === 'approve' ? t('billAdmin.recon.approve') : t('billAdmin.recon.reject')" width="md" @close="review.open = false">
      <form id="review-form" class="space-y-3" @submit.prevent="submit">
        <p v-if="review.tx" class="text-sm">{{ formatMoney(review.tx.amount_minor, review.tx.currency) }} · {{ review.tx.description }}</p>
        <AppInput v-if="review.mode === 'approve'" v-model="review.orderId" :label="t('billAdmin.recon.orderId')" :required="!review.tx?.payment_order_id" :placeholder="review.tx?.payment_order_id ?? ''" />
        <AppInput v-model="review.note" :label="t('billAdmin.common.note')" required />
      </form>
      <template #footer><AppButton variant="secondary" @click="review.open = false">{{ t('common.cancel') }}</AppButton><AppButton type="submit" form="review-form" :variant="review.mode === 'reject' ? 'danger' : 'primary'" :loading="busy" :disabled="review.note.trim().length < 5">{{ t('common.confirm') }}</AppButton></template>
    </AppModal>
  </div>
</template>
