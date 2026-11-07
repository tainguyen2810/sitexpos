<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { agentApi, RUN_KINDS, type RunKind, type Schedule } from '@/shared/api/agent'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AnalyticsNav from '../analytics/AnalyticsNav.vue'
import { useAnalytics } from '../analytics/useAnalytics'

/** Schedules run in the website's timezone; one slot never starts two runs. */
const { t } = useI18n()
const { workspaceId, siteId, sites, onSite, canConfigure, toast, toastError } = useAnalytics()
const { data, error, loading, run } = useAsync(() => (siteId.value ? agentApi.schedules(workspaceId.value, siteId.value) : Promise.resolve({ data: [] as Schedule[] })))
onSite(run)
const open = ref(false)
const saving = ref(false)
const form = ref<{ kind: RunKind; frequency: string; day_of_week: number | null; day_of_month: number | null; local_time: string; notify_on_failure: boolean }>({
  kind: 'weekly_audit',
  frequency: 'weekly',
  day_of_week: 1,
  day_of_month: 1,
  local_time: '07:00',
  notify_on_failure: true,
})

async function save() {
  saving.value = true
  try {
    await agentApi.saveSchedule(workspaceId.value, siteId.value, {
      ...form.value,
      day_of_week: form.value.frequency === 'weekly' ? form.value.day_of_week : null,
      day_of_month: form.value.frequency === 'monthly' ? form.value.day_of_month : null,
    })
    toast.success(t('an.common.saved'))
    open.value = false
    await run()
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
async function setStatus(s: Schedule, status: Schedule['status']) {
  try {
    await agentApi.updateSchedule(workspaceId.value, s.id, { status })
    await run()
  } catch (e) {
    toastError(e)
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('ag.title')" :description="t('ag.schedules.subtitle')" />
    <AnalyticsNav active="schedules" section="agent">
      <AppButton v-if="canConfigure && siteId" size="sm" class="ml-auto" @click="open = true">{{ t('ag.schedules.new') }}</AppButton>
    </AnalyticsNav>
    <EmptyState v-if="!sites.length" icon="globe" :title="t('an.connections.noSites')" />
    <LoadingState v-else-if="loading && !data" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <EmptyState v-else-if="!data?.data.length" icon="calendar" :title="t('ag.schedules.empty')" />
    <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <AppCard v-for="s in data.data" :key="s.id" :title="t(`ag.kinds.${s.kind}`)" :subtitle="`${t(`ag.frequency.${s.frequency}`)} · ${s.local_time} (${s.timezone})`">
        <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
          <AppBadge :tone="s.status === 'active' ? 'success' : 'neutral'">{{ t(`ag.schedules.status.${s.status}`) }}</AppBadge>
          <AppBadge v-if="s.consecutive_failures" tone="warning">{{ t('ag.schedules.failures', { n: s.consecutive_failures }) }}</AppBadge>
          <AppBadge v-if="s.is_demo">DEMO</AppBadge>
        </div>
        <dl class="grid grid-cols-2 gap-y-1 text-sm">
          <dt class="text-ink-muted">{{ t('ag.schedules.next') }}</dt><dd>{{ formatDateTime(s.next_run_at, s.timezone) }}</dd>
          <dt class="text-ink-muted">{{ t('ag.schedules.last') }}</dt><dd>{{ formatDateTime(s.last_run_at, s.timezone) }} <span v-if="s.last_status" class="text-xs text-ink-muted">({{ t(`ag.schedules.lastStatus.${s.last_status}`, s.last_status) }})</span></dd>
        </dl>
        <p v-if="s.preview.length" class="mt-2 text-xs text-ink-muted">{{ t('ag.schedules.preview') }}: {{ s.preview.map((p) => formatDateTime(p, s.timezone)).join(' · ') }}</p>
        <div v-if="canConfigure" class="mt-3 flex gap-2">
          <AppButton v-if="s.status !== 'active'" size="sm" variant="secondary" @click="setStatus(s, 'active')">{{ t('ag.schedules.activate') }}</AppButton>
          <AppButton v-if="s.status === 'active'" size="sm" variant="secondary" @click="setStatus(s, 'paused')">{{ t('ag.schedules.pause') }}</AppButton>
          <AppButton v-if="s.status !== 'disabled'" size="sm" variant="ghost" @click="setStatus(s, 'disabled')">{{ t('ag.schedules.disable') }}</AppButton>
        </div>
      </AppCard>
    </div>

    <AppModal :open="open" :title="t('ag.schedules.new')" @close="open = false">
      <form class="space-y-3 text-sm" @submit.prevent="save">
        <label class="flex flex-col gap-1">
          <span class="text-xs text-ink-muted">{{ t('ag.runs.kind') }}</span>
          <select v-model="form.kind" class="rounded-lg border border-line bg-surface px-2 py-1.5">
            <option v-for="k in RUN_KINDS" :key="k" :value="k">{{ t(`ag.kinds.${k}`) }}</option>
          </select>
        </label>
        <label class="flex flex-col gap-1">
          <span class="text-xs text-ink-muted">{{ t('ag.schedules.frequencyLabel') }}</span>
          <select v-model="form.frequency" class="rounded-lg border border-line bg-surface px-2 py-1.5">
            <option v-for="f in ['daily', 'weekly', 'monthly']" :key="f" :value="f">{{ t(`ag.frequency.${f}`) }}</option>
          </select>
        </label>
        <label v-if="form.frequency === 'weekly'" class="flex flex-col gap-1">
          <span class="text-xs text-ink-muted">{{ t('ag.schedules.dayOfWeek') }}</span>
          <select v-model.number="form.day_of_week" class="rounded-lg border border-line bg-surface px-2 py-1.5">
            <option v-for="d in 7" :key="d" :value="d">{{ t(`ag.weekdays.${d}`) }}</option>
          </select>
        </label>
        <label v-if="form.frequency === 'monthly'" class="flex flex-col gap-1">
          <span class="text-xs text-ink-muted">{{ t('ag.schedules.dayOfMonth') }}</span>
          <input v-model.number="form.day_of_month" type="number" min="1" max="28" class="rounded-lg border border-line bg-surface px-2 py-1.5" />
        </label>
        <label class="flex flex-col gap-1">
          <span class="text-xs text-ink-muted">{{ t('ag.schedules.time') }}</span>
          <input v-model="form.local_time" type="time" class="rounded-lg border border-line bg-surface px-2 py-1.5" />
        </label>
        <label class="flex items-center gap-2"><input v-model="form.notify_on_failure" type="checkbox" /> {{ t('ag.schedules.notify') }}</label>
        <p class="text-xs text-ink-muted">{{ t('ag.schedules.tzHint') }}</p>
        <div class="flex justify-end gap-2">
          <AppButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</AppButton>
          <AppButton type="submit" :loading="saving">{{ t('common.save') }}</AppButton>
        </div>
      </form>
    </AppModal>
  </div>
</template>
