<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ARTICLE_STATUSES, BUSY_STATUSES, CONTENT_TYPES, formatUsd, writerApi } from '@/shared/api/writer'
import { siteApi } from '@/shared/api/sites'
import { useAsync } from '@/shared/composables/useAsync'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import StatCard from '@/shared/ui/StatCard.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import SearchInput from '@/shared/ui/SearchInput.vue'
import StatusBadge from './components/StatusBadge.vue'
import WriterNav from './components/WriterNav.vue'
import { usePolling, useWriterPerms } from './useWriter'

const { t } = useI18n()
const { workspaceId: id, canEdit } = useWriterPerms()

const status = ref('')
const type = ref('')
const site = ref('')
const sort = ref('updated')
const q = ref('')
const list = useCursorList((cursor) =>
  writerApi.articles(id.value, { cursor, status: status.value, content_type: type.value, site_id: site.value, sort: sort.value === 'updated' ? undefined : sort.value, q: q.value }),
)
void list.reload()
const overview = useAsync(() => writerApi.overview(id.value))
const sites = useAsync(() => siteApi.list(id.value))

let debounce: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => void list.reload(), 300)
})
watch([status, type, site, sort], () => void list.reload())

// Keep progress fresh while anything on screen is still being worked on by AI.
const busy = computed(() => list.items.value.some((a) => BUSY_STATUSES.includes(a.status)))
usePolling(() => Promise.all([list.reload(), overview.run()]), busy, 6000)

const statusOptions = computed(() => [
  { value: '', label: t('common.all') },
  { value: 'active', label: t('writer.library.activeOnly') },
  ...ARTICLE_STATUSES.map((s) => ({ value: s, label: t(`writer.status.${s}`) })),
])
const typeOptions = computed(() => [{ value: '', label: t('common.all') }, ...CONTENT_TYPES.map((c) => ({ value: c, label: t(`writer.contentType.${c}`) }))])
const siteOptions = computed(() => [{ value: '', label: t('common.all') }, ...(sites.data.value ?? []).map((s) => ({ value: s.id, label: s.name }))])
const sortOptions = computed(() => (['updated', 'title', 'quality'] as const).map((s) => ({ value: s, label: t(`writer.library.sort.${s}`) })))
const siteName = (sid: string) => sites.data.value?.find((s) => s.id === sid)?.name ?? ''
</script>

<template>
  <div>
    <PageHeader :title="t('writer.title')" :description="t('writer.subtitle')">
      <template #actions>
        <RouterLink v-if="canEdit" :to="{ name: 'writer-new', params: { workspaceId: id } }">
          <AppButton data-testid="new-article"><AppIcon name="plus" :size="16" /> {{ t('writer.nav.wizard') }}</AppButton>
        </RouterLink>
      </template>
    </PageHeader>
    <WriterNav active="library" />

    <div v-if="overview.data.value" class=" ">
      <div class="rounded-xl bg-surface shadow-xs ring-1 ring-line p-4">
        <p class="text-sm text-ink-muted">{{ t('writer.overview.quota') }}</p>
        <p class="mt-2 text-2xl font-semibold tabular-nums">{{ overview.data.value.quota.used }}<span class="text-base text-ink-muted">/{{ overview.data.value.quota.limit }}</span></p>
        <ProgressBar class="mt-2" tone="auto" :value="overview.data.value.quota.used" :max="overview.data.value.quota.limit" />
      </div>
      <StatCard :label="t('writer.overview.cost')" :value="formatUsd(overview.data.value.cost_this_month_micro_usd)" icon="coins" />
      <StatCard :label="t('writer.overview.active')" :value="overview.data.value.active_workflows" icon="activity" />
      <StatCard :label="t('writer.overview.needsReview')" :value="overview.data.value.needs_review" icon="shieldCheck" />
    </div>

    <AppCard :padded="false">
      <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
        <SearchInput v-model="q" :placeholder="t('writer.library.searchPlaceholder')" />
        <FilterSelect v-model="status" :label="t('common.status')" :options="statusOptions" />
        <FilterSelect v-model="type" :label="t('writer.article.type')" :options="typeOptions" />
        <FilterSelect v-if="(sites.data.value?.length ?? 0) > 1" v-model="site" :label="t('nav.sites')" :options="siteOptions" />
        <FilterSelect v-model="sort" :label="t('writer.library.sort.updated')" :options="sortOptions" class="ml-auto" />
      </div>
      <LoadingState v-if="list.loading.value && !list.loaded.value" class="p-5" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" class="p-5" @retry="list.reload" />
      <EmptyState v-else-if="!list.items.value.length" icon="pen" :title="t('writer.library.empty')" :description="t('writer.library.emptyBody')" />
      <ul v-else class="divide-y divide-line" data-testid="article-list">
        <li v-for="a in list.items.value" :key="a.id">
          <RouterLink :to="{ name: 'writer-article', params: { workspaceId: id, articleId: a.id } }" class="flex flex-wrap items-center gap-3 px-5 py-4 hover:bg-surface-muted">
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-ink">
                {{ a.title }} <AppBadge v-if="a.is_demo" tone="warning">{{ t('writer.demo') }}</AppBadge>
              </p>
              <p class="truncate text-xs text-ink-muted">
                {{ t(`writer.contentType.${a.content_type}`) }} · {{ a.primary_keyword }}<template v-if="siteName(a.site_id)"> · {{ siteName(a.site_id) }}</template>
              </p>
              <p v-if="a.unresolved_critical || a.unresolved_high" class="mt-1 text-xs" :class="a.unresolved_critical ? 'text-danger-600' : 'text-warning-600'">
                {{ t('writer.library.unresolved', { critical: a.unresolved_critical, high: a.unresolved_high }) }}
              </p>
            </div>
            <div class="hidden text-right text-xs text-ink-muted md:block">
              <p v-if="a.quality_score !== null">{{ t('writer.library.quality') }}: <span class="font-medium text-ink">{{ a.quality_score }}</span></p>
              <p>{{ t('writer.library.spent', { spent: formatUsd(a.budget.spent_micro_usd), limit: formatUsd(a.budget.limit_micro_usd) }) }}</p>
            </div>
            <p class="hidden text-xs text-ink-muted sm:block">{{ formatDateTime(a.updated_at) }}</p>
            <StatusBadge :status="a.status" />
          </RouterLink>
        </li>
      </ul>
      <div v-if="list.next.value" class="border-t border-line p-3 text-center">
        <AppButton variant="ghost" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton>
      </div>
    </AppCard>
  </div>
</template>
