<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { billingApi, formatCredits } from '@/shared/api/billing'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import BillingNav from './BillingNav.vue'
import { useBilling } from './useBilling'

const TYPES = ['grant', 'consume', 'expire', 'refund', 'adjust', 'reversal'] as const
const { t } = useI18n()
const { workspaceId } = useBilling()
const type = ref('')
const list = useCursorList((cursor) => billingApi.transactions(workspaceId.value, cursor, type.value))
watch(type, () => void list.reload(), { immediate: true })
</script>

<template>
  <div>
    <PageHeader :title="t('bill.title')" :description="t('bill.transactions.subtitle')" />
    <BillingNav active="transactions" />
    <AppCard :padded="false">
      <template #actions>
        <div class="w-48"><AppSelect v-model="type" :options="[{ value: '', label: t('bill.transactions.all') }, ...TYPES.map((x) => ({ value: x, label: t(`bill.transactions.types.${x}`) }))]" /></div>
      </template>
      <LoadingState v-if="list.loading.value" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload" />
      <EmptyState v-else-if="!list.items.value.length" icon="list" :title="t('bill.transactions.empty')" />
      <div v-else class="overflow-x-auto">
        <table class="data-table min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
            <tr>
              <th class="px-4 py-2">{{ t('bill.transactions.at') }}</th><th class="px-4 py-2">{{ t('bill.transactions.type') }}</th>
              <th class="px-4 py-2">{{ t('bill.transactions.description') }}</th><th class="px-4 py-2 text-right">{{ t('bill.transactions.amount') }}</th>
              <th class="px-4 py-2 text-right">{{ t('bill.transactions.balanceAfter') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="e in list.items.value" :key="e.id">
              <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ formatDateTime(e.created_at) }}</td>
              <td class="px-4 py-2">{{ t(`bill.transactions.types.${e.entry_type}`) }}</td>
              <td class="px-4 py-2 text-ink-muted">{{ e.description ?? '—' }}</td>
              <td class="px-4 py-2 text-right tabular-nums" :class="e.amount_mc < 0 ? 'text-danger-600' : 'text-success-600'">{{ e.amount_mc > 0 ? '+' : '' }}{{ formatCredits(e.amount_mc) }}</td>
              <td class="px-4 py-2 text-right tabular-nums">{{ formatCredits(e.balance_after_mc) }}</td>
            </tr>
          </tbody>
        </table>
        <div v-if="list.next.value" class="p-4 text-center"><AppButton variant="secondary" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton></div>
      </div>
    </AppCard>
  </div>
</template>
