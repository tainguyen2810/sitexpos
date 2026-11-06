<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { agentApi, PHASES, runTone } from '@/shared/api/agent'
import { micro } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import { useAnalytics } from '../analytics/useAnalytics'
import AgentTaskCard from './AgentTaskCard.vue'
import { useTaskActions } from './useTaskActions'

/** One run through Observe → Analyze → Plan → Estimate → Approve → Execute → Verify → Learn. */
const { t } = useI18n()
const route = useRoute()
const { workspaceId, canEdit, toastError } = useAnalytics()
const id = computed(() => String(route.params.runId))
const { data, error, loading, run } = useAsync(() => agentApi.run(workspaceId.value, id.value))
const r = computed(() => data.value?.data ?? null)
const { busy, act } = useTaskActions(run)
const working = ref(false)
const phaseIndex = computed(() => (r.value ? PHASES.indexOf(r.value.phase) : -1))
const logs = useAsync(() => agentApi.logs(workspaceId.value, { run_id: id.value }))

async function control(kind: 'pause' | 'resume' | 'cancel') {
  working.value = true
  try {
    data.value = { data: { ...(await agentApi[kind](workspaceId.value, id.value)).data, tasks: r.value?.tasks ?? [] } }
    await run()
  } catch (e) {
    toastError(e)
  } finally {
    working.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="r ? t(`ag.kinds.${r.kind}`) : t('ag.runs.run')" :description="t('ag.runs.detailHint')" :crumbs="[{ label: t('ag.nav.runs'), to: `/w/${workspaceId}/agent/runs` }, { label: id }]" />
    <LoadingState v-if="loading && !data" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <template v-else-if="r">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <AppBadge :tone="runTone(r.status)">{{ t(`ag.runStatus.${r.status}`) }}</AppBadge>
        <span class="text-sm text-ink-muted">{{ t('ag.runs.level') }} {{ r.autonomy_level }} · {{ t(`ag.trigger.${r.trigger}`, r.trigger) }} · {{ t('ag.runs.cost') }} {{ micro(r.actual_cost_micro_usd) }}</span>
        <span v-if="r.error_code" class="text-sm text-danger-600">{{ t(`ag.reasons.${r.error_code}`, r.error_code) }}</span>
        <div v-if="canEdit" class="ml-auto flex gap-2">
          <AppButton v-if="['queued', 'running', 'awaiting_approval'].includes(r.status)" size="sm" variant="secondary" :loading="working" @click="control('pause')">{{ t('ag.runs.pause') }}</AppButton>
          <AppButton v-if="r.status === 'paused'" size="sm" :loading="working" @click="control('resume')">{{ t('ag.runs.resume') }}</AppButton>
          <AppButton v-if="['queued', 'running', 'paused', 'awaiting_approval'].includes(r.status)" size="sm" variant="danger" :loading="working" @click="control('cancel')">{{ t('ag.runs.cancel') }}</AppButton>
        </div>
      </div>
      <ol class="mb-6 flex flex-wrap gap-2 text-xs" :aria-label="t('ag.runs.phase')">
        <li v-for="(p, i) in PHASES.filter((x) => x !== 'done')" :key="p" class="rounded-full border px-3 py-1" :class="i < phaseIndex || r.phase === 'done' ? 'border-success-600 text-success-600' : i === phaseIndex ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-line text-ink-muted'">
          {{ i + 1 }}. {{ t(`ag.phases.${p}`) }}
        </li>
      </ol>
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <AppCard :title="t('ag.runs.tasks')" :padded="false" class="lg:col-span-2">
          <EmptyState v-if="!r.tasks.length" icon="list" :title="t('ag.runs.noTasks')" />
          <div v-else class="divide-y divide-line">
            <AgentTaskCard
              v-for="task in r.tasks"
              :key="task.id"
              :task="task"
              :can-act="canEdit"
              :busy="busy === task.id"
              @approve="(n) => act(task, 'approve', n)"
              @reject="(n) => act(task, 'reject', n)"
              @retry="act(task, 'retry')"
            />
          </div>
        </AppCard>
        <div class="space-y-6">
          <AppCard :title="t('ag.runs.observations')">
            <pre v-if="r.observations" class="max-h-64 overflow-auto rounded bg-surface-muted p-2 text-[11px]">{{ JSON.stringify(r.observations, null, 2) }}</pre>
            <p v-else class="text-sm text-ink-muted">{{ t('an.common.noData') }}</p>
          </AppCard>
          <AppCard :title="t('ag.runs.summary')">
            <pre v-if="r.summary" class="max-h-64 overflow-auto rounded bg-surface-muted p-2 text-[11px]">{{ JSON.stringify(r.summary, null, 2) }}</pre>
            <p v-else class="text-sm text-ink-muted">{{ t('an.common.noData') }}</p>
            <p class="mt-2 text-xs text-ink-muted">{{ t('ag.runs.deadline') }}: {{ formatDateTime(r.deadline_at) }}</p>
          </AppCard>
        </div>
        <AppCard :title="t('ag.nav.logs')" :padded="false" class="lg:col-span-3">
          <EmptyState v-if="!logs.data.value?.data.length" icon="list" :title="t('ag.logs.empty')" />
          <ul v-else class="divide-y divide-line text-xs">
            <li v-for="l in logs.data.value.data" :key="l.id" class="flex flex-wrap gap-2 px-4 py-2">
              <span class="text-ink-muted">{{ formatDateTime(l.created_at) }}</span>
              <AppBadge :tone="l.level === 'error' ? 'danger' : l.level === 'warning' ? 'warning' : 'neutral'">{{ l.event }}</AppBadge>
              <span>{{ l.message }}</span>
              <span class="ml-auto text-ink-muted">{{ t(`ag.actor.${l.actor_type}`) }}</span>
            </li>
          </ul>
        </AppCard>
      </div>
    </template>
  </div>
</template>
