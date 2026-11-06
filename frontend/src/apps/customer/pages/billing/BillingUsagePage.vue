<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { billingApi, formatCredits, RESOURCES } from '@/shared/api/billing'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDate } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import BillingNav from './BillingNav.vue'
import { useBilling } from './useBilling'

const { t } = useI18n()
const { workspaceId } = useBilling()
const { data, error, loading, run } = useAsync(() => billingApi.usage(workspaceId.value))
const maxDaily = computed(() => Math.max(1, ...(data.value?.daily.map((d) => d.charged_mc) ?? [1])))
const uncertain = computed(() => data.value?.by_category.reduce((n, c) => n + c.uncertain_calls, 0) ?? 0)
const monthly = computed(() => RESOURCES.filter((r) => data.value?.entitlements[r]?.period === 'month'))
</script>

<template>
  <div>
    <PageHeader :title="t('bill.title')" :description="t('bill.usage.subtitle')" />
    <BillingNav active="usage" />
    <LoadingState v-if="loading && !data" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <div v-else-if="data" class="grid gap-6 lg:grid-cols-2">
      <AppCard :title="t('bill.usage.byCategory')" :subtitle="formatDate(data.period_start)" :padded="false">
        <EmptyState v-if="!data.by_category.length" icon="sparkles" :title="t('bill.usage.empty')" />
        <table v-else class="min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
            <tr><th class="px-4 py-2" /><th class="px-4 py-2 text-right">{{ t('bill.usage.calls') }}</th><th class="px-4 py-2 text-right">{{ t('bill.usage.charged') }}</th></tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="c in data.by_category" :key="c.category">
              <td class="px-4 py-2">{{ t(`bill.usage.categories.${c.category}`) }}</td>
              <td class="px-4 py-2 text-right tabular-nums">{{ c.calls }}</td>
              <td class="px-4 py-2 text-right tabular-nums">{{ formatCredits(c.charged_mc) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-if="uncertain" class="border-t border-line px-4 py-3 text-xs text-warning-600">{{ t('bill.usage.uncertain') }}: {{ uncertain }} — {{ t('bill.usage.uncertainHint') }}</p>
      </AppCard>

      <AppCard :title="t('bill.usage.limits')">
        <p v-if="!monthly.length" class="text-sm text-ink-muted">{{ t('bill.common.noData') }}</p>
        <ul v-else class="space-y-3 text-sm">
          <li v-for="r in monthly" :key="r">
            <div class="mb-1 flex justify-between"><span>{{ t(`bill.resources.${r}`) }}</span><span class="tabular-nums text-ink-muted">{{ data.usage[r] ?? 0 }} / {{ (data.entitlements[r]?.limit ?? 0) < 0 ? t('bill.common.unlimited') : data.entitlements[r]?.limit }}</span></div>
            <ProgressBar v-if="(data.entitlements[r]?.limit ?? 0) > 0" :value="data.usage[r] ?? 0" :max="data.entitlements[r]!.limit" tone="auto" :label="t(`bill.resources.${r}`)" />
          </li>
        </ul>
      </AppCard>

      <AppCard :title="t('bill.usage.daily')" class="lg:col-span-2">
        <EmptyState v-if="!data.daily.length" icon="chart" :title="t('bill.usage.empty')" />
        <div v-else class="flex h-48 items-end gap-1 overflow-x-auto" role="img" :aria-label="t('bill.usage.daily')">
          <!-- Bars keep a fixed maximum width so a single day doesn't fill the whole card. -->
          <div v-for="d in data.daily" :key="d.day" class="flex h-full min-w-6 max-w-12 flex-1 flex-col items-center justify-end gap-1" :title="`${formatDate(d.day)}: ${formatCredits(d.charged_mc)}`">
            <span class="text-[10px] tabular-nums text-ink-muted">{{ formatCredits(d.charged_mc) }}</span>
            <div class="w-full rounded-t bg-brand-500" :style="{ height: `${Math.max(2, (d.charged_mc / maxDaily) * 75)}%` }" />
            <span class="text-[10px] tabular-nums text-ink-muted">{{ d.day.slice(8, 10) }}</span>
          </div>
        </div>
      </AppCard>
    </div>
  </div>
</template>
