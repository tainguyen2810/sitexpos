<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { analyticsApi, micro, refreshTone } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import { useAnalytics } from './useAnalytics'

/**
 * One content refresh: WordPress check → brief → section drafts (Writer) →
 * fact-check → human approval → SEO audit → Publisher update → monitoring.
 * The diff is plain text (no HTML rendering); approval and publishing are
 * human actions and the Publisher re-runs every gate.
 */
const WAITING = ['queued', 'checking_wordpress', 'analyzing', 'brief_ready', 'drafting', 'fact_checking', 'approved', 'publishing']
const { t } = useI18n()
const route = useRoute()
const { workspaceId, canEdit, canPublish, toast, toastError } = useAnalytics()
const id = computed(() => String(route.params.refreshId))
const { data, error, loading, run } = useAsync(() => analyticsApi.refreshJob(workspaceId.value, id.value))
const job = computed(() => data.value?.data ?? null)
const busy = ref('')
const note = ref('')
const confirm = ref<'' | 'approve' | 'publish' | 'cancel'>('')
const onlyChanged = ref(true)
const sections = computed(() => (job.value?.diff?.sections ?? []).filter((s) => !onlyChanged.value || s.changed))

let timer: ReturnType<typeof setInterval> | null = null
watch(
  () => job.value?.status,
  (s) => {
    if (timer) clearInterval(timer)
    // Read-only refresh of the page while the queue works; no writes, no audit noise.
    timer = s && WAITING.includes(s) ? setInterval(() => void run(), 8000) : null
  },
)
onBeforeUnmount(() => timer && clearInterval(timer))

/** Ask the queue to check the Writer / Publisher again (only for steps that wait on them). */
async function poll() {
  busy.value = 'poll'
  try {
    data.value = await analyticsApi.pollRefresh(workspaceId.value, id.value)
    toast.success(t('an.refresh.done.poll'))
    setTimeout(() => void run(), 1500)
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = ''
  }
}
async function act(kind: 'approve' | 'publish' | 'cancel') {
  busy.value = kind
  try {
    if (kind === 'approve') data.value = await analyticsApi.approveRefresh(workspaceId.value, id.value, note.value || undefined)
    if (kind === 'publish') data.value = await analyticsApi.publishRefresh(workspaceId.value, id.value)
    if (kind === 'cancel') data.value = await analyticsApi.cancelRefresh(workspaceId.value, id.value)
    toast.success(t(`an.refresh.done.${kind}`))
    confirm.value = ''
    setTimeout(() => void run(), 1500)
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <div>
    <PageHeader
      :title="t('an.refresh.detail')"
      :description="t('an.refresh.detailHint')"
      :crumbs="[{ label: t('an.nav.refresh'), to: `/w/${workspaceId}/analytics/refresh` }, { label: id }]"
    />
    <LoadingState v-if="loading && !data" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <template v-else-if="job">
      <div class="mb-6 flex flex-wrap items-center gap-3">
        <AppBadge :tone="refreshTone(job.status)" data-testid="refresh-status">{{ t(`an.refresh.status.${job.status}`) }}</AppBadge>
        <AppBadge v-if="job.agent_task_id" tone="brand">{{ t('an.refresh.byAgent') }}</AppBadge>
        <AppBadge v-if="job.is_demo">DEMO</AppBadge>
        <span class="text-sm text-ink-muted">{{ t('an.opportunities.cost') }}: {{ micro(job.estimated_cost_micro_usd) }} ({{ t('an.common.estimate') }})</span>
        <RouterLink :to="{ name: 'analytics-article', params: { workspaceId, articleId: job.article_id } }" class="text-sm text-brand-700 hover:underline">{{ t('an.refresh.viewArticle') }}</RouterLink>
        <div class="ml-auto flex flex-wrap gap-2">
          <AppButton v-if="canPublish && job.status === 'awaiting_approval'" size="sm" data-testid="refresh-approve" @click="confirm = 'approve'">{{ t('an.refresh.approve') }}</AppButton>
          <AppButton v-if="canPublish && job.status === 'awaiting_publish'" size="sm" data-testid="refresh-publish" @click="confirm = 'publish'">{{ t('an.refresh.publish') }}</AppButton>
          <AppButton v-if="canEdit && ['drafting', 'fact_checking', 'publishing'].includes(job.status)" size="sm" variant="ghost" :loading="busy === 'poll'" @click="poll">{{ t('an.refresh.checkNow') }}</AppButton>
          <AppButton v-if="canEdit && !['completed', 'conflict', 'failed', 'cancelled', 'seo_blocked', 'publishing', 'monitoring'].includes(job.status)" size="sm" variant="secondary" @click="confirm = 'cancel'">
            {{ t('an.refresh.cancel') }}
          </AppButton>
        </div>
      </div>
      <p v-if="job.status === 'awaiting_approval' && !canPublish" class="mb-4 rounded-lg bg-surface-muted p-3 text-sm text-ink-muted">{{ t('an.refresh.needsPublisher') }}</p>
      <p v-if="job.error_code" class="mb-4 rounded-lg bg-danger-50 p-3 text-sm text-danger-600" data-testid="refresh-error">{{ t(`an.errors.${job.error_code}`, job.error_message ?? job.error_code) }}</p>
      <p v-if="job.status === 'conflict'" class="mb-4 rounded-lg bg-warning-50 p-3 text-sm text-warning-700">{{ t('an.refresh.conflictHint') }}</p>

      <div class="grid gap-6 lg:grid-cols-3">
        <AppCard :title="t('an.refresh.brief')" class="lg:col-span-1">
          <EmptyState v-if="!job.brief" icon="file" :title="t('an.refresh.noBrief')" />
          <div v-else class="space-y-3 text-sm">
            <p><span class="text-ink-muted">{{ t('an.refresh.goal') }}:</span> {{ job.brief.goal ? t(`an.actions.${job.brief.goal}`, job.brief.goal) : '—' }}</p>
            <div>
              <h3 class="font-medium">{{ t('an.refresh.targets') }}</h3>
              <ul class="mt-1 space-y-1">
                <li v-for="s in job.brief.target_sections ?? []" :key="s.key">
                  <span class="font-medium">{{ s.heading }}</span> — {{ t(`an.refresh.sectionAction.${s.action}`, s.action) }}
                  <p class="text-xs text-ink-muted">{{ s.reason }}</p>
                </li>
              </ul>
            </div>
            <div v-if="job.brief.questions_to_research?.length">
              <h3 class="font-medium">{{ t('an.refresh.research') }}</h3>
              <ul class="mt-1 list-disc pl-5 text-xs"><li v-for="q in job.brief.questions_to_research" :key="q">{{ q }}</li></ul>
            </div>
            <p v-if="job.brief.keep" class="text-xs text-ink-muted">{{ job.brief.keep }}</p>
            <p v-if="job.brief.limits" class="text-xs text-ink-muted">{{ job.brief.limits }}</p>
          </div>
        </AppCard>
        <AppCard :title="t('an.refresh.diff')" :subtitle="job.diff ? t('an.refresh.changedSections', { n: job.diff.changed }) : undefined" class="lg:col-span-2" :padded="false">
          <template #actions>
            <label class="flex items-center gap-2 text-xs"><input v-model="onlyChanged" type="checkbox" /> {{ t('an.refresh.onlyChanged') }}</label>
          </template>
          <EmptyState v-if="!job.diff" icon="layers" :title="t('an.refresh.noDiff')" />
          <div v-else class="divide-y divide-line" data-testid="refresh-diff">
            <section v-for="s in sections" :key="s.heading" class="p-4">
              <h3 class="mb-2 text-sm font-semibold">{{ s.heading }}<AppBadge v-if="s.changed" tone="warning" class="ml-2">{{ t('an.refresh.changed') }}</AppBadge></h3>
              <div class="grid gap-3 md:grid-cols-2">
                <div><p class="mb-1 text-xs uppercase text-ink-muted">{{ t('an.refresh.before') }}</p><p class="whitespace-pre-line rounded bg-danger-50 p-2 text-xs">{{ s.before || '—' }}</p></div>
                <div><p class="mb-1 text-xs uppercase text-ink-muted">{{ t('an.refresh.after') }}</p><p class="whitespace-pre-line rounded bg-success-50 p-2 text-xs">{{ s.after || '—' }}</p></div>
              </div>
            </section>
          </div>
        </AppCard>
        <AppCard :title="t('an.refresh.checks')" class="lg:col-span-3">
          <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
            <div>
              <dt class="text-ink-muted">{{ t('an.refresh.factCheck') }}</dt>
              <dd v-if="job.fact_check">{{ t('an.refresh.unresolved', { c: job.fact_check.unresolved_critical ?? 0, h: job.fact_check.unresolved_high ?? 0 }) }}</dd>
              <dd v-else class="text-ink-muted">{{ t('an.common.noData') }}</dd>
            </div>
            <div>
              <dt class="text-ink-muted">{{ t('an.refresh.seoAudit') }}</dt>
              <dd v-if="job.seo_audit">{{ t('an.refresh.seoResult', { s: job.seo_audit.score, e: job.seo_audit.errors, w: job.seo_audit.warnings }) }}</dd>
              <dd v-else class="text-ink-muted">{{ t('an.refresh.seoLater') }}</dd>
            </div>
            <div>
              <dt class="text-ink-muted">{{ t('an.refresh.wordpress') }}</dt>
              <dd>{{ job.wp_check ? (Array.isArray(job.wp_check.conflicts) && job.wp_check.conflicts.length ? t('an.refresh.wpChanged') : t('an.refresh.wpOk')) : t('an.common.noData') }}</dd>
            </div>
            <div><dt class="text-ink-muted">{{ t('an.common.createdAt') }}</dt><dd>{{ formatDateTime(job.created_at) }}</dd></div>
            <div><dt class="text-ink-muted">{{ t('an.refresh.approvedAt') }}</dt><dd>{{ formatDateTime(job.approved_at) }}</dd></div>
            <div>
              <dt class="text-ink-muted">{{ t('an.nav.experiments') }}</dt>
              <dd>
                <RouterLink v-if="job.experiment_id" :to="{ name: 'analytics-experiments', params: { workspaceId } }" class="text-brand-700 hover:underline">{{ t('an.refresh.monitoring') }}</RouterLink>
                <span v-else class="text-ink-muted">—</span>
              </dd>
            </div>
          </dl>
        </AppCard>
      </div>
    </template>

    <ConfirmDialog
      :open="confirm === 'approve'"
      :title="t('an.refresh.approve')"
      :message="t('an.refresh.approveConfirm')"
      :loading="busy === 'approve'"
      @close="confirm = ''"
      @confirm="act('approve')"
    />
    <ConfirmDialog
      :open="confirm === 'publish'"
      :title="t('an.refresh.publish')"
      :message="t('an.refresh.publishConfirm')"
      :loading="busy === 'publish'"
      @close="confirm = ''"
      @confirm="act('publish')"
    />
    <ConfirmDialog :open="confirm === 'cancel'" :title="t('an.refresh.cancel')" :message="t('an.refresh.cancelConfirm')" danger :loading="busy === 'cancel'" @close="confirm = ''" @confirm="act('cancel')" />
  </div>
</template>
