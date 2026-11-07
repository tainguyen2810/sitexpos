<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatCredits, formatMoney, statusTone } from '@/shared/api/billing'
import { billingAdminApi, type RefundRow } from '@/shared/api/billingAdmin'
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
import Toggle from '@/shared/ui/Toggle.vue'
import AdminBillingNav from './AdminBillingNav.vue'
import { useAdminBilling } from './useAdminBilling'

const STATUSES = ['requested', 'processing', 'succeeded', 'failed'] as const
const { t } = useI18n()
const { toast, toastError } = useAdminBilling()
const status = ref('')
const list = useCursorList((cursor) => billingAdminApi.refunds({ cursor, status: status.value || undefined }))
watch(status, () => void list.reload(), { immediate: true })
const form = reactive({ open: false, refund: null as RefundRow | null, succeeded: true, external_ref: '', failure_reason: '' })
const busy = ref(false)

async function submit() {
  if (!form.refund) return
  busy.value = true
  try {
    await billingAdminApi.completeRefund(form.refund.id, { succeeded: form.succeeded, external_ref: form.succeeded ? form.external_ref.trim() : null, failure_reason: form.succeeded ? null : form.failure_reason.trim() })
    toast.success(t('billAdmin.common.saved'))
    form.open = false
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
    <PageHeader :title="t('billAdmin.title')" :description="t('billAdmin.refunds.subtitle')" />
    <AdminBillingNav active="refunds" />
    <AppCard :padded="false">
      <template #actions>
        <div class="w-48"><AppSelect v-model="status" :options="[{ value: '', label: t('billAdmin.common.all') }, ...STATUSES.map((s) => ({ value: s, label: t(`billAdmin.refunds.statuses.${s}`) }))]" /></div>
      </template>
      <LoadingState v-if="list.loading.value" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload" />
      <EmptyState v-else-if="!list.items.value.length" icon="receipt" :title="t('billAdmin.common.empty')" />
      <div v-else class="overflow-x-auto">
        <table class="data-table min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted"><tr><th class="px-4 py-2">{{ t('bill.orders.created') }}</th><th class="px-4 py-2 text-right">{{ t('bill.orders.amount') }}</th><th class="px-4 py-2">{{ t('billAdmin.common.reason') }}</th><th class="px-4 py-2">{{ t('bill.orders.status') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.refunds.creditsReversed') }}</th><th /></tr></thead>
          <tbody class="divide-y divide-line">
            <tr v-for="r in list.items.value" :key="r.id">
              <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ formatDateTime(r.created_at) }}</td>
              <td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(r.amount_minor, r.currency) }}</td>
              <td class="px-4 py-2">{{ r.reason }}<p v-if="r.failure_reason" class="text-xs text-danger-600">{{ r.failure_reason }}</p></td>
              <td class="px-4 py-2"><AppBadge :tone="statusTone(r.status)">{{ t(`billAdmin.refunds.statuses.${r.status}`) }}</AppBadge></td>
              <td class="px-4 py-2 text-right tabular-nums">{{ formatCredits(r.credits_reversed_mc) }}</td>
              <td class="px-4 py-2 text-right"><AppButton v-if="['requested', 'processing'].includes(r.status)" size="sm" @click="Object.assign(form, { open: true, refund: r, succeeded: true, external_ref: '', failure_reason: '' })">{{ t('billAdmin.refunds.complete') }}</AppButton></td>
            </tr>
          </tbody>
        </table>
        <div v-if="list.next.value" class="p-4 text-center"><AppButton variant="secondary" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton></div>
      </div>
    </AppCard>
    <AppModal :open="form.open" :title="t('billAdmin.refunds.complete')" width="md" @close="form.open = false">
      <form id="refund-complete" class="space-y-3" @submit.prevent="submit">
        <Toggle v-model="form.succeeded" :label="t('billAdmin.refunds.succeeded')" />
        <AppInput v-if="form.succeeded" v-model="form.external_ref" :label="t('billAdmin.refunds.externalRef')" required />
        <AppInput v-else v-model="form.failure_reason" :label="t('billAdmin.refunds.failureReason')" required />
      </form>
      <template #footer><AppButton variant="secondary" @click="form.open = false">{{ t('common.cancel') }}</AppButton><AppButton type="submit" form="refund-complete" :loading="busy">{{ t('common.confirm') }}</AppButton></template>
    </AppModal>
  </div>
</template>
