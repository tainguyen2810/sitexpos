<script setup lang="ts">
import { computed, reactive, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RISK_LEVELS, VERDICTS, isUnresolved, riskTone, verdictNeedsEvidence, verdictTone, writerApi, type Claim, type Evidence, type Verdict } from '@/shared/api/writer'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import Drawer from '@/shared/ui/Drawer.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import WTextarea from '../components/WTextarea.vue'
import { useArticle } from '../articleContext'
import { useWriterErrors } from '../useWriter'

const { t } = useI18n()
const { workspaceId: ws, articleId: id, article, reload, busy, canEdit, canApprove } = useArticle()
const { toastError, toast } = useWriterErrors()

const risk = ref('')
const verdict = ref('')
const unresolved = ref(false)
const claims = useCursorList((cursor) => writerApi.claims(ws, id, { cursor, risk_level: risk.value, status: verdict.value, unresolved: unresolved.value ? 1 : undefined }))
const runs = useAsync(() => writerApi.factChecks(ws, id, { per_page: 5 }).then((p) => p.data))
const ack = ref(false)
const checklist = useAsync(() => writerApi.checklist(ws, id, ack.value))
void claims.reload()
watch([risk, verdict, unresolved], () => void claims.reload())
watch(ack, () => void checklist.run())
watch(() => [article.value?.updated_at, article.value?.status], () => {
  void claims.reload()
  void runs.run()
  void checklist.run()
})

const riskOptions = computed(() => [{ value: '', label: t('common.all') }, ...RISK_LEVELS.map((r) => ({ value: r, label: t(`writer.risk.${r}`) }))])
const verdictOptions = computed(() => [{ value: '', label: t('common.all') }, ...(['PENDING', ...VERDICTS] as const).map((v) => ({ value: v, label: t(`writer.verdict.${v}`) }))])
const latestRun = computed(() => runs.data.value?.[0] ?? null)

// ---- claim detail + manual verification ----
const open = shallowRef<Claim | null>(null)
const detailLoading = ref(false)
async function show(c: Claim) {
  open.value = c
  detailLoading.value = true
  try {
    open.value = await writerApi.claim(ws, id, c.id)
  } catch (e) {
    toastError(e)
  } finally {
    detailLoading.value = false
  }
  resetForm()
}
const evidence = shallowRef<Evidence[]>([])
async function loadEvidence() {
  if (evidence.value.length) return
  evidence.value = (await writerApi.evidence(ws, id, { per_page: 100 }).catch(() => ({ data: [] as Evidence[] }))).data
}
const { errors, formError, submitting, submit } = useApiForm()
const form = reactive({ verdict: 'VERIFIED' as Verdict, note: '', evidence_ids: [] as string[], reference_url: '' })
function resetForm() {
  form.verdict = 'VERIFIED'
  form.note = ''
  form.evidence_ids = []
  form.reference_url = ''
}
const isCritical = computed(() => open.value?.risk_level === 'CRITICAL')
const canVerify = computed(() => !!open.value && !busy.value && (open.value.risk_level === 'CRITICAL' ? canApprove.value : canEdit.value))
const missingEvidence = computed(() => verdictNeedsEvidence(form.verdict) && !form.evidence_ids.length && !form.reference_url.trim())
const verdictFormOptions = computed(() => VERDICTS.map((v) => ({ value: v, label: t(`writer.verdict.${v}`) })))
async function verify() {
  if (!open.value) return
  const r = await submit(() => writerApi.verifyClaim(ws, id, open.value!.id, { verdict: form.verdict, note: form.note, evidence_ids: form.evidence_ids, reference_url: form.reference_url.trim() || null }))
  if (r) {
    toast.success(t('common.saved'))
    open.value = await writerApi.claim(ws, id, r.id)
    resetForm()
    await Promise.all([claims.reload(), checklist.run(), reload()])
  }
}

// ---- approval ----
const approveNote = ref('')
const changesNote = ref('')
const acting = ref(false)
async function approve() {
  const v = article.value?.current_version_id
  if (!v) return
  acting.value = true
  try {
    await writerApi.approve(ws, id, { version_id: v, acknowledge_high_risk: ack.value, note: approveNote.value.trim() || null })
    toast.success(t('writer.facts.approved'))
    await Promise.all([reload(), checklist.run()])
  } catch (e) {
    toastError(e)
    await checklist.run()
  } finally {
    acting.value = false
  }
}
async function requestChanges() {
  acting.value = true
  try {
    await writerApi.requestChanges(ws, id, changesNote.value.trim())
    changesNote.value = ''
    toast.success(t('common.saved'))
    await Promise.all([reload(), checklist.run()])
  } catch (e) {
    toastError(e)
  } finally {
    acting.value = false
  }
}
const needsAck = computed(() => checklist.data.value?.blocking.some((b) => b.requires_ack) || checklist.data.value?.warnings.some((w) => w.code === 'high_unresolved'))
const reviewer = (r: { reviewer_type: string; reviewer_model: string | null }) => `${t(`writer.reviewer.${r.reviewer_type}`, r.reviewer_type)}${r.reviewer_model ? ` (${r.reviewer_model})` : ''}`
</script>

<template>
  <div class="grid gap-4 xl:grid-cols-[1fr_22rem]">
    <div class="min-w-0 space-y-4">
      <AppCard v-if="latestRun">
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <h3 class="font-semibold">{{ t('writer.facts.summary') }}</h3>
          <span class="text-ink-muted">{{ t('writer.facts.round', { n: latestRun.round }) }} · {{ formatDateTime(latestRun.finished_at ?? latestRun.started_at) }} · {{ latestRun.model ?? '' }}</span>
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
          <AppBadge tone="neutral">{{ t('writer.facts.total') }}: {{ latestRun.claims_total }}</AppBadge>
          <AppBadge v-for="(n, v) in latestRun.counts" :key="v" :tone="verdictTone(v as Verdict)">{{ t(`writer.verdict.${v}`, String(v)) }}: {{ n }}</AppBadge>
        </div>
        <div class="mt-3">
          <p class="text-xs font-medium text-ink-muted">{{ t('writer.facts.consistency') }}</p>
          <p v-if="!latestRun.consistency_issues.length" class="text-xs text-ink-muted">{{ t('writer.facts.noConsistency') }}</p>
          <ul v-else class="mt-1 space-y-1 text-xs text-warning-600">
            <li v-for="(c, i) in latestRun.consistency_issues" :key="i">{{ c.message }}</li>
          </ul>
        </div>
      </AppCard>

      <AppCard :padded="false">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
          <FilterSelect v-model="risk" :label="t('writer.facts.filterRisk')" :options="riskOptions" />
          <FilterSelect v-model="verdict" :label="t('writer.facts.filterVerdict')" :options="verdictOptions" />
          <label class="ml-auto flex items-center gap-2 text-sm"><input v-model="unresolved" type="checkbox" /> {{ t('writer.facts.unresolvedOnly') }}</label>
        </div>
        <LoadingState v-if="claims.loading.value && !claims.loaded.value" class="p-5" />
        <ErrorState v-else-if="claims.error.value" :error="claims.error.value" class="p-5" @retry="claims.reload" />
        <EmptyState v-else-if="!claims.items.value.length" icon="shieldCheck" :title="t('writer.facts.empty')" :description="t('writer.facts.emptyBody')" />
        <ul v-else class="divide-y divide-line" data-testid="claim-list">
          <li v-for="c in claims.items.value" :key="c.id">
            <button type="button" class="flex w-full flex-wrap items-start gap-2 px-5 py-3 text-left hover:bg-surface-muted" @click="show(c)">
              <AppBadge :tone="riskTone(c.risk_level)">{{ t(`writer.risk.${c.risk_level}`) }}</AppBadge>
              <span class="min-w-0 flex-1 text-sm">{{ c.claim }}
                <span class="block text-xs text-ink-muted">{{ c.category }}<template v-if="c.latest_result"> · {{ reviewer(c.latest_result) }}</template></span>
              </span>
              <AppBadge v-if="c.is_stale" tone="warning">{{ t('writer.facts.stale') }}</AppBadge>
              <AppBadge :tone="verdictTone(c.status)" :data-unresolved="isUnresolved(c) ? '1' : '0'">{{ t(`writer.verdict.${c.status}`) }}</AppBadge>
            </button>
          </li>
        </ul>
        <div v-if="claims.next.value" class="border-t border-line p-3 text-center">
          <AppButton variant="ghost" size="sm" :loading="claims.loadingMore.value" @click="claims.more">{{ t('common.loadMore') }}</AppButton>
        </div>
      </AppCard>
    </div>

    <AppCard class="h-fit xl:sticky xl:top-4">
      <h3 class="mb-3 text-sm font-semibold">{{ t('writer.facts.checklist') }}</h3>
      <LoadingState v-if="checklist.loading.value && !checklist.data.value" />
      <ErrorState v-else-if="checklist.error.value" :error="checklist.error.value" @retry="checklist.run" />
      <template v-else-if="checklist.data.value">
        <div v-if="checklist.data.value.blocking.length" class="mb-3" data-testid="blocking">
          <p class="text-xs font-medium text-danger-600">{{ t('writer.facts.blocking') }}</p>
          <ul class="mt-1 space-y-1 text-xs text-danger-600"><li v-for="b in checklist.data.value.blocking" :key="b.code">• {{ b.message }}</li></ul>
        </div>
        <div v-if="checklist.data.value.warnings.length" class="mb-3">
          <p class="text-xs font-medium text-warning-600">{{ t('writer.facts.warnings') }}</p>
          <ul class="mt-1 space-y-1 text-xs text-warning-600"><li v-for="w in checklist.data.value.warnings" :key="w.code">• {{ w.message }}</li></ul>
        </div>
        <p class="mb-3 text-xs text-ink-muted">{{ t('writer.facts.aiNeverApproves') }}</p>
        <template v-if="article?.status === 'NEEDS_HUMAN_REVIEW' || article?.status === 'QUALITY_REVIEW'">
          <template v-if="checklist.data.value.can_approve && canApprove">
            <label v-if="needsAck" class="mb-2 flex items-start gap-2 text-xs"><input v-model="ack" type="checkbox" class="mt-0.5" /> {{ t('writer.facts.ack') }}</label>
            <AppInput v-model="approveNote" :label="t('writer.facts.approveNote')" :hint="t('common.optional')" />
            <AppButton class="mt-2" block :loading="acting" :disabled="!checklist.data.value.ok" data-testid="approve-article" @click="approve">{{ t('writer.facts.approve') }}</AppButton>
          </template>
          <p v-else class="text-xs text-ink-muted">{{ t('writer.facts.noApprovePermission') }}</p>
          <template v-if="canEdit">
            <WTextarea v-model="changesNote" class="mt-4" :rows="2" :label="t('writer.facts.requestChanges')" :placeholder="t('writer.facts.requestNote')" />
            <AppButton class="mt-2" block variant="secondary" :loading="acting" :disabled="changesNote.trim().length < 5" @click="requestChanges">{{ t('writer.facts.requestChanges') }}</AppButton>
          </template>
        </template>
        <div v-if="checklist.data.value.approvals.length" class="mt-4 border-t border-line pt-3">
          <p class="mb-1 text-xs font-medium text-ink-muted">{{ t('writer.facts.approvals') }}</p>
          <ul class="space-y-1 text-xs">
            <li v-for="ap in checklist.data.value.approvals" :key="ap.id">
              {{ t(`writer.facts.decision.${ap.decision}`, ap.decision) }} · {{ formatDateTime(ap.created_at) }}
              <span v-if="ap.note" class="block text-ink-muted">“{{ ap.note }}”</span>
              <span v-if="ap.invalidated_at" class="block text-warning-600">{{ t('writer.facts.invalidated', { reason: ap.invalidated_reason ?? '' }) }}</span>
            </li>
          </ul>
        </div>
      </template>
    </AppCard>

    <Drawer :open="!!open" :title="open ? t(`writer.risk.${open.risk_level}`) + ' · ' + open.category : ''" @close="open = null">
      <div v-if="open" class="space-y-4 text-sm">
        <p class="font-medium">{{ open.claim }}</p>
        <div class="flex flex-wrap gap-2">
          <AppBadge :tone="verdictTone(open.status)">{{ t(`writer.verdict.${open.status}`) }}</AppBadge>
          <AppBadge v-if="open.is_stale" tone="warning">{{ t('writer.facts.stale') }}</AppBadge>
        </div>
        <p v-if="open.evidence_requirements" class="text-xs text-ink-muted">{{ open.evidence_requirements }}</p>
        <LoadingState v-if="detailLoading" />
        <template v-else-if="open.latest_result">
          <p v-if="open.latest_result.explanation"><span class="text-xs font-medium text-ink-muted">{{ t('writer.facts.explanation') }}:</span> {{ open.latest_result.explanation }}</p>
          <ul v-if="open.latest_result.rule_notes.length" class="list-disc pl-5 text-xs text-ink-muted"><li v-for="n in open.latest_result.rule_notes" :key="n">{{ n }}</li></ul>
          <div v-for="kind in (['supporting', 'contradicting'] as const)" :key="kind">
            <p class="text-xs font-medium" :class="kind === 'supporting' ? 'text-success-600' : 'text-danger-600'">{{ t(`writer.facts.${kind}`) }}</p>
            <p v-if="!open.latest_result[kind].length" class="text-xs text-ink-muted">{{ kind === 'supporting' ? t('writer.facts.noEvidenceFound') : '—' }}</p>
            <ul v-else class="mt-1 space-y-2">
              <li v-for="ev in open.latest_result[kind]" :key="ev.evidence_id" class="rounded border border-line p-2 text-xs">
                <span class="font-mono">[{{ ev.ref }}]</span> “{{ ev.excerpt }}”
                <a v-if="ev.url" :href="ev.url" target="_blank" rel="noopener noreferrer nofollow" class="mt-1 block truncate text-brand-600 underline">{{ ev.title || ev.url }}</a>
                <span class="text-ink-muted"><template v-if="ev.reliability !== null">{{ t('writer.research.reliability') }} {{ ev.reliability }}</template><template v-if="ev.date"> · {{ ev.date }}</template></span>
              </li>
            </ul>
          </div>
        </template>
        <div v-if="open.history && open.history.length > 1">
          <p class="text-xs font-medium text-ink-muted">{{ t('writer.facts.history') }}</p>
          <ul class="mt-1 space-y-1 text-xs">
            <li v-for="h in open.history" :key="h.id"><AppBadge :tone="verdictTone(h.verdict)">{{ t(`writer.verdict.${h.verdict}`) }}</AppBadge> {{ t('writer.facts.reviewedBy', { who: reviewer(h), time: formatDateTime(h.reviewed_at) }) }}</li>
          </ul>
        </div>

        <form v-if="canVerify" class="space-y-3 border-t border-line pt-4" novalidate @submit.prevent="verify" @focusin="loadEvidence">
          <h4 class="text-sm font-semibold">{{ t('writer.facts.verify') }}</h4>
          <p v-if="formError" class="rounded bg-danger-50 px-2 py-1 text-xs text-danger-600" role="alert">{{ formError }}</p>
          <AppSelect v-model="form.verdict" :label="t('writer.facts.verdictLabel')" :options="verdictFormOptions" :error="errors.verdict" />
          <p class="text-xs text-ink-muted">{{ t(`writer.verdictHelp.${form.verdict}`) }}</p>
          <WTextarea v-model="form.note" :rows="2" :label="t('writer.facts.note')" :error="errors.note" />
          <fieldset v-if="verdictNeedsEvidence(form.verdict)">
            <legend class="text-sm font-medium">{{ t('writer.facts.evidenceIds') }}</legend>
            <ul class="mt-1 max-h-48 space-y-1 overflow-y-auto rounded border border-line p-2 text-xs">
              <li v-if="!evidence.length" class="text-ink-muted">{{ t('writer.editor.noCitations') }}</li>
              <li v-for="e in evidence" :key="e.id"><label class="flex items-start gap-2"><input v-model="form.evidence_ids" type="checkbox" :value="e.id" class="mt-0.5" /> <span><span class="font-mono">[{{ e.ref }}]</span> {{ e.statement }}</span></label></li>
            </ul>
            <AppInput v-model="form.reference_url" class="mt-2" :label="t('writer.facts.referenceUrl')" placeholder="https://" :error="errors.reference_url" />
            <p v-if="missingEvidence" class="mt-1 text-xs text-warning-600">{{ t('writer.facts.needEvidence') }}</p>
          </fieldset>
          <AppButton type="submit" block :loading="submitting" :disabled="form.note.trim().length < 10 || missingEvidence">{{ t('common.save') }}</AppButton>
        </form>
        <p v-if="!canVerify && isCritical && canEdit && !canApprove" class="text-xs text-warning-600">{{ t('writer.facts.criticalNeedsApprover') }}</p>
      </div>
    </Drawer>
  </div>
</template>
