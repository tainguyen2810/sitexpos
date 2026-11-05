<script setup lang="ts">
import { ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatBytes, mediaApi, microUsd, type MediaSourceRow, type MediaUsage, type ImageJob } from '@/shared/api/media'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import StatCard from '@/shared/ui/StatCard.vue'
import SectionNav from './SectionNav.vue'
import { usePhase6Errors, usePhase6Perms, useSites } from './usePhase6'

const { t } = useI18n()
const { workspaceId, canEdit } = usePhase6Perms()
const { toastError, toast } = usePhase6Errors()
const sites = useSites({ required: true })
const usage = shallowRef<MediaUsage | null>(null)
const sources = shallowRef<MediaSourceRow[]>([])
const recent = shallowRef<ImageJob[]>([])
const loading = ref(true)
const error = shallowRef<unknown>(null)
const syncing = ref(false)

async function load() {
  loading.value = true
  error.value = null
  try {
    const [u, s, g] = await Promise.all([
      mediaApi.usage(workspaceId.value),
      sites.siteId.value ? mediaApi.sources(workspaceId.value, sites.siteId.value) : Promise.resolve([]),
      mediaApi.generations(workspaceId.value, { cursor: null }),
    ])
    usage.value = u
    sources.value = s
    recent.value = g.data.slice(0, 6)
  } catch (e) {
    error.value = e
  } finally {
    loading.value = false
  }
}
watch(() => sites.siteId.value, () => void load(), { immediate: true })

async function sync() {
  if (!sites.siteId.value) return
  syncing.value = true
  try {
    await mediaApi.sync(workspaceId.value, sites.siteId.value)
    toast.success(t('p6.media.studio.syncQueued'))
    await load()
  } catch (e) {
    toastError(e)
  } finally {
    syncing.value = false
  }
}
const sourceTone = (s: MediaSourceRow) => (!s.available ? 'neutral' : s.status === 'failed' ? 'danger' : s.status === 'syncing' ? 'brand' : 'success')
</script>

<template>
  <div>
    <PageHeader :title="t('p6.media.title')" :description="t('p6.media.subtitle')" />
    <SectionNav section="media" active="studio" />
    <LoadingState v-if="loading && !usage" />
    <ErrorState v-else-if="error" :error="error" @retry="load" />
    <template v-else-if="usage">
      <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard :label="t('p6.media.studio.assets')" :value="usage.assets" icon="image" />
        <StatCard :label="t('p6.media.studio.storage')" :value="formatBytes(usage.stored_bytes)" icon="server" :hint="t('p6.media.studio.ofLimit', { limit: formatBytes(usage.limits.max_storage_bytes) })" />
        <StatCard :label="t('p6.media.studio.aiToday')" :value="`${usage.ai_images_today} / ${usage.limits.max_ai_images_per_day}`" icon="sparkles" />
        <StatCard :label="t('p6.media.studio.aiEnabled')" :value="usage.limits.ai_images_enabled ? t('common.yes') : t('common.no')" icon="zap" />
      </div>
      <AppCard :title="t('p6.media.studio.sources')" :subtitle="t('p6.media.studio.sourcesHint')" class="mb-6" :padded="false">
        <template #actions>
          <FilterSelect v-model="sites.siteId.value" :label="t('p6.common.site')" :options="sites.options.value" />
          <AppButton v-if="canEdit" size="sm" variant="secondary" :loading="syncing" :disabled="!sites.siteId.value" data-testid="sync-media" @click="sync">{{ t('p6.media.studio.sync') }}</AppButton>
        </template>
        <EmptyState v-if="!sources.length" icon="globe" :title="t('p6.common.noSite')" />
        <ul v-else class="divide-y divide-line">
          <li v-for="s in sources" :key="s.type" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
            <div>
              <p class="font-medium">{{ t(`p6.media.source.${s.type}`) }}</p>
              <p class="text-xs text-ink-muted">{{ t(`p6.media.sourceHint.${s.type}`) }}</p>
            </div>
            <div class="flex items-center gap-3">
              <span class="tabular-nums text-ink-muted">{{ s.items_count }}</span>
              <AppBadge :tone="sourceTone(s)">{{ s.available ? t(`p6.media.sourceStatus.${s.status}`) : t('p6.media.sourceStatus.unavailable') }}</AppBadge>
              <span v-if="s.last_synced_at" class="text-xs text-ink-muted">{{ formatDateTime(s.last_synced_at) }}</span>
            </div>
          </li>
        </ul>
      </AppCard>
      <AppCard :title="t('p6.media.studio.recent')" :padded="false">
        <template #actions>
          <RouterLink :to="{ name: 'media-generate', params: { workspaceId } }" class="text-sm text-brand-600 underline">{{ t('p6.media.nav.generator') }}</RouterLink>
        </template>
        <EmptyState v-if="!recent.length" icon="sparkles" :title="t('p6.media.gen.empty')" />
        <ul v-else class="divide-y divide-line">
          <li v-for="j in recent" :key="j.id" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
            <span>{{ t(`p6.media.imageType.${j.image_type}`) }} · {{ j.aspect_ratio }} · {{ j.model ?? '—' }}</span>
            <span class="flex items-center gap-2"><span class="text-xs text-ink-muted">{{ microUsd(j.cost_micro_usd) }}</span><AppBadge :tone="j.status === 'succeeded' ? 'success' : j.status === 'failed' || j.status === 'blocked' ? 'danger' : 'brand'">{{ t(`p6.media.jobStatus.${j.status}`) }}</AppBadge></span>
          </li>
        </ul>
      </AppCard>
    </template>
  </div>
</template>
