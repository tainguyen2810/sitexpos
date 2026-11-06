<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { analyticsApi, num, signedPct, type Report, type ReportPayload } from '@/shared/api/analytics'
import { workspaceApi } from '@/shared/api/workspaces'
import type { Member } from '@/shared/api/types'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDate, formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import Drawer from '@/shared/ui/Drawer.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AnalyticsNav from './AnalyticsNav.vue'
import { useAnalytics } from './useAnalytics'

/**
 * Weekly / monthly / custom SEO reports built from stored data only.
 * Email goes only to active members of this workspace (checked again by
 * the API); CSV is formula-safe.
 */
const { t } = useI18n()
const { workspaceId, siteId, onSite, canEdit, toast, toastError } = useAnalytics()
const { data, error, loading, run } = useAsync(() => analyticsApi.reports(workspaceId.value, siteId.value || undefined))
onSite(run)
const creating = ref(false)
const period = ref<'weekly' | 'monthly' | 'custom'>('weekly')
const range = ref({ start: '', end: '' })
const selected = shallowRef<Report | null>(null)
const members = shallowRef<Member[]>([])
const emailOpen = ref(false)
const recipients = ref<string[]>([])
const sending = ref(false)
const payload = computed(() => (selected.value?.payload ?? {}) as ReportPayload)
const csvHref = (id: string) => `${(import.meta.env.VITE_API_BASE_URL ?? '').replace(/\/$/, '')}${analyticsApi.csvUrl(workspaceId.value, id)}`

async function create() {
  creating.value = true
  try {
    const body = { site_id: siteId.value || null, period_type: period.value, ...(period.value === 'custom' ? { period_start: range.value.start, period_end: range.value.end } : {}) }
    const r = (await analyticsApi.createReport(workspaceId.value, body)).data
    toast.success(t('an.reports.created'))
    await run()
    await open(r)
  } catch (e) {
    toastError(e)
  } finally {
    creating.value = false
  }
}
async function open(r: Report) {
  try {
    selected.value = (await analyticsApi.report(workspaceId.value, r.id)).data
  } catch (e) {
    toastError(e)
  }
}
async function openEmail() {
  try {
    members.value = (await workspaceApi.members(workspaceId.value)).filter((m) => m.status.toLowerCase() === 'active')
    recipients.value = []
    emailOpen.value = true
  } catch (e) {
    toastError(e)
  }
}
async function send() {
  if (!selected.value) return
  sending.value = true
  try {
    selected.value = { ...selected.value, ...(await analyticsApi.emailReport(workspaceId.value, selected.value.id, recipients.value)).data }
    toast.success(t('an.reports.sent'))
    emailOpen.value = false
    await run()
  } catch (e) {
    toastError(e)
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.reports.subtitle')" />
    <AnalyticsNav active="reports" all-sites />
    <AppCard v-if="canEdit" :title="t('an.reports.new')" class="mb-6">
      <form class="flex flex-wrap items-end gap-3 text-sm" @submit.prevent="create">
        <label class="flex flex-col gap-1">
          <span class="text-xs text-ink-muted">{{ t('an.reports.period') }}</span>
          <select v-model="period" class="rounded-lg border border-line bg-surface px-2 py-1.5">
            <option v-for="p in ['weekly', 'monthly', 'custom']" :key="p" :value="p">{{ t(`an.reports.periods.${p}`) }}</option>
          </select>
        </label>
        <template v-if="period === 'custom'">
          <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('an.filters.from') }}</span><input v-model="range.start" type="date" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
          <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ t('an.filters.to') }}</span><input v-model="range.end" type="date" class="rounded-lg border border-line bg-surface px-2 py-1.5" /></label>
        </template>
        <AppButton type="submit" :loading="creating">{{ t('an.reports.generate') }}</AppButton>
      </form>
    </AppCard>
    <AppCard :padded="false">
      <LoadingState v-if="loading && !data" />
      <ErrorState v-else-if="error" :error="error" @retry="run" />
      <EmptyState v-else-if="!data?.data.length" icon="file" :title="t('an.reports.empty')" />
      <table v-else class="min-w-full text-sm">
        <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
          <tr>
            <th class="px-4 py-2">{{ t('an.reports.period') }}</th>
            <th class="px-4 py-2">{{ t('an.common.status') }}</th>
            <th class="px-4 py-2">{{ t('an.reports.emailed') }}</th>
            <th class="px-4 py-2" />
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="r in data.data" :key="r.id">
            <td class="px-4 py-2">
              {{ t(`an.reports.periods.${r.period_type}`) }}: {{ formatDate(r.period_start) }} – {{ formatDate(r.period_end) }}
              <AppBadge v-if="r.is_demo" class="ml-1">DEMO</AppBadge>
            </td>
            <td class="px-4 py-2"><AppBadge :tone="r.status === 'ready' ? 'success' : r.status === 'failed' ? 'danger' : 'brand'">{{ t(`an.reports.status.${r.status}`, r.status) }}</AppBadge></td>
            <td class="px-4 py-2">{{ r.emailed_at ? `${formatDateTime(r.emailed_at)} (${r.recipients_count})` : '—' }}</td>
            <td class="px-4 py-2 text-right">
              <AppButton size="sm" variant="ghost" :disabled="r.status !== 'ready'" @click="open(r)">{{ t('an.reports.view') }}</AppButton>
              <a v-if="r.status === 'ready'" :href="csvHref(r.id)" class="ml-2 text-sm text-brand-700 hover:underline" download>CSV</a>
            </td>
          </tr>
        </tbody>
      </table>
    </AppCard>

    <Drawer :open="!!selected" :title="t('an.reports.report')" :subtitle="selected ? `${formatDate(selected.period_start)} – ${formatDate(selected.period_end)}` : ''" @close="selected = null">
      <div v-if="selected" class="space-y-5 text-sm" data-testid="report-detail">
        <p class="rounded-lg bg-surface-muted p-3 text-xs text-ink-muted">{{ payload.data_note ?? t('an.reports.dataNote') }}</p>
        <section>
          <h3 class="mb-1 font-medium">{{ t('an.reports.performance') }}</h3>
          <p v-if="!payload.performance" class="text-ink-muted">{{ t('an.common.noData') }}</p>
          <p v-else class="tabular-nums">
            {{ num(payload.performance.current?.clicks) }} {{ t('an.metrics.clicks') }} ({{ signedPct(payload.performance.clicks_change_pct) }}) ·
            {{ num(payload.performance.current?.impressions) }} {{ t('an.metrics.impressions') }} ({{ signedPct(payload.performance.impressions_change_pct) }})
          </p>
        </section>
        <section v-for="k in ['growing_pages', 'declining_pages']" :key="k">
          <h3 class="mb-1 font-medium">{{ t(`an.reports.${k}`) }}</h3>
          <p v-if="!payload[k]?.length" class="text-ink-muted">{{ t('an.common.noData') }}</p>
          <ul v-else class="list-disc pl-5 text-xs">
            <li v-for="p in payload[k]" :key="p.page_url" class="break-all">{{ p.page_url }} — {{ num(p.clicks) }} {{ t('an.metrics.clicks') }}</li>
          </ul>
        </section>
        <section>
          <h3 class="mb-1 font-medium">{{ t('an.nav.opportunities') }}</h3>
          <p v-if="!payload.opportunities?.length" class="text-ink-muted">{{ t('an.common.noData') }}</p>
          <ul v-else class="list-disc pl-5 text-xs">
            <li v-for="o in payload.opportunities" :key="o.id">{{ t(`an.rules.${o.rule_code}.name`, o.rule_code) }} — {{ o.target_label }} ({{ t(`an.severity.${o.severity}`, o.severity) }})</li>
          </ul>
        </section>
        <section>
          <h3 class="mb-1 font-medium">{{ t('an.reports.completed') }}</h3>
          <p class="text-xs">{{ t('an.reports.completedLine', { r: payload.completed_optimizations?.refreshes ?? 0, a: payload.completed_optimizations?.recommendations_applied ?? 0 }) }}</p>
        </section>
        <section>
          <h3 class="mb-1 font-medium">{{ t('an.reports.agent') }}</h3>
          <p class="text-xs">{{ t('an.reports.agentLine', { runs: payload.agent?.runs ?? 0, ok: payload.agent?.tasks_succeeded ?? 0, failed: payload.agent?.tasks_failed ?? 0, waiting: payload.agent?.tasks_awaiting_approval ?? 0 }) }}</p>
        </section>
        <section>
          <h3 class="mb-1 font-medium">{{ t('an.reports.pending') }}</h3>
          <p class="text-xs">{{ t('an.reports.pendingLine', { r: payload.pending_approvals?.recommendations ?? 0, f: payload.pending_approvals?.refreshes ?? 0, a: payload.pending_approvals?.agent_tasks ?? 0 }) }}</p>
        </section>
        <section>
          <h3 class="mb-1 font-medium">{{ t('an.reports.next') }}</h3>
          <p v-if="!payload.next_actions?.length" class="text-ink-muted">{{ t('an.common.noData') }}</p>
          <ol v-else class="list-decimal pl-5 text-xs">
            <li v-for="n in payload.next_actions" :key="n.id">{{ t(`an.actions.${n.type}`, n.type) }} — {{ n.target_label }}</li>
          </ol>
        </section>
        <div class="flex gap-2 border-t border-line pt-4">
          <a :href="csvHref(selected.id)" class="rounded-lg border border-line px-3 py-1.5 text-sm hover:bg-surface-muted" download>{{ t('an.reports.csv') }}</a>
          <AppButton v-if="canEdit" size="sm" @click="openEmail">{{ t('an.reports.email') }}</AppButton>
        </div>
      </div>
    </Drawer>

    <AppModal :open="emailOpen" :title="t('an.reports.email')" @close="emailOpen = false">
      <p class="mb-3 text-sm text-ink-muted">{{ t('an.reports.emailHint') }}</p>
      <ul class="max-h-64 space-y-1 overflow-auto text-sm">
        <li v-for="m in members" :key="m.user.id">
          <label class="flex items-center gap-2"><input v-model="recipients" type="checkbox" :value="m.user.id" /> {{ m.user.name }} <span class="text-xs text-ink-muted">{{ m.user.email }}</span></label>
        </li>
      </ul>
      <div class="mt-4 flex justify-end gap-2">
        <AppButton variant="secondary" @click="emailOpen = false">{{ t('common.cancel') }}</AppButton>
        <AppButton :disabled="!recipients.length" :loading="sending" @click="send">{{ t('an.reports.send') }}</AppButton>
      </div>
    </AppModal>
  </div>
</template>
