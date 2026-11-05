<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { researchApi, INTENTS, CONTENT_TYPES, scoreTone, type Campaign, type Idea } from '@/shared/api/research'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useCursorList } from '@/shared/composables/useCursorList'
import { useToast } from '@/shared/composables/useToast'
import { formatDate } from '@/shared/utils/format'
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
const err = (e: unknown) => toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))

const filters = ref({ q: '', status: '', search_intent: '', content_type: '', priority: '', source: '', cluster_id: '', sort: '' })
const filtered = computed(() => Object.values(filters.value).some((v) => v !== ''))
const list = useCursorList((cursor) => {
  const f = filters.value
  const [sort, direction] = f.sort ? f.sort.split(':') : ['', '']
  const q: Record<string, string | undefined> = { cursor: cursor ?? undefined, sort: sort || undefined, direction: direction || undefined }
  for (const k of ['q', 'status', 'search_intent', 'content_type', 'priority', 'source', 'cluster_id'] as const) q[k] = f[k] || undefined
  return researchApi.ideas(props.workspaceId, props.campaign.id, q)
})
void list.reload()
let debounce: ReturnType<typeof setTimeout> | null = null
watch(filters, () => {
  if (debounce) clearTimeout(debounce)
  debounce = setTimeout(() => void list.reload(), 300)
}, { deep: true })
function clearFilters() {
  filters.value = { q: '', status: '', search_intent: '', content_type: '', priority: '', source: '', cluster_id: '', sort: '' }
}

const clusters = useAsync(() => researchApi.clusters(props.workspaceId, props.campaign.id))
const all = computed(() => ({ value: '', label: t('common.all') }))
const opts = computed(() => ({
  status: [all.value, ...(['suggested', 'approved', 'briefed', 'rejected'] as const).map((s) => ({ value: s, label: t(`research.ideas.status.${s}`) }))],
  intent: [all.value, ...INTENTS.map((i) => ({ value: i, label: t(`research.intent.${i}`) }))],
  type: [all.value, ...CONTENT_TYPES.map((c) => ({ value: c, label: t(`research.contentType.${c}`) }))],
  priority: [all.value, ...(['high', 'medium', 'low'] as const).map((p) => ({ value: p, label: t(`research.ideas.priority.${p}`) }))],
  source: [all.value, ...(['ai', 'rule', 'user', 'demo'] as const).map((s) => ({ value: s, label: t(`research.ideas.source.${s}`) }))],
  cluster: [all.value, ...(clusters.data.value ?? []).map((c) => ({ value: c.id, label: c.name }))],
  sort: [{ value: '', label: t('research.keywords.score') }, { value: 'title:asc', label: 'A → Z' }, { value: 'created:desc', label: t('research.ideas.newest') }],
}))
const statusTone = (s: Idea['status']) => (({ suggested: 'warning', approved: 'success', briefed: 'brand', rejected: 'neutral' }) as const)[s]
const priorityTone = (p: Idea['priority']) => (({ high: 'danger', medium: 'warning', low: 'neutral' }) as const)[p]

function replace(i: Idea) {
  list.items.value = list.items.value.map((x) => (x.id === i.id ? i : x))
  if (detail.value?.id === i.id) detail.value = i
}
async function setStatus(i: Idea, status: 'suggested' | 'approved' | 'rejected') {
  try {
    replace(await researchApi.updateIdea(props.workspaceId, props.campaign.id, i.id, { status }))
  } catch (e) {
    err(e)
  }
}

// detail drawer (full idea incl. brief)
const detail = ref<Idea | null>(null)
async function open(i: Idea) {
  detail.value = i
  try {
    detail.value = await researchApi.idea(props.workspaceId, props.campaign.id, i.id)
  } catch (e) {
    err(e)
  }
}
const briefing = ref(false)
const briefOpen = ref(false)
async function makeBrief(i: Idea) {
  briefing.value = true
  try {
    replace(await researchApi.brief(props.workspaceId, props.campaign.id, i.id))
    briefOpen.value = true
  } catch (e) {
    err(e)
  } finally {
    briefing.value = false
  }
}
const brief = computed(() => (detail.value?.brief ?? null) as null | {
  title?: string; primary_keyword?: string; secondary_keywords?: string[]; outline?: string[]; audience?: string | null; angle?: string | null
  internal_links?: { anchor: string; reason: string | null }[]; avoid_duplicating?: { title: string; url?: string | null }[]
  competitor_references?: { title: string | null; url: string | null; fetched_at: string | null }[]; rules?: string[]
})

// schedule into calendar
const scheduleFor = ref<Idea | null>(null)
const scheduleDate = ref('')
const scheduleForm = useApiForm()
function openSchedule(i: Idea) {
  scheduleForm.reset()
  const d = new Date()
  d.setDate(d.getDate() + 1)
  scheduleDate.value = d.toISOString().slice(0, 10)
  scheduleFor.value = i
}
async function schedule() {
  const i = scheduleFor.value
  if (!i) return
  const res = await scheduleForm.submit(() => researchApi.schedule_item(props.workspaceId, props.campaign.id, { content_idea_id: i.id, scheduled_for: scheduleDate.value }))
  if (!res) return
  scheduleFor.value = null
  toast.success(t('research.ideas.scheduled', { date: formatDate(res.scheduled_for) }))
  replace({ ...i, calendar: { id: res.id, scheduled_for: res.scheduled_for, status: res.status } })
}

// edit
const editing = ref<Idea | null>(null)
const draft = ref({ title: '', angle: '', audience: '', content_type: '', search_intent: '', priority: '' })
const editForm = useApiForm()
function openEdit(i: Idea) {
  editForm.reset()
  draft.value = { title: i.title, angle: i.angle ?? '', audience: i.audience ?? '', content_type: i.content_type, search_intent: i.search_intent, priority: i.priority }
  editing.value = i
}
async function saveEdit() {
  const i = editing.value
  if (!i) return
  const d = draft.value
  const res = await editForm.submit(() =>
    researchApi.updateIdea(props.workspaceId, props.campaign.id, i.id, {
      title: d.title, angle: d.angle || null, audience: d.audience || null,
      content_type: d.content_type as Idea['content_type'], search_intent: d.search_intent as Idea['search_intent'], priority: d.priority as Idea['priority'],
    }),
  )
  if (!res) return
  editing.value = null
  replace(res)
}
</script>

<template>
  <AppCard :padded="false">
    <Toolbar :count="list.items.value.length" :filtered="filtered" @clear="clearFilters">
      <SearchInput v-model="filters.q" />
      <FilterSelect v-model="filters.status" :label="t('common.status')" :options="opts.status" />
      <FilterSelect v-model="filters.search_intent" :label="t('research.keywords.intent')" :options="opts.intent" />
      <FilterSelect v-model="filters.content_type" :label="t('research.keywords.type')" :options="opts.type" />
      <FilterSelect v-model="filters.priority" :label="t('research.ideas.priorityLabel')" :options="opts.priority" />
      <FilterSelect v-model="filters.source" :label="t('research.keywords.source')" :options="opts.source" />
      <FilterSelect v-model="filters.cluster_id" :label="t('research.tabs.clusters')" :options="opts.cluster" />
      <FilterSelect v-model="filters.sort" :label="t('research.keywords.sort')" :options="opts.sort" />
    </Toolbar>
    <p class="border-b border-line px-5 py-2 text-xs text-ink-muted">{{ t('research.ideas.noContentNote') }}</p>

    <LoadingState v-if="list.loading.value" class="p-5" />
    <ErrorState v-else-if="list.error.value" :error="list.error.value" class="p-5" @retry="list.reload" />
    <EmptyState v-else-if="!list.items.value.length" icon="sparkles" :title="t('research.ideas.empty')" :description="t('research.ideas.emptyBody')" />
    <ul v-else class="divide-y divide-line">
      <li v-for="i in list.items.value" :key="i.id" class="flex flex-wrap items-start gap-3 px-5 py-4" :class="i.status === 'rejected' ? 'opacity-60' : ''">
        <div class="min-w-0 flex-1">
          <button type="button" class="text-left text-sm font-medium text-ink hover:text-brand-700" @click="open(i)">{{ i.title }}</button>
          <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
            <AppBadge v-if="i.is_pillar" tone="brand">{{ t('research.ideas.pillar') }}</AppBadge>
            <AppBadge>{{ t(`research.contentType.${i.content_type}`) }}</AppBadge>
            <AppBadge>{{ t(`research.intent.${i.search_intent}`) }}</AppBadge>
            <AppBadge :tone="priorityTone(i.priority)">{{ t(`research.ideas.priority.${i.priority}`) }}</AppBadge>
            <span v-if="i.primary_keyword" class="text-ink-muted">· {{ i.primary_keyword.keyword }}</span>
            <span v-if="i.cluster_name" class="text-ink-muted">· {{ i.cluster_name }}</span>
            <span class="text-ink-subtle">· {{ t(`research.ideas.source.${i.source}`) }}</span>
          </div>
          <p v-if="i.overlap" class="mt-1 text-xs text-warning-600">{{ t('research.ideas.overlap', { score: i.overlap.score ?? '?' }) }}</p>
          <p v-if="i.calendar" class="mt-1 text-xs text-success-600">{{ t('research.ideas.scheduled', { date: formatDate(i.calendar.scheduled_for) }) }}</p>
        </div>
        <AppBadge :tone="scoreTone(i.opportunity.score)" :title="t('research.internalEstimate')">{{ i.opportunity.score ?? '—' }}</AppBadge>
        <AppBadge :tone="statusTone(i.status)">{{ t(`research.ideas.status.${i.status}`) }}</AppBadge>
        <div v-if="canOperate" class="flex flex-wrap gap-1">
          <AppButton v-if="i.status === 'suggested'" size="sm" variant="secondary" @click="setStatus(i, 'approved')"><AppIcon name="check" :size="14" /> {{ t('research.ideas.approve') }}</AppButton>
          <AppButton v-if="i.status === 'suggested' || i.status === 'approved'" size="sm" variant="ghost" @click="setStatus(i, 'rejected')">{{ t('research.ideas.reject') }}</AppButton>
          <AppButton v-if="i.status === 'rejected'" size="sm" variant="ghost" @click="setStatus(i, 'suggested')">{{ t('research.ideas.restore') }}</AppButton>
          <AppButton v-if="(i.status === 'approved' || i.status === 'briefed') && !i.calendar" size="sm" variant="ghost" @click="openSchedule(i)"><AppIcon name="calendar" :size="14" /> {{ t('research.ideas.schedule') }}</AppButton>
        </div>
      </li>
    </ul>
    <div v-if="list.next.value" class="border-t border-line p-3 text-center">
      <AppButton variant="ghost" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton>
    </div>
  </AppCard>

  <Drawer :open="!!detail" :title="detail?.title ?? ''" :subtitle="detail ? `${t(`research.contentType.${detail.content_type}`)} · ${t(`research.intent.${detail.search_intent}`)}` : ''" @close="detail = null; briefOpen = false">
    <template v-if="detail">
      <section v-if="detail.rationale" class="mb-5">
        <h3 class="mb-1 text-sm font-semibold">{{ t('research.ideas.why') }}</h3>
        <p class="text-sm">{{ detail.rationale }}</p>
      </section>
      <dl class="mb-5 grid gap-2 text-sm">
        <div v-if="detail.primary_keyword"><dt class="text-xs text-ink-muted">{{ t('research.ideas.primary') }}</dt><dd class="font-medium">{{ detail.primary_keyword.keyword }}</dd></div>
        <div v-if="detail.secondary_keywords.length"><dt class="text-xs text-ink-muted">{{ t('research.ideas.secondary') }}</dt><dd>{{ detail.secondary_keywords.map((k) => k.keyword).join(', ') }}</dd></div>
        <div v-if="detail.angle"><dt class="text-xs text-ink-muted">{{ t('research.ideas.angle') }}</dt><dd>{{ detail.angle }}</dd></div>
        <div v-if="detail.audience"><dt class="text-xs text-ink-muted">{{ t('research.ideas.audience') }}</dt><dd>{{ detail.audience }}</dd></div>
      </dl>
      <section v-if="detail.evidence.outline?.length" class="mb-5">
        <h3 class="mb-1 text-sm font-semibold">{{ t('research.ideas.outline') }}</h3>
        <ol class="list-decimal space-y-0.5 pl-5 text-sm"><li v-for="(o, n) in detail.evidence.outline" :key="n">{{ o }}</li></ol>
      </section>
      <section v-if="detail.evidence.gaps?.length" class="mb-5">
        <h3 class="mb-1 text-sm font-semibold">{{ t('research.ideas.gaps') }}</h3>
        <ul class="space-y-0.5 text-sm"><li v-for="(g, n) in detail.evidence.gaps" :key="n">• {{ t(`research.clusters.gapType.${g.type}`) }}: {{ g.topic }}</li></ul>
      </section>
      <section v-if="detail.evidence.related_existing?.length" class="mb-5">
        <h3 class="mb-1 text-sm font-semibold">{{ t('research.ideas.related') }}</h3>
        <ul class="space-y-0.5 text-sm">
          <li v-for="r in detail.evidence.related_existing" :key="r.id">
            <a v-if="r.url" :href="r.url" target="_blank" rel="noopener noreferrer" class="text-brand-700 hover:underline">{{ r.title }}</a><span v-else>{{ r.title }}</span>
          </li>
        </ul>
      </section>
      <section class="mb-5">
        <h3 class="mb-1 text-sm font-semibold">{{ t('research.internalEstimate') }}: {{ detail.opportunity.score ?? '—' }}</h3>
        <ScoreBreakdown :components="detail.opportunity.components" />
      </section>

      <section v-if="briefOpen && brief" class="rounded-lg border border-brand-200 bg-brand-50 p-4 text-sm" data-testid="brief">
        <h3 class="mb-2 font-semibold">{{ t('research.ideas.briefTitle') }}</h3>
        <p><strong>{{ t('research.ideas.primary') }}:</strong> {{ brief.primary_keyword }}</p>
        <p v-if="brief.secondary_keywords?.length"><strong>{{ t('research.ideas.secondary') }}:</strong> {{ brief.secondary_keywords.join(', ') }}</p>
        <div v-if="brief.outline?.length" class="mt-2"><strong>{{ t('research.ideas.outline') }}:</strong><ol class="list-decimal pl-5"><li v-for="(o, n) in brief.outline" :key="n">{{ o }}</li></ol></div>
        <div v-if="brief.internal_links?.length" class="mt-2"><strong>{{ t('research.clusters.links') }}:</strong><ul class="pl-4"><li v-for="(l, n) in brief.internal_links" :key="n">• {{ l.anchor }}<span v-if="l.reason" class="text-ink-muted"> — {{ l.reason }}</span></li></ul></div>
        <div v-if="brief.avoid_duplicating?.length" class="mt-2"><strong>{{ t('research.ideas.avoid') }}:</strong><ul class="pl-4"><li v-for="(a, n) in brief.avoid_duplicating" :key="n">• {{ a.title }}</li></ul></div>
        <div v-if="brief.competitor_references?.length" class="mt-2"><strong>{{ t('research.ideas.competitorRefs') }}:</strong><ul class="pl-4"><li v-for="(c, n) in brief.competitor_references" :key="n" class="break-all">• {{ c.title ?? c.url }} <span class="text-xs text-ink-muted">{{ c.url }}</span></li></ul></div>
        <div v-if="brief.rules?.length" class="mt-2"><strong>{{ t('research.ideas.briefRules') }}:</strong><ul class="pl-4"><li v-for="(r, n) in brief.rules" :key="n">• {{ r }}</li></ul></div>
      </section>
    </template>
    <template v-if="detail" #footer>
      <AppButton v-if="detail.brief" variant="secondary" @click="briefOpen = !briefOpen">{{ t('research.ideas.viewBrief') }}</AppButton>
      <template v-if="canOperate">
        <AppButton variant="ghost" @click="openEdit(detail)"><AppIcon name="edit" :size="14" /> {{ t('common.edit') }}</AppButton>
        <AppButton v-if="detail.status !== 'rejected'" :loading="briefing" data-testid="make-brief" @click="makeBrief(detail)">{{ t('research.ideas.brief') }}</AppButton>
      </template>
    </template>
  </Drawer>

  <AppModal :open="!!scheduleFor" :title="t('research.ideas.schedule')" @close="scheduleFor = null">
    <form id="schedule-form" class="space-y-4" @submit.prevent="schedule">
      <p class="text-sm font-medium">{{ scheduleFor?.title }}</p>
      <p v-if="scheduleForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ scheduleForm.formError.value }}</p>
      <AppInput v-model="scheduleDate" type="date" :label="t('research.calendar.date')" required :error="scheduleForm.errors.scheduled_for" />
      <p class="text-xs text-ink-muted">{{ t('research.ideas.noContentNote') }}</p>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="scheduleFor = null">{{ t('common.cancel') }}</AppButton>
      <AppButton type="submit" form="schedule-form" :loading="scheduleForm.submitting.value">{{ t('common.save') }}</AppButton>
    </template>
  </AppModal>

  <AppModal :open="!!editing" :title="t('research.ideas.editTitle')" @close="editing = null">
    <form id="idea-form" class="space-y-4" @submit.prevent="saveEdit">
      <p v-if="editForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ editForm.formError.value }}</p>
      <AppInput v-model="draft.title" :label="t('research.ideas.title')" required :error="editForm.errors.title" />
      <AppInput v-model="draft.angle" :label="t('research.ideas.angle')" :error="editForm.errors.angle" />
      <AppInput v-model="draft.audience" :label="t('research.ideas.audience')" :error="editForm.errors.audience" />
      <div class="grid gap-4 sm:grid-cols-3">
        <AppSelect v-model="draft.content_type" :label="t('research.keywords.type')" :options="opts.type.slice(1)" :error="editForm.errors.content_type" />
        <AppSelect v-model="draft.search_intent" :label="t('research.keywords.intent')" :options="opts.intent.slice(1)" :error="editForm.errors.search_intent" />
        <AppSelect v-model="draft.priority" :label="t('research.ideas.priorityLabel')" :options="opts.priority.slice(1)" :error="editForm.errors.priority" />
      </div>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="editing = null">{{ t('common.cancel') }}</AppButton>
      <AppButton type="submit" form="idea-form" :loading="editForm.submitting.value">{{ t('common.save') }}</AppButton>
    </template>
  </AppModal>
</template>
