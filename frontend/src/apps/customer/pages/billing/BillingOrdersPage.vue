<script setup lang="ts">
import { ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { billingApi, formatMoney, statusTone, type BillingDocument } from '@/shared/api/billing'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDate, formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import Drawer from '@/shared/ui/Drawer.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Tabs from '@/shared/ui/Tabs.vue'
import BillingNav from './BillingNav.vue'
import { useBilling } from './useBilling'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { workspaceId } = useBilling()
const tab = ref(route.query.tab === 'documents' ? 'documents' : 'orders')
const orders = useCursorList((cursor) => billingApi.orders(workspaceId.value, cursor))
const docs = useCursorList((cursor) => billingApi.documents(workspaceId.value, cursor))
const doc = shallowRef<BillingDocument | null>(null)
watch(tab, (v) => void (v === 'orders' ? orders.reload() : docs.reload()), { immediate: true })

function snap(d: BillingDocument, key: string): Record<string, unknown> | null {
  const v = d.snapshot?.[key]
  return v && typeof v === 'object' ? (v as Record<string, unknown>) : null
}
function printDoc() {
  window.print()
}
</script>

<template>
  <div>
    <PageHeader :title="t('bill.title')" :description="t('bill.orders.subtitle')" />
    <BillingNav active="orders" />
    <Tabs v-model="tab" class="mb-4" :tabs="[{ key: 'orders', label: t('bill.orders.tabOrders') }, { key: 'documents', label: t('bill.orders.tabDocuments') }]" />

    <AppCard v-if="tab === 'orders'" :padded="false">
      <LoadingState v-if="orders.loading.value" />
      <ErrorState v-else-if="orders.error.value" :error="orders.error.value" @retry="orders.reload" />
      <EmptyState v-else-if="!orders.items.value.length" icon="receipt" :title="t('bill.orders.empty')" />
      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
            <tr><th class="px-4 py-2">{{ t('bill.orders.reference') }}</th><th class="px-4 py-2">{{ t('bill.orders.purpose') }}</th><th class="px-4 py-2 text-right">{{ t('bill.orders.amount') }}</th><th class="px-4 py-2">{{ t('bill.orders.status') }}</th><th class="px-4 py-2">{{ t('bill.orders.created') }}</th><th /></tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="o in orders.items.value" :key="o.id">
              <td class="px-4 py-2 font-mono">{{ o.reference }}</td>
              <td class="px-4 py-2">{{ t(`bill.purpose.${o.purpose}`) }}<span class="block text-xs text-ink-muted">{{ o.description }}</span></td>
              <td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(o.amount_minor, o.currency) }}</td>
              <td class="px-4 py-2"><AppBadge :tone="statusTone(o.status)">{{ t(`bill.orderStatus.${o.status}`) }}</AppBadge></td>
              <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ formatDateTime(o.created_at) }}</td>
              <td class="px-4 py-2 text-right"><AppButton size="sm" variant="ghost" @click="router.push({ name: 'billing-checkout', params: { workspaceId, orderId: o.id } })">{{ ['PENDING', 'PROCESSING'].includes(o.status) ? t('bill.orders.pay') : t('bill.orders.view') }}</AppButton></td>
            </tr>
          </tbody>
        </table>
        <div v-if="orders.next.value" class="p-4 text-center"><AppButton variant="secondary" size="sm" :loading="orders.loadingMore.value" @click="orders.more">{{ t('common.loadMore') }}</AppButton></div>
      </div>
    </AppCard>

    <AppCard v-else :padded="false">
      <p class="border-b border-line px-4 py-2 text-xs text-ink-muted">{{ t('bill.common.internalDoc') }}</p>
      <LoadingState v-if="docs.loading.value" />
      <ErrorState v-else-if="docs.error.value" :error="docs.error.value" @retry="docs.reload" />
      <EmptyState v-else-if="!docs.items.value.length" icon="file" :title="t('bill.orders.docEmpty')" />
      <ul v-else class="divide-y divide-line">
        <li v-for="d in docs.items.value" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
          <div><p class="font-mono font-medium">{{ d.number }}</p><p class="text-xs text-ink-muted">{{ t(`bill.orders.docType.${d.type}`) }} · {{ formatDate(d.issued_at) }}</p></div>
          <div class="flex items-center gap-3"><span class="tabular-nums">{{ formatMoney(d.amount_minor, d.currency) }}</span><AppButton size="sm" variant="secondary" :data-testid="`doc-${d.number}`" @click="doc = d">{{ t('bill.orders.view') }}</AppButton></div>
        </li>
      </ul>
      <div v-if="docs.next.value" class="p-4 text-center"><AppButton variant="secondary" size="sm" :loading="docs.loadingMore.value" @click="docs.more">{{ t('common.loadMore') }}</AppButton></div>
    </AppCard>

    <Drawer :open="!!doc" :title="doc ? `${t(`bill.orders.docType.${doc.type}`)} ${doc.number}` : ''" :subtitle="t('bill.common.internalDoc')" @close="doc = null">
      <div v-if="doc" class="space-y-4 text-sm" data-testid="document">
        <p class="rounded bg-warning-50 px-3 py-2 text-xs font-medium text-warning-600">{{ t('bill.common.internalDoc') }}</p>
        <dl class="grid grid-cols-2 gap-2">
          <dt class="text-ink-muted">{{ t('bill.orders.docNumber') }}</dt><dd>{{ doc.number }}</dd>
          <dt class="text-ink-muted">{{ t('bill.orders.issued') }}</dt><dd>{{ formatDateTime(doc.issued_at) }}</dd>
          <dt class="text-ink-muted">{{ t('bill.orders.reference') }}</dt><dd class="font-mono">{{ doc.snapshot?.reference ?? '—' }}</dd>
          <dt class="text-ink-muted">{{ t('bill.orders.amount') }}</dt><dd class="font-semibold tabular-nums">{{ formatMoney(doc.amount_minor, doc.currency) }}</dd>
        </dl>
        <div><p class="font-medium">{{ t('bill.orders.seller') }}</p><p class="text-ink-muted">{{ snap(doc, 'seller')?.name ?? '—' }} {{ snap(doc, 'seller')?.tax_code ? `· ${snap(doc, 'seller')?.tax_code}` : '' }}</p><p class="text-ink-muted">{{ snap(doc, 'seller')?.address ?? '' }}</p></div>
        <div><p class="font-medium">{{ t('bill.orders.buyer') }}</p><p class="text-ink-muted">{{ snap(doc, 'customer')?.company_name ?? '—' }} {{ snap(doc, 'customer')?.tax_code ? `· ${snap(doc, 'customer')?.tax_code}` : '' }}</p><p class="text-ink-muted">{{ snap(doc, 'customer')?.address ?? '' }}</p></div>
        <div><p class="font-medium">{{ t('bill.orders.lines') }}</p><p class="text-ink-muted">{{ doc.snapshot?.description ?? doc.snapshot?.reason ?? '—' }}</p></div>
        <AppButton variant="secondary" size="sm" @click="printDoc">{{ t('bill.orders.print') }}</AppButton>
      </div>
    </Drawer>
  </div>
</template>
