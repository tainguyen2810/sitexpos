<script setup lang="ts">
// SEO score of the article, shown next to the AI budget. It is the Phase 6
// SEO audit (rule checklist, no AI call, no ranking promise). The audit runs
// once by itself when the current version has none yet.
import { computed, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { seoApi, type AuditRun } from '@/shared/api/seo'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import { useArticle } from '../articleContext'
import { useWriterErrors } from '../useWriter'

const { t } = useI18n()
const { workspaceId: ws, articleId: id, article, busy, canEdit } = useArticle()
const { toastError } = useWriterErrors()

const audit = shallowRef<AuditRun | null>(null)
const loaded = ref(false)
const running = ref(false)
const autoRanFor = new Set<string>()

const hasContent = computed(() => !!article.value?.current_version_id)
const stale = computed(() => !!audit.value && !!article.value?.current_version_id && audit.value.article_version_id !== article.value.current_version_id)
const level = computed<'danger' | 'warning' | 'success'>(() => {
  const s = audit.value?.score ?? 0
  if ((audit.value?.blocked_count ?? 0) > 0 || s < 50) return 'danger'
  return s < 80 ? 'warning' : 'success'
})
const tone = computed(() => ({ danger: 'text-danger-600', warning: 'text-warning-600', success: 'text-success-600' })[level.value])

async function run(silent = false) {
  running.value = true
  try {
    audit.value = await seoApi.runAudit(ws, id)
  } catch (e) {
    if (!silent) toastError(e)
  } finally {
    running.value = false
  }
}

async function load() {
  if (!hasContent.value || busy.value) return
  try {
    audit.value = (await seoApi.audits(ws, id))[0] ?? null
  } catch {
    audit.value = null
  } finally {
    loaded.value = true
  }
  const v = article.value?.current_version_id
  if (canEdit.value && v && (!audit.value || stale.value) && !autoRanFor.has(v)) {
    autoRanFor.add(v)
    await run(true)
  }
}
watch(() => [article.value?.current_version_id, busy.value], () => void load(), { immediate: true })
</script>

<template>
  <AppCard v-if="hasContent" data-testid="seo-score">
    <p class="text-sm text-ink-muted">{{ t('writer.seo.title') }}</p>
    <template v-if="audit">
      <p class="mt-1 text-lg font-semibold tabular-nums"><span :class="tone">{{ audit.score }}</span> <span class="text-sm font-normal text-ink-muted">/ 100</span></p>
      <ProgressBar class="mt-2" :tone="level" :value="audit.score" :max="100" />
      <p class="mt-2 text-xs text-ink-muted">{{ t('writer.seo.counts', { e: audit.error_count + audit.blocked_count, w: audit.warning_count }) }}</p>
      <p v-if="stale" class="mt-1 text-xs text-warning-600">{{ t('writer.seo.stale') }}</p>
    </template>
    <p v-else-if="loaded && !running" class="mt-1 text-sm text-ink-muted">{{ t('writer.seo.none') }}</p>
    <p class="mt-2 text-xs text-ink-muted">{{ t('writer.seo.note') }}</p>
    <div class="mt-2 flex flex-wrap gap-1">
      <AppButton v-if="canEdit" size="sm" variant="ghost" :loading="running" :disabled="busy" data-testid="seo-run" @click="run()">{{ t('writer.seo.run') }}</AppButton>
      <RouterLink :to="`/w/${ws}/seo/audit/${id}`" class="inline-flex items-center px-2 py-1 text-sm text-brand-600 hover:underline">{{ t('writer.seo.details') }}</RouterLink>
    </div>
  </AppCard>
</template>
