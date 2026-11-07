<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { jobTone, PUBLISHING_STATUSES, publishingApi, type Batch } from '@/shared/api/publishing'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Tabs from '@/shared/ui/Tabs.vue'
import JobDrawer from './JobDrawer.vue'
import SectionNav from './SectionNav.vue'
import { siteName, usePhase6Errors, usePhase6Perms, useSites } from './usePhase6'

const { t } = useI18n()
const { workspaceId, canEdit, canPublish } = usePhase6Perms()
const { toastError, toast } = usePhase6Errors()
const sites = useSites()
const tab = ref('jobs')
const route = useRoute()
const status = ref(typeof route.query.status === 'string' ? route.query.status : '')
const openJob = ref<string | null>(null)
const jobs = useCursorList((cursor) => publishingApi.jobs(workspaceId.value, { cursor, status: status.value || undefined, site_id: sites.siteId.value || undefined }))
const batches = useCursorList((cursor) => publishingApi.batches(workspaceId.value, { cursor }))
const mappings = useCursorList((cursor) => publishingApi.mappings(workspaceId.value, { cursor, site_id: sites.siteId.value || undefined }))
watch([status, () => sites.siteId.value], () => void jobs.reload(), { immediate: true })
watch(tab, (v) => {
  if (v === 'batches' && !batches.loaded.value) void batches.reload()
  if (v === 'mappings' && !mappings.loaded.value) void mappings.reload()
})
const statusOpts = computed(() => [{ value: '', label: t('common.all') }, ...PUBLISHING_STATUSES.map((s) => ({ value: s, label: t(`p6.pub.status.${s}`) }))])
const siteOpts = computed(() => [{ value: '', label: t('p6.common.allSites') }, ...sites.options.value])
const tabs = computed(() => [{ key: 'jobs', label: t('p6.pub.history.jobs') }, { key: 'batches', label: t('p6.pub.history.batches') }, { key: 'mappings', label: t('p6.pub.history.mappings') }])

async function batchAction(b: Batch, action: 'pause' | 'resume' | 'cancel') {
  try {
    const updated = await publishingApi.batchAction(workspaceId.value, b.id, action)
    batches.items.value = batches.items.value.map((x) => (x.id === b.id ? updated : x))
  } catch (e) {
    toastError(e)
  }
}
async function checkSync(id: string) {
  try {
    await publishingApi.checkMapping(workspaceId.value, id)
    toast.success(t('p6.pub.history.syncQueued'))
  } catch (e) {
    toastError(e)
  }
}
const syncTone = (s: string | undefined) => (s === 'in_sync' ? 'success' : !s || s === 'unreachable' ? 'neutral' : 'danger') as 'success' | 'danger' | 'neutral'
</script>

<template>
  <div>
    <PageHeader :title="t('p6.pub.history.title')" :description="t('p6.pub.history.subtitle')" />
    <SectionNav section="publishing" active="history" />
    <Tabs v-model="tab" :tabs="tabs" class="mb-4" />
    <AppCard :padded="false">
      <template v-if="tab === 'jobs'">
        <div class="flex flex-wrap gap-2 border-b border-line px-5 py-3">
          <FilterSelect v-model="status" :label="t('common.status')" :options="statusOpts" />
          <FilterSelect v-model="sites.siteId.value" :label="t('p6.common.site')" :options="siteOpts" />
        </div>
        <LoadingState v-if="jobs.loading.value && !jobs.loaded.value" />
        <ErrorState v-else-if="jobs.error.value" :error="jobs.error.value" @retry="jobs.reload" />
        <EmptyState v-else-if="!jobs.items.value.length" icon="clock" :title="t('p6.pub.history.empty')" />
        <div v-else class="overflow-x-auto">
          <table class="data-table w-full min-w-[44rem] text-left text-sm" data-testid="history-jobs">
            <thead class="border-b border-line text-xs text-ink-muted"><tr><th class="px-5 py-2">{{ t('p6.common.article') }}</th><th class="px-2">{{ t('p6.common.site') }}</th><th class="px-2">{{ t('p6.pub.center.mode') }}</th><th class="px-2">{{ t('common.status') }}</th><th class="px-2">{{ t('p6.pub.history.attempts') }}</th><th class="px-5 text-right">{{ t('p6.pub.history.updated') }}</th></tr></thead>
            <tbody class="divide-y divide-line">
              <tr v-for="j in jobs.items.value" :key="j.id" class="cursor-pointer hover:bg-surface-muted" @click="openJob = j.id">
                <td class="max-w-xs truncate px-5 py-2 font-medium">{{ j.article_title ?? j.article_id }}<span v-if="j.is_demo" class="ml-1 text-[10px] text-ink-muted">DEMO</span></td>
                <td class="px-2 text-xs">{{ siteName(sites.sites.value, j.site_id) }}</td>
                <td class="px-2 text-xs">{{ t(`p6.pub.mode.${j.mode}`) }}</td>
                <td class="px-2"><AppBadge :tone="jobTone(j.status)">{{ t(`p6.pub.status.${j.status}`) }}</AppBadge><p v-if="j.last_error_code" class="text-xs text-danger-600">{{ j.last_error_code }}</p></td>
                <td class="px-2 tabular-nums">{{ j.attempts }}/{{ j.max_attempts }}</td>
                <td class="px-5 text-right text-xs text-ink-muted">{{ formatDateTime(j.updated_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="jobs.next.value" class="border-t border-line p-3 text-center"><AppButton variant="ghost" size="sm" :loading="jobs.loadingMore.value" @click="jobs.more">{{ t('common.loadMore') }}</AppButton></div>
      </template>

      <template v-else-if="tab === 'batches'">
        <LoadingState v-if="batches.loading.value && !batches.loaded.value" />
        <ErrorState v-else-if="batches.error.value" :error="batches.error.value" @retry="batches.reload" />
        <EmptyState v-else-if="!batches.items.value.length" icon="layers" :title="t('p6.pub.history.noBatches')" />
        <ul v-else class="divide-y divide-line">
          <li v-for="b in batches.items.value" :key="b.id" class="flex flex-wrap items-center gap-2 px-5 py-3 text-sm">
            <AppBadge :tone="b.status === 'completed' ? 'success' : b.status === 'canceled' ? 'neutral' : b.status === 'paused' ? 'warning' : 'brand'">{{ t(`p6.pub.batchStatus.${b.status}`) }}</AppBadge>
            <span>{{ t(`p6.pub.mode.${b.mode}`) }} · {{ b.total }} · {{ siteName(sites.sites.value, b.site_id) }}</span>
            <span class="text-xs text-ink-muted">{{ Object.entries(b.counts).map(([k, v]) => `${t(`p6.pub.status.${k}`)}: ${v}`).join(' · ') }}</span>
            <span class="ml-auto flex gap-1">
              <AppButton v-if="canPublish && b.status === 'running'" size="sm" variant="ghost" @click="batchAction(b, 'pause')">{{ t('p6.pub.history.pause') }}</AppButton>
              <AppButton v-if="canPublish && b.status === 'paused'" size="sm" variant="ghost" @click="batchAction(b, 'resume')">{{ t('p6.pub.history.resume') }}</AppButton>
              <AppButton v-if="canEdit && (b.status === 'running' || b.status === 'paused')" size="sm" variant="ghost" @click="batchAction(b, 'cancel')">{{ t('common.cancel') }}</AppButton>
            </span>
          </li>
        </ul>
      </template>

      <template v-else>
        <LoadingState v-if="mappings.loading.value && !mappings.loaded.value" />
        <ErrorState v-else-if="mappings.error.value" :error="mappings.error.value" @retry="mappings.reload" />
        <EmptyState v-else-if="!mappings.items.value.length" icon="link" :title="t('p6.pub.history.noMappings')" />
        <ul v-else class="divide-y divide-line">
          <li v-for="m in mappings.items.value" :key="m.id" class="flex flex-wrap items-center gap-2 px-5 py-3 text-sm">
            <span class="min-w-0 flex-1 truncate font-medium">{{ m.article_title ?? m.article_id }}</span>
            <a v-if="m.url" :href="m.url" target="_blank" rel="noopener noreferrer" class="text-xs text-brand-600 underline">#{{ m.wp_post_id }} · {{ m.wp_status }}</a>
            <AppBadge :tone="syncTone(m.sync?.state)">{{ m.sync ? t(`p6.pub.syncState.${m.sync.state}`) : t('p6.pub.syncState.unknown') }}</AppBadge>
            <span class="text-xs text-ink-muted">{{ formatDateTime(m.last_published_at) }}</span>
            <AppButton v-if="canEdit && m.wp_post_id" size="sm" variant="ghost" @click="checkSync(m.id)">{{ t('p6.pub.history.checkSync') }}</AppButton>
          </li>
        </ul>
      </template>
    </AppCard>
    <JobDrawer :job-id="openJob" @close="openJob = null" @changed="jobs.reload" />
  </div>
</template>
