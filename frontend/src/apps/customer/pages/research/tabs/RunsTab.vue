<script setup lang="ts">
import { ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { researchApi, runTone, formatUsd, isActiveRun, type Campaign, type ResearchRun, type Snapshot } from '@/shared/api/research'
import { ApiError } from '@/shared/api/client'
import { useCursorList } from '@/shared/composables/useCursorList'
import { useToast } from '@/shared/composables/useToast'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import Drawer from '@/shared/ui/Drawer.vue'

const props = defineProps<{ workspaceId: string; campaign: Campaign; canOperate: boolean }>()
const emit = defineEmits<{ retried: [run: ResearchRun] }>()
const { t, te } = useI18n()
const toast = useToast()

const list = useCursorList((cursor) => researchApi.runs(props.workspaceId, props.campaign.id, { cursor }))
void list.reload()

const detail = shallowRef<ResearchRun | null>(null)
const snapshots = shallowRef<Snapshot[] | null>(null)
const loadingDetail = ref(false)
async function open(run: ResearchRun) {
  detail.value = run
  snapshots.value = null
  loadingDetail.value = true
  try {
    detail.value = await researchApi.run(props.workspaceId, props.campaign.id, run.id)
    snapshots.value = await researchApi.snapshots(props.workspaceId, props.campaign.id, run.id)
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  } finally {
    loadingDetail.value = false
  }
}

const busy = ref(false)
async function retry(run: ResearchRun) {
  busy.value = true
  try {
    const updated = await researchApi.retryRun(props.workspaceId, props.campaign.id, run.id)
    detail.value = null
    emit('retried', updated)
    await list.reload()
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  } finally {
    busy.value = false
  }
}
async function cancel(run: ResearchRun) {
  busy.value = true
  try {
    detail.value = await researchApi.cancelRun(props.workspaceId, props.campaign.id, run.id)
    await list.reload()
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  } finally {
    busy.value = false
  }
}

function warningText(code: string): string {
  return te(`research.run.warning.${code}`) ? t(`research.run.warning.${code}`) : t('research.run.warning.other', { code })
}
function statLabel(key: string): string {
  return te(`research.run.stat.${key}`) ? t(`research.run.stat.${key}`) : key
}
const sourceTone = (s: string) => (({ ok: 'success', skipped: 'neutral', failed: 'danger', blocked: 'warning' }) as Record<string, 'success' | 'neutral' | 'danger' | 'warning'>)[s] ?? 'neutral'
</script>

<template>
  <AppCard :title="t('research.run.history')" :padded="false">
    <LoadingState v-if="list.loading.value" class="p-5" />
    <ErrorState v-else-if="list.error.value" :error="list.error.value" class="p-5" @retry="list.reload" />
    <EmptyState v-else-if="!list.items.value.length" icon="activity" :title="t('research.run.empty')" :description="t('research.run.emptyBody')" />
    <ul v-else class="divide-y divide-line">
      <li v-for="r in list.items.value" :key="r.id">
        <button type="button" class="flex w-full flex-wrap items-center gap-3 px-5 py-3 text-left hover:bg-surface-muted" @click="open(r)">
          <AppBadge :tone="runTone(r.status)">{{ t(`research.run.status.${r.status}`) }}</AppBadge>
          <span class="text-sm text-ink">{{ formatDateTime(r.started_at ?? r.created_at) }}</span>
          <span class="text-xs text-ink-muted">{{ t(`research.run.trigger.${r.trigger}`) }}</span>
          <span v-if="r.stats.ideas_created !== undefined" class="text-xs text-ink-muted">{{ statLabel('ideas_created') }}: {{ r.stats.ideas_created }}</span>
          <span v-if="r.warnings.length" class="text-xs text-warning-600">{{ t('research.run.warnings') }}: {{ r.warnings.length }}</span>
          <span class="ml-auto text-xs tabular-nums text-ink-muted">{{ formatUsd(r.cost_micro_usd) }}</span>
        </button>
      </li>
    </ul>
    <div v-if="list.next.value" class="border-t border-line p-3 text-center">
      <AppButton variant="ghost" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton>
    </div>
  </AppCard>

  <Drawer :open="!!detail" :title="detail ? formatDateTime(detail.started_at ?? detail.created_at) : ''" :subtitle="detail ? t(`research.run.status.${detail.status}`) : ''" @close="detail = null">
    <template v-if="detail">
      <p v-if="detail.error" class="mb-4 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600">{{ detail.error.message ?? detail.error.code }}</p>

      <section v-if="detail.warnings.length" class="mb-5">
        <h3 class="mb-2 text-sm font-semibold">{{ t('research.run.warnings') }}</h3>
        <ul class="space-y-1 text-sm text-warning-600">
          <li v-for="w in detail.warnings" :key="w">• {{ warningText(w) }}</li>
        </ul>
      </section>

      <section class="mb-5">
        <h3 class="mb-2 text-sm font-semibold">{{ t('research.run.stats') }}</h3>
        <dl class="grid grid-cols-2 gap-2 text-sm">
          <div v-for="(v, k) in detail.stats" :key="k" class="rounded-lg bg-surface-muted px-3 py-2">
            <dt class="text-xs text-ink-muted">{{ statLabel(String(k)) }}</dt>
            <dd class="font-semibold tabular-nums">{{ v }}</dd>
          </div>
          <div class="rounded-lg bg-surface-muted px-3 py-2">
            <dt class="text-xs text-ink-muted">{{ t('research.run.cost') }}</dt>
            <dd class="font-semibold tabular-nums">{{ formatUsd(detail.cost_micro_usd) }}</dd>
          </div>
        </dl>
      </section>

      <LoadingState v-if="loadingDetail" :rows="3" />
      <template v-else>
        <section v-if="detail.sources?.length" class="mb-5">
          <h3 class="mb-2 text-sm font-semibold">{{ t('research.run.sources') }}</h3>
          <ul class="divide-y divide-line rounded-lg border border-line text-sm">
            <li v-for="s in detail.sources" :key="s.id" class="px-3 py-2">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium">{{ s.label }}</span>
                <AppBadge :tone="sourceTone(s.status)">{{ t(`research.run.sourceStatus.${s.status}`) }}</AppBadge>
                <span v-if="s.robots_allowed === false" class="text-xs text-warning-600">{{ t('research.run.robotsBlocked') }}</span>
                <span class="ml-auto text-xs tabular-nums text-ink-muted">{{ s.items_count }}</span>
              </div>
              <p v-if="s.url" class="truncate text-xs text-ink-muted">{{ s.url }}</p>
              <p v-if="s.error" class="text-xs text-danger-600">{{ s.error.message ?? s.error.code }}</p>
              <p v-if="s.fetched_at" class="text-xs text-ink-subtle">{{ t('research.keywords.collected') }}: {{ formatDateTime(s.fetched_at) }}</p>
            </li>
          </ul>
        </section>

        <section v-if="detail.steps?.length" class="mb-5">
          <h3 class="mb-2 text-sm font-semibold">{{ t('research.run.steps') }}</h3>
          <ol class="space-y-1 text-sm">
            <li v-for="s in detail.steps" :key="s.key" class="flex items-center gap-2">
              <span class="w-40 truncate font-mono text-xs">{{ s.key }}</span>
              <AppBadge :tone="s.status === 'succeeded' ? 'success' : s.status === 'failed' ? 'danger' : 'neutral'">{{ s.status }}</AppBadge>
              <span v-if="s.attempts > 1" class="text-xs text-ink-muted">×{{ s.attempts }}</span>
              <span v-if="s.error" class="truncate text-xs text-danger-600">{{ s.error.code }}</span>
            </li>
          </ol>
        </section>

        <section v-if="snapshots?.length">
          <h3 class="mb-2 text-sm font-semibold">{{ t('research.run.snapshots') }} ({{ snapshots.length }})</h3>
          <ul class="space-y-2 text-sm">
            <li v-for="s in snapshots.slice(0, 50)" :key="s.id" class="rounded-lg border border-line px-3 py-2">
              <p class="font-medium">{{ s.title ?? s.url }}</p>
              <p class="truncate text-xs text-ink-muted">{{ s.url }} · {{ formatDateTime(s.fetched_at) }}</p>
              <p v-if="s.headings.length" class="mt-1 text-xs text-ink-muted">{{ s.headings.slice(0, 5).map((h) => h.text).join(' · ') }}</p>
            </li>
          </ul>
        </section>
      </template>
    </template>
    <template v-if="detail && canOperate" #footer>
      <AppButton v-if="isActiveRun(detail)" variant="secondary" :loading="busy" @click="cancel(detail)">{{ t('research.run.cancel') }}</AppButton>
      <AppButton v-else-if="detail.status === 'failed' || detail.status === 'partial' || detail.status === 'canceled'" :loading="busy" @click="retry(detail)">{{ t('research.run.retry') }}</AppButton>
    </template>
  </Drawer>
</template>
