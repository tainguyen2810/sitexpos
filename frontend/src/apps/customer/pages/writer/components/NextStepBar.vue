<script setup lang="ts">
// "Bước tiếp theo": one prominent action for where the article is now —
// resolve claims → approve → publish to WordPress → view the post.
// UI shortcut only: approval goes through ApprovalGate and publishing through
// every PublishingGates check on the server, exactly like the full screens.
import { computed, reactive, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { needsAcknowledgement, writerApi, type Checklist } from '@/shared/api/writer'
import { localInputToIso, newIdempotencyKey, publishingApi, type PublishingJob } from '@/shared/api/publishing'
import { formatDateTime } from '@/shared/utils/format'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import { useArticle } from '../articleContext'
import { usePolling, useWriterErrors } from '../useWriter'

const emit = defineEmits<{ goto: [tab: string] }>()
const { t } = useI18n()
const { workspaceId: ws, articleId: id, article, reload, busy, canEdit, canApprove } = useArticle()
const { toastError, toast } = useWriterErrors()

const status = computed(() => article.value?.status)
const inReview = computed(() => !busy.value && (status.value === 'NEEDS_HUMAN_REVIEW' || status.value === 'QUALITY_REVIEW'))
const approved = computed(() => status.value === 'APPROVED')
const unresolved = computed(() => (article.value?.unresolved_critical ?? 0) + (article.value?.unresolved_high ?? 0))

// ---- review → approve ----
const checklist = shallowRef<Checklist | null>(null)
const ack = ref(false)
async function loadChecklist() {
  if (!inReview.value) return
  checklist.value = await writerApi.checklist(ws, id, ack.value).catch(() => null)
}
const needsAck = computed(() => !!checklist.value && needsAcknowledgement(checklist.value))
// Only blocking items that do not disappear with the acknowledgement checkbox.
const hardBlocking = computed(() => (checklist.value?.blocking ?? []).filter((b) => !b.requires_ack))
const canApproveNow = computed(() => !!checklist.value && hardBlocking.value.length === 0 && (!needsAck.value || ack.value))

const acting = ref(false)
async function approve() {
  const v = article.value?.current_version_id
  if (!v) return
  acting.value = true
  try {
    await writerApi.approve(ws, id, { version_id: v, acknowledge_high_risk: ack.value, note: null })
    toast.success(t('writer.facts.approved'))
    await reload()
  } catch (e) {
    toastError(e)
    await loadChecklist()
  } finally {
    acting.value = false
  }
}

// ---- approved → publish ----
const job = shallowRef<PublishingJob | null>(null)
const jobLoaded = ref(false)
async function loadJob() {
  if (!approved.value) return
  try {
    const page = await publishingApi.jobs(ws, { article_id: id })
    job.value = page.data.find((j) => j.status !== 'CANCELLED') ?? null
  } catch {
    job.value = null
  } finally {
    jobLoaded.value = true
  }
}
const jobState = computed(() => {
  const j = job.value
  if (!j) return 'none'
  if (j.status === 'PUBLISHED') return 'done'
  if (j.status === 'UPDATE_PENDING') return 'conflict'
  if (j.status === 'FAILED' || j.blocked_reason) return 'failed'
  if (j.status === 'DRAFT' || j.status === 'READY_FOR_REVIEW') return 'waiting'
  if (j.status === 'SCHEDULED') return 'scheduled'
  return 'working'
})
const failMessage = computed(() => job.value?.gate_results.find((g) => !g.ok)?.message ?? job.value?.last_error_message ?? job.value?.blocked_reason ?? '')
usePolling(loadJob, computed(() => jobState.value === 'working'), 5000)

watch(() => [status.value, article.value?.updated_at, busy.value], () => {
  void loadChecklist()
  void loadJob()
}, { immediate: true })
watch(ack, () => void loadChecklist())

const publishOpen = ref(false)
const form = reactive({ mode: 'wp_draft', scheduled_at: '' })
let key = newIdempotencyKey('job')
function openPublish() {
  Object.assign(form, { mode: 'wp_draft', scheduled_at: '' })
  key = newIdempotencyKey('job')
  publishOpen.value = true
}
const MODES = ['wp_draft', 'publish_now', 'scheduled'] as const
async function publish() {
  acting.value = true
  try {
    job.value = await publishingApi.create(ws, {
      article_id: id, mode: form.mode, scheduled_at: form.mode === 'scheduled' ? localInputToIso(form.scheduled_at) : null, approve: true,
    }, key)
    publishOpen.value = false
    toast.success(t('writer.next.publish.created'))
  } catch (e) {
    toastError(e)
  } finally {
    acting.value = false
  }
}
async function jobAction(fn: (j: PublishingJob) => Promise<PublishingJob>) {
  if (!job.value) return
  acting.value = true
  try {
    job.value = await fn(job.value)
  } catch (e) {
    toastError(e)
  } finally {
    acting.value = false
  }
}
const approveJob = () => jobAction((j) => publishingApi.approve(ws, j.id))
const recheck = () => jobAction((j) => publishingApi.recheck(ws, j.id))

const visible = computed(() => (inReview.value && !!checklist.value) || (approved.value && jobLoaded.value))
const tone = computed(() => {
  if (inReview.value) return hardBlocking.value.length || unresolved.value ? 'warning' : 'brand'
  if (jobState.value === 'done') return 'success'
  if (jobState.value === 'failed' || jobState.value === 'conflict') return 'danger'
  return 'brand'
})
const TONES = {
  brand: 'border-brand-200 bg-brand-50',
  warning: 'border-warning-200 bg-warning-50',
  success: 'border-success-200 bg-success-50',
  danger: 'border-danger-200 bg-danger-50',
} as const
</script>

<template>
  <section v-if="visible" :class="['mb-4 rounded-xl border px-4 py-3', TONES[tone]]" data-testid="next-step">
    <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('writer.next.title') }}</p>
    <div class="mt-1 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <!-- Review: fact-check only warns (v1.0.11); hard blockers are quality / placeholders / duplicates -->
      <div v-if="inReview" class="min-w-0 text-sm">
        <template v-if="hardBlocking.length">
          <p class="font-medium">{{ t('writer.next.review.blocked') }}</p>
          <ul class="mt-1 space-y-0.5 text-xs text-ink-muted" data-testid="next-blocking"><li v-for="b in hardBlocking.slice(0, 3)" :key="b.code">• {{ b.message }}</li></ul>
        </template>
        <template v-else-if="checklist">
          <p v-if="unresolved" class="text-warning-600" data-testid="next-claims-warning">{{ t('writer.next.review.claims', { n: unresolved }) }}</p>
          <p class="font-medium">{{ canApprove ? t('writer.next.review.ready') : t('writer.next.review.noPermission') }}</p>
          <label v-if="needsAck && canApprove" class="mt-1 flex items-start gap-2 text-xs"><input v-model="ack" type="checkbox" class="mt-0.5" data-testid="next-ack" /> {{ t('writer.next.review.ack') }}</label>
        </template>
      </div>
      <div v-if="inReview" class="flex shrink-0 flex-wrap gap-2">
        <AppButton v-if="hardBlocking.length" size="sm" variant="secondary" data-testid="next-details" @click="emit('goto', 'fact-check')">{{ t('writer.next.review.details') }}</AppButton>
        <template v-else-if="checklist">
          <AppButton v-if="unresolved" size="sm" variant="ghost" @click="emit('goto', 'fact-check')">{{ t('writer.next.review.viewClaims') }}</AppButton>
          <AppButton v-if="canApprove" size="sm" :loading="acting" :disabled="!canApproveNow" data-testid="next-approve" @click="approve">{{ t('writer.next.review.approve') }}</AppButton>
        </template>
      </div>

      <!-- Publish -->
      <template v-if="approved">
        <div class="min-w-0 text-sm">
          <p v-if="jobState === 'none'" class="font-medium">{{ canApprove ? t('writer.next.publish.ready') : t('writer.next.publish.noPermission') }}</p>
          <p v-else-if="jobState === 'waiting'" class="font-medium">{{ t('writer.next.publish.waitingApproval') }}</p>
          <p v-else-if="jobState === 'working'" class="font-medium">{{ t('writer.next.publish.working') }}</p>
          <p v-else-if="jobState === 'scheduled'" class="font-medium">{{ t('writer.next.publish.scheduled', { time: formatDateTime(job?.scheduled_at ?? null) }) }}</p>
          <p v-else-if="jobState === 'done'" class="font-medium">{{ job?.wp_target_status === 'draft' ? t('writer.next.publish.doneDraft') : t('writer.next.publish.donePublic') }}</p>
          <p v-else-if="jobState === 'conflict'" class="font-medium">{{ t('writer.next.publish.conflict') }}</p>
          <template v-else-if="jobState === 'failed'">
            <p class="font-medium">{{ t('writer.next.publish.failed') }}</p>
            <p class="text-xs text-danger-600" data-testid="next-fail">{{ failMessage }}</p>
          </template>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
          <AppButton v-if="jobState === 'none' && canApprove" size="sm" data-testid="next-publish" @click="openPublish">{{ t('writer.next.publish.open') }}</AppButton>
          <AppButton v-if="jobState === 'waiting' && canApprove" size="sm" :loading="acting" data-testid="next-approve-job" @click="approveJob">{{ t('writer.next.publish.approveJob') }}</AppButton>
          <AppButton v-if="jobState === 'failed' && canEdit" size="sm" :loading="acting" @click="recheck">{{ t('writer.next.publish.recheck') }}</AppButton>
          <a v-if="jobState === 'done' && job?.url" :href="job.url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700" data-testid="next-view">{{ t('writer.next.publish.view') }}</a>
          <RouterLink v-if="jobState === 'conflict'" :to="`/w/${ws}/publishing/conflicts`" class="inline-flex items-center rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700">{{ t('writer.next.publish.resolve') }}</RouterLink>
          <RouterLink v-if="jobState !== 'none'" :to="`/w/${ws}/publishing`" class="inline-flex items-center px-2 py-1.5 text-sm text-brand-600 hover:underline">{{ t('writer.next.publish.center') }}</RouterLink>
        </div>
      </template>
    </div>

    <AppModal :open="publishOpen" :title="t('writer.next.publish.title')" width="sm" @close="publishOpen = false">
      <p class="mb-3 text-sm text-ink-muted">{{ t('writer.next.publish.intro') }}</p>
      <fieldset class="space-y-2" data-testid="next-modes">
        <label v-for="m in MODES" :key="m" class="flex cursor-pointer items-start gap-2 rounded-lg border border-line p-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
          <input v-model="form.mode" type="radio" name="next-mode" :value="m" class="mt-1" />
          <span><span class="font-medium">{{ t(`p6.pub.mode.${m}`) }}</span><span class="block text-xs text-ink-muted">{{ t(`p6.pub.modeHint.${m}`) }}</span></span>
        </label>
      </fieldset>
      <AppInput v-if="form.mode === 'scheduled'" v-model="form.scheduled_at" class="mt-3" type="datetime-local" :label="t('writer.next.publish.when')" />
      <template #footer>
        <AppButton variant="secondary" @click="publishOpen = false">{{ t('common.cancel') }}</AppButton>
        <AppButton :loading="acting" :disabled="form.mode === 'scheduled' && !form.scheduled_at" data-testid="next-submit" @click="publish">{{ t('writer.next.publish.submit') }}</AppButton>
      </template>
    </AppModal>
  </section>
</template>
