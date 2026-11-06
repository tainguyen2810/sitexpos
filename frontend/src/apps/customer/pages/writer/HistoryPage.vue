<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isActiveWorkflow, writerApi, type HistoryRow } from '@/shared/api/writer'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import StatusBadge from './components/StatusBadge.vue'
import WriterNav from './components/WriterNav.vue'
import { usePolling, useWriterPerms } from './useWriter'

const { t } = useI18n()
const { workspaceId: id } = useWriterPerms()
const kind = ref('')
const status = ref('')
const list = useCursorList((cursor) => writerApi.history(id.value, { cursor, kind: kind.value, status: status.value }))
void list.reload()
watch([kind, status], () => void list.reload())
const active = computed(() => list.items.value.some((r) => isActiveWorkflow(r)))
usePolling(() => list.reload(), active, 6000)

const kindOptions = computed(() => [{ value: '', label: t('common.all') }, ...(['research', 'outline', 'draft', 'recheck', 'rewrite'] as const).map((k) => ({ value: k, label: t(`writer.kind.${k}`) }))])
const statusOptions = computed(() => [{ value: '', label: t('common.all') }, ...(['running', 'succeeded', 'failed', 'canceled'] as const).map((s) => ({ value: s, label: t(`writer.runStatus.${s}`) }))])
const tone = (s: string) => (s === 'succeeded' ? 'success' : s === 'failed' ? 'danger' : s === 'canceled' ? 'neutral' : 'brand') as 'success' | 'danger' | 'neutral' | 'brand'
function duration(r: HistoryRow): string {
  if (!r.started_at) return '—'
  const ms = new Date(r.finished_at ?? Date.now()).getTime() - new Date(r.started_at).getTime()
  const s = Math.max(0, Math.round(ms / 1000))
  return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${s % 60}s`
}
</script>

<template>
  <div>
    <PageHeader :title="t('writer.history.title')" :description="t('writer.history.subtitle')" />
    <WriterNav active="history" />
    <AppCard :padded="false">
      <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
        <FilterSelect v-model="kind" :label="t('writer.history.kind')" :options="kindOptions" />
        <FilterSelect v-model="status" :label="t('common.status')" :options="statusOptions" />
      </div>
      <LoadingState v-if="list.loading.value && !list.loaded.value" class="p-5" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" class="p-5" @retry="list.reload" />
      <EmptyState v-else-if="!list.items.value.length" icon="clock" :title="t('writer.history.empty')" />
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[40rem] text-left text-sm" data-testid="history-table">
          <thead class="border-b border-line text-xs text-ink-muted">
            <tr><th class="px-5 py-2">{{ t('writer.history.article') }}</th><th class="px-2">{{ t('writer.history.kind') }}</th><th class="px-2">{{ t('common.status') }}</th><th class="px-2">{{ t('writer.article.steps') }}</th><th class="px-2">{{ t('writer.history.duration') }}</th><th class="px-5 text-right">{{ t('common.createdAt') }}</th></tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="r in list.items.value" :key="r.id">
              <td class="max-w-xs px-5 py-3">
                <RouterLink v-if="r.article" :to="{ name: 'writer-article', params: { workspaceId: id, articleId: r.article.id } }" class="block truncate font-medium text-brand-600 underline">{{ r.article.title }}</RouterLink>
                <StatusBadge v-if="r.article" :status="r.article.status" class="mt-1" />
              </td>
              <td class="px-2">{{ t(`writer.kind.${r.kind}`) }}</td>
              <td class="px-2"><AppBadge :tone="tone(r.status)">{{ t(`writer.runStatus.${r.status}`) }}</AppBadge>
                <p v-if="r.error" class="mt-1 max-w-[12rem] truncate text-xs text-danger-600" :title="r.error.message ?? ''">{{ r.error.code }}</p></td>
              <td class="w-40 px-2"><ProgressBar :value="r.progress" :max="100" :tone="r.status === 'failed' ? 'danger' : 'brand'" /><span class="text-xs text-ink-muted">{{ r.current_step_label ?? '' }}</span></td>
              <td class="px-2 tabular-nums text-ink-muted">{{ duration(r) }}</td>
              <td class="px-5 text-right text-xs text-ink-muted">{{ formatDateTime(r.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="list.next.value" class="border-t border-line p-3 text-center">
        <AppButton variant="ghost" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton>
      </div>
    </AppCard>
  </div>
</template>
