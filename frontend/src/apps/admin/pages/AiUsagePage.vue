<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminAiApi, formatTokens, formatUsd, type AiRequestLog } from '@/shared/api/ai'
import { useAsync } from '@/shared/composables/useAsync'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'

const { t } = useI18n()
const days = ref('30')
const dayOptions = computed(() => ['1', '7', '30', '90'].map((d) => ({ value: d, label: t('aiAdmin.days', { n: d }) })))
const report = useAsync(() => adminAiApi.usage({ days: Number(days.value) }))
watch(days, () => void report.run())

const onlyFailed = ref(false)
const log = useCursorList<AiRequestLog>((cursor) => adminAiApi.requests({ cursor, failed: onlyFailed.value ? '1' : undefined }))
void log.reload()
watch(onlyFailed, () => void log.reload())

const d = computed(() => report.data.value)
const errorRate = computed(() => {
  const tot = d.value?.totals
  return tot && tot.requests > 0 ? Math.round((tot.failed * 1000) / tot.requests) / 10 : 0
})
const maxDay = computed(() => Math.max(1, ...(d.value?.by_day ?? []).map((r) => r.cost_micro_usd)))
const statusTone = (s: AiRequestLog['status']) => ({ succeeded: 'success', failed: 'danger', skipped: 'neutral', blocked: 'warning' } as const)[s]
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">{{ $t('aiAdmin.usageTitle') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ $t('aiAdmin.usageSubtitle') }}</p>
      </div>
      <AppSelect v-model="days" class="w-36" :options="dayOptions" />
    </div>

    <LoadingState v-if="report.loading.value && !d" :rows="3" />
    <ErrorState v-else-if="report.error.value" :error="report.error.value" @retry="report.run" />
    <template v-else-if="d">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="usage-totals">
        <AppCard><p class="text-sm text-ink-muted">{{ $t('aiAdmin.totalCost') }}</p><p class="mt-1 text-3xl font-bold">{{ formatUsd(d.totals.cost_micro_usd) }}</p></AppCard>
        <AppCard><p class="text-sm text-ink-muted">{{ $t('aiAdmin.totalRequests') }}</p><p class="mt-1 text-3xl font-bold">{{ d.totals.requests }}</p></AppCard>
        <AppCard><p class="text-sm text-ink-muted">{{ $t('aiAdmin.tokensInOut') }}</p><p class="mt-1 text-xl font-bold">{{ formatTokens(d.totals.input_tokens + d.totals.cached_input_tokens) }} / {{ formatTokens(d.totals.output_tokens) }}</p></AppCard>
        <AppCard><p class="text-sm text-ink-muted">{{ $t('aiAdmin.errorRate') }}</p><p class="mt-1 text-3xl font-bold">{{ errorRate }}%</p></AppCard>
      </div>

      <AppCard :title="$t('aiAdmin.byDay')">
        <p v-if="!d.by_day.length" class="text-sm text-ink-muted">{{ $t('aiAdmin.noData') }}</p>
        <div v-else class="flex h-40 items-end gap-1" role="img" :aria-label="$t('aiAdmin.byDay')">
          <div v-for="r in d.by_day" :key="r.date" class="group relative max-w-10 flex-1">
            <div class="w-full rounded-t bg-brand-500" :style="{ height: Math.max(2, (r.cost_micro_usd / maxDay) * 150) + 'px' }" :title="`${r.date}: ${formatUsd(r.cost_micro_usd)} · ${r.requests}`" />
          </div>
        </div>
      </AppCard>

      <div class="grid gap-6 lg:grid-cols-2">
        <AppCard :title="$t('aiAdmin.byProvider')" :padded="false">
          <table class="w-full text-sm">
            <tbody class="divide-y divide-line">
              <tr v-for="r in d.by_provider ?? []" :key="r.provider"><td class="px-5 py-2 font-medium">{{ r.provider }}</td><td class="px-5 py-2 text-right">{{ r.requests }}</td><td class="px-5 py-2 text-right">{{ formatUsd(r.cost_micro_usd) }}</td></tr>
              <tr v-if="!(d.by_provider ?? []).length"><td class="px-5 py-3 text-ink-muted">{{ $t('aiAdmin.noData') }}</td></tr>
            </tbody>
          </table>
        </AppCard>
        <AppCard :title="$t('aiAdmin.byTask')" :padded="false">
          <table class="w-full text-sm">
            <tbody class="divide-y divide-line">
              <tr v-for="r in d.by_task" :key="r.task_key"><td class="px-5 py-2 font-mono text-xs">{{ r.task_key }}</td><td class="px-5 py-2 text-right">{{ r.requests }}</td><td class="px-5 py-2 text-right">{{ formatUsd(r.cost_micro_usd) }}</td></tr>
              <tr v-if="!d.by_task.length"><td class="px-5 py-3 text-ink-muted">{{ $t('aiAdmin.noData') }}</td></tr>
            </tbody>
          </table>
        </AppCard>
        <AppCard :title="$t('aiAdmin.byModel')" :padded="false">
          <table class="w-full text-sm">
            <tbody class="divide-y divide-line">
              <tr v-for="r in d.by_model" :key="r.provider + r.model">
                <td class="px-5 py-2"><span class="text-ink-muted">{{ r.provider }}/</span>{{ r.model }}</td>
                <td class="px-5 py-2 text-right text-xs">{{ formatTokens(r.input_tokens) }} / {{ formatTokens(r.output_tokens) }}</td>
                <td class="px-5 py-2 text-right">{{ formatUsd(r.cost_micro_usd) }}</td>
              </tr>
              <tr v-if="!d.by_model.length"><td class="px-5 py-3 text-ink-muted">{{ $t('aiAdmin.noData') }}</td></tr>
            </tbody>
          </table>
        </AppCard>
        <AppCard :title="$t('aiAdmin.byWorkspace')" :padded="false">
          <table class="w-full text-sm">
            <tbody class="divide-y divide-line">
              <tr v-for="r in d.by_workspace ?? []" :key="r.workspace_id ?? 'none'"><td class="px-5 py-2">{{ r.workspace_name ?? '—' }}</td><td class="px-5 py-2 text-right">{{ r.requests }}</td><td class="px-5 py-2 text-right">{{ formatUsd(r.cost_micro_usd) }}</td></tr>
              <tr v-if="!(d.by_workspace ?? []).length"><td class="px-5 py-3 text-ink-muted">{{ $t('aiAdmin.noData') }}</td></tr>
            </tbody>
          </table>
        </AppCard>
      </div>

      <AppCard v-if="d.errors.length" :title="$t('aiAdmin.errors')">
        <div class="flex flex-wrap gap-2">
          <AppBadge v-for="e in d.errors" :key="e.error_code" tone="danger">{{ e.error_code }} · {{ e.count }}</AppBadge>
        </div>
      </AppCard>
    </template>

    <AppCard :title="$t('aiAdmin.requestLog')" :subtitle="$t('aiAdmin.requestLogHint')" :padded="false">
      <template #actions>
        <label class="flex items-center gap-2 text-sm"><input v-model="onlyFailed" type="checkbox" class="rounded border-line" /> {{ $t('aiAdmin.onlyFailed') }}</label>
      </template>
      <LoadingState v-if="log.loading.value" class="p-5" />
      <ErrorState v-else-if="log.error.value" :error="log.error.value" class="p-5" @retry="log.reload" />
      <EmptyState v-else-if="!log.items.value.length" icon="activity" :title="$t('aiAdmin.noData')" />
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-sm" data-testid="request-log">
          <thead class="text-left text-xs text-ink-muted">
            <tr><th class="px-5 py-2">{{ $t('ai.createdAt') }}</th><th class="px-3 py-2">Workspace</th><th class="px-3 py-2">{{ $t('ai.model') }}</th><th class="px-3 py-2"></th><th class="px-3 py-2 text-right">{{ $t('ai.tokensIn') }} / {{ $t('ai.tokensOut') }}</th><th class="px-3 py-2 text-right">{{ $t('ai.cost') }}</th><th class="px-5 py-2 text-right">{{ $t('aiAdmin.latency') }}</th></tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="r in log.items.value" :key="r.id">
              <td class="px-5 py-2 text-xs">{{ formatDateTime(r.created_at) }}</td>
              <td class="px-3 py-2">{{ r.workspace?.name ?? '—' }}</td>
              <td class="px-3 py-2"><span class="text-ink-muted">{{ r.provider }}/</span>{{ r.model }} <span v-if="r.is_fallback" class="text-xs text-warning-600">({{ $t('aiAdmin.fallback') }})</span></td>
              <td class="px-3 py-2">
                <AppBadge :tone="statusTone(r.status)">{{ $t(`aiAdmin.reqStatuses.${r.status}`) }}</AppBadge>
                <span v-if="r.error" class="ml-1 font-mono text-xs text-danger-600" :title="r.error.message ?? ''">{{ r.error.code }}</span>
                <AppBadge v-if="r.billing_uncertain" tone="warning" class="ml-1">{{ $t('aiAdmin.billingUncertain') }}</AppBadge>
              </td>
              <td class="px-3 py-2 text-right text-xs">{{ formatTokens(r.usage.input_tokens + r.usage.cached_input_tokens) }} / {{ formatTokens(r.usage.output_tokens) }}</td>
              <td class="px-3 py-2 text-right">{{ formatUsd(r.cost_micro_usd) }}</td>
              <td class="px-5 py-2 text-right text-xs">{{ r.latency_ms }} ms</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="log.next.value" class="border-t border-line p-3 text-center">
        <AppButton variant="ghost" size="sm" :loading="log.loadingMore.value" @click="log.more">{{ $t('common.loadMore') }}</AppButton>
      </div>
    </AppCard>
  </div>
</template>
