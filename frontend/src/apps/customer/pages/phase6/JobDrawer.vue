<script setup lang="ts">
import { ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { jobTone, localInputToIso, publishingApi, type PublishingJob } from '@/shared/api/publishing'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import Drawer from '@/shared/ui/Drawer.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import SimulationFrame from './SimulationFrame.vue'
import { usePhase6Errors, usePhase6Perms } from './usePhase6'

/** Job detail: gates, attempts, schedule, conflicts and the actions the state machine allows. */
const props = defineProps<{ jobId: string | null }>()
const emit = defineEmits<{ close: []; changed: [job: PublishingJob] }>()
const { t } = useI18n()
const { workspaceId, canEdit, canPublish } = usePhase6Perms()
const { toastError, toast } = usePhase6Errors()
const job = shallowRef<PublishingJob | null>(null)
const loading = ref(false)
const busy = ref('')
const when = ref('')
const html = ref<string | null>(null)
const confirmCancel = ref(false)

async function load() {
  if (!props.jobId) { job.value = null; return }
  loading.value = true
  html.value = null
  try {
    job.value = await publishingApi.job(workspaceId.value, props.jobId)
  } catch (e) {
    toastError(e)
    emit('close')
  } finally {
    loading.value = false
  }
}
watch(() => props.jobId, load, { immediate: true })

async function act(name: string, fn: () => Promise<PublishingJob>, okMsg?: string) {
  busy.value = name
  try {
    job.value = await fn()
    emit('changed', job.value)
    if (okMsg) toast.success(okMsg)
  } catch (e) {
    toastError(e)
    await load()
  } finally {
    busy.value = ''
  }
}
const recheck = () => act('recheck', () => publishingApi.recheck(workspaceId.value, job.value!.id))
const approve = () => act('approve', () => publishingApi.approve(workspaceId.value, job.value!.id, localInputToIso(when.value)), t('p6.pub.job.approved'))
const reschedule = () => act('reschedule', () => publishingApi.reschedule(workspaceId.value, job.value!.id, localInputToIso(when.value)!), t('common.saved'))
const retry = () => act('retry', () => publishingApi.retry(workspaceId.value, job.value!.id), t('p6.pub.job.retried'))
async function cancel() {
  confirmCancel.value = false
  await act('cancel', () => publishingApi.cancel(workspaceId.value, job.value!.id))
}
async function showHtml() {
  try {
    html.value = (await publishingApi.html(workspaceId.value, job.value!.id)).html
  } catch (e) {
    toastError(e)
  }
}
const can = (s: string) => !!job.value?.allowed_transitions.includes(s as never)
</script>

<template>
  <Drawer :open="!!jobId" :title="job?.article_title ?? t('p6.pub.job.title')" :subtitle="job ? t(`p6.pub.mode.${job.mode}`) : ''" @close="emit('close')">
    <LoadingState v-if="loading && !job" />
    <div v-else-if="job" class="space-y-5 text-sm" data-testid="job-drawer">
      <div class="flex flex-wrap items-center gap-2">
        <AppBadge :tone="jobTone(job.status)">{{ t(`p6.pub.status.${job.status}`) }}</AppBadge>
        <span class="text-xs text-ink-muted">{{ t('p6.pub.job.attempts', { n: job.attempts, max: job.max_attempts }) }}</span>
        <AppBadge v-if="job.approval_source === 'system_auto'" tone="warning">{{ t('p6.pub.job.auto') }}</AppBadge>
      </div>
      <p v-if="job.last_error_code" class="rounded bg-danger-50 px-3 py-2 text-xs text-danger-600">{{ job.last_error_message ?? job.last_error_code }}</p>
      <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
        <dt class="text-ink-muted">{{ t('p6.pub.job.target') }}</dt><dd>{{ job.wp_target_status }}</dd>
        <dt class="text-ink-muted">{{ t('p6.pub.job.scheduled') }}</dt><dd>{{ job.scheduled_at ? `${formatDateTime(job.scheduled_at, job.timezone)} (${job.timezone})` : '—' }}</dd>
        <dt class="text-ink-muted">{{ t('p6.pub.job.approvedAt') }}</dt><dd>{{ formatDateTime(job.approved_at) }}</dd>
        <dt class="text-ink-muted">{{ t('p6.pub.job.publishedAt') }}</dt><dd>{{ formatDateTime(job.published_at) }}</dd>
        <template v-if="job.url"><dt class="text-ink-muted">WordPress</dt><dd><a :href="job.url" target="_blank" rel="noopener noreferrer" class="text-brand-600 underline">#{{ job.wp_post_id }}</a></dd></template>
      </dl>

      <section>
        <h3 class="mb-2 font-semibold">{{ t('p6.pub.gates.title') }}</h3>
        <ul class="space-y-1" data-testid="job-gates">
          <li v-for="g in job.gate_results" :key="g.gate" class="flex items-start gap-2">
            <AppBadge :tone="g.ok ? 'success' : 'danger'">{{ g.ok ? '✓' : '✕' }}</AppBadge>
            <span><strong>{{ t(`p6.pub.gates.${g.gate}`) }}</strong> — {{ g.message }}</span>
          </li>
        </ul>
        <p class="mt-1 text-xs text-ink-muted">{{ t('p6.pub.gates.note') }}</p>
      </section>

      <section v-if="job.attempts_log?.length">
        <h3 class="mb-2 font-semibold">{{ t('p6.pub.job.attemptsLog') }}</h3>
        <ul class="space-y-1 text-xs">
          <li v-for="a in job.attempts_log" :key="a.attempt_no">#{{ a.attempt_no }} · {{ a.operation }} · <strong>{{ a.outcome }}</strong><span v-if="a.error_code"> · {{ a.error_code }}</span><span v-if="a.http_status"> · HTTP {{ a.http_status }}</span> · {{ formatDateTime(a.created_at) }}</li>
        </ul>
      </section>

      <section v-if="job.conflicts?.length">
        <h3 class="mb-2 font-semibold">{{ t('p6.pub.conflicts.title') }}</h3>
        <ul class="space-y-1 text-xs">
          <li v-for="c in job.conflicts" :key="c.id">{{ c.types.map((x) => t(`p6.pub.conflictType.${x}`)).join(', ') }} · {{ c.status }}
            <RouterLink :to="{ name: 'publishing-conflicts', params: { workspaceId }, query: { id: c.id } }" class="text-brand-600 underline">{{ t('p6.pub.conflicts.open') }}</RouterLink></li>
        </ul>
      </section>

      <section v-if="canEdit" class="space-y-2 border-t border-line pt-4">
        <label v-if="can('SCHEDULED') || job.mode === 'scheduled'" class="block text-xs">{{ t('p6.pub.job.when') }}
          <input v-model="when" type="datetime-local" class="mt-1 w-full rounded border border-line px-2 py-1 text-sm" />
        </label>
        <div class="flex flex-wrap gap-2">
          <AppButton v-if="['DRAFT', 'READY_FOR_REVIEW', 'FAILED', 'UPDATE_PENDING'].includes(job.status)" size="sm" variant="secondary" :loading="busy === 'recheck'" @click="recheck">{{ t('p6.pub.job.recheck') }}</AppButton>
          <AppButton v-if="canPublish && ['DRAFT', 'READY_FOR_REVIEW'].includes(job.status)" size="sm" :loading="busy === 'approve'" data-testid="approve-job" @click="approve">{{ t('p6.pub.job.approve') }}</AppButton>
          <AppButton v-if="job.status === 'SCHEDULED' && when" size="sm" variant="secondary" :loading="busy === 'reschedule'" @click="reschedule">{{ t('p6.pub.job.reschedule') }}</AppButton>
          <AppButton v-if="canPublish && job.status === 'FAILED'" size="sm" :loading="busy === 'retry'" @click="retry">{{ t('p6.pub.job.retry') }}</AppButton>
          <AppButton v-if="can('CANCELLED')" size="sm" variant="danger" :loading="busy === 'cancel'" @click="confirmCancel = true">{{ t('p6.pub.job.cancel') }}</AppButton>
          <AppButton v-if="job.has_rendered_html" size="sm" variant="ghost" @click="showHtml">{{ t('p6.pub.job.sentHtml') }}</AppButton>
        </div>
      </section>
      <SimulationFrame v-if="html !== null" :html="html" :title="t('p6.pub.job.sentHtml')" />
    </div>
    <ConfirmDialog :open="confirmCancel" :title="t('p6.pub.job.cancelTitle')" :message="t('p6.pub.job.cancelBody')" danger @close="confirmCancel = false" @confirm="cancel" />
  </Drawer>
</template>
