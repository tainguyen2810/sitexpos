<script setup lang="ts">
import { ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { writerApi, type Diff, type Version } from '@/shared/api/writer'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import Drawer from '@/shared/ui/Drawer.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import SafeHtml from '@/shared/ui/SafeHtml.vue'
import DiffView from '../components/DiffView.vue'
import { useArticle } from '../articleContext'
import { useWriterErrors } from '../useWriter'

const { t, te } = useI18n()
const { workspaceId: ws, articleId: id, article, reload, busy, canEdit } = useArticle()
const { toastError, toast } = useWriterErrors()
const versions = useCursorList((cursor) => writerApi.versions(ws, id, { cursor }))
void versions.reload()
watch(() => article.value?.current_version_id, () => void versions.reload())

const diff = shallowRef<Diff | null>(null)
const viewing = shallowRef<Version | null>(null)
const loading = ref(false)
async function compare(v: Version) {
  loading.value = true
  viewing.value = null
  try {
    diff.value = await writerApi.diff(ws, id, v.id)
  } catch (e) {
    toastError(e)
  } finally {
    loading.value = false
  }
}
async function view(v: Version) {
  diff.value = null
  loading.value = true
  try {
    viewing.value = await writerApi.version(ws, id, v.id)
  } catch (e) {
    toastError(e)
  } finally {
    loading.value = false
  }
}
const restoring = shallowRef<Version | null>(null)
const acting = ref(false)
async function restore() {
  if (!restoring.value) return
  acting.value = true
  try {
    await writerApi.restore(ws, id, restoring.value.id)
    toast.success(t('common.saved'))
    restoring.value = null
    await Promise.all([versions.reload(), reload()])
  } catch (e) {
    toastError(e)
  } finally {
    acting.value = false
  }
}
const src = (s: string) => (te(`writer.versions.source.${s}`) ? t(`writer.versions.source.${s}`) : s)
const canRestore = (v: Version) => canEdit.value && !busy.value && v.id !== article.value?.current_version_id && ['NEEDS_HUMAN_REVIEW', 'APPROVED', 'FAILED'].includes(article.value?.status ?? '')
</script>

<template>
  <AppCard :padded="false">
    <LoadingState v-if="versions.loading.value && !versions.loaded.value" class="p-5" />
    <ErrorState v-else-if="versions.error.value" :error="versions.error.value" class="p-5" @retry="versions.reload" />
    <EmptyState v-else-if="!versions.items.value.length" icon="layers" :title="t('writer.versions.empty')" />
    <ul v-else class="divide-y divide-line" data-testid="version-list">
      <li v-for="v in versions.items.value" :key="v.id" class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
        <span class="font-mono text-xs">v{{ v.number }}</span>
        <div class="min-w-0 flex-1">
          <p class="truncate">{{ v.change_summary || v.title }}</p>
          <p class="text-xs text-ink-muted">{{ src(v.source) }}<template v-if="v.model"> · {{ v.model }}</template> · {{ t('writer.editor.words', { n: v.word_count }) }} · {{ formatDateTime(v.created_at) }}</p>
        </div>
        <AppBadge v-if="v.id === article?.current_version_id" tone="brand">{{ t('writer.versions.current') }}</AppBadge>
        <AppBadge v-if="v.id === article?.approved_version_id" tone="success">{{ t('writer.versions.approved') }}</AppBadge>
        <AppButton size="sm" variant="ghost" @click="view(v)">{{ t('writer.versions.view') }}</AppButton>
        <AppButton v-if="v.id !== article?.current_version_id" size="sm" variant="secondary" @click="compare(v)">{{ t('writer.versions.compare') }}</AppButton>
        <AppButton v-if="canRestore(v)" size="sm" variant="secondary" @click="restoring = v">{{ t('writer.versions.restore') }}</AppButton>
      </li>
    </ul>
    <div v-if="versions.next.value" class="border-t border-line p-3 text-center">
      <AppButton variant="ghost" size="sm" :loading="versions.loadingMore.value" @click="versions.more">{{ t('common.loadMore') }}</AppButton>
    </div>

    <Drawer :open="loading || !!diff || !!viewing" :title="diff ? t('writer.versions.diffTitle', { from: diff.from.number, to: diff.to.number }) : viewing ? `v${viewing.number}` : ''" @close="() => { diff = null; viewing = null }">
      <LoadingState v-if="loading" />
      <div v-else-if="diff" class="space-y-4" data-testid="diff">
        <p class="text-sm text-ink-muted">{{ t('writer.versions.changeRatio', { n: Math.round(diff.change_ratio * 100) }) }}</p>
        <p v-if="!diff.sections.length" class="text-sm text-ink-muted">{{ t('writer.versions.noChanges') }}</p>
        <section v-for="s in diff.sections" :key="s.key" class="rounded-lg border border-line p-3">
          <p class="mb-2 text-sm font-medium">{{ s.heading || t('writer.outline.intro') }} <AppBadge :tone="s.change === 'added' ? 'success' : s.change === 'removed' ? 'danger' : 'warning'">{{ t(`writer.versions.change.${s.change}`) }}</AppBadge></p>
          <DiffView :ops="s.ops" />
        </section>
      </div>
      <SafeHtml v-else-if="viewing" :html="viewing.content_html ?? ''" class="article-content" />
    </Drawer>
    <ConfirmDialog :open="!!restoring" :title="t('writer.versions.restore')" :message="t('writer.versions.restoreConfirm', { n: restoring?.number ?? '' })" :loading="acting" @close="restoring = null" @confirm="restore" />
  </AppCard>
</template>
