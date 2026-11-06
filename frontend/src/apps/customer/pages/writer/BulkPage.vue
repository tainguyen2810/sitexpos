<script setup lang="ts">
import { computed, reactive, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatUsd, idempotencyKey, usdToMicro, writerApi, type ApprovalMode, type Batch, type BatchInput, type BatchPreview, type WriterIdea } from '@/shared/api/writer'
import { researchApi, type Campaign } from '@/shared/api/research'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import Drawer from '@/shared/ui/Drawer.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import WriterNav from './components/WriterNav.vue'
import { usePolling, useWriterErrors, useWriterPerms } from './useWriter'

const { t, te } = useI18n()
const { workspaceId: id, canEdit } = useWriterPerms()
const { toastError, toast } = useWriterErrors()
const status = ref('')
const list = useCursorList((cursor) => writerApi.batches(id.value, { cursor, status: status.value }))
void list.reload()
watch(status, () => void list.reload())
const statusOptions = computed(() => [{ value: '', label: t('common.all') }, ...['running', 'paused', 'completed', 'canceled'].map((s) => ({ value: s, label: t(`writer.bulk.status.${s}`) }))])
const anyRunning = computed(() => list.items.value.some((b) => b.status === 'running' || b.status === 'queued'))
usePolling(() => list.reload(), anyRunning, 8000)

const done = (b: Batch) => (b.counts.succeeded ?? 0) + (b.counts.failed ?? 0) + (b.counts.canceled ?? 0) + (b.counts.skipped_duplicate ?? 0)
const tone = (s: string) => (s === 'completed' ? 'success' : s === 'failed' ? 'danger' : s === 'paused' ? 'warning' : s === 'canceled' ? 'neutral' : 'brand') as 'success' | 'danger' | 'warning' | 'neutral' | 'brand'
const tr = (p: string, v: string) => (te(`${p}.${v}`) ? t(`${p}.${v}`) : v)

// ---- new batch ----
const open = ref(false)
const options = useAsync(() => writerApi.options(id.value), { immediate: false })
const presets = useAsync(() => writerApi.presets(id.value), { immediate: false })
const campaigns = shallowRef<Campaign[]>([])
const ideas = shallowRef<WriterIdea[]>([])
const ideasLoading = ref(false)
const form = reactive({ campaign_id: '', ids: [] as string[], concurrency: '2', budget: '', target_words: '1500', review_mode: 'final_only' as 'brief_only' | 'final_only', approval_mode: 'manual' as ApprovalMode, preset: '' })
const preview = shallowRef<BatchPreview | null>(null)
const { errors, formError, submitting, submit } = useApiForm()
let key = idempotencyKey()
async function openNew() {
  open.value = true
  preview.value = null
  key = idempotencyKey()
  void options.run().then((o) => {
    if (o && !form.budget) form.budget = String(o.limits.default_article_budget_micro_usd / 1_000_000)
  })
  void presets.run()
  campaigns.value = (await researchApi.campaigns(id.value, { status: 'active', per_page: 100 }).catch(() => ({ data: [] as Campaign[] }))).data
}
watch(() => form.campaign_id, async (c) => {
  ideas.value = []
  form.ids = []
  preview.value = null
  if (!c) return
  ideasLoading.value = true
  ideas.value = await writerApi.ideas(id.value, c).catch(() => [])
  ideasLoading.value = false
})
watch(() => [form.ids.length, form.budget, form.target_words], () => (preview.value = null))
const free = computed(() => ideas.value.filter((i) => !i.article_id))
const maxSize = computed(() => options.data.value?.limits.max_batch_size ?? 20)
function toggleAll() {
  form.ids = form.ids.length ? [] : free.value.slice(0, maxSize.value).map((i) => i.id)
}
function input(): BatchInput {
  return {
    campaign_id: form.campaign_id, content_idea_ids: form.ids,
    concurrency: Math.max(1, Number(form.concurrency) || 1), per_article_budget_micro_usd: usdToMicro(Number(form.budget) || 0),
    writing_preset_id: form.preset || null, settings: { target_words: Number(form.target_words) || 1500, review_mode: form.review_mode, approval_mode: form.approval_mode },
  }
}
async function doPreview() {
  const p = await submit(() => writerApi.previewBatch(id.value, input()))
  if (p) preview.value = p
}
async function create() {
  const b = await submit(() => writerApi.createBatch(id.value, input(), key))
  if (b) {
    open.value = false
    toast.success(t('common.saved'))
    await list.reload()
    void show(b)
  }
}
const campaignOptions = computed(() => [{ value: '', label: '—' }, ...campaigns.value.map((c) => ({ value: c.id, label: c.name }))])
const presetOptions = computed(() => [{ value: '', label: t('writer.wizard.noPreset') }, ...(presets.data.value ?? []).map((p) => ({ value: p.id, label: p.name }))])
const autoApprove = computed(() => options.data.value?.auto_approve ?? { enabled: false, min_quality: 0 })
const approvalOptions = computed(() => (autoApprove.value.enabled ? (['manual', 'auto'] as const) : (['manual'] as const)).map((v) => ({ value: v, label: t(`writer.approvalMode.${v}`) })))
const reviewOptions = computed(() => (['final_only', 'brief_only'] as const).map((v) => ({ value: v, label: t(`writer.reviewMode.${v}`) })))

// ---- batch detail ----
const detail = shallowRef<Batch | null>(null)
async function show(b: Batch) {
  detail.value = b
  try {
    detail.value = await writerApi.batch(id.value, b.id)
  } catch (e) {
    toastError(e)
  }
}
const detailRunning = computed(() => detail.value?.status === 'running')
usePolling(() => detail.value && show(detail.value), detailRunning, 5000)
const acting = ref(false)
const confirmCancel = ref(false)
async function act(action: 'pause' | 'resume' | 'cancel' | 'retry') {
  if (!detail.value) return
  acting.value = true
  try {
    detail.value = await writerApi.batchAction(id.value, detail.value.id, action)
    confirmCancel.value = false
    await list.reload()
  } catch (e) {
    toastError(e)
  } finally {
    acting.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('writer.bulk.title')" :description="t('writer.bulk.subtitle')">
      <template #actions>
        <AppButton v-if="canEdit" data-testid="new-batch" @click="openNew"><AppIcon name="plus" :size="16" /> {{ t('writer.bulk.new') }}</AppButton>
      </template>
    </PageHeader>
    <WriterNav active="bulk" />

    <AppCard :padded="false">
      <div class="flex items-center gap-2 border-b border-line px-5 py-3">
        <FilterSelect v-model="status" :label="t('common.status')" :options="statusOptions" />
      </div>
      <LoadingState v-if="list.loading.value && !list.loaded.value" class="p-5" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" class="p-5" @retry="list.reload" />
      <EmptyState v-else-if="!list.items.value.length" icon="layers" :title="t('writer.bulk.empty')" :description="t('writer.bulk.subtitle')" />
      <ul v-else class="divide-y divide-line" data-testid="batch-list">
        <li v-for="b in list.items.value" :key="b.id">
          <button type="button" class="flex w-full flex-wrap items-center gap-3 px-5 py-4 text-left hover:bg-surface-muted" @click="show(b)">
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium">{{ formatDateTime(b.created_at) }} <AppBadge v-if="b.is_demo" tone="warning">{{ t('writer.demo') }}</AppBadge></p>
              <p class="text-xs text-ink-muted">{{ t('writer.bulk.progress', { done: done(b), total: b.total }) }} · {{ formatUsd(b.spent_micro_usd) }} / {{ formatUsd(b.estimated_cost_micro_usd) }}</p>
              <ProgressBar class="mt-1 max-w-xs" :value="done(b)" :max="b.total || 1" />
            </div>
            <AppBadge :tone="tone(b.status)">{{ tr('writer.bulk.status', b.status) }}</AppBadge>
          </button>
        </li>
      </ul>
      <div v-if="list.next.value" class="border-t border-line p-3 text-center">
        <AppButton variant="ghost" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton>
      </div>
    </AppCard>

    <AppModal :open="open" :title="t('writer.bulk.new')" width="lg" @close="open = false">
      <div class="grid gap-4 sm:grid-cols-2">
        <p v-if="formError" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600 sm:col-span-2" role="alert">{{ formError }}</p>
        <AppSelect v-model="form.campaign_id" class="sm:col-span-2" :label="t('writer.bulk.campaign')" :options="campaignOptions" :error="errors.campaign_id" />
        <div class="sm:col-span-2">
          <div class="mb-1 flex items-center justify-between text-sm">
            <span class="font-medium">{{ t('writer.bulk.pickIdeas') }}</span>
            <button v-if="free.length" type="button" class="text-xs text-brand-600 underline" @click="toggleAll">{{ t('writer.bulk.selected', { n: form.ids.length }) }}</button>
          </div>
          <LoadingState v-if="ideasLoading" />
          <p v-else-if="form.campaign_id && !ideas.length" class="text-sm text-ink-muted">{{ t('writer.wizard.noIdeas') }}</p>
          <ul v-else-if="ideas.length" class="max-h-64 divide-y divide-line overflow-y-auto rounded-lg border border-line">
            <li v-for="i in ideas" :key="i.id">
              <label class="flex items-center gap-2 px-3 py-2 text-sm" :class="i.article_id ? 'opacity-60' : ''">
                <input v-model="form.ids" type="checkbox" :value="i.id" :disabled="!!i.article_id || (!form.ids.includes(i.id) && form.ids.length >= maxSize)" />
                <span class="min-w-0 flex-1 truncate">{{ i.title }}</span>
                <AppBadge v-if="i.article_id" tone="neutral">{{ t('writer.wizard.ideaTaken') }}</AppBadge>
              </label>
            </li>
          </ul>
          <p v-if="errors.content_idea_ids" class="mt-1 text-xs text-danger-600">{{ errors.content_idea_ids }}</p>
        </div>
        <AppInput v-model="form.concurrency" type="number" :label="t('writer.bulk.concurrency')" :error="errors.concurrency" :hint="options.data.value ? `≤ ${options.data.value.limits.max_batch_concurrency}` : undefined" />
        <AppInput v-model="form.budget" type="number" :label="t('writer.bulk.perArticle')" :error="errors.per_article_budget_micro_usd" />
        <AppInput v-model="form.target_words" type="number" :label="t('writer.wizard.targetWords')" />
        <AppSelect v-model="form.review_mode" :label="t('writer.wizard.steps.reviewMode')" :options="reviewOptions" />
        <AppSelect
          v-model="form.approval_mode" :label="t('writer.article.approvalMode')" :options="approvalOptions" :error="errors['settings.approval_mode']"
          :hint="autoApprove.enabled ? t('writer.autoApprove.hint', { min: autoApprove.min_quality }) : t('writer.autoApprove.disabled')" />
        <AppSelect v-model="form.preset" class="sm:col-span-2" :label="t('writer.wizard.preset')" :options="presetOptions" />
        <div v-if="preview" class="rounded-lg border border-line p-3 text-sm sm:col-span-2" data-testid="batch-preview">
          <p class="font-medium">{{ t('writer.bulk.creatable', { n: preview.creatable }) }}</p>
          <p class="text-ink-muted">{{ t('writer.bulk.estimated') }}: {{ formatUsd(preview.estimated_cost_micro_usd) }} · {{ t('writer.bulk.maxCost') }}: {{ formatUsd(preview.max_cost_micro_usd) }}</p>
          <p class="text-ink-muted">{{ t('writer.wizard.quota', { remaining: preview.quota.remaining, limit: preview.quota.limit }) }}</p>
          <p v-if="preview.items.some((i) => i.duplicate)" class="text-warning-600">{{ t('writer.bulk.skippedDup') }}: {{ preview.items.filter((i) => i.duplicate).map((i) => i.title).join(', ') }}</p>
          <p v-if="preview.not_approved_or_missing.length" class="text-warning-600">{{ t('writer.bulk.notApproved', { n: preview.not_approved_or_missing.length }) }}</p>
        </div>
        <p class="text-xs text-ink-muted sm:col-span-2">{{ t('writer.notPublished') }}</p>
      </div>
      <template #footer>
        <AppButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</AppButton>
        <AppButton v-if="!preview" :loading="submitting" :disabled="!form.ids.length" @click="doPreview">{{ t('writer.bulk.preview') }}</AppButton>
        <AppButton v-else :loading="submitting" :disabled="!preview.creatable || preview.creatable > preview.quota.remaining" data-testid="start-batch" @click="create">{{ t('writer.bulk.start') }}</AppButton>
      </template>
    </AppModal>

    <Drawer :open="!!detail" :title="detail ? tr('writer.bulk.status', detail.status) : ''" :subtitle="detail ? t('writer.bulk.progress', { done: done(detail), total: detail.total }) : undefined" @close="detail = null">
      <div v-if="detail" class="space-y-4 text-sm">
        <p class="text-ink-muted">{{ formatUsd(detail.spent_micro_usd) }} / {{ formatUsd(detail.estimated_cost_micro_usd) }} · {{ t('writer.bulk.concurrency') }}: {{ detail.concurrency }}</p>
        <div v-if="canEdit" class="flex flex-wrap gap-2">
          <AppButton v-if="detail.status === 'running'" size="sm" variant="secondary" :loading="acting" @click="act('pause')"><AppIcon name="pause" :size="14" /> {{ t('writer.bulk.pause') }}</AppButton>
          <AppButton v-if="detail.status === 'paused'" size="sm" :loading="acting" @click="act('resume')"><AppIcon name="play" :size="14" /> {{ t('writer.bulk.resume') }}</AppButton>
          <AppButton v-if="(detail.counts.failed ?? 0) > 0 && detail.status !== 'canceled'" size="sm" variant="secondary" :loading="acting" @click="act('retry')">{{ t('writer.bulk.retry') }}</AppButton>
          <AppButton v-if="['running', 'paused', 'queued'].includes(detail.status)" size="sm" variant="danger" @click="confirmCancel = true">{{ t('writer.bulk.cancel') }}</AppButton>
        </div>
        <ul class="divide-y divide-line rounded-lg border border-line">
          <li v-for="i in detail.items ?? []" :key="i.id" class="flex flex-wrap items-center gap-2 px-3 py-2">
            <span class="w-6 text-xs text-ink-muted">{{ i.position }}</span>
            <RouterLink v-if="i.article_id" :to="{ name: 'writer-article', params: { workspaceId: id, articleId: i.article_id } }" class="min-w-0 flex-1 truncate text-brand-600 underline">{{ i.title }}</RouterLink>
            <span v-else class="min-w-0 flex-1 truncate">{{ i.title }}</span>
            <AppBadge :tone="i.status === 'failed' ? 'danger' : i.status === 'succeeded' ? 'success' : i.status === 'running' ? 'brand' : 'neutral'">{{ tr('writer.bulk.itemStatus', i.status) }}</AppBadge>
            <p v-if="i.error" class="w-full text-xs text-danger-600">{{ i.error.message || i.error.code }}</p>
          </li>
        </ul>
      </div>
    </Drawer>
    <ConfirmDialog :open="confirmCancel" :title="t('writer.bulk.cancel')" :message="t('writer.bulk.cancelConfirm')" danger :loading="acting" @close="confirmCancel = false" @confirm="act('cancel')" />
  </div>
</template>
