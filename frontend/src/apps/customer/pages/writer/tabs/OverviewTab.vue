<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { approvalModeOf, autoApproveResult, formatUsd, writerApi, type ApprovalMode, type DuplicateDecision } from '@/shared/api/writer'
import { useAsync } from '@/shared/composables/useAsync'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import WorkflowProgress from '../components/WorkflowProgress.vue'
import { useArticle } from '../articleContext'
import { useWriterErrors } from '../useWriter'

const { t } = useI18n()
const { workspaceId: ws, articleId: id, article, reload, busy, canEdit } = useArticle()
const { toastError, toast } = useWriterErrors()

const workflows = useAsync(() => writerApi.workflows(ws, id))
const usage = useAsync(() => writerApi.usage(ws, id))
watch(() => article.value?.updated_at, () => {
  void workflows.run()
  void usage.run()
})

const a = computed(() => article.value!)
const START: Record<string, 'research' | 'outline' | 'draft' | 'recheck'> = {
  IDEA_SELECTED: 'research', RESEARCH_COMPLETE: 'research', BRIEF_READY: 'outline', OUTLINING: 'draft', NEEDS_HUMAN_REVIEW: 'recheck', APPROVED: 'recheck',
}
const startKind = computed(() => (busy.value ? null : START[a.value.status] ?? null))
const startBlocked = computed(() =>
  (startKind.value === 'outline' && a.value.brief?.status !== 'approved') || (startKind.value === 'draft' && a.value.outline?.status !== 'approved'))
const full = ref(false)
const autoRevise = ref(false)
const acting = ref(false)
async function run(fn: () => Promise<unknown>, done?: string) {
  acting.value = true
  try {
    await fn()
    await reload()
    if (done) toast.success(done)
  } catch (e) {
    toastError(e)
  } finally {
    acting.value = false
  }
}
const start = () => startKind.value && run(() => writerApi.start(ws, id, startKind.value!, startKind.value === 'recheck' ? { full: full.value, auto_revise: autoRevise.value } : {}))

const reviewMode = ref(a.value.review_mode)
const reviewOptions = computed(() => (['step_by_step', 'brief_only', 'final_only'] as const).map((v) => ({ value: v, label: t(`writer.reviewMode.${v}`) })))
watch(reviewMode, (m) => {
  if (m !== a.value.review_mode) void run(() => writerApi.update(ws, id, { review_mode: m }), t('common.saved'))
})

const opts = useAsync(() => writerApi.options(ws))
const autoEnabled = computed(() => !!opts.data.value?.auto_approve.enabled)
const approvalMode = ref<ApprovalMode>(approvalModeOf(a.value.settings))
const approvalOptions = computed(() => (autoEnabled.value || approvalMode.value === 'auto' ? (['manual', 'auto'] as const) : (['manual'] as const))
  .map((v) => ({ value: v, label: t(`writer.approvalMode.${v}`) })))
watch(approvalMode, (m) => {
  if (m !== approvalModeOf(a.value.settings)) void run(() => writerApi.update(ws, id, { approval_mode: m }), t('common.saved'))
})
const autoResult = computed(() => (approvalModeOf(a.value.settings) === 'auto' ? autoApproveResult(a.value.settings) : null))
const autoReasons = computed(() => (autoResult.value?.reasons ?? []).map((r) => t(`writer.autoApprove.reason.${r}`)).join(', '))

const dup = computed(() => a.value.duplicate_check)
const decisions: DuplicateDecision[] = ['create_new', 'update_existing', 'merge', 'reject']
const decide = (d: DuplicateDecision) => run(() => writerApi.duplicateDecision(ws, id, d), t('common.saved'))

const cancelOpen = ref(false)
async function cancelArticle() {
  await run(() => writerApi.cancel(ws, id))
  cancelOpen.value = false
}
</script>

<template>
  <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    <AppCard>
      <h3 class="mb-3 text-sm font-semibold">{{ t('writer.article.workflow') }}</h3>
      <div v-if="canEdit && startKind" class="mb-4 space-y-2 rounded-lg border border-line p-3">
        <template v-if="startKind === 'recheck'">
          <Toggle v-model="full" :label="t('writer.article.recheckFull')" />
          <Toggle v-model="autoRevise" :label="t('writer.article.recheckAutoRevise')" />
        </template>
        <AppButton :loading="acting" :disabled="startBlocked" data-testid="start-workflow" @click="start">{{ t(`writer.article.start.${startKind}`) }}</AppButton>
      </div>
      <LoadingState v-if="workflows.loading.value && !workflows.data.value" />
      <ErrorState v-else-if="workflows.error.value" :error="workflows.error.value" @retry="workflows.run" />
      <EmptyState v-else-if="!workflows.data.value?.length" icon="activity" :title="t('writer.article.noWorkflow')" />
      <ul v-else class="space-y-4">
        <li v-for="w in workflows.data.value.slice(0, 6)" :key="w.id" class="border-b border-line pb-4 last:border-0"><WorkflowProgress :workflow="w" /></li>
      </ul>
    </AppCard>

    <div class="space-y-4">
      <AppCard>
        <h3 class="mb-3 text-sm font-semibold">{{ t('writer.article.duplicate') }}</h3>
        <p v-if="!dup" class="text-sm text-ink-muted">{{ t('common.noData') }}</p>
        <template v-else>
          <p class="text-sm">
            <AppBadge :tone="dup.recommendation === 'create_new' ? 'success' : dup.recommendation === 'reject' ? 'danger' : 'warning'">
              {{ t(`writer.article.duplicateRec.${dup.recommendation}`, dup.recommendation) }}
            </AppBadge>
            <span v-if="dup.reason" class="ml-2 text-ink-muted">{{ dup.reason }}</span>
          </p>
          <ul v-if="dup.matches?.length" class="mt-3 space-y-2 text-sm">
            <li v-for="m in dup.matches.slice(0, 5)" :key="`${m.type}:${m.id}`">
              <a v-if="m.url" :href="m.url" target="_blank" rel="noopener noreferrer nofollow" class="font-medium text-brand-600 underline">{{ m.title }}</a>
              <span v-else class="font-medium">{{ m.title }}</span>
              <span class="block text-xs text-ink-muted">{{ t('writer.article.similarity', { t: Math.round(m.title_similarity * 100), c: Math.round(m.content_similarity * 100) }) }} · {{ m.risk }}</span>
            </li>
          </ul>
          <div v-if="canEdit && dup.recommendation !== 'create_new'" class="mt-3">
            <p class="mb-2 text-xs text-ink-muted">{{ t('writer.article.duplicateDecide') }}<template v-if="a.duplicate_decision">: <strong>{{ t(`writer.article.duplicateDecision.${a.duplicate_decision}`) }}</strong></template></p>
            <div class="flex flex-wrap gap-2">
              <AppButton v-for="d in decisions" :key="d" size="sm" :variant="a.duplicate_decision === d ? 'primary' : 'secondary'" :disabled="acting" @click="decide(d)">
                {{ t(`writer.article.duplicateDecision.${d}`) }}
              </AppButton>
            </div>
          </div>
        </template>
      </AppCard>

      <AppCard>
        <h3 class="mb-3 text-sm font-semibold">{{ t('writer.article.usage') }}</h3>
        <LoadingState v-if="usage.loading.value && !usage.data.value" />
        <ErrorState v-else-if="usage.error.value" :error="usage.error.value" @retry="usage.run" />
        <p v-else-if="!usage.data.value?.breakdown.length" class="text-sm text-ink-muted">{{ t('common.noData') }}</p>
        <div v-else class="overflow-x-auto">
          <table class="data-table w-full text-left text-xs">
            <thead class="text-ink-muted"><tr><th class="py-1 pr-2">{{ t('writer.article.steps') }}</th><th class="pr-2">{{ t('writer.article.calls') }}</th><th class="pr-2">{{ t('writer.article.tokens') }}</th><th class="pr-2">{{ t('writer.article.fetches') }}</th><th class="text-right">USD</th></tr></thead>
            <tbody class="divide-y divide-line">
              <tr v-for="r in usage.data.value.breakdown" :key="`${r.step}:${r.task_key}:${r.model}`">
                <td class="py-1 pr-2"><span class="font-medium">{{ r.step }}</span><span class="block text-ink-muted">{{ r.model ?? '—' }}</span></td>
                <td class="pr-2 tabular-nums">{{ r.calls }}<span v-if="r.failed" class="text-danger-600"> ({{ r.failed }}✕)</span></td>
                <td class="pr-2 tabular-nums">{{ r.input_tokens }}/{{ r.output_tokens }}</td>
                <td class="pr-2 tabular-nums">{{ r.fetches }}</td>
                <td class="text-right tabular-nums">{{ formatUsd(r.cost_micro_usd) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>

      <AppCard>
        <AppSelect v-model="reviewMode" :label="t('writer.article.reviewMode')" :options="reviewOptions" :disabled="!canEdit || ['APPROVED', 'CANCELLED'].includes(a.status)" />
        <AppSelect
          v-model="approvalMode" class="mt-3" data-testid="overview-approval-mode" :label="t('writer.article.approvalMode')" :options="approvalOptions"
          :disabled="!canEdit || ['APPROVED', 'CANCELLED'].includes(a.status)"
          :hint="autoEnabled ? t('writer.autoApprove.hint', { min: opts.data.value?.auto_approve.min_quality ?? 0 }) : undefined" />
        <p v-if="autoResult?.approved && a.status === 'APPROVED'" class="mt-2 rounded bg-success-50 p-2 text-xs text-success-600" data-testid="auto-approved">{{ t('writer.autoApprove.approved') }}</p>
        <p v-else-if="autoResult && !autoResult.approved" class="mt-2 rounded bg-warning-50 p-2 text-xs text-warning-600" data-testid="auto-skipped">{{ t('writer.autoApprove.skipped', { reasons: autoReasons }) }}</p>
        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
          <div><dt class="text-ink-muted">{{ t('writer.article.keyword') }}</dt><dd>{{ a.primary_keyword }}</dd></div>
          <div><dt class="text-ink-muted">{{ t('writer.article.type') }}</dt><dd>{{ t(`writer.contentType.${a.content_type}`) }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-ink-muted">{{ t('writer.notPublished') }}</p>
        <AppButton v-if="canEdit && !['APPROVED', 'CANCELLED'].includes(a.status)" class="mt-3" size="sm" variant="danger" @click="cancelOpen = true">{{ t('writer.article.cancelArticle') }}</AppButton>
      </AppCard>
    </div>

    <ConfirmDialog :open="cancelOpen" :title="t('writer.article.cancelArticle')" :message="t('writer.article.cancelConfirm')" danger :loading="acting" @close="cancelOpen = false" @confirm="cancelArticle" />
  </div>
</template>
