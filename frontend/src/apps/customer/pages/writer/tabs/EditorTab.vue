<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'
import { REWRITE_ACTIONS, idempotencyKey, sectionKeyFor, writerApi, type EditorState, type RewriteAction } from '@/shared/api/writer'
import { useCursorList } from '@/shared/composables/useCursorList'
import { sanitizeArticleHtml, wordCount } from '@/shared/utils/articleHtml'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import SafeHtml from '@/shared/ui/SafeHtml.vue'
import Tabs from '@/shared/ui/Tabs.vue'
import DiffView from '../components/DiffView.vue'
import RichEditor from '../components/RichEditor.vue'
import { useArticle } from '../articleContext'
import { usePolling, useWriterErrors } from '../useWriter'

const AUTOSAVE_MS = 5000
const { t } = useI18n()
const { workspaceId: ws, articleId: id, article, reload, busy, canEdit } = useArticle()
const { toastError, toast, message } = useWriterErrors()

const state = shallowRef<EditorState | null>(null)
const loadError = shallowRef<unknown>(null)
const loading = ref(true)
const html = ref('')
const baseHtml = ref('')
const lockVersion = ref(0)
const conflict = ref(false)
const autosaveState = ref<'idle' | 'saving' | 'saved' | 'failed'>('idle')
const autosavedAt = ref<string | null>(null)
const draftOffer = ref<{ content_html: string; autosaved_at: string } | null>(null)
const mode = ref<'edit' | 'preview'>('edit')
const editor = ref<InstanceType<typeof RichEditor> | null>(null)

async function load() {
  loading.value = true
  try {
    const s = await writerApi.editor(ws, id)
    state.value = s
    const content = sanitizeArticleHtml(s.version?.content_html ?? '')
    html.value = content
    baseHtml.value = content
    lockVersion.value = s.lock_version
    conflict.value = false
    draftOffer.value = s.autosave && sanitizeArticleHtml(s.autosave.content_html) !== content ? s.autosave : null
    loadError.value = null
  } catch (e) {
    loadError.value = e
  } finally {
    loading.value = false
  }
}
void load()
// A new version from outside (AI revision, restore, another editor) → reload when we have nothing unsaved.
watch(() => article.value?.current_version_id, (v) => {
  if (v && v !== state.value?.version?.id && !dirty.value) void load()
})

const version = computed(() => state.value?.version ?? null)
const dirty = computed(() => html.value !== baseHtml.value)
const editable = computed(() => canEdit.value && !busy.value && !conflict.value && ['NEEDS_HUMAN_REVIEW', 'APPROVED', 'FAILED'].includes(article.value?.status ?? ''))
const readonlyReason = computed(() => {
  if (!canEdit.value) return t('writer.editor.readonlyRole')
  if (busy.value) return t('writer.editor.readonlyBusy')
  if (!['NEEDS_HUMAN_REVIEW', 'APPROVED', 'FAILED'].includes(article.value?.status ?? '')) return t('writer.editor.readonlyStatus')
  return null
})
const words = computed(() => wordCount(html.value))

function onChange(v: string) {
  html.value = v
  scheduleAutosave()
}

// ---- autosave (never loses content: failures keep the editor as is) ----
let timer: ReturnType<typeof setTimeout> | undefined
function scheduleAutosave() {
  clearTimeout(timer)
  if (!editable.value || !version.value) return
  timer = setTimeout(() => void autosave(), AUTOSAVE_MS)
}
async function autosave() {
  if (!dirty.value || !version.value || conflict.value) return
  autosaveState.value = 'saving'
  try {
    const r = await writerApi.autosave(ws, id, { content_html: html.value, base_version_id: version.value.id, lock_version: lockVersion.value })
    lockVersion.value = r.lock_version
    autosavedAt.value = r.autosaved_at
    autosaveState.value = 'saved'
  } catch (e) {
    autosaveState.value = 'failed'
    if (e instanceof ApiError && e.status === 409) conflict.value = true
  }
}
function warnUnload(e: BeforeUnloadEvent) {
  if (dirty.value) e.preventDefault()
}
onMounted(() => window.addEventListener('beforeunload', warnUnload))
onBeforeUnmount(() => {
  clearTimeout(timer)
  window.removeEventListener('beforeunload', warnUnload)
  if (dirty.value && editable.value) void autosave()
})

function useDraft() {
  if (!draftOffer.value) return
  html.value = sanitizeArticleHtml(draftOffer.value.content_html)
  editor.value?.load(html.value)
  draftOffer.value = null
}

// ---- save as version ----
const summary = ref('')
const saving = ref(false)
async function save() {
  if (!version.value) return
  saving.value = true
  clearTimeout(timer)
  try {
    const r = await writerApi.save(ws, id, { content_html: html.value, base_version_id: version.value.id, summary: summary.value.trim() || null })
    state.value = { ...state.value!, version: r.version, autosave: null, lock_version: r.lock_version }
    const content = sanitizeArticleHtml(r.version.content_html ?? html.value)
    html.value = content
    baseHtml.value = content
    lockVersion.value = r.lock_version
    summary.value = ''
    autosaveState.value = 'idle'
    toast.success(t('common.saved'))
    await reload()
  } catch (e) {
    if (e instanceof ApiError && e.code === 'version_conflict') {
      conflict.value = true
      void navigator.clipboard?.writeText(html.value).catch(() => undefined)
    }
    toastError(e)
  } finally {
    saving.value = false
  }
}

// ---- AI rewrite proposals ----
const action = ref<RewriteAction>('rewrite')
const tone = ref('')
const aiError = ref<string | null>(null)
const requesting = ref(false)
const actionOptions = computed(() => (state.value?.rewrite_actions ?? REWRITE_ACTIONS).map((a) => ({ value: a, label: t(`writer.editor.action.${a}`) })))
const revisions = useCursorList((cursor) => writerApi.revisions(ws, id, { cursor, per_page: 10 }))
void revisions.reload()
const pending = computed(() => revisions.items.value.some((r) => r.status === 'queued'))
usePolling(() => revisions.reload(), pending, 3000)

async function requestRewrite() {
  aiError.value = null
  if (dirty.value) {
    aiError.value = t('writer.editor.aiUnsaved')
    return
  }
  const ctx = editor.value?.selectionContext()
  const sectionKey = sectionKeyFor(ctx?.heading ?? null, version.value?.sections ?? [])
  if (!sectionKey) {
    aiError.value = t('writer.editor.aiNoSection')
    return
  }
  requesting.value = true
  try {
    await writerApi.rewrite(ws, id, { action: action.value, section_key: sectionKey, selection: action.value === 'regenerate_section' ? null : (ctx?.text.trim() || null), tone: action.value === 'tone' ? tone.value || null : null }, idempotencyKey())
    await revisions.reload()
  } catch (e) {
    aiError.value = message(e) // content in the editor is untouched
  } finally {
    requesting.value = false
  }
}
const busyRev = ref<string | null>(null)
async function apply(revId: string) {
  busyRev.value = revId
  try {
    await writerApi.applyRevision(ws, id, revId)
    await Promise.all([load(), revisions.reload(), reload()])
  } catch (e) {
    toastError(e)
    await revisions.reload()
  } finally {
    busyRev.value = null
  }
}
async function discard(revId: string) {
  busyRev.value = revId
  try {
    await writerApi.discardRevision(ws, id, revId)
    await revisions.reload()
  } catch (e) {
    toastError(e)
  } finally {
    busyRev.value = null
  }
}
const sectionHeading = (key: string | null) => version.value?.sections.find((s) => s.key === key)?.heading || (key === 'intro' ? t('writer.outline.intro') : key ?? '')

const panel = ref('ai')
const panels = computed(() => [
  { key: 'ai', label: t('writer.editor.ai') },
  { key: 'cite', label: t('writer.editor.citations') },
  { key: 'facts', label: t('writer.editor.factPanel') },
  { key: 'seo', label: t('writer.editor.seoPanel') },
])
const cite = (c: EditorState['citations'][number]) => editor.value?.insertCitation(c.id, c.ref, c.url)
</script>

<template>
  <LoadingState v-if="loading && !state" />
  <ErrorState v-else-if="loadError" :error="loadError" @retry="load" />
  <EmptyState v-else-if="!version" icon="pen" :title="t('writer.editor.empty')" :description="t('writer.editor.emptyBody')" />
  <div v-else class="grid grid-cols-1 gap-4 xl:grid-cols-[1fr_22rem]">
    <div class="min-w-0 space-y-3">
      <p v-if="conflict" class="flex flex-wrap items-center gap-2 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">
        {{ t('writer.editor.conflict') }} <AppButton size="sm" variant="secondary" @click="load">{{ t('writer.editor.reload') }}</AppButton>
      </p>
      <p v-else-if="readonlyReason" class="rounded-lg bg-surface-muted px-3 py-2 text-sm text-ink-muted">{{ readonlyReason }}</p>
      <p v-if="draftOffer && editable" class="flex flex-wrap items-center gap-2 rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-600" data-testid="draft-offer">
        {{ t('writer.editor.restoreDraft', { time: formatDateTime(draftOffer.autosaved_at) }) }}
        <AppButton size="sm" @click="useDraft">{{ t('writer.editor.useDraft') }}</AppButton>
        <AppButton size="sm" variant="ghost" @click="draftOffer = null">{{ t('writer.editor.discardDraft') }}</AppButton>
      </p>

      <div class="flex flex-wrap items-center gap-2 text-sm">
        <AppButton size="sm" :variant="mode === 'edit' ? 'primary' : 'secondary'" @click="mode = 'edit'">{{ t('writer.editor.edit') }}</AppButton>
        <AppButton size="sm" :variant="mode === 'preview' ? 'primary' : 'secondary'" @click="mode = 'preview'">{{ t('writer.editor.preview') }}</AppButton>
        <span class="text-ink-muted">v{{ version.number }} · {{ t('writer.editor.words', { n: words }) }}</span>
        <AppBadge v-if="article?.approved_version_id === version.id" tone="success">{{ t('writer.versions.approved') }}</AppBadge>
        <span class="ml-auto text-xs" :class="autosaveState === 'failed' ? 'text-danger-600' : 'text-ink-muted'" aria-live="polite">
          <template v-if="autosaveState === 'saving'">{{ t('writer.editor.autosaving') }}</template>
          <template v-else-if="autosaveState === 'failed'">{{ t('writer.editor.autosaveFailed') }}</template>
          <template v-else-if="dirty && autosavedAt">{{ t('writer.editor.autosaved', { time: formatDateTime(autosavedAt) }) }}</template>
          <template v-else-if="dirty">{{ t('writer.editor.unsaved') }}</template>
        </span>
      </div>

      <RichEditor v-show="mode === 'edit'" ref="editor" :html="html" :readonly="!editable" @change="onChange" />
      <AppCard v-if="mode === 'preview'"><SafeHtml :html="html" class="article-content" /></AppCard>

      <div v-if="editable" class="flex flex-wrap items-end gap-2">
        <AppInput v-model="summary" class="min-w-0 flex-1" :label="t('writer.editor.summary')" :hint="t('common.optional')" />
        <AppButton :loading="saving" :disabled="!dirty" data-testid="save-version" @click="save">{{ t('writer.editor.save') }}</AppButton>
      </div>
    </div>

    <AppCard :padded="false" class="h-fit xl:sticky xl:top-4">
      <Tabs v-model="panel" :tabs="panels" class="px-3" />
      <div class="max-h-[70vh] space-y-3 overflow-y-auto p-4 text-sm">
        <template v-if="panel === 'ai'">
          <p class="text-xs text-ink-muted">{{ t('writer.editor.aiHint') }}</p>
          <template v-if="editable">
            <AppSelect v-model="action" :options="actionOptions" :label="t('common.actions')" />
            <AppInput v-if="action === 'tone'" v-model="tone" :label="t('writer.editor.tone')" />
            <p v-if="aiError" class="rounded bg-danger-50 px-2 py-1 text-xs text-danger-600" role="alert">{{ aiError }}</p>
            <AppButton block :loading="requesting" data-testid="request-rewrite" @mousedown.prevent @click="requestRewrite">{{ t(`writer.editor.action.${action}`) }}</AppButton>
          </template>
          <h4 class="pt-2 text-xs font-semibold uppercase text-ink-muted">{{ t('writer.editor.proposals') }}</h4>
          <LoadingState v-if="revisions.loading.value && !revisions.loaded.value" />
          <ErrorState v-else-if="revisions.error.value" :error="revisions.error.value" @retry="revisions.reload" />
          <p v-else-if="!revisions.items.value.length" class="text-xs text-ink-muted">{{ t('writer.editor.noProposals') }}</p>
          <ul v-else class="space-y-3">
            <li v-for="r in revisions.items.value" :key="r.id" class="rounded-lg border border-line p-2">
              <div class="mb-1 flex flex-wrap items-center gap-1 text-xs">
                <span class="font-medium">{{ r.kind === 'auto_fix' ? t('writer.editor.autoFix') : t(`writer.editor.action.${r.action}`, r.action) }}</span>
                <span class="text-ink-muted">· {{ sectionHeading(r.section_key) }}</span>
                <AppBadge class="ml-auto" :tone="r.status === 'proposed' ? 'warning' : r.status === 'applied' ? 'success' : r.status === 'failed' || r.status === 'stale' ? 'danger' : 'neutral'">
                  {{ t(`writer.editor.revStatus.${r.status}`) }}
                </AppBadge>
              </div>
              <DiffView v-if="r.diff.length" :ops="r.diff" class="max-h-48 overflow-y-auto text-xs" />
              <p v-if="r.error_code" class="text-xs text-danger-600">{{ r.error_code }}</p>
              <div v-if="r.status === 'proposed' && editable" class="mt-2 flex gap-2">
                <AppButton size="sm" :loading="busyRev === r.id" :disabled="dirty" @click="apply(r.id)">{{ t('writer.editor.apply') }}</AppButton>
                <AppButton size="sm" variant="ghost" :disabled="busyRev === r.id" @click="discard(r.id)">{{ t('writer.editor.discard') }}</AppButton>
              </div>
            </li>
          </ul>
        </template>

        <template v-else-if="panel === 'cite'">
          <p class="text-xs text-ink-muted">{{ t('writer.editor.citationsHint') }}</p>
          <p v-if="!state?.citations.length" class="text-xs text-ink-muted">{{ t('writer.editor.noCitations') }}</p>
          <ul v-else class="space-y-2">
            <li v-for="c in state.citations" :key="c.id" class="flex items-start gap-2">
              <span class="font-mono text-xs">[{{ c.ref }}]</span>
              <span class="min-w-0 flex-1 text-xs">{{ c.statement }}<span v-if="c.title" class="block truncate text-ink-muted">{{ c.title }}</span></span>
              <AppButton v-if="editable" size="sm" variant="secondary" @mousedown.prevent @click="cite(c)">{{ t('writer.editor.insert') }}</AppButton>
            </li>
          </ul>
        </template>

        <template v-else-if="panel === 'facts'">
          <p :class="article?.unresolved_critical ? 'text-danger-600' : 'text-ink-muted'">
            {{ t('writer.library.unresolved', { critical: article?.unresolved_critical ?? 0, high: article?.unresolved_high ?? 0 }) }}
          </p>
          <ul class="space-y-1 text-xs">
            <li v-for="s in version.sections" :key="s.key" class="flex items-center gap-2">
              <AppBadge :tone="s.claims_checked ? 'success' : 'warning'">{{ s.claims_checked ? '✓' : '!' }}</AppBadge>
              <span class="truncate">{{ s.heading || t('writer.outline.intro') }}</span>
            </li>
          </ul>
          <RouterLink :to="{ params: { tab: 'fact-check' } }" class="text-xs font-medium text-brand-600 underline">{{ t('writer.article.tabs.fact-check') }} →</RouterLink>
        </template>

        <template v-else>
          <p>{{ t('writer.quality.score') }}: <strong>{{ article?.quality_score ?? '—' }}</strong></p>
          <RouterLink :to="{ params: { tab: 'quality' } }" class="text-xs font-medium text-brand-600 underline">{{ t('writer.article.tabs.quality') }} →</RouterLink>
        </template>
      </div>
    </AppCard>
  </div>
</template>
