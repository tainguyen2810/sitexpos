<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ACTIVE_STATUSES, adminAiApi, type DeadLetter, type WorkflowRun, type WorkflowStatus } from '@/shared/api/ai'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useCursorList } from '@/shared/composables/useCursorList'
import { useToast } from '@/shared/composables/useToast'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import WorkflowStatusBadge from '@/shared/components/ai/WorkflowStatusBadge.vue'
import WorkflowRunDetail from '@/shared/components/ai/WorkflowRunDetail.vue'

const { t } = useI18n()
const toast = useToast()
const overview = useAsync(() => adminAiApi.jobsOverview())

const tab = ref<'workflows' | 'dead'>('workflows')
const status = ref('')
const statuses: WorkflowStatus[] = ['pending', 'running', 'retry_scheduled', 'succeeded', 'failed', 'canceled']
const statusOptions = computed(() => [{ value: '', label: t('ai.allStatuses') }, ...statuses.map((s) => ({ value: s, label: t(`ai.statuses.${s}`) }))])
const runs = useCursorList<WorkflowRun>((cursor) => adminAiApi.workflows({ status: status.value || undefined, cursor }))
watch(status, () => void runs.reload())

const dlStatus = ref('open')
const dlOptions = computed(() => (['open', 'retried', 'discarded'] as const).map((s) => ({ value: s, label: t(`jobs.dlStatuses.${s}`) })))
const dead = useCursorList<DeadLetter>((cursor) => adminAiApi.deadLetters({ status: dlStatus.value, cursor }))
watch(dlStatus, () => void dead.reload())

void runs.reload()
void dead.reload()

function refreshAll() {
  void overview.run()
  void (tab.value === 'workflows' ? runs.reload() : dead.reload())
}
const auto = ref(true)
let timer: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  timer = setInterval(() => {
    if (auto.value && document.visibilityState === 'visible') void overview.run()
  }, 10_000)
})
onBeforeUnmount(() => timer && clearInterval(timer))

function fail(e: unknown) {
  toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
}

const selected = ref<WorkflowRun | null>(null)
async function open(run: WorkflowRun) {
  try {
    selected.value = await adminAiApi.workflow(run.id)
  } catch (e) {
    fail(e)
  }
}
async function act(kind: 'retry' | 'cancel', run: WorkflowRun) {
  try {
    selected.value = kind === 'retry' ? await adminAiApi.retryWorkflow(run.id) : await adminAiApi.cancelWorkflow(run.id)
    toast.success(t('jobs.retriedOk'))
    refreshAll()
  } catch (e) {
    fail(e)
  }
}
async function dlAct(kind: 'retry' | 'discard', d: DeadLetter) {
  try {
    if (kind === 'retry') await adminAiApi.retryDeadLetter(d.id)
    else await adminAiApi.discardDeadLetter(d.id)
    toast.success(t(kind === 'retry' ? 'jobs.retriedOk' : 'jobs.discardedOk'))
    await dead.reload()
    void overview.run()
  } catch (e) {
    fail(e)
  }
}
const horizonTone = computed(() => (overview.data.value?.horizon === 'running' ? 'success' : overview.data.value?.horizon === 'paused' ? 'warning' : 'danger'))
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">{{ $t('jobs.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-ink-muted">{{ $t('jobs.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-3">
        <label class="flex items-center gap-2 text-xs text-ink-muted"><input v-model="auto" type="checkbox" /> {{ $t('jobs.autoRefresh') }}</label>
        <AppButton size="sm" variant="secondary" @click="refreshAll">{{ $t('jobs.refresh') }}</AppButton>
      </div>
    </div>

    <LoadingState v-if="overview.loading.value && !overview.data.value" :rows="2" />
    <ErrorState v-else-if="overview.error.value" :error="overview.error.value" @retry="overview.run" />
    <template v-else-if="overview.data.value">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="jobs-overview">
        <AppCard>
          <p class="text-sm text-ink-muted">{{ $t('jobs.horizon') }}</p>
          <p class="mt-1"><AppBadge :tone="horizonTone">{{ $t(`jobs.horizonStates.${overview.data.value.horizon}`) }}</AppBadge></p>
          <p class="mt-1 text-xs text-ink-muted">{{ $t('jobs.connection') }}: {{ overview.data.value.queue_connection }}</p>
        </AppCard>
        <AppCard><p class="text-sm text-ink-muted">{{ $t('jobs.stuck') }}</p><p class="mt-1 text-3xl font-bold" :class="overview.data.value.stuck ? 'text-warning-600' : ''">{{ overview.data.value.stuck }}</p></AppCard>
        <AppCard><p class="text-sm text-ink-muted">{{ $t('jobs.deadLetters') }}</p><p class="mt-1 text-3xl font-bold" :class="overview.data.value.dead_letters_open ? 'text-danger-600' : ''">{{ overview.data.value.dead_letters_open }}</p></AppCard>
        <AppCard><p class="text-sm text-ink-muted">{{ $t('jobs.failedJobs') }}</p><p class="mt-1 text-3xl font-bold">{{ overview.data.value.failed_jobs }}</p></AppCard>
      </div>
      <AppCard :title="$t('jobs.queues')">
        <div class="flex flex-wrap gap-2">
          <span v-for="q in overview.data.value.queues" :key="q.name" class="rounded-lg border border-line px-3 py-1.5 text-sm">
            <span class="font-mono">{{ q.name }}</span> <span class="ml-1 font-semibold">{{ q.size ?? '—' }}</span>
          </span>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
          <span v-for="s in statuses" :key="s" class="text-xs"><WorkflowStatusBadge :status="s" /> {{ overview.data.value.workflows[s] }}</span>
        </div>
      </AppCard>
    </template>

    <div class="flex flex-wrap gap-2 border-b border-line" role="tablist">
      <button
v-for="k in (['workflows', 'dead'] as const)" :key="k" type="button" role="tab" :aria-selected="tab === k"
        class="-mb-px border-b-2 px-3 py-2 text-sm font-medium" :class="tab === k ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:text-ink'"
        @click="tab = k">{{ k === 'workflows' ? $t('jobs.workflows') : $t('jobs.deadLetterTab') }}</button>
    </div>

    <AppCard v-if="tab === 'workflows'" :padded="false">
      <div class="flex justify-end border-b border-line px-5 py-3"><AppSelect v-model="status" class="w-52" :options="statusOptions" /></div>
      <LoadingState v-if="runs.loading.value" class="p-5" />
      <ErrorState v-else-if="runs.error.value" :error="runs.error.value" class="p-5" @retry="runs.reload" />
      <EmptyState v-else-if="!runs.items.value.length" icon="layers" :title="$t('aiAdmin.noData')" />
      <ul v-else class="divide-y divide-line" data-testid="workflow-list">
        <li v-for="r in runs.items.value" :key="r.id" class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
          <WorkflowStatusBadge :status="r.status" />
          <span class="font-mono text-xs">{{ r.type }}</span>
          <span class="min-w-0 flex-1 truncate">{{ r.workspace?.name ?? '—' }}</span>
          <span v-if="r.error" class="font-mono text-xs text-danger-600">{{ r.error.code }}</span>
          <span class="text-xs text-ink-muted">{{ r.attempts }}/{{ r.max_attempts }} · {{ formatDateTime(r.created_at) }}</span>
          <AppButton size="sm" variant="ghost" @click="open(r)">{{ $t('ai.detail') }}</AppButton>
        </li>
      </ul>
      <div v-if="runs.next.value" class="border-t border-line p-3 text-center"><AppButton variant="ghost" size="sm" :loading="runs.loadingMore.value" @click="runs.more">{{ $t('common.loadMore') }}</AppButton></div>
    </AppCard>

    <AppCard v-else :padded="false">
      <div class="flex justify-end border-b border-line px-5 py-3"><AppSelect v-model="dlStatus" class="w-44" :options="dlOptions" /></div>
      <LoadingState v-if="dead.loading.value" class="p-5" />
      <ErrorState v-else-if="dead.error.value" :error="dead.error.value" class="p-5" @retry="dead.reload" />
      <EmptyState v-else-if="!dead.items.value.length" icon="check" :title="$t('aiAdmin.noData')" />
      <ul v-else class="divide-y divide-line" data-testid="dead-letter-list">
        <li v-for="d in dead.items.value" :key="d.id" class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
          <AppBadge :tone="d.source === 'workflow' ? 'brand' : 'neutral'">{{ $t(`jobs.sources.${d.source}`) }}</AppBadge>
          <span class="font-mono text-xs">{{ d.job_class }}</span>
          <span class="min-w-0 flex-1 truncate text-xs text-danger-600" :title="d.error.message ?? ''">{{ d.error.code }} — {{ d.error.message }}</span>
          <span class="text-xs text-ink-muted">{{ d.queue }} · {{ formatDateTime(d.created_at) }}</span>
          <template v-if="d.status === 'open'">
            <AppButton size="sm" @click="dlAct('retry', d)">{{ $t('jobs.retry') }}</AppButton>
            <AppButton size="sm" variant="ghost" @click="dlAct('discard', d)">{{ $t('jobs.discard') }}</AppButton>
          </template>
          <AppBadge v-else>{{ $t(`jobs.dlStatuses.${d.status}`) }}</AppBadge>
        </li>
      </ul>
      <div v-if="dead.next.value" class="border-t border-line p-3 text-center"><AppButton variant="ghost" size="sm" :loading="dead.loadingMore.value" @click="dead.more">{{ $t('common.loadMore') }}</AppButton></div>
    </AppCard>

    <AppModal :open="!!selected" :title="$t('ai.detail')" @close="selected = null">
      <p v-if="selected?.workspace" class="mb-3 text-sm text-ink-muted">Workspace: {{ selected.workspace.name }}</p>
      <WorkflowRunDetail v-if="selected" :run="selected" />
      <template #footer>
        <AppButton v-if="selected && ACTIVE_STATUSES.includes(selected.status)" variant="danger" @click="act('cancel', selected)">{{ $t('ai.cancel') }}</AppButton>
        <AppButton v-if="selected?.status === 'failed'" @click="act('retry', selected)">{{ $t('jobs.retry') }}</AppButton>
        <AppButton variant="secondary" @click="selected = null">{{ $t('common.close') }}</AppButton>
      </template>
    </AppModal>
  </div>
</template>
