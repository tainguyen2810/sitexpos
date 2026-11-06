<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { writerApi } from '@/shared/api/writer'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import { useArticle } from '../articleContext'
import { useWriterErrors } from '../useWriter'

const { t, te } = useI18n()
const { workspaceId: ws, articleId: id, article, reload, busy, canEdit } = useArticle()
const { toastError } = useWriterErrors()
const quality = useAsync(() => writerApi.quality(ws, id))
const checklist = useAsync(() => writerApi.checklist(ws, id))
watch(() => article.value?.updated_at, () => {
  void quality.run()
  void checklist.run()
})
const report = computed(() => quality.data.value?.current ?? null)
const min = computed(() => checklist.data.value?.stats.min_quality ?? null)
const running = ref(false)
async function audit() {
  running.value = true
  try {
    await writerApi.audit(ws, id)
    await Promise.all([quality.run(), reload()])
  } catch (e) {
    toastError(e)
  } finally {
    running.value = false
  }
}
const label = (k: string) => (te(`writer.quality.criterion.${k}`) ? t(`writer.quality.criterion.${k}`) : k)
const sevTone = (s: string) => (s === 'error' ? 'danger' : s === 'warning' ? 'warning' : 'neutral') as 'danger' | 'warning' | 'neutral'
</script>

<template>
  <LoadingState v-if="quality.loading.value && !quality.data.value" />
  <ErrorState v-else-if="quality.error.value" :error="quality.error.value" @retry="quality.run" />
  <div v-else class="space-y-4">
    <AppCard>
      <div class="flex flex-wrap items-center gap-4">
        <div>
          <p class="text-sm text-ink-muted">{{ t('writer.quality.score') }}</p>
          <p class="text-4xl font-semibold tabular-nums" data-testid="quality-score">{{ report?.score ?? '—' }}<span class="text-base text-ink-muted">/100</span></p>
        </div>
        <p v-if="min !== null" class="text-sm" :class="report && report.score < min ? 'text-danger-600' : 'text-ink-muted'">{{ t('writer.quality.min', { n: min }) }}</p>
        <AppButton v-if="canEdit && article?.current_version_id" class="ml-auto" variant="secondary" :loading="running" :disabled="busy" @click="audit">{{ t('writer.quality.run') }}</AppButton>
      </div>
      <p class="mt-3 rounded-lg bg-surface-muted px-3 py-2 text-xs text-ink-muted" data-testid="quality-disclaimer">{{ report?.disclaimer ?? t('writer.facts.aiNeverApproves') }}</p>
    </AppCard>

    <EmptyState v-if="!report" icon="chart" :title="t('writer.quality.empty')" />
    <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <AppCard>
        <h3 class="mb-3 text-sm font-semibold">{{ t('writer.quality.criteria') }}</h3>
        <ul class="space-y-3">
          <li v-for="c in report.criteria" :key="c.key">
            <div class="flex justify-between text-sm"><span>{{ label(c.key) }}</span><span class="tabular-nums">{{ c.score }}/{{ c.max }}</span></div>
            <ProgressBar :value="c.score" :max="c.max" tone="auto" />
            <ul v-if="c.notes.length" class="mt-1 text-xs text-ink-muted"><li v-for="n in c.notes" :key="n">• {{ n }}</li></ul>
          </li>
        </ul>
      </AppCard>
      <div class="space-y-4">
        <AppCard>
          <h3 class="mb-3 text-sm font-semibold">{{ t('writer.quality.issues') }}</h3>
          <p v-if="!report.issues.length" class="text-sm text-ink-muted">{{ t('writer.quality.noIssues') }}</p>
          <ul v-else class="space-y-2 text-sm">
            <li v-for="(i, k) in report.issues" :key="k" class="flex items-start gap-2"><AppBadge :tone="sevTone(i.severity)">{{ i.severity }}</AppBadge> {{ i.message }}</li>
          </ul>
        </AppCard>
        <AppCard v-if="report.ai_notes.length">
          <h3 class="mb-3 text-sm font-semibold">{{ t('writer.quality.aiNotes') }}</h3>
          <ul class="space-y-1 text-sm"><li v-for="n in report.ai_notes" :key="n">• {{ n }}</li></ul>
        </AppCard>
        <AppCard v-if="quality.data.value!.history.length > 1">
          <h3 class="mb-3 text-sm font-semibold">{{ t('writer.quality.history') }}</h3>
          <ul class="space-y-1 text-xs"><li v-for="h in quality.data.value!.history" :key="h.id">{{ h.score }} · {{ formatDateTime(h.created_at) }}</li></ul>
        </AppCard>
      </div>
    </div>
  </div>
</template>
