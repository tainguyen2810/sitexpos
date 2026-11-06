<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { AUDIT_CATEGORIES, seoApi, severityTone, sortIssues, type AuditRun, type SchemaPreview } from '@/shared/api/seo'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import ArticlePicker from './ArticlePicker.vue'
import SectionNav from './SectionNav.vue'
import { useArticleParam } from './useArticleParam'
import { usePhase6Errors, usePhase6Perms } from './usePhase6'

const { t } = useI18n()
const { workspaceId, canEdit } = usePhase6Perms()
const { toastError, toast } = usePhase6Errors()
const run = shallowRef<AuditRun | null>(null)
const history = shallowRef<AuditRun[]>([])
const schema = shallowRef<SchemaPreview | null>(null)
const loading = ref(false)
const error = shallowRef<unknown>(null)
const running = ref(false)
const severity = ref('')
const category = ref('')

async function load(id: string) {
  run.value = null
  schema.value = null
  if (!id) return
  loading.value = true
  error.value = null
  try {
    history.value = await seoApi.audits(workspaceId.value, id)
    run.value = history.value[0] ? await seoApi.audit(workspaceId.value, history.value[0].id) : null
    schema.value = await seoApi.schema(workspaceId.value, id).catch(() => null)
  } catch (e) {
    error.value = e
  } finally {
    loading.value = false
  }
}
const { articles, articleId, listError, listLoading, loadList } = useArticleParam('seo', (id) => void load(id))

async function runAudit() {
  running.value = true
  try {
    run.value = await seoApi.runAudit(workspaceId.value, articleId.value)
    history.value = await seoApi.audits(workspaceId.value, articleId.value)
    void loadList()
  } catch (e) {
    toastError(e)
  } finally {
    running.value = false
  }
}
async function checkLinks() {
  try {
    await seoApi.checkLinks(workspaceId.value, articleId.value)
    toast.success(t('p6.seo.audit.linkCheckQueued'))
  } catch (e) {
    toastError(e)
  }
}
async function openRun(id: string) {
  try {
    run.value = await seoApi.audit(workspaceId.value, id)
  } catch (e) {
    toastError(e)
  }
}
const issues = computed(() => sortIssues((run.value?.issues ?? []).filter((i) => (!severity.value || i.severity === severity.value) && (!category.value || i.category === category.value))))
const sevOpts = computed(() => [{ value: '', label: t('common.all') }, ...['BLOCKED', 'ERROR', 'WARNING', 'PASS'].map((s) => ({ value: s, label: t(`p6.seo.severity.${s}`) }))])
const catOpts = computed(() => [{ value: '', label: t('common.all') }, ...AUDIT_CATEGORIES.map((c) => ({ value: c, label: t(`p6.seo.category.${c}`) }))])
</script>

<template>
  <div>
    <PageHeader :title="t('p6.seo.title')" :description="t('p6.seo.subtitle')" />
    <SectionNav section="seo" active="audit" />
    <AppCard class="mb-6" :padded="false">
      <LoadingState v-if="listLoading" />
      <ErrorState v-else-if="listError" :error="listError" @retry="loadList" />
      <EmptyState v-else-if="!articles.length" icon="file" :title="t('p6.seo.noArticles')" :description="t('p6.seo.noArticlesHint')" />
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[36rem] text-left text-sm" data-testid="seo-articles">
          <thead class="border-b border-line text-xs text-ink-muted"><tr><th class="px-5 py-2">{{ t('p6.common.article') }}</th><th class="px-2">{{ t('p6.seo.audit.score') }}</th><th class="px-2">{{ t('p6.seo.audit.result') }}</th><th class="px-5 text-right">{{ t('p6.seo.audit.lastRun') }}</th></tr></thead>
          <tbody class="divide-y divide-line">
            <tr v-for="a in articles" :key="a.id" class="cursor-pointer hover:bg-surface-muted" :class="a.id === articleId ? 'bg-brand-50' : ''" @click="articleId = a.id">
              <td class="max-w-xs truncate px-5 py-2 font-medium">{{ a.title }}</td>
              <td class="px-2 tabular-nums">{{ a.latest_audit ? a.latest_audit.score : '—' }}</td>
              <td class="px-2">
                <AppBadge v-if="!a.latest_audit" tone="neutral">{{ t('p6.seo.audit.notRun') }}</AppBadge>
                <AppBadge v-else-if="a.latest_audit.blocked_count" tone="danger">{{ t('p6.seo.severity.BLOCKED') }}</AppBadge>
                <AppBadge v-else-if="a.latest_audit.error_count" tone="danger">{{ t('p6.seo.audit.errors', { n: a.latest_audit.error_count }) }}</AppBadge>
                <AppBadge v-else tone="success">{{ t('p6.seo.audit.ok') }}</AppBadge>
              </td>
              <td class="px-5 text-right text-xs text-ink-muted">{{ formatDateTime(a.latest_audit?.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppCard>

    <EmptyState v-if="!articleId" icon="search" :title="t('p6.common.pickArticleFirst')" />
    <LoadingState v-else-if="loading" />
    <ErrorState v-else-if="error" :error="error" @retry="load(articleId)" />
    <template v-else>
      <div class="mb-4 flex flex-wrap items-center gap-2">
        <ArticlePicker v-model="articleId" :articles="articles" class="min-w-[16rem]" />
        <div class="ml-auto flex gap-2">
          <AppButton v-if="canEdit" variant="secondary" size="sm" @click="checkLinks">{{ t('p6.seo.audit.checkLinks') }}</AppButton>
          <AppButton v-if="canEdit" size="sm" :loading="running" data-testid="run-audit" @click="runAudit">{{ t('p6.seo.audit.run') }}</AppButton>
        </div>
      </div>
      <EmptyState v-if="!run" icon="search" :title="t('p6.seo.audit.noRun')" />
      <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6">
          <AppCard :title="t('p6.seo.audit.score')">
            <p class="text-4xl font-semibold tabular-nums" data-testid="audit-score">{{ run.score }}<span class="text-base text-ink-muted">/100</span></p>
            <ProgressBar :value="run.score" tone="auto" class="mt-2" />
            <p class="mt-3 text-xs text-ink-muted" data-testid="audit-disclaimer">{{ run.disclaimer }}</p>
            <div class="mt-3 flex flex-wrap gap-1 text-xs">
              <AppBadge tone="danger">{{ t('p6.seo.severity.BLOCKED') }} {{ run.blocked_count }}</AppBadge>
              <AppBadge tone="danger">{{ t('p6.seo.severity.ERROR') }} {{ run.error_count }}</AppBadge>
              <AppBadge tone="warning">{{ t('p6.seo.severity.WARNING') }} {{ run.warning_count }}</AppBadge>
              <AppBadge tone="success">{{ t('p6.seo.severity.PASS') }} {{ run.pass_count }}</AppBadge>
            </div>
            <p class="mt-2 text-xs text-ink-muted">{{ run.links_checked ? t('p6.seo.audit.linksChecked') : t('p6.seo.audit.linksNotChecked') }} · {{ formatDateTime(run.created_at) }}</p>
          </AppCard>
          <AppCard :title="t('p6.seo.audit.history')" :padded="false">
            <ul class="divide-y divide-line text-sm">
              <li v-for="h in history" :key="h.id"><button type="button" class="flex w-full justify-between px-5 py-2 text-left hover:bg-surface-muted" :class="h.id === run.id ? 'font-semibold' : ''" @click="openRun(h.id)"><span>{{ formatDateTime(h.created_at) }}</span><span class="tabular-nums">{{ h.score }}</span></button></li>
            </ul>
          </AppCard>
          <AppCard v-if="schema" :title="t('p6.seo.schema.title')">
            <p class="mb-2 text-xs text-ink-muted">{{ schema.disclaimer }}</p>
            <p class="text-sm">{{ schema.enabled ? t('p6.seo.schema.enabled') : t('p6.seo.schema.disabled') }}</p>
            <p v-if="schema.types.length" class="mt-1 text-sm">{{ schema.types.join(', ') }}</p>
            <ul v-if="Object.keys(schema.skipped).length" class="mt-2 space-y-0.5 text-xs text-ink-muted">
              <li v-for="(why, type) in schema.skipped" :key="type">{{ type }}: {{ t(`p6.seo.schema.skip.${why}`) }}</li>
            </ul>
            <details v-if="schema.graph.length" class="mt-2"><summary class="cursor-pointer text-xs text-brand-600">JSON-LD</summary><pre class="mt-1 max-h-64 overflow-auto rounded bg-surface-muted p-2 text-[11px]">{{ JSON.stringify(schema.graph, null, 2) }}</pre></details>
          </AppCard>
        </div>
        <AppCard :title="t('p6.seo.audit.issues')" class="lg:col-span-2" :padded="false">
          <div class="flex flex-wrap gap-2 border-b border-line px-5 py-3">
            <FilterSelect v-model="severity" :label="t('p6.seo.audit.severity')" :options="sevOpts" />
            <FilterSelect v-model="category" :label="t('p6.seo.audit.category')" :options="catOpts" />
          </div>
          <EmptyState v-if="!issues.length" icon="check" :title="t('p6.seo.audit.noIssues')" />
          <ul v-else class="divide-y divide-line" data-testid="audit-issues">
            <li v-for="i in issues" :key="i.id" class="space-y-1 px-5 py-3 text-sm">
              <div class="flex flex-wrap items-center gap-2">
                <AppBadge :tone="severityTone(i.severity)">{{ t(`p6.seo.severity.${i.severity}`) }}</AppBadge>
                <span class="font-medium">{{ i.message }}</span>
                <span class="ml-auto text-xs text-ink-muted">{{ t(`p6.seo.category.${i.category}`) }} · {{ i.points }}/{{ i.weight }}</span>
              </div>
              <p class="text-ink-muted">{{ i.explanation }}</p>
              <p v-if="i.fix && i.severity !== 'PASS'" class="text-xs"><strong>{{ t('p6.seo.audit.fix') }}:</strong> {{ i.fix }}</p>
            </li>
          </ul>
        </AppCard>
      </div>
    </template>
  </div>
</template>
