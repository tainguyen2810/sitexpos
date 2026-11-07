<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { writerApi } from '@/shared/api/writer'
import { useAsync } from '@/shared/composables/useAsync'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDate, formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import SearchInput from '@/shared/ui/SearchInput.vue'
import StatCard from '@/shared/ui/StatCard.vue'
import { useArticle } from '../articleContext'

const { t, te } = useI18n()
const { workspaceId: ws, articleId: id, article } = useArticle()
const dossier = useAsync(() => writerApi.dossier(ws, id))
const source = ref('')
const q = ref('')
const evidence = useCursorList((cursor) => writerApi.evidence(ws, id, { cursor, source_id: source.value, q: q.value }))
void evidence.reload()
watch(() => article.value?.status, () => {
  void dossier.run()
  void evidence.reload()
})
let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(() => void evidence.reload(), 300)
})
watch(source, () => void evidence.reload())

const sourceOptions = computed(() => [{ value: '', label: t('writer.research.allSources') }, ...(dossier.data.value?.sources ?? [])
  .filter((s) => s.retrieval_status === 'fetched').map((s) => ({ value: s.id, label: (s.title ?? s.url).slice(0, 60) }))])
const relTone = (n: number | null) => (n === null ? 'neutral' : n >= 75 ? 'success' : n >= 55 ? 'warning' : 'danger') as 'neutral' | 'success' | 'warning' | 'danger'
const tr = (prefix: string, v: string) => (te(`${prefix}.${v}`) ? t(`${prefix}.${v}`) : v)
const warningText = (w: string) => {
  const [code, detail] = w.split(':')
  const k = `writer.research.warning.${code}`
  return te(k) ? t(k) + (detail ? ` (${detail})` : '') : w
}
const hostOf = (u: string) => {
  try {
    return new URL(u).host
  } catch {
    return u
  }
}
</script>

<template>
  <LoadingState v-if="dossier.loading.value && !dossier.data.value" />
  <ErrorState v-else-if="dossier.error.value" :error="dossier.error.value" @retry="dossier.run" />
  <EmptyState v-else-if="!dossier.data.value" icon="search" :title="t('writer.research.empty')" :description="t('writer.research.emptyBody')" />
  <div v-else class="space-y-4">
    <div class=" ">
      <StatCard :label="t('writer.research.questions')" :value="dossier.data.value.questions_count" icon="list" />
      <StatCard :label="t('writer.research.sources')" :value="t('writer.research.usable', { usable: dossier.data.value.usable_sources_count, total: dossier.data.value.sources_count })" icon="globe" />
      <StatCard :label="t('writer.research.evidence')" :value="dossier.data.value.evidence_count" icon="shieldCheck" :hint="t('writer.research.rejected', { n: dossier.data.value.rejected_evidence_count })" />
      <StatCard :label="t('common.status')" :value="dossier.data.value.status" icon="activity" :hint="formatDateTime(dossier.data.value.completed_at)" />
    </div>
    <p v-if="!dossier.data.value.retrieval_available" class="rounded-lg bg-danger-50 px-4 py-3 text-sm text-danger-600" role="alert">{{ t('writer.research.retrievalOff') }}</p>
    <ul v-if="dossier.data.value.warnings.length" class="rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-600">
      <li v-for="w in dossier.data.value.warnings" :key="w">{{ warningText(w) }}</li>
    </ul>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_1.4fr]">
      <AppCard>
        <h3 class="mb-3 text-sm font-semibold">{{ t('writer.research.questions') }}</h3>
        <ol class="list-decimal space-y-2 pl-5 text-sm">
          <li v-for="qq in dossier.data.value.questions" :key="qq.id">{{ qq.question }}<span v-if="qq.purpose" class="block text-xs text-ink-muted">{{ qq.purpose }}</span></li>
        </ol>
      </AppCard>
      <AppCard :padded="false">
        <h3 class="px-5 pt-4 text-sm font-semibold">{{ t('writer.research.sources') }}</h3>
        <ul class="divide-y divide-line" data-testid="source-list">
          <li v-for="s in dossier.data.value.sources" :key="s.id" class="px-5 py-3 text-sm">
            <div class="flex flex-wrap items-center gap-2">
              <a :href="s.final_url ?? s.url" target="_blank" rel="noopener noreferrer nofollow" class="min-w-0 flex-1 truncate font-medium text-brand-600 underline">{{ s.title || hostOf(s.url) }}</a>
              <AppBadge :tone="s.retrieval_status === 'fetched' ? 'success' : s.retrieval_status === 'pending' ? 'neutral' : 'danger'">{{ tr('writer.research.retrieval', s.retrieval_status) }}</AppBadge>
              <AppBadge v-if="s.reliability_score !== null" :tone="relTone(s.reliability_score)">{{ t('writer.research.reliability') }} {{ s.reliability_score }}</AppBadge>
            </div>
            <p class="mt-1 text-xs text-ink-muted">
              {{ hostOf(s.final_url ?? s.url) }} · {{ tr('writer.research.sourceType', s.source_type) }} · {{ tr('writer.research.via', s.discovered_via) }}
              · {{ t('writer.research.published') }}: {{ s.published_at ? formatDate(s.published_at) : t('writer.research.unknownDate') }}
              <template v-if="s.retrieved_at"> · {{ t('writer.research.retrieved') }} {{ formatDateTime(s.retrieved_at) }}</template>
              <template v-if="s.error_code"> · <span class="text-danger-600">{{ s.error_code }}<template v-if="s.http_status"> (HTTP {{ s.http_status }})</template></span></template>
            </p>
          </li>
        </ul>
      </AppCard>
    </div>

    <AppCard :padded="false">
      <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
        <h3 class="mr-auto text-sm font-semibold">{{ t('writer.research.evidence') }}</h3>
        <SearchInput v-model="q" :placeholder="t('writer.research.searchEvidence')" />
        <FilterSelect v-model="source" :label="t('writer.research.sources')" :options="sourceOptions" />
      </div>
      <LoadingState v-if="evidence.loading.value" class="p-5" />
      <ErrorState v-else-if="evidence.error.value" :error="evidence.error.value" class="p-5" @retry="evidence.reload" />
      <p v-else-if="!evidence.items.value.length" class="p-5 text-sm text-ink-muted">{{ t('writer.research.noEvidence') }}</p>
      <ul v-else class="divide-y divide-line" data-testid="evidence-list">
        <li v-for="e in evidence.items.value" :key="e.id" class="px-5 py-3 text-sm">
          <p><span class="mr-2 rounded bg-surface-muted px-1.5 font-mono text-xs">[{{ e.ref }}]</span><span class="font-medium">{{ e.statement }}</span></p>
          <blockquote class="mt-1 border-l-2 border-line pl-3 text-xs text-ink-muted">“{{ e.excerpt }}”</blockquote>
          <p v-if="e.source" class="mt-1 text-xs text-ink-muted">
            <a :href="e.source.url" target="_blank" rel="noopener noreferrer nofollow" class="underline">{{ e.source.title || hostOf(e.source.url) }}</a>
            <template v-if="e.date_context"> · {{ e.date_context }}</template><template v-if="e.version_context"> · {{ e.version_context }}</template>
          </p>
        </li>
      </ul>
      <div v-if="evidence.next.value" class="border-t border-line p-3 text-center">
        <AppButton variant="ghost" size="sm" :loading="evidence.loadingMore.value" @click="evidence.more">{{ t('common.loadMore') }}</AppButton>
      </div>
    </AppCard>
  </div>
</template>
