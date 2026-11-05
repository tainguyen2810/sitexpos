<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { researchApi, INTENTS, scoreTone, formatNumber, formatUsd, type Campaign, type Intent, type Keyword } from '@/shared/api/research'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useCursorList } from '@/shared/composables/useCursorList'
import { useToast } from '@/shared/composables/useToast'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import Drawer from '@/shared/ui/Drawer.vue'
import Toolbar from '@/shared/ui/Toolbar.vue'
import SearchInput from '@/shared/ui/SearchInput.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import ScoreBreakdown from './ScoreBreakdown.vue'

const props = defineProps<{ workspaceId: string; campaign: Campaign; canOperate: boolean }>()
const { t } = useI18n()
const toast = useToast()

const SOURCES = ['campaign', 'wordpress', 'woocommerce', 'website_crawl', 'competitor', 'search_console', 'ai_expansion', 'dataforseo', 'user', 'demo'] as const
const filters = ref({ q: '', intent: '', status: '', source: '', cluster_id: '', has_metrics: '', sort: '' })
const filtered = computed(() => Object.values(filters.value).some((v) => v !== ''))
const list = useCursorList((cursor) => {
  const f = filters.value
  const [sort, direction] = f.sort ? f.sort.split(':') : ['', '']
  return researchApi.keywords(props.workspaceId, props.campaign.id, { cursor, q: f.q || undefined, intent: f.intent || undefined, status: f.status || undefined, source: f.source || undefined, cluster_id: f.cluster_id || undefined, has_metrics: f.has_metrics || undefined, sort: sort || undefined, direction: direction || undefined })
})
void list.reload()
let debounce: ReturnType<typeof setTimeout> | null = null
watch(filters, () => {
  if (debounce) clearTimeout(debounce)
  debounce = setTimeout(() => void list.reload(), 300)
}, { deep: true })
function clearFilters() {
  filters.value = { q: '', intent: '', status: '', source: '', cluster_id: '', has_metrics: '', sort: '' }
}

const clusters = useAsync(() => researchApi.clusters(props.workspaceId, props.campaign.id))
const clusterName = (id: string | null) => clusters.data.value?.find((c) => c.id === id)?.name ?? null

const all = computed(() => ({ value: '', label: t('common.all') }))
const intentOptions = computed(() => [all.value, ...INTENTS.map((i) => ({ value: i, label: t(`research.intent.${i}`) }))])
const statusOptions = computed(() => [all.value, ...(['suggested', 'approved', 'rejected'] as const).map((s) => ({ value: s, label: t(`research.keywords.statuses.${s}`) }))])
const sourceOptions = computed(() => [all.value, ...SOURCES.map((s) => ({ value: s, label: t(`research.keywords.sources.${s}`) }))])
const clusterOptions = computed(() => [all.value, { value: 'none', label: '—' }, ...(clusters.data.value ?? []).map((c) => ({ value: c.id, label: c.name }))])
const metricOptions = computed(() => [all.value, { value: '1', label: t('research.keywords.volume') }])
const sortOptions = computed(() => [
  { value: '', label: t('research.keywords.score') },
  { value: 'keyword:asc', label: 'A → Z' },
  { value: 'created:desc', label: t('research.keywords.collected') },
])

// selection & bulk
const selected = ref<Set<string>>(new Set())
function toggle(id: string) {
  const s = new Set(selected.value)
  if (s.has(id)) s.delete(id)
  else s.add(id)
  selected.value = s
}
const allSelected = computed(() => list.items.value.length > 0 && list.items.value.every((k) => selected.value.has(k.id)))
function toggleAll() {
  selected.value = allSelected.value ? new Set() : new Set(list.items.value.map((k) => k.id))
}
const bulkBusy = ref(false)
async function bulk(status: Keyword['status']) {
  bulkBusy.value = true
  try {
    const res = await researchApi.bulkKeywords(props.workspaceId, props.campaign.id, [...selected.value], status)
    toast.success(t('research.keywords.bulkDone', { n: res.updated }))
    selected.value = new Set()
    await list.reload()
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  } finally {
    bulkBusy.value = false
  }
}

async function setStatus(k: Keyword, status: Keyword['status']) {
  try {
    replace(await researchApi.updateKeyword(props.workspaceId, props.campaign.id, k.id, { status }))
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  }
}
async function setIntent(k: Keyword, intent: string) {
  try {
    replace(await researchApi.updateKeyword(props.workspaceId, props.campaign.id, k.id, { intent: intent as Intent }))
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  }
}
function replace(k: Keyword) {
  list.items.value = list.items.value.map((x) => (x.id === k.id ? k : x))
  if (detail.value?.id === k.id) detail.value = k
}

// detail drawer
const detail = ref<Keyword | null>(null)

// add keyword
const addOpen = ref(false)
const draft = ref({ keyword: '', intent: '' })
const form = useApiForm()
function openAdd() {
  form.reset()
  draft.value = { keyword: '', intent: '' }
  addOpen.value = true
}
async function add() {
  const res = await form.submit(() => researchApi.addKeyword(props.workspaceId, props.campaign.id, { keyword: draft.value.keyword, intent: (draft.value.intent || undefined) as Intent | undefined }))
  if (!res) return
  addOpen.value = false
  await list.reload()
}

function metricText(v: number | null | undefined): string {
  return v === null || v === undefined ? t('research.noData') : formatNumber(v)
}
function evidenceText(e: Record<string, unknown>): string {
  const type = String(e.type ?? '')
  const label = (e.title ?? e.name ?? e.url ?? e.query ?? e.value ?? '') as string
  return `${type}${label ? ` · ${label}` : ''}`
}
</script>

<template>
  <AppCard :padded="false">
    <Toolbar :count="list.items.value.length" :filtered="filtered" @clear="clearFilters">
      <SearchInput v-model="filters.q" :placeholder="t('research.keywords.search')" />
      <FilterSelect v-model="filters.intent" :label="t('research.keywords.intent')" :options="intentOptions" />
      <FilterSelect v-model="filters.status" :label="t('research.keywords.status')" :options="statusOptions" />
      <FilterSelect v-model="filters.source" :label="t('research.keywords.source')" :options="sourceOptions" />
      <FilterSelect v-model="filters.cluster_id" :label="t('research.tabs.clusters')" :options="clusterOptions" />
      <FilterSelect v-model="filters.has_metrics" :label="t('research.keywords.hasMetrics')" :options="metricOptions" />
      <FilterSelect v-model="filters.sort" :label="t('research.keywords.sort')" :options="sortOptions" />
      <template #right>
        <AppButton v-if="canOperate" size="sm" @click="openAdd"><AppIcon name="plus" :size="14" /> {{ t('research.keywords.add') }}</AppButton>
      </template>
    </Toolbar>

    <div v-if="canOperate && selected.size" class="flex flex-wrap items-center gap-2 border-b border-line bg-brand-50 px-5 py-2 text-sm">
      <span>{{ t('research.keywords.selected', { n: selected.size }) }}</span>
      <AppButton size="sm" variant="secondary" :loading="bulkBusy" @click="bulk('approved')">{{ t('research.keywords.approve') }}</AppButton>
      <AppButton size="sm" variant="ghost" :loading="bulkBusy" @click="bulk('rejected')">{{ t('research.keywords.reject') }}</AppButton>
    </div>

    <p class="border-b border-line px-5 py-2 text-xs text-ink-muted">{{ t('research.keywords.metricsHint') }}</p>

    <LoadingState v-if="list.loading.value" class="p-5" />
    <ErrorState v-else-if="list.error.value" :error="list.error.value" class="p-5" @retry="list.reload" />
    <EmptyState v-else-if="!list.items.value.length" icon="key" :title="t('research.noData')" :description="t('research.ideas.emptyBody')" />
    <div v-else class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-surface-muted text-left text-xs uppercase tracking-wide text-ink-muted">
          <tr>
            <th v-if="canOperate" class="w-8 px-3 py-2"><input type="checkbox" :checked="allSelected" :aria-label="t('common.all')" @change="toggleAll" /></th>
            <th class="px-3 py-2">{{ t('research.keywords.keyword') }}</th>
            <th class="px-3 py-2">{{ t('research.keywords.intent') }}</th>
            <th class="px-3 py-2 text-right">{{ t('research.keywords.volume') }}</th>
            <th class="px-3 py-2 text-right">{{ t('research.keywords.difficulty') }}</th>
            <th class="px-3 py-2 text-right" :title="t('research.internalEstimateHint')">{{ t('research.keywords.score') }}</th>
            <th class="px-3 py-2">{{ t('research.keywords.source') }}</th>
            <th class="px-3 py-2">{{ t('research.keywords.status') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="k in list.items.value" :key="k.id" class="hover:bg-surface-muted" :class="k.status === 'rejected' ? 'opacity-60' : ''">
            <td v-if="canOperate" class="px-3 py-2"><input type="checkbox" :checked="selected.has(k.id)" :aria-label="k.keyword" @change="toggle(k.id)" /></td>
            <td class="px-3 py-2">
              <button type="button" class="text-left font-medium text-ink hover:text-brand-700" @click="detail = k">{{ k.keyword }}</button>
              <p v-if="clusterName(k.cluster_id)" class="text-xs text-ink-muted">{{ clusterName(k.cluster_id) }}</p>
              <p v-if="k.covered_by.length" class="text-xs text-warning-600">{{ t('research.keywords.coveredBy') }}: {{ k.covered_by[0]?.title }}</p>
            </td>
            <td class="px-3 py-2"><AppBadge>{{ t(`research.intent.${k.intent}`) }}</AppBadge></td>
            <td class="px-3 py-2 text-right tabular-nums" :class="k.metrics.search_volume === null ? 'text-xs text-ink-subtle' : ''">{{ metricText(k.metrics.search_volume) }}</td>
            <td class="px-3 py-2 text-right tabular-nums" :class="k.metrics.difficulty === null ? 'text-xs text-ink-subtle' : ''">{{ metricText(k.metrics.difficulty) }}</td>
            <td class="px-3 py-2 text-right"><AppBadge :tone="scoreTone(k.opportunity.score)">{{ k.opportunity.score ?? '—' }}</AppBadge></td>
            <td class="px-3 py-2 text-xs text-ink-muted">{{ k.sources.map((s) => t(`research.keywords.sources.${s}`)).join(', ') }}</td>
            <td class="px-3 py-2 whitespace-nowrap">
              <template v-if="canOperate">
                <AppButton v-if="k.status !== 'approved'" size="sm" variant="ghost" :aria-label="t('research.keywords.approve')" @click="setStatus(k, 'approved')"><AppIcon name="check" :size="14" /></AppButton>
                <AppButton v-if="k.status !== 'rejected'" size="sm" variant="ghost" :aria-label="t('research.keywords.reject')" @click="setStatus(k, 'rejected')"><AppIcon name="x" :size="14" /></AppButton>
              </template>
              <AppBadge :tone="k.status === 'approved' ? 'success' : 'neutral'">{{ t(`research.keywords.statuses.${k.status}`) }}</AppBadge>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="list.next.value" class="border-t border-line p-3 text-center">
      <AppButton variant="ghost" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton>
    </div>
  </AppCard>

  <Drawer :open="!!detail" :title="detail?.keyword ?? ''" :subtitle="detail ? `${t(`research.intent.${detail.intent}`)} · ${detail.country}/${detail.language}` : ''" @close="detail = null">
    <template v-if="detail">
      <div v-if="canOperate" class="mb-4">
        <AppSelect :model-value="detail.intent" :label="t('research.keywords.intent')" :options="intentOptions.slice(1)" @update:model-value="(v: string) => setIntent(detail!, v)" />
      </div>

      <section class="mb-5">
        <h3 class="mb-2 text-sm font-semibold">{{ t('research.keywords.metrics') }}</h3>
        <dl class="grid grid-cols-3 gap-2 text-sm">
          <div class="rounded-lg bg-surface-muted px-3 py-2"><dt class="text-xs text-ink-muted">{{ t('research.keywords.volume') }}</dt><dd class="font-semibold tabular-nums">{{ metricText(detail.metrics.search_volume) }}</dd></div>
          <div class="rounded-lg bg-surface-muted px-3 py-2"><dt class="text-xs text-ink-muted">{{ t('research.keywords.difficulty') }}</dt><dd class="font-semibold tabular-nums">{{ metricText(detail.metrics.difficulty) }}</dd></div>
          <div class="rounded-lg bg-surface-muted px-3 py-2"><dt class="text-xs text-ink-muted">CPC</dt><dd class="font-semibold tabular-nums">{{ detail.metrics.cpc_micro_usd === null ? t('research.noData') : formatUsd(detail.metrics.cpc_micro_usd) }}</dd></div>
        </dl>
        <p v-if="detail.metrics.provider" class="mt-2 text-xs text-ink-muted">{{ t('research.keywords.provider', { source: detail.metrics.provider.source, time: formatDateTime(detail.metrics.provider.collected_at) }) }}</p>
        <p v-else class="mt-2 text-xs text-ink-muted">{{ t('research.keywords.metricsHint') }}</p>
        <div v-if="detail.metrics.search_console" class="mt-3 rounded-lg border border-line px-3 py-2 text-sm">
          <p class="text-xs font-medium text-ink-muted">{{ t('research.keywords.searchConsole') }}</p>
          <p>{{ t('research.keywords.gsc', { impressions: formatNumber(detail.metrics.search_console.impressions), clicks: formatNumber(detail.metrics.search_console.clicks), position: detail.metrics.search_console.position ?? '—' }) }}</p>
          <p class="text-xs text-ink-subtle">{{ detail.metrics.search_console.period.join(' → ') }}</p>
        </div>
      </section>

      <section class="mb-5">
        <h3 class="mb-1 text-sm font-semibold">{{ t('research.internalEstimate') }}: {{ detail.opportunity.score ?? '—' }}</h3>
        <ScoreBreakdown :components="detail.opportunity.components" :confidence="detail.opportunity.confidence" :penalty="detail.opportunity.penalty" />
      </section>

      <section v-if="detail.covered_by.length" class="mb-5">
        <h3 class="mb-2 text-sm font-semibold">{{ t('research.keywords.coveredBy') }}</h3>
        <ul class="space-y-1 text-sm">
          <li v-for="c in detail.covered_by" :key="c.id">
            <a v-if="c.url" :href="c.url" target="_blank" rel="noopener noreferrer" class="text-brand-700 hover:underline">{{ c.title }}</a>
            <span v-else>{{ c.title }}</span>
            <span v-if="c.similarity !== undefined" class="text-xs text-ink-muted"> · {{ Math.round(c.similarity * 100) }}%</span>
          </li>
        </ul>
      </section>

      <section>
        <h3 class="mb-2 text-sm font-semibold">{{ t('research.keywords.evidence') }}</h3>
        <ul class="space-y-1 text-xs text-ink-muted">
          <li v-for="(e, i) in detail.evidence.slice(0, 20)" :key="i" class="break-words">• {{ evidenceText(e) }}</li>
        </ul>
        <p class="mt-3 text-xs text-ink-subtle">{{ t('research.keywords.collected') }}: {{ formatDateTime(detail.data_collected_at) }}</p>
      </section>
    </template>
  </Drawer>

  <AppModal :open="addOpen" :title="t('research.keywords.add')" @close="addOpen = false">
    <form id="keyword-form" class="space-y-4" @submit.prevent="add">
      <p v-if="form.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ form.formError.value }}</p>
      <AppInput v-model="draft.keyword" :label="t('research.keywords.keyword')" required :error="form.errors.keyword" />
      <AppSelect v-model="draft.intent" :label="t('research.keywords.intent')" :options="[{ value: '', label: t('research.keywords.autoIntent') }, ...intentOptions.slice(1)]" :error="form.errors.intent" />
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="addOpen = false">{{ t('common.cancel') }}</AppButton>
      <AppButton type="submit" form="keyword-form" :loading="form.submitting.value">{{ t('common.save') }}</AppButton>
    </template>
  </AppModal>
</template>
