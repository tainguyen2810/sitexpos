<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { researchApi, type Campaign } from '@/shared/api/research'
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
import { useWorkspaceStore } from '../../stores/workspace'
import CampaignFormModal from './CampaignFormModal.vue'

const { t } = useI18n()
const router = useRouter()
const ws = useWorkspaceStore()
const id = ws.current!.id
const canCreate = computed(() => ws.can('content.edit') && !ws.current?.impersonating)

const status = ref('')
const list = useCursorList((cursor) => researchApi.campaigns(id, { cursor, status: status.value, sort: 'last_run' }))
void list.reload()
const sites = useAsync(() => siteApi.list(id))
const usage = useAsync(() => researchApi.usage(id))
const formOpen = ref(false)

const statusOptions = computed(() => [{ value: '', label: t('common.all') }, ...(['active', 'paused', 'archived'] as const).map((s) => ({ value: s, label: t(`research.campaigns.status.${s}`) }))])
function onStatus(v: string) {
  status.value = v
  void list.reload()
}
function onSaved(c: Campaign) {
  formOpen.value = false
  void router.push({ name: 'research-campaign', params: { workspaceId: id, campaignId: c.id } })
}
const statusTone = (s: Campaign['status']) => (({ active: 'success', paused: 'warning', archived: 'neutral' }) as const)[s]
</script>

<template>
  <div>
    <PageHeader :title="t('research.title')" :description="t('research.subtitle')">
      <template #actions>
        <AppButton v-if="canCreate" :disabled="!sites.data.value?.length" data-testid="new-campaign" @click="formOpen = true">
          <AppIcon name="plus" :size="16" /> {{ t('research.campaigns.new') }}
        </AppButton>
      </template>
    </PageHeader>

    <div v-if="usage.data.value" class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard :label="t('research.campaigns.title')" :value="`${usage.data.value.campaigns}/${usage.data.value.limits.max_campaigns}`" icon="target" />
      <div class="rounded-xl border border-line bg-surface p-4 shadow-sm">
        <p class="text-sm text-ink-muted">{{ t('research.run.history') }}</p>
        <p class="mt-2 text-2xl font-semibold tabular-nums">{{ usage.data.value.usage.runs.used }}<span class="text-base text-ink-muted">/{{ usage.data.value.usage.runs.limit }}</span></p>
        <ProgressBar class="mt-2" tone="auto" :value="usage.data.value.usage.runs.used + usage.data.value.usage.runs.reserved" :max="usage.data.value.usage.runs.limit ?? 0" />
      </div>
      <StatCard :label="t('research.overview.ideas')" :value="usage.data.value.usage.ideas.used" icon="sparkles" />
      <div class="col-span-2 rounded-xl border border-line bg-surface p-4 shadow-sm lg:col-span-1">
        <p class="text-sm text-ink-muted">{{ t('research.keywords.source') }}</p>
        <ul class="mt-2 space-y-1 text-xs">
          <li v-for="s in usage.data.value.sources" :key="s.source" class="flex items-center justify-between gap-2">
            <span class="truncate">{{ s.label }}</span>
            <AppBadge :tone="s.enabled && s.configured ? 'success' : 'neutral'">{{ s.enabled && s.configured ? '✓' : t('research.noData') }}</AppBadge>
          </li>
        </ul>
      </div>
    </div>

    <p v-if="sites.data.value && !sites.data.value.length" class="mb-4 rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-600">
      {{ t('research.campaigns.needSite') }}
      <RouterLink :to="{ name: 'sites', params: { workspaceId: id } }" class="font-medium underline">{{ t('nav.sites') }}</RouterLink>
    </p>

    <AppCard :padded="false">
      <div class="flex items-center gap-2 border-b border-line px-5 py-3">
        <FilterSelect :model-value="status" :label="t('common.status')" :options="statusOptions" @update:model-value="onStatus" />
      </div>
      <LoadingState v-if="list.loading.value" class="p-5" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" class="p-5" @retry="list.reload" />
      <EmptyState v-else-if="!list.items.value.length" icon="target" :title="t('research.campaigns.empty')" :description="t('research.campaigns.emptyBody')" />
      <ul v-else class="divide-y divide-line">
        <li v-for="c in list.items.value" :key="c.id">
          <RouterLink :to="{ name: 'research-campaign', params: { workspaceId: id, campaignId: c.id } }" class="flex flex-wrap items-center gap-3 px-5 py-4 hover:bg-surface-muted">
            <span class="rounded-lg bg-brand-50 p-2 text-brand-600"><AppIcon name="target" :size="18" /></span>
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-ink">
                {{ c.name }} <AppBadge v-if="c.is_demo" tone="warning">DEMO</AppBadge>
              </p>
              <p class="truncate text-xs text-ink-muted">{{ c.industry }} · {{ c.market }} · {{ c.language.toUpperCase() }}</p>
            </div>
            <p v-if="c.counts" class="hidden text-xs text-ink-muted md:block">{{ t('research.campaigns.counts', c.counts) }}</p>
            <p class="hidden text-xs text-ink-muted sm:block">{{ t('research.campaigns.lastRun') }}: {{ c.last_run_at ? formatDateTime(c.last_run_at) : t('research.campaigns.never') }}</p>
            <AppBadge :tone="statusTone(c.status)">{{ t(`research.campaigns.status.${c.status}`) }}</AppBadge>
          </RouterLink>
        </li>
      </ul>
      <div v-if="list.next.value" class="border-t border-line p-3 text-center">
        <AppButton variant="ghost" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton>
      </div>
    </AppCard>

    <CampaignFormModal :open="formOpen" :workspace-id="id" :sites="sites.data.value ?? []" @close="formOpen = false" @saved="onSaved" />
  </div>
</template>
