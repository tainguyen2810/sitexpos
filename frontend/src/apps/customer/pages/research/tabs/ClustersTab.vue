<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { researchApi, scoreTone, type Campaign, type Cluster, type Gap, type LinkSuggestion } from '@/shared/api/research'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useCursorList } from '@/shared/composables/useCursorList'
import { useToast } from '@/shared/composables/useToast'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'

const props = defineProps<{ workspaceId: string; campaign: Campaign; canOperate: boolean }>()
const { t } = useI18n()
const toast = useToast()
const err = (e: unknown) => toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))

const clusters = useAsync(() => researchApi.clusters(props.workspaceId, props.campaign.id))
const selectedId = ref<string>('')
const cluster = shallowRef<Cluster | null>(null)
const loadingCluster = ref(false)
async function select(c: Cluster) {
  selectedId.value = c.id
  loadingCluster.value = true
  try {
    cluster.value = await researchApi.cluster(props.workspaceId, props.campaign.id, c.id)
  } catch (e) {
    err(e)
  } finally {
    loadingCluster.value = false
  }
  void gaps.reload()
  void loadLinks()
}
function clearSelection() {
  selectedId.value = ''
  cluster.value = null
  void gaps.reload()
  void loadLinks()
}

const gapType = ref('')
const gapTypes = ['missing_topic', 'competitor_topic', 'unanswered_question', 'cannibalization', 'overlap', 'striking_distance'] as const
const gapOptions = computed(() => [{ value: '', label: t('common.all') }, ...gapTypes.map((g) => ({ value: g, label: t(`research.clusters.gapType.${g}`) }))])
const gaps = useCursorList((cursor) => researchApi.gaps(props.workspaceId, props.campaign.id, { cursor, gap_type: gapType.value || undefined, cluster_id: selectedId.value || undefined }))
void gaps.reload()
function onGapType(v: string) {
  gapType.value = v
  void gaps.reload()
}
async function setGap(g: Gap, status: Gap['status']) {
  try {
    await researchApi.updateGap(props.workspaceId, props.campaign.id, g.id, status)
    gaps.items.value = gaps.items.value.filter((x) => x.id !== g.id)
  } catch (e) {
    err(e)
  }
}

const links = shallowRef<LinkSuggestion[]>([])
async function loadLinks() {
  try {
    links.value = await researchApi.links(props.workspaceId, props.campaign.id, { cluster_id: selectedId.value || undefined })
  } catch {
    links.value = []
  }
}
void loadLinks()

const endpoint = (e: LinkSuggestion['from']) => e.label || t(`research.clusters.linkType.${e.type}`)

async function archive(c: Cluster) {
  try {
    await researchApi.updateCluster(props.workspaceId, props.campaign.id, c.id, { status: 'archived' })
    clearSelection()
    await clusters.run()
  } catch (e) {
    err(e)
  }
}
const coverageTotal = (c: Cluster) => (c.coverage.covered ?? 0) + (c.coverage.partial ?? 0) + (c.coverage.missing ?? 0)
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-3">
    <AppCard :title="t('research.tabs.clusters')" :padded="false">
      <LoadingState v-if="clusters.loading.value" class="p-5" />
      <ErrorState v-else-if="clusters.error.value" :error="clusters.error.value" class="p-5" @retry="clusters.run" />
      <EmptyState v-else-if="!clusters.data.value?.length" icon="layers" :title="t('research.clusters.empty')" :description="t('research.ideas.emptyBody')" />
      <ul v-else class="divide-y divide-line">
        <li v-for="c in clusters.data.value" :key="c.id">
          <button type="button" class="w-full px-4 py-3 text-left hover:bg-surface-muted" :class="selectedId === c.id ? 'bg-brand-50' : ''" @click="select(c)">
            <div class="flex items-center gap-2">
              <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ c.name }}</span>
              <AppBadge :tone="scoreTone(c.opportunity_score)">{{ c.opportunity_score ?? '—' }}</AppBadge>
            </div>
            <p class="text-xs text-ink-muted">
              {{ t('research.clusters.keywords', { n: c.keywords_count }) }}<template v-if="c.ideas_count !== undefined"> · {{ t('research.clusters.ideas', { n: c.ideas_count }) }}</template>
              · {{ t(`research.intent.${c.intent}`) }}
            </p>
            <div v-if="coverageTotal(c)" class="mt-1.5 flex h-1.5 overflow-hidden rounded-full bg-surface-muted" :title="t('research.clusters.coverage', { covered: c.coverage.covered ?? 0, partial: c.coverage.partial ?? 0, missing: c.coverage.missing ?? 0 })">
              <span class="bg-success-600" :style="{ width: `${((c.coverage.covered ?? 0) / coverageTotal(c)) * 100}%` }" />
              <span class="bg-warning-600" :style="{ width: `${((c.coverage.partial ?? 0) / coverageTotal(c)) * 100}%` }" />
            </div>
          </button>
        </li>
      </ul>
    </AppCard>

    <div class="space-y-6 lg:col-span-2">
      <AppCard v-if="selectedId" :title="cluster?.name ?? ''" :subtitle="cluster?.description ?? undefined">
        <template #actions>
          <AppButton size="sm" variant="ghost" @click="clearSelection">{{ t('common.close') }}</AppButton>
          <AppButton v-if="canOperate && cluster" size="sm" variant="secondary" @click="archive(cluster)">{{ t('research.clusters.archive') }}</AppButton>
        </template>
        <LoadingState v-if="loadingCluster" :rows="3" />
        <template v-else-if="cluster">
          <p v-if="cluster.pillar_keyword" class="mb-3 text-sm"><span class="text-ink-muted">{{ t('research.clusters.pillar') }}:</span> <strong>{{ cluster.pillar_keyword.keyword }}</strong></p>
          <div class="grid gap-4 md:grid-cols-2">
            <div>
              <p class="mb-1 text-xs font-semibold uppercase text-ink-muted">{{ t('research.tabs.keywords') }}</p>
              <ul class="max-h-72 space-y-1 overflow-y-auto text-sm">
                <li v-for="k in cluster.keywords ?? []" :key="k.id" class="flex items-center gap-2">
                  <span class="min-w-0 flex-1 truncate">{{ k.keyword }}</span>
                  <span v-if="k.covered_by.length" class="text-xs text-success-600" :title="k.covered_by[0]?.title">✓</span>
                  <AppBadge :tone="scoreTone(k.opportunity.score)">{{ k.opportunity.score ?? '—' }}</AppBadge>
                </li>
              </ul>
            </div>
            <div>
              <p class="mb-1 text-xs font-semibold uppercase text-ink-muted">{{ t('research.tabs.ideas') }}</p>
              <ul class="max-h-72 space-y-1 overflow-y-auto text-sm">
                <li v-for="i in cluster.ideas ?? []" :key="i.id" class="flex items-center gap-2">
                  <AppBadge v-if="i.is_pillar" tone="brand">{{ t('research.ideas.pillar') }}</AppBadge>
                  <span class="min-w-0 flex-1 truncate" :title="i.title">{{ i.title }}</span>
                  <span class="text-xs text-ink-muted">{{ t(`research.ideas.status.${i.status}`) }}</span>
                </li>
              </ul>
            </div>
          </div>
        </template>
      </AppCard>

      <AppCard :title="t('research.clusters.gaps')" :padded="false">
        <template #actions>
          <FilterSelect :model-value="gapType" :label="t('research.keywords.type')" :options="gapOptions" @update:model-value="onGapType" />
        </template>
        <LoadingState v-if="gaps.loading.value" class="p-5" />
        <ErrorState v-else-if="gaps.error.value" :error="gaps.error.value" class="p-5" @retry="gaps.reload" />
        <p v-else-if="!gaps.items.value.length" class="p-5 text-sm text-ink-muted">{{ t('research.clusters.noGaps') }}</p>
        <ul v-else class="divide-y divide-line">
          <li v-for="g in gaps.items.value" :key="g.id" class="flex flex-wrap items-start gap-3 px-5 py-3">
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium">{{ g.topic }}</p>
              <p class="text-xs text-ink-muted"><AppBadge>{{ t(`research.clusters.gapType.${g.gap_type}`) }}</AppBadge> <span v-if="g.description">{{ g.description }}</span></p>
            </div>
            <AppBadge :tone="scoreTone(g.score)">{{ g.score ?? '—' }}</AppBadge>
            <template v-if="canOperate">
              <AppButton size="sm" variant="secondary" @click="setGap(g, 'planned')">{{ t('research.clusters.plan') }}</AppButton>
              <AppButton size="sm" variant="ghost" @click="setGap(g, 'dismissed')">{{ t('research.clusters.dismiss') }}</AppButton>
            </template>
          </li>
        </ul>
        <div v-if="gaps.next.value" class="border-t border-line p-3 text-center">
          <AppButton variant="ghost" size="sm" :loading="gaps.loadingMore.value" @click="gaps.more">{{ t('common.loadMore') }}</AppButton>
        </div>
      </AppCard>

      <AppCard :title="t('research.clusters.links')">
        <p v-if="!links.length" class="text-sm text-ink-muted">{{ t('research.clusters.noLinks') }}</p>
        <ul v-else class="space-y-2 text-sm">
          <li v-for="l in links.slice(0, 50)" :key="l.id" class="rounded-lg border border-line px-3 py-2">
            <p><span class="text-ink-muted">{{ endpoint(l.from) }}</span> → <strong>{{ endpoint(l.to) }}</strong></p>
            <p class="text-xs text-ink-muted">{{ t('research.clusters.anchor', { text: l.anchor_text }) }}<template v-if="l.reason"> · {{ l.reason }}</template></p>
          </li>
        </ul>
      </AppCard>
    </div>
  </div>
</template>
