<script setup lang="ts">
import { computed, ref, shallowRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { publishingApi, resolutionAvailable, RESOLUTIONS, type Conflict, type Resolution } from '@/shared/api/publishing'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import SectionNav from './SectionNav.vue'
import { usePhase6Errors, usePhase6Perms } from './usePhase6'

const { t } = useI18n()
const route = useRoute()
const { workspaceId, canPublish } = usePhase6Perms()
const { toastError, toast } = usePhase6Errors()
const status = ref('open')
const list = useCursorList((cursor) => publishingApi.conflicts(workspaceId.value, { cursor, status: status.value || undefined }))
watch(status, () => void list.reload(), { immediate: true })
const statusOpts = computed(() => [{ value: 'open', label: t('p6.pub.conflicts.statusOpen') }, { value: 'resolved', label: t('p6.pub.conflicts.statusResolved') }, { value: '', label: t('common.all') }])

const current = shallowRef<Conflict | null>(null)
const resolution = ref<Resolution | ''>('')
const note = ref('')
const confirmed = ref(false)
const saving = ref(false)
async function open(id: string) {
  try {
    current.value = await publishingApi.conflict(workspaceId.value, id)
    resolution.value = ''
    note.value = ''
    confirmed.value = false
  } catch (e) {
    toastError(e)
  }
}
if (typeof route.query.id === 'string') void open(route.query.id)
async function resolve() {
  if (!current.value || !resolution.value) return
  saving.value = true
  try {
    await publishingApi.resolve(workspaceId.value, current.value.id, resolution.value, note.value)
    toast.success(t('p6.pub.conflicts.resolved'))
    current.value = null
    await list.reload()
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
const diffRows = computed(() => {
  const c = current.value
  if (!c) return []
  const keys = ['title', 'slug', 'status', 'content_hash', 'seo_hash', 'revision_id', 'modified_gmt', 'url', 'site_host']
  return keys.map((k) => ({ key: k, wp: (c.wp_state ?? {})[k], expected: (c.expected_state ?? {})[k] })).filter((r) => r.wp !== undefined || r.expected !== undefined)
})
const show = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : String(v))
</script>

<template>
  <div>
    <PageHeader :title="t('p6.pub.conflicts.title')" :description="t('p6.pub.conflicts.subtitle')" />
    <SectionNav section="publishing" active="conflicts" />
    <AppCard :padded="false">
      <div class="flex gap-2 border-b border-line px-5 py-3"><FilterSelect v-model="status" :label="t('common.status')" :options="statusOpts" /></div>
      <LoadingState v-if="list.loading.value && !list.loaded.value" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload" />
      <EmptyState v-else-if="!list.items.value.length" icon="check" :title="t('p6.pub.conflicts.empty')" />
      <ul v-else class="divide-y divide-line" data-testid="conflicts">
        <li v-for="c in list.items.value" :key="c.id">
          <button type="button" class="flex w-full flex-wrap items-center gap-2 px-5 py-3 text-left text-sm hover:bg-surface-muted" @click="open(c.id)">
            <AppBadge :tone="c.status === 'open' ? 'danger' : 'neutral'">{{ c.status === 'open' ? t('p6.pub.conflicts.statusOpen') : t(`p6.pub.resolution.${c.resolution}`) }}</AppBadge>
            <span class="min-w-0 flex-1 truncate font-medium">{{ c.article_title ?? c.article_id }}</span>
            <span class="text-xs text-ink-muted">{{ c.types.map((x) => t(`p6.pub.conflictType.${x}`)).join(', ') }} · {{ formatDateTime(c.created_at) }}</span>
          </button>
        </li>
      </ul>
      <div v-if="list.next.value" class="border-t border-line p-3 text-center"><AppButton variant="ghost" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton></div>
    </AppCard>

    <AppModal :open="!!current" :title="current?.article_title ?? t('p6.pub.conflicts.title')" width="lg" @close="current = null">
      <div v-if="current" class="space-y-4 text-sm">
        <p class="rounded bg-warning-50 px-3 py-2 text-xs text-amber-900">{{ t('p6.pub.conflicts.never') }}</p>
        <ul class="list-disc pl-5 text-xs">
          <li v-for="x in current.types" :key="x"><strong>{{ t(`p6.pub.conflictType.${x}`) }}</strong> — {{ t(`p6.pub.conflictHint.${x}`) }}</li>
        </ul>
        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="text-ink-muted"><tr><th class="py-1 text-left" /><th class="text-left">{{ t('p6.pub.conflicts.wp') }}</th><th class="text-left">{{ t('p6.pub.conflicts.expected') }}</th></tr></thead>
            <tbody class="divide-y divide-line">
              <tr v-for="r in diffRows" :key="r.key" :class="show(r.wp) !== show(r.expected) ? 'bg-warning-50' : ''">
                <td class="py-1 pr-2 font-medium">{{ r.key }}</td><td class="max-w-[14rem] truncate pr-2" :title="show(r.wp)">{{ show(r.wp) }}</td><td class="max-w-[14rem] truncate" :title="show(r.expected)">{{ show(r.expected) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <template v-if="current.status === 'open' && canPublish">
          <fieldset class="space-y-1">
            <legend class="mb-1 font-medium">{{ t('p6.pub.conflicts.choose') }}</legend>
            <label v-for="r in RESOLUTIONS" :key="r" class="flex items-start gap-2" :class="resolutionAvailable(r, current.types) ? '' : 'opacity-40'">
              <input v-model="resolution" type="radio" :value="r" :disabled="!resolutionAvailable(r, current.types)" class="mt-1" />
              <span><strong>{{ t(`p6.pub.resolution.${r}`) }}</strong><br /><span class="text-xs text-ink-muted">{{ t(`p6.pub.resolutionHint.${r}`) }}</span></span>
            </label>
          </fieldset>
          <textarea v-model="note" rows="2" :placeholder="t('p6.pub.conflicts.note')" class="w-full rounded border border-line p-2 text-sm" />
          <label class="flex items-center gap-2 text-xs"><input v-model="confirmed" type="checkbox" data-testid="confirm-resolution" />{{ t('p6.pub.conflicts.confirm') }}</label>
        </template>
        <p v-else-if="current.status !== 'open'" class="text-xs text-ink-muted">{{ t(`p6.pub.resolution.${current.resolution}`) }} · {{ formatDateTime(current.resolved_at) }}</p>
      </div>
      <template v-if="current?.status === 'open' && canPublish" #footer>
        <AppButton variant="secondary" @click="current = null">{{ t('common.cancel') }}</AppButton>
        <AppButton :variant="resolution === 'overwrite' ? 'danger' : 'primary'" :loading="saving" :disabled="!resolution || !confirmed" data-testid="resolve-conflict" @click="resolve">{{ t('p6.pub.conflicts.apply') }}</AppButton>
      </template>
    </AppModal>
  </div>
</template>
