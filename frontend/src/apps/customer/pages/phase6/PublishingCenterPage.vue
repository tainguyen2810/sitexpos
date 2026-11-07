<script setup lang="ts">
import { computed, reactive, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { jobTone, localInputToIso, newIdempotencyKey, publishingApi, type BatchPreviewItem, type Preview, type PublishingArticle, type PublishingJob } from '@/shared/api/publishing'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import { usePolling } from '../writer/useWriter'
import JobDrawer from './JobDrawer.vue'
import SectionNav from './SectionNav.vue'
import SimulationFrame from './SimulationFrame.vue'
import { usePhase6Errors, usePhase6Perms, useSites } from './usePhase6'

const { t } = useI18n()
const { workspaceId, canEdit, canPublish } = usePhase6Perms()
const { toastError, toast } = usePhase6Errors()
const sites = useSites()
const articles = shallowRef<PublishingArticle[]>([])
const loading = ref(true)
const error = shallowRef<unknown>(null)
const selected = ref<string[]>([])

async function loadArticles() {
  loading.value = true
  error.value = null
  try {
    articles.value = await publishingApi.articles(workspaceId.value, sites.siteId.value || undefined)
    selected.value = selected.value.filter((id) => articles.value.some((a) => a.id === id))
  } catch (e) {
    error.value = e
  } finally {
    loading.value = false
  }
}
const jobs = useCursorList((cursor) => publishingApi.jobs(workspaceId.value, { cursor, site_id: sites.siteId.value || undefined, status: 'DRAFT,READY_FOR_REVIEW,APPROVED,SCHEDULED,PUBLISHING,UPDATE_PENDING,FAILED' }))
watch(() => sites.siteId.value, () => { void loadArticles(); void jobs.reload() }, { immediate: true })
const busy = computed(() => jobs.items.value.some((j) => j.status === 'PUBLISHING' || j.status === 'APPROVED'))
usePolling(() => { void jobs.reload(); void loadArticles() }, busy, 5000)
const siteOpts = computed(() => [{ value: '', label: t('p6.common.allSites') }, ...sites.options.value])

// Preview
const preview = shallowRef<Preview | null>(null)
async function openPreview(a: PublishingArticle) {
  try {
    preview.value = await publishingApi.preview(workspaceId.value, a.id)
  } catch (e) {
    toastError(e)
  }
}

// Single job
const createFor = shallowRef<PublishingArticle | null>(null)
const createForm = reactive({ mode: 'wp_draft', scheduled_at: '', approve: false })
const creating = ref(false)
let createKey = newIdempotencyKey('job')
function openCreate(a: PublishingArticle) {
  createFor.value = a
  Object.assign(createForm, { mode: a.wordpress ? 'update' : 'wp_draft', scheduled_at: '', approve: false })
  createKey = newIdempotencyKey('job')
}
const modeOpts = computed(() => {
  const a = createFor.value
  const modes = a?.wordpress ? ['update'] : ['wp_draft', 'publish_now', 'scheduled']
  return modes.map((m) => ({ value: m, label: t(`p6.pub.mode.${m}`) }))
})
async function create() {
  if (!createFor.value) return
  creating.value = true
  try {
    const job = await publishingApi.create(workspaceId.value, {
      article_id: createFor.value.id, mode: createForm.mode, scheduled_at: createForm.mode === 'scheduled' ? localInputToIso(createForm.scheduled_at) : null,
      approve: canPublish.value && createForm.approve,
    }, createKey)
    createFor.value = null
    openJob.value = job.id
    toast.success(t('p6.pub.center.jobCreated'))
    await Promise.all([loadArticles(), jobs.reload()])
  } catch (e) {
    toastError(e)
  } finally {
    creating.value = false
  }
}

// Bulk
const bulkOpen = ref(false)
const bulk = reactive({ site_id: '', mode: 'wp_draft', start_at: '', interval_minutes: '60' })
const bulkPreview = shallowRef<BatchPreviewItem[] | null>(null)
const bulkBusy = ref(false)
let bulkKey = newIdempotencyKey('batch')
function openBulk() {
  const siteIds = new Set(articles.value.filter((a) => selected.value.includes(a.id)).map((a) => a.site_id))
  bulk.site_id = [...siteIds][0] ?? ''
  bulkPreview.value = null
  bulkKey = newIdempotencyKey('batch')
  bulkOpen.value = true
}
const bulkSiteMismatch = computed(() => new Set(articles.value.filter((a) => selected.value.includes(a.id)).map((a) => a.site_id)).size > 1)
const bulkPayload = () => ({ site_id: bulk.site_id, article_ids: selected.value, mode: bulk.mode, start_at: localInputToIso(bulk.start_at), interval_minutes: Number(bulk.interval_minutes) || 0 })
async function previewBulk() {
  bulkBusy.value = true
  try {
    bulkPreview.value = (await publishingApi.previewBatch(workspaceId.value, bulkPayload())).items
  } catch (e) {
    toastError(e)
  } finally {
    bulkBusy.value = false
  }
}
async function createBulk() {
  bulkBusy.value = true
  try {
    await publishingApi.createBatch(workspaceId.value, bulkPayload(), bulkKey)
    bulkOpen.value = false
    selected.value = []
    toast.success(t('p6.pub.bulk.created'))
    await Promise.all([loadArticles(), jobs.reload()])
  } catch (e) {
    toastError(e)
  } finally {
    bulkBusy.value = false
  }
}
watch(() => ({ ...bulk }), () => (bulkPreview.value = null))

const openJob = ref<string | null>(null)
function onJobChanged(j: PublishingJob) {
  jobs.items.value = jobs.items.value.map((x) => (x.id === j.id ? j : x))
  void loadArticles()
}
</script>

<template>
  <div>
    <PageHeader :title="t('p6.pub.title')" :description="t('p6.pub.subtitle')" />
    <SectionNav section="publishing" active="center" />
    <p class="mb-4 rounded-lg bg-brand-50 px-4 py-3 text-sm text-brand-700">{{ t('p6.pub.center.safeDefault') }}</p>

    <AppCard :title="t('p6.pub.center.approved')" :subtitle="t('p6.pub.center.approvedHint')" class="mb-6" :padded="false">
      <template #actions>
        <FilterSelect v-model="sites.siteId.value" :label="t('p6.common.site')" :options="siteOpts" />
        <AppButton v-if="canPublish" size="sm" variant="secondary" :disabled="!selected.length" data-testid="open-bulk" @click="openBulk">{{ t('p6.pub.bulk.open', { n: selected.length }) }}</AppButton>
      </template>
      <LoadingState v-if="loading && !articles.length" />
      <ErrorState v-else-if="error" :error="error" @retry="loadArticles" />
      <EmptyState v-else-if="!articles.length" icon="send" :title="t('p6.pub.center.empty')" :description="t('p6.pub.center.emptyHint')" />
      <div v-else class="overflow-x-auto">
        <table class="data-table w-full min-w-[44rem] text-left text-sm" data-testid="publish-articles">
          <thead class="border-b border-line text-xs text-ink-muted"><tr><th class="w-8 px-5 py-2" /><th class="px-2">{{ t('p6.common.article') }}</th><th class="px-2">{{ t('p6.pub.center.factCheck') }}</th><th class="px-2">WordPress</th><th class="px-2">{{ t('p6.pub.center.activeJob') }}</th><th class="px-5 text-right">{{ t('common.actions') }}</th></tr></thead>
          <tbody class="divide-y divide-line">
            <tr v-for="a in articles" :key="a.id">
              <td class="px-5 py-2"><input v-model="selected" type="checkbox" :value="a.id" :disabled="!!a.active_job || !canPublish" :aria-label="a.title" /></td>
              <td class="max-w-xs px-2"><p class="truncate font-medium">{{ a.title }}</p><p class="text-xs text-ink-muted">{{ a.primary_keyword }} · {{ formatDateTime(a.approved_at) }}</p></td>
              <td class="px-2"><AppBadge v-if="a.unresolved_critical || a.unresolved_high" tone="danger">{{ t('p6.pub.center.unresolved', { n: a.unresolved_critical + a.unresolved_high }) }}</AppBadge><AppBadge v-else tone="success">{{ t('p6.pub.center.verified') }}</AppBadge></td>
              <td class="px-2 text-xs"><a v-if="a.wordpress?.url" :href="a.wordpress.url" target="_blank" rel="noopener noreferrer" class="text-brand-600 underline">#{{ a.wordpress.wp_post_id }} · {{ a.wordpress.status }}</a><span v-else class="text-ink-muted">{{ t('p6.pub.center.notOnWp') }}</span></td>
              <td class="px-2"><button v-if="a.active_job" type="button" @click="openJob = a.active_job.id"><AppBadge :tone="jobTone(a.active_job.status)">{{ t(`p6.pub.status.${a.active_job.status}`) }}</AppBadge></button></td>
              <td class="whitespace-nowrap px-5 text-right">
                <AppButton size="sm" variant="ghost" @click="openPreview(a)">{{ t('p6.pub.center.preview') }}</AppButton>
                <AppButton v-if="canEdit && !a.active_job" size="sm" data-testid="open-create-job" @click="openCreate(a)">{{ a.wordpress ? t('p6.pub.center.update') : t('p6.pub.center.publish') }}</AppButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppCard>

    <AppCard :title="t('p6.pub.center.jobs')" :padded="false">
      <LoadingState v-if="jobs.loading.value && !jobs.loaded.value" />
      <ErrorState v-else-if="jobs.error.value" :error="jobs.error.value" @retry="jobs.reload" />
      <EmptyState v-else-if="!jobs.items.value.length" icon="layers" :title="t('p6.pub.center.noJobs')" />
      <ul v-else class="divide-y divide-line" data-testid="active-jobs">
        <li v-for="j in jobs.items.value" :key="j.id">
          <button type="button" class="flex w-full flex-wrap items-center gap-2 px-5 py-3 text-left text-sm hover:bg-surface-muted" @click="openJob = j.id">
            <AppBadge :tone="jobTone(j.status)">{{ t(`p6.pub.status.${j.status}`) }}</AppBadge>
            <span class="min-w-0 flex-1 truncate font-medium">{{ j.article_title ?? j.article_id }}</span>
            <span class="text-xs text-ink-muted">{{ t(`p6.pub.mode.${j.mode}`) }}<template v-if="j.scheduled_at"> · {{ formatDateTime(j.scheduled_at, j.timezone) }}</template></span>
            <span v-if="j.blocked_reason" class="w-full text-xs text-danger-600">{{ j.gate_results.find((g) => !g.ok)?.message ?? j.blocked_reason }}</span>
          </button>
        </li>
      </ul>
      <div v-if="jobs.next.value" class="border-t border-line p-3 text-center"><AppButton variant="ghost" size="sm" :loading="jobs.loadingMore.value" @click="jobs.more">{{ t('common.loadMore') }}</AppButton></div>
    </AppCard>

    <AppModal :open="!!preview" :title="t('p6.pub.preview.title')" width="xl" @close="preview = null">
      <template v-if="preview">
        <p class="mb-3 rounded bg-warning-50 px-3 py-2 text-xs text-amber-900" data-testid="simulation-disclaimer">{{ preview.disclaimer }}</p>
        <div class="mb-3 rounded-lg border border-line p-3">
          <p class="truncate text-xs text-success-600">{{ preview.snippet.url }}</p>
          <p class="truncate text-base text-brand-700">{{ preview.snippet.title }}</p>
          <p class="text-sm text-ink-muted">{{ preview.snippet.description || t('p6.seo.meta.noDescription') }}</p>
        </div>
        <p v-if="!preview.approved" class="mb-2 text-xs text-danger-600">{{ t('p6.pub.preview.notApproved') }}</p>
        <SimulationFrame :html="preview.html" :title="preview.title" />
      </template>
    </AppModal>

    <AppModal :open="!!createFor" :title="t('p6.pub.center.createTitle')" @close="createFor = null">
      <div v-if="createFor" class="space-y-3 text-sm">
        <p class="font-medium">{{ createFor.title }}</p>
        <AppSelect v-model="createForm.mode" :label="t('p6.pub.center.mode')" :options="modeOpts" />
        <p class="text-xs text-ink-muted">{{ t(`p6.pub.modeHint.${createForm.mode}`) }}</p>
        <label v-if="createForm.mode === 'scheduled'" class="block text-sm">{{ t('p6.pub.job.when') }}
          <input v-model="createForm.scheduled_at" type="datetime-local" class="mt-1 w-full rounded border border-line px-2 py-1" />
        </label>
        <label v-if="canPublish" class="flex items-center gap-2"><input v-model="createForm.approve" type="checkbox" />{{ t('p6.pub.center.approveNow') }}</label>
        <p class="text-xs text-ink-muted">{{ t('p6.pub.gates.note') }}</p>
      </div>
      <template #footer>
        <AppButton variant="secondary" @click="createFor = null">{{ t('common.cancel') }}</AppButton>
        <AppButton :loading="creating" data-testid="create-job" @click="create">{{ t('p6.pub.center.createJob') }}</AppButton>
      </template>
    </AppModal>

    <AppModal :open="bulkOpen" :title="t('p6.pub.bulk.title')" width="lg" @close="bulkOpen = false">
      <div class="space-y-3 text-sm">
        <p v-if="bulkSiteMismatch" class="text-xs text-danger-600">{{ t('p6.pub.bulk.oneSite') }}</p>
        <AppSelect v-model="bulk.mode" :label="t('p6.pub.center.mode')" :options="['wp_draft', 'publish_now', 'scheduled'].map((m) => ({ value: m, label: t(`p6.pub.mode.${m}`) }))" />
        <template v-if="bulk.mode === 'scheduled'">
          <label class="block">{{ t('p6.pub.bulk.start') }}<input v-model="bulk.start_at" type="datetime-local" class="mt-1 w-full rounded border border-line px-2 py-1" /></label>
          <label class="block">{{ t('p6.pub.bulk.interval') }}<input v-model="bulk.interval_minutes" type="number" min="0" class="mt-1 w-full rounded border border-line px-2 py-1" /></label>
        </template>
        <AppButton variant="secondary" size="sm" :loading="bulkBusy" :disabled="bulkSiteMismatch" @click="previewBulk">{{ t('p6.pub.bulk.check') }}</AppButton>
        <ul v-if="bulkPreview" class="max-h-72 divide-y divide-line overflow-y-auto rounded border border-line" data-testid="bulk-preview">
          <li v-for="i in bulkPreview" :key="i.article_id" class="flex flex-wrap items-center gap-2 px-3 py-2 text-xs">
            <AppBadge :tone="i.ok ? 'success' : 'danger'">{{ i.ok ? '✓' : '✕' }}</AppBadge>
            <span class="min-w-0 flex-1 truncate">{{ i.title }}</span>
            <span v-if="i.local_time" class="text-ink-muted">{{ i.local_time }}</span>
            <span v-if="i.message" class="w-full text-danger-600">{{ i.message }}</span>
          </li>
        </ul>
      </div>
      <template #footer>
        <AppButton variant="secondary" @click="bulkOpen = false">{{ t('common.cancel') }}</AppButton>
        <AppButton :loading="bulkBusy" :disabled="!bulkPreview || !bulkPreview.some((i) => i.ok)" data-testid="create-bulk" @click="createBulk">{{ t('p6.pub.bulk.create') }}</AppButton>
      </template>
    </AppModal>

    <JobDrawer :job-id="openJob" @close="openJob = null" @changed="onJobChanged" />
  </div>
</template>
