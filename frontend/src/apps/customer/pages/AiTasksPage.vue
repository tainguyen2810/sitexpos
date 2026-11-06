<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ACTIVE_STATUSES, aiApi, formatTokens, formatUsd, newIdempotencyKey, type WorkflowRun } from '@/shared/api/ai'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useCursorList } from '@/shared/composables/useCursorList'
import { useToast } from '@/shared/composables/useToast'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import WorkflowStatusBadge from '@/shared/components/ai/WorkflowStatusBadge.vue'
import WorkflowRunDetail from '@/shared/components/ai/WorkflowRunDetail.vue'
import { aiErrorMessage } from '@/shared/components/ai/aiErrors'
import { useWorkspaceStore } from '../stores/workspace'

const POLL_MS = 3000

const { t } = useI18n()
const toast = useToast()
const ws = useWorkspaceStore()
const id = ws.current!.id
const canRun = computed(() => ws.can('content.edit') && !ws.current?.impersonating)

// ---- list + usage ----
const status = ref('')
const statusOptions = computed(() => [
  { value: '', label: t('ai.allStatuses') },
  ...(['pending', 'running', 'retry_scheduled', 'succeeded', 'failed', 'canceled'] as const).map((s) => ({ value: s, label: t(`ai.statuses.${s}`) })),
])
const list = useCursorList<WorkflowRun>((cursor) => aiApi.tasks(id, { status: status.value || undefined, cursor }))
const usage = useAsync(() => aiApi.usage(id, 30))
void list.reload()
watch(status, () => void list.reload())

// ---- submit (Idempotency-Key survives double clicks / network retries) ----
const draft = ref({ prompt: '', structured: false, web_search: false })
let idemKey = newIdempotencyKey()
const form = useApiForm()
const { errors, formError, submitting } = form
async function submit() {
  const res = await form.submit(() => aiApi.createTask(id, draft.value, idemKey))
  if (!res) return
  toast.success(t('ai.submitted'))
  draft.value = { prompt: '', structured: draft.value.structured, web_search: false }
  idemKey = newIdempotencyKey()
  await list.reload()
  open(res.workflow_run)
}

// ---- detail with polling ----
const selected = ref<WorkflowRun | null>(null)
let timer: ReturnType<typeof setTimeout> | null = null
function stopPolling() {
  if (timer) clearTimeout(timer)
  timer = null
}
async function refreshSelected() {
  if (!selected.value) return
  try {
    selected.value = await aiApi.task(id, selected.value.id)
  } catch {
    /* keep last state; next tick retries */
  }
  schedule()
}
function schedule() {
  stopPolling()
  if (selected.value && ACTIVE_STATUSES.includes(selected.value.status)) {
    timer = setTimeout(() => void refreshSelected(), POLL_MS)
  } else if (selected.value) {
    void list.reload()
    void usage.run()
  }
}
function open(run: WorkflowRun) {
  selected.value = run
  void refreshSelected()
}
function close() {
  stopPolling()
  selected.value = null
}
onBeforeUnmount(stopPolling)

// ---- cancel / retry ----
const confirmCancel = ref(false)
const acting = ref(false)
async function act(kind: 'cancel' | 'retry') {
  if (!selected.value) return
  acting.value = true
  try {
    selected.value = kind === 'cancel' ? await aiApi.cancelTask(id, selected.value.id) : await aiApi.retryTask(id, selected.value.id)
    toast.success(t(kind === 'cancel' ? 'ai.canceled' : 'ai.retried'))
    schedule()
    await list.reload()
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  } finally {
    acting.value = false
    confirmCancel.value = false
  }
}

const totals = computed(() => usage.data.value?.totals ?? null)
const budgets = computed(() => usage.data.value?.budgets ?? [])
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold">{{ $t('ai.title') }}</h1>
      <p class="mt-1 text-sm text-ink-muted">{{ $t('ai.subtitle') }}</p>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <AppCard v-if="canRun" :title="$t('ai.newTask')" class="lg:col-span-2">
        <form id="ai-task-form" class="space-y-4" novalidate @submit.prevent="submit">
          <p v-if="formError" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ formError }}</p>
          <div class="space-y-1.5">
            <label for="ai-prompt" class="block text-sm font-medium">{{ $t('ai.prompt') }}</label>
            <textarea
              id="ai-prompt"
              v-model="draft.prompt"
              rows="4"
              maxlength="4000"
              :placeholder="$t('ai.promptPlaceholder')"
              class="block w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
            />
            <p v-if="errors.prompt" class="text-xs text-danger-600">{{ errors.prompt }}</p>
            <p v-else class="text-xs text-ink-muted">{{ $t('ai.promptHint') }} ({{ draft.prompt.length }}/4000)</p>
          </div>
          <label class="flex items-center gap-2 text-sm"><input v-model="draft.structured" type="checkbox" class="rounded border-line" /> {{ $t('ai.structured') }}</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="draft.web_search" type="checkbox" class="rounded border-line" /> {{ $t('ai.webSearch') }}</label>
          <AppButton type="submit" :loading="submitting" :disabled="draft.prompt.trim().length < 2">{{ $t('ai.submit') }}</AppButton>
        </form>
      </AppCard>

      <AppCard :title="$t('ai.usage.title')" :class="canRun ? '' : 'lg:col-span-3'">
        <LoadingState v-if="usage.loading.value && !totals" />
        <ErrorState v-else-if="usage.error.value" :error="usage.error.value" @retry="usage.run" />
        <div v-else-if="totals" class="space-y-3 text-sm" data-testid="ai-usage">
          <div class="flex justify-between"><span class="text-ink-muted">{{ $t('ai.usage.cost') }}</span><span class="font-semibold">{{ formatUsd(totals.cost_micro_usd) }}</span></div>
          <div class="flex justify-between"><span class="text-ink-muted">{{ $t('ai.usage.requests') }}</span><span>{{ totals.requests }}</span></div>
          <div class="flex justify-between"><span class="text-ink-muted">{{ $t('ai.usage.tokens') }}</span><span>{{ formatTokens(totals.input_tokens + totals.cached_input_tokens) }} / {{ formatTokens(totals.output_tokens) }}</span></div>
          <div class="flex justify-between"><span class="text-ink-muted">{{ $t('ai.usage.failed') }}</span><span>{{ totals.failed }}</span></div>
          <div v-for="b in budgets" :key="b.period" class="border-t border-line pt-3">
            <p class="text-ink-muted">{{ $t('ai.usage.budget', { period: $t(`ai.usage.periods.${b.period}`) }) }}</p>
            <div class="mt-1 h-2 rounded-full bg-surface-muted"><div class="h-2 rounded-full" :class="b.used_pct >= b.alert_threshold_pct ? 'bg-warning-600' : 'bg-brand-500'" :style="{ width: Math.min(100, b.used_pct) + '%' }" /></div>
            <p class="mt-1 text-xs">{{ $t('ai.usage.used', { spent: formatUsd(b.spent_micro_usd), limit: formatUsd(b.limit_micro_usd) }) }}</p>
          </div>
          <p v-if="!budgets.length" class="border-t border-line pt-3 text-xs text-ink-muted">{{ $t('ai.usage.noBudget') }}</p>
        </div>
      </AppCard>
    </div>

    <AppCard :padded="false">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
        <h2 class="text-base font-semibold">{{ $t('ai.title') }}</h2>
        <AppSelect v-model="status" class="w-52" :options="statusOptions" :aria-label="$t('ai.filterStatus')" />
      </div>
      <LoadingState v-if="list.loading.value" class="p-5" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" class="p-5" @retry="list.reload" />
      <EmptyState v-else-if="!list.items.value.length" icon="sparkles" :title="$t('ai.emptyTitle')" :description="$t('ai.emptyBody')" />
      <ul v-else class="divide-y divide-line" data-testid="ai-task-list">
        <li v-for="run in list.items.value" :key="run.id">
          <button type="button" class="flex w-full flex-wrap items-center gap-3 px-5 py-3 text-left hover:bg-surface-muted" @click="open(run)">
            <WorkflowStatusBadge :status="run.status" />
            <span class="min-w-0 flex-1 truncate text-sm">{{ run.input.prompt }}</span>
            <span v-if="run.error" class="text-xs text-danger-600">{{ aiErrorMessage(run.error) }}</span>
            <span class="text-xs text-ink-muted">{{ formatDateTime(run.created_at) }}</span>
          </button>
        </li>
      </ul>
      <div v-if="list.next.value" class="border-t border-line p-3 text-center">
        <AppButton variant="ghost" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ $t('common.loadMore') }}</AppButton>
      </div>
    </AppCard>

    <AppModal :open="!!selected" :title="$t('ai.detail')" @close="close">
      <WorkflowRunDetail v-if="selected" :run="selected" />
      <p v-if="selected && ACTIVE_STATUSES.includes(selected.status)" class="mt-3 text-xs text-ink-muted">{{ $t('ai.polling') }}</p>
      <template #footer>
        <AppButton v-if="canRun && selected && ACTIVE_STATUSES.includes(selected.status) && !selected.cancel_requested" variant="danger" @click="confirmCancel = true">{{ $t('ai.cancel') }}</AppButton>
        <AppButton v-if="canRun && selected?.status === 'failed'" :loading="acting" @click="act('retry')">{{ $t('ai.retry') }}</AppButton>
        <AppButton variant="secondary" @click="close">{{ $t('common.close') }}</AppButton>
      </template>
    </AppModal>
    <ConfirmDialog :open="confirmCancel" :title="$t('ai.cancel')" :message="$t('ai.cancelConfirm')" danger :loading="acting" @close="confirmCancel = false" @confirm="act('cancel')" />
  </div>
</template>
