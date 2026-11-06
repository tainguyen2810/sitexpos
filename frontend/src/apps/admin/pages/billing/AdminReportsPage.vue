<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatCredits, formatMicroUsd, formatMoney } from '@/shared/api/billing'
import { billingAdminApi } from '@/shared/api/billingAdmin'
import { useAsync } from '@/shared/composables/useAsync'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import StatCard from '@/shared/ui/StatCard.vue'
import Tabs from '@/shared/ui/Tabs.vue'
import AdminBillingNav from './AdminBillingNav.vue'

const { t } = useI18n()
const tab = ref('revenue')
const today = new Date()
const from = ref(new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), 1)).toISOString().slice(0, 10))
const to = ref(today.toISOString().slice(0, 10))
const revenue = useAsync(() => billingAdminApi.revenue(from.value, to.value), { immediate: false })
const costs = useAsync(() => billingAdminApi.costs(from.value, to.value), { immediate: false })
const profit = useAsync(() => billingAdminApi.profitability(from.value, to.value), { immediate: false })
const current = computed(() => (tab.value === 'revenue' ? revenue : tab.value === 'costs' ? costs : profit))
function load() {
  return current.value.run()
}
watch(tab, load, { immediate: true })
const pct = (bps: number | null | undefined) => (bps === null || bps === undefined ? '—' : `${(bps / 100).toFixed(1).replace('.', ',')}%`)
const maxTrend = computed(() => Math.max(1, ...(costs.data.value?.trend.map((x) => x.cost_micro_usd) ?? [1])))
</script>

<template>
  <div>
    <PageHeader :title="t('billAdmin.title')" :description="t('billAdmin.reports.subtitle')" />
    <AdminBillingNav active="reports" />
    <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
      <Tabs v-model="tab" :tabs="[{ key: 'revenue', label: t('billAdmin.reports.tabRevenue') }, { key: 'costs', label: t('billAdmin.reports.tabCosts') }, { key: 'profit', label: t('billAdmin.reports.tabProfit') }]" />
      <form class="flex items-end gap-2" @submit.prevent="load">
        <AppInput v-model="from" type="date" :label="t('billAdmin.common.from')" />
        <AppInput v-model="to" type="date" :label="t('billAdmin.common.to')" />
        <AppButton type="submit" variant="secondary">{{ t('billAdmin.common.apply') }}</AppButton>
      </form>
    </div>
    <LoadingState v-if="current.loading.value" />
    <ErrorState v-else-if="current.error.value" :error="current.error.value" @retry="load" />

    <div v-else-if="tab === 'revenue' && revenue.data.value" class="space-y-6">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard :label="t('billAdmin.reports.cash')" :value="formatMoney(revenue.data.value.cash.net_minor, revenue.data.value.currency)" icon="coins" :hint="`${t('billAdmin.reports.refunds')}: ${formatMoney(revenue.data.value.cash.refunds_minor, revenue.data.value.currency)}`" />
        <StatCard :label="t('billAdmin.reports.recognized')" :value="formatMoney(revenue.data.value.recognized.total_minor, revenue.data.value.currency)" icon="chart" />
        <StatCard :label="t('billAdmin.reports.deferred')" :value="formatMoney(revenue.data.value.deferred_subscription_minor, revenue.data.value.currency)" icon="clock" />
        <StatCard :label="t('billAdmin.reports.liability')" :value="formatMoney(revenue.data.value.credit_liability_minor, revenue.data.value.currency)" icon="layers" />
      </div>
      <div class="grid gap-4 sm:grid-cols-3">
        <StatCard :label="t('billAdmin.reports.aiCost')" :value="revenue.data.value.costs.ai_cost_minor === null ? formatMicroUsd(revenue.data.value.costs.total_micro_usd) : formatMoney(revenue.data.value.costs.ai_cost_minor, revenue.data.value.currency)" icon="cpu" />
        <StatCard :label="t('billAdmin.reports.profit')" :value="revenue.data.value.gross_profit_minor === null ? '—' : formatMoney(revenue.data.value.gross_profit_minor, revenue.data.value.currency)" icon="trending" />
        <StatCard :label="t('billAdmin.reports.margin')" :value="pct(revenue.data.value.gross_margin_bps)" icon="activity" />
      </div>
      <p v-if="revenue.data.value.exchange_rate === null" class="rounded-lg bg-warning-50 px-4 py-2 text-sm text-warning-600">{{ t('billAdmin.reports.noRate') }}</p>
      <AppCard :title="t('billAdmin.reports.recognized')">
        <dl class="grid gap-2 text-sm sm:grid-cols-2">
          <div class="flex justify-between"><dt class="text-ink-muted">{{ t('billAdmin.reports.subscriptions') }}</dt><dd class="tabular-nums">{{ formatMoney(revenue.data.value.recognized.subscription_minor, revenue.data.value.currency) }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-muted">{{ t('billAdmin.reports.creditsRev') }}</dt><dd class="tabular-nums">{{ formatMoney(revenue.data.value.recognized.credits_minor, revenue.data.value.currency) }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-muted">{{ t('billAdmin.reports.refunds') }}</dt><dd class="tabular-nums">{{ formatMoney(revenue.data.value.recognized.refunds_minor, revenue.data.value.currency) }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-muted">{{ t('billAdmin.reports.gross') }}</dt><dd class="tabular-nums">{{ formatMoney(revenue.data.value.cash.gross_minor, revenue.data.value.currency) }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-muted">{{ t('billAdmin.reports.fees') }}</dt><dd class="tabular-nums">{{ formatMoney(revenue.data.value.costs.payment_fees_minor, revenue.data.value.currency) }}</dd></div>
        </dl>
      </AppCard>
    </div>

    <div v-else-if="tab === 'costs' && costs.data.value" class="space-y-6">
      <div class="grid gap-4 sm:grid-cols-2">
        <StatCard :label="t('billAdmin.reports.aiCost')" :value="formatMicroUsd(costs.data.value.total_micro_usd)" icon="cpu" />
        <StatCard :label="t('billAdmin.reports.uncertain')" :value="formatMicroUsd(costs.data.value.uncertain_micro_usd)" icon="alert" />
      </div>
      <EmptyState v-if="!costs.data.value.total_micro_usd" icon="chart" :title="t('bill.common.noData')" />
      <template v-else>
        <div class="grid gap-6 lg:grid-cols-2">
          <AppCard :title="t('billAdmin.reports.byCategory')">
            <ul class="space-y-2 text-sm"><li v-for="(v, k) in costs.data.value.by_category" :key="k" class="flex justify-between"><span>{{ t(`bill.usage.categories.${k}`) }}</span><span class="tabular-nums">{{ formatMicroUsd(v) }}</span></li></ul>
          </AppCard>
          <AppCard :title="t('billAdmin.reports.byModel')">
            <ul class="space-y-2 text-sm"><li v-for="m in costs.data.value.by_model" :key="m.provider + m.model" class="flex justify-between gap-2"><span>{{ m.provider }}/{{ m.model }} <span class="text-ink-muted">· {{ m.requests }}</span></span><span class="tabular-nums">{{ formatMicroUsd(m.cost_micro_usd) }}</span></li></ul>
          </AppCard>
        </div>
        <AppCard :title="t('billAdmin.reports.trend')">
          <div class="flex h-40 items-end gap-1" role="img" :aria-label="t('billAdmin.reports.trend')">
            <div v-for="d in costs.data.value.trend" :key="d.day" class="flex h-full min-w-2 max-w-12 flex-1 flex-col justify-end" :title="`${d.day}: ${formatMicroUsd(d.cost_micro_usd)}`">
              <div class="w-full rounded-t bg-brand-500" :style="{ height: `${Math.max(2, (d.cost_micro_usd / maxTrend) * 100)}%` }" />
            </div>
          </div>
        </AppCard>
      </template>
    </div>

    <div v-else-if="tab === 'profit' && profit.data.value" class="space-y-6">
      <p class="text-sm text-ink-muted">{{ t('billAdmin.reports.noAutoLock') }}</p>
      <p v-if="profit.data.value.exchange_rate === null" class="rounded-lg bg-warning-50 px-4 py-2 text-sm text-warning-600">{{ t('billAdmin.reports.noRate') }}</p>
      <AppCard :title="t('billAdmin.reports.byPlan')" :padded="false">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted"><tr><th class="px-4 py-2">{{ t('billAdmin.subs.plan') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.reports.tenants') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.reports.recognized') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.reports.aiCost') }}</th></tr></thead>
          <tbody class="divide-y divide-line"><tr v-for="p in profit.data.value.by_plan" :key="p.plan_code ?? 'none'"><td class="px-4 py-2">{{ p.plan_code ?? '—' }}</td><td class="px-4 py-2 text-right">{{ p.tenants }}</td><td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(p.recognized_minor, profit.data.value.currency) }}</td><td class="px-4 py-2 text-right tabular-nums">{{ formatMicroUsd(p.ai_cost_micro_usd) }}</td></tr></tbody>
        </table>
      </AppCard>
      <AppCard :title="t('billAdmin.reports.tenant')" :padded="false">
        <EmptyState v-if="!profit.data.value.tenants.length" icon="chart" :title="t('bill.common.noData')" />
        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted"><tr><th class="px-4 py-2">{{ t('billAdmin.reports.tenant') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.reports.recognized') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.reports.aiCost') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.reports.creditsRev') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.reports.profit') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.reports.margin') }}</th><th class="px-4 py-2">{{ t('billAdmin.reports.alerts') }}</th></tr></thead>
            <tbody class="divide-y divide-line">
              <tr v-for="r in profit.data.value.tenants" :key="r.workspace_id">
                <td class="px-4 py-2"><RouterLink :to="`/workspaces/${r.workspace_id}`" class="hover:underline">{{ r.workspace_name ?? r.workspace_id }}</RouterLink><span class="block text-xs text-ink-muted">{{ r.plan_code ?? '—' }}</span></td>
                <td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(r.recognized_minor, profit.data.value.currency) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ formatMicroUsd(r.ai_cost_micro_usd) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ formatCredits(r.credits_consumed_mc) }}</td>
                <td class="px-4 py-2 text-right tabular-nums" :class="(r.gross_profit_minor ?? 0) < 0 ? 'text-danger-600' : ''">{{ r.gross_profit_minor === null ? '—' : formatMoney(r.gross_profit_minor, profit.data.value.currency) }}</td>
                <td class="px-4 py-2 text-right">{{ pct(r.margin_bps) }}</td>
                <td class="px-4 py-2"><AppBadge v-for="f in r.flags" :key="f" :tone="f === 'heavy_user' ? 'warning' : 'danger'" class="mr-1">{{ t(`billAdmin.reports.flags.${f}`) }}</AppBadge></td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>
    </div>
  </div>
</template>
