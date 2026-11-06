<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { billingApi, formatCredits, formatMoney, idempotencyKey } from '@/shared/api/billing'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDate } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import StatCard from '@/shared/ui/StatCard.vue'
import BillingNav from './BillingNav.vue'
import { useBilling } from './useBilling'

const { t } = useI18n()
const router = useRouter()
const { workspaceId, canManage, toastError } = useBilling()
const { data, error, loading, run } = useAsync(async () => {
  const [wallet, packages] = await Promise.all([billingApi.wallet(workspaceId.value), billingApi.packages(workspaceId.value)])
  return { ...wallet, packages }
})
const buying = ref<string | null>(null)

async function buy(code: string) {
  buying.value = code
  try {
    const order = await billingApi.buyCredits(workspaceId.value, { package_code: code, idempotency_key: idempotencyKey() })
    await router.push({ name: 'billing-checkout', params: { workspaceId: workspaceId.value, orderId: order.id } })
  } catch (e) {
    toastError(e)
  } finally {
    buying.value = null
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('bill.title')" :description="t('bill.credits.subtitle')" />
    <BillingNav active="credits" />
    <LoadingState v-if="loading && !data" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <div v-else-if="data" class="space-y-6">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard :label="t('bill.credits.available')" :value="formatCredits(data.wallet.available_mc)" icon="coins" data-testid="available" />
        <StatCard :label="t('bill.credits.balance')" :value="formatCredits(data.wallet.balance_mc)" icon="layers" />
        <StatCard :label="t('bill.credits.held')" :value="formatCredits(data.wallet.held_mc)" icon="clock" />
      </div>

      <AppCard :title="t('bill.credits.buy')">
        <p v-if="!canManage" class="text-sm text-ink-muted">{{ t('bill.common.ownerOnly') }}</p>
        <EmptyState v-else-if="!data.packages.length" icon="coins" :title="t('bill.credits.noPackages')" />
        <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div v-for="p in data.packages" :key="p.id" class="rounded-lg border border-line p-4" :data-testid="`package-${p.code}`">
            <div class="flex items-start justify-between gap-2">
              <p class="font-semibold">{{ p.name }}</p>
              <AppBadge v-if="p.is_demo" tone="warning">{{ t('bill.common.demo') }}</AppBadge>
            </div>
            <p class="mt-2 text-xl font-semibold tabular-nums">{{ formatCredits(p.credits_mc) }} <span class="text-sm font-normal text-ink-muted">{{ t('bill.common.credits') }}</span></p>
            <p v-if="p.bonus_credits_mc" class="text-sm text-success-600">{{ t('bill.credits.bonus', { n: formatCredits(p.bonus_credits_mc) }) }}</p>
            <p class="text-xs text-ink-muted">{{ p.validity_days ? t('bill.credits.validity', { n: p.validity_days }) : t('bill.credits.noExpiry') }}</p>
            <div class="mt-3 flex items-center justify-between gap-2">
              <span class="font-medium tabular-nums">{{ formatMoney(p.price_minor, p.currency) }}</span>
              <AppButton size="sm" :loading="buying === p.code" :data-testid="`buy-${p.code}`" @click="buy(p.code)">{{ t('bill.credits.buyNow') }}</AppButton>
            </div>
          </div>
        </div>
      </AppCard>

      <AppCard :title="t('bill.credits.grants')" :padded="false">
        <EmptyState v-if="!data.grants.length" icon="coins" :title="t('bill.credits.noGrants')" />
        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
              <tr><th class="px-4 py-2">{{ t('bill.credits.source') }}</th><th class="px-4 py-2 text-right">{{ t('bill.credits.remaining') }}</th><th class="px-4 py-2">{{ t('bill.credits.expires') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="g in data.grants" :key="g.id">
                <td class="px-4 py-2">{{ t(`bill.credits.sources.${g.source}`) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ formatCredits(g.remaining_mc) }} / {{ formatCredits(g.amount_mc) }}</td>
                <td class="px-4 py-2">{{ g.expires_at ? formatDate(g.expires_at) : t('bill.credits.never') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>
    </div>
  </div>
</template>
