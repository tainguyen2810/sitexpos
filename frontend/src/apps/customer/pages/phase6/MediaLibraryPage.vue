<script setup lang="ts">
import { computed, reactive, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatBytes, mediaApi, mediaStatusTone, type MediaAsset } from '@/shared/api/media'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
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
import SearchInput from '@/shared/ui/SearchInput.vue'
import { usePolling } from '../writer/useWriter'
import SectionNav from './SectionNav.vue'
import { usePhase6Errors, usePhase6Perms, useSites } from './usePhase6'

const { t } = useI18n()
const { workspaceId, canEdit } = usePhase6Perms()
const { toastError, toast } = usePhase6Errors()
const sites = useSites()
const source = ref('')
const status = ref('')
const q = ref('')
const list = useCursorList((cursor) => mediaApi.list(workspaceId.value, { cursor, site_id: sites.siteId.value || undefined, source_type: source.value || undefined, status: status.value || undefined, q: q.value || undefined }))
void list.reload()
let timer: ReturnType<typeof setTimeout> | null = null
watch([() => sites.siteId.value, source, status], () => void list.reload())
watch(q, () => { if (timer) clearTimeout(timer); timer = setTimeout(() => void list.reload(), 300) })
const processing = computed(() => list.items.value.some((a) => a.status === 'processing'))
usePolling(() => list.reload(), processing, 4000)

const sourceOpts = computed(() => [{ value: '', label: t('p6.media.allSources') }, ...['upload', 'wordpress', 'woocommerce', 'ai', 'infographic'].map((s) => ({ value: s, label: t(`p6.media.source.${s}`) }))])
const statusOpts = computed(() => [{ value: '', label: t('common.all') }, ...['ready', 'processing', 'rejected', 'failed'].map((s) => ({ value: s, label: t(`p6.media.status.${s}`) }))])
const siteOpts = computed(() => [{ value: '', label: t('p6.common.allSites') }, ...sites.options.value])
const licenseOpts = computed(() => ['owned', 'customer_provided', 'licensed_stock'].map((l) => ({ value: l, label: t(`p6.media.license.${l}`) })))

// Upload
const uploadOpen = ref(false)
const uploading = ref(false)
const file = shallowRef<File | null>(null)
const up = reactive({ site_id: '', title: '', alt_text: '', caption: '', license: 'owned', attribution: '' })
function onFile(e: Event) {
  file.value = (e.target as HTMLInputElement).files?.[0] ?? null
}
async function upload() {
  if (!file.value) return
  uploading.value = true
  try {
    await mediaApi.upload(workspaceId.value, file.value, { ...up, site_id: up.site_id || undefined })
    toast.success(t('p6.media.lib.uploaded'))
    uploadOpen.value = false
    file.value = null
    Object.assign(up, { title: '', alt_text: '', caption: '', attribution: '' })
    await list.reload()
  } catch (e) {
    toastError(e)
  } finally {
    uploading.value = false
  }
}

// Detail
const selected = shallowRef<MediaAsset | null>(null)
const edit = reactive({ title: '', alt_text: '', caption: '', attribution: '' })
const saving = ref(false)
const confirmDelete = ref(false)
async function open(a: MediaAsset) {
  try {
    selected.value = await mediaApi.get(workspaceId.value, a.id)
    Object.assign(edit, { title: selected.value.title ?? '', alt_text: selected.value.alt_text ?? '', caption: selected.value.caption ?? '', attribution: selected.value.attribution ?? '' })
  } catch (e) {
    toastError(e)
  }
}
async function save() {
  if (!selected.value) return
  saving.value = true
  try {
    selected.value = await mediaApi.update(workspaceId.value, selected.value.id, { ...edit })
    toast.success(t('common.saved'))
    await list.reload()
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
async function crop(ratio: string) {
  if (!selected.value) return
  try {
    await mediaApi.derive(workspaceId.value, selected.value.id, { operation: 'crop', ratio })
    toast.success(t('p6.media.lib.derived'))
    await list.reload()
  } catch (e) {
    toastError(e)
  }
}
async function remove() {
  if (!selected.value) return
  try {
    await mediaApi.remove(workspaceId.value, selected.value.id)
    confirmDelete.value = false
    selected.value = null
    await list.reload()
  } catch (e) {
    toastError(e)
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('p6.media.lib.title')" :description="t('p6.media.lib.subtitle')" />
    <SectionNav section="media" active="library" />
    <AppCard :padded="false">
      <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
        <SearchInput v-model="q" :placeholder="t('p6.media.lib.search')" />
        <FilterSelect v-model="sites.siteId.value" :label="t('p6.common.site')" :options="siteOpts" />
        <FilterSelect v-model="source" :label="t('p6.media.sourceLabel')" :options="sourceOpts" />
        <FilterSelect v-model="status" :label="t('common.status')" :options="statusOpts" />
        <AppButton v-if="canEdit" size="sm" class="ml-auto" data-testid="open-upload" @click="uploadOpen = true">{{ t('p6.media.lib.upload') }}</AppButton>
      </div>
      <LoadingState v-if="list.loading.value && !list.loaded.value" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload" />
      <EmptyState v-else-if="!list.items.value.length" icon="image" :title="t('p6.media.lib.empty')" :description="t('p6.media.lib.emptyHint')" />
      <ul v-else class=" " data-testid="media-grid">
        <li v-for="a in list.items.value" :key="a.id">
          <button type="button" class="block w-full text-left" @click="open(a)">
            <div class="relative aspect-[4/3] overflow-hidden rounded-lg border border-line bg-surface-muted">
              <img v-if="a.thumbnail_url && a.status === 'ready'" :src="a.thumbnail_url" :alt="a.alt_text ?? ''" class="h-full w-full object-cover" loading="lazy" />
              <span v-if="a.is_ai_generated" class="absolute left-1 top-1 rounded bg-black/70 px-1 text-[10px] text-white">{{ t('p6.media.aiLabel') }}</span>
            </div>
            <p class="mt-1 truncate text-sm font-medium">{{ a.title ?? a.original_filename ?? a.id }}</p>
            <p class="flex items-center gap-1 text-xs text-ink-muted">
              <AppBadge :tone="mediaStatusTone(a.status)">{{ t(`p6.media.status.${a.status}`) }}</AppBadge>
              <span>{{ t(`p6.media.source.${a.source_type}`) }}</span>
            </p>
            <p v-if="a.status === 'ready' && !a.alt_text" class="text-xs text-warning-600">{{ t('p6.media.lib.noAlt') }}</p>
          </button>
        </li>
      </ul>
      <div v-if="list.next.value" class="border-t border-line p-3 text-center">
        <AppButton variant="ghost" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton>
      </div>
    </AppCard>

    <AppModal :open="uploadOpen" :title="t('p6.media.lib.upload')" @close="uploadOpen = false">
      <div class="space-y-3">
        <input type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/avif" data-testid="upload-file" @change="onFile" />
        <p class="text-xs text-ink-muted">{{ t('p6.media.lib.uploadHint') }}</p>
        <AppSelect v-model="up.site_id" :label="t('p6.common.site')" :options="siteOpts" />
        <AppSelect v-model="up.license" :label="t('p6.media.lib.license')" :options="licenseOpts" />
        <AppInput v-if="up.license === 'licensed_stock'" v-model="up.attribution" :label="t('p6.media.lib.attribution')" required />
        <AppInput v-model="up.title" :label="t('p6.media.lib.titleField')" />
        <AppInput v-model="up.alt_text" :label="t('p6.media.lib.alt')" :hint="t('p6.media.lib.altHint')" />
        <AppInput v-model="up.caption" :label="t('p6.media.lib.caption')" />
      </div>
      <template #footer>
        <AppButton variant="secondary" @click="uploadOpen = false">{{ t('common.cancel') }}</AppButton>
        <AppButton :loading="uploading" :disabled="!file" data-testid="do-upload" @click="upload">{{ t('p6.media.lib.upload') }}</AppButton>
      </template>
    </AppModal>

    <Drawer :open="!!selected" :title="selected?.title ?? t('p6.media.lib.detail')" @close="selected = null">
      <div v-if="selected" class="space-y-4 text-sm">
        <img v-if="selected.preview_url && selected.status === 'ready'" :src="selected.preview_url" :alt="selected.alt_text ?? ''" class="w-full rounded-lg border border-line" />
        <p v-if="selected.is_ai_generated" class="rounded bg-warning-50 px-3 py-2 text-xs text-amber-900">{{ t('p6.media.lib.aiNotice') }}</p>
        <p v-if="selected.error_code" class="rounded bg-danger-50 px-3 py-2 text-xs text-danger-600">{{ t('p6.media.lib.rejected') }}: {{ selected.error_code }}</p>
        <dl class="">
          <dt class="text-ink-muted">{{ t('p6.media.sourceLabel') }}</dt><dd>{{ t(`p6.media.source.${selected.source_type}`) }}</dd>
          <dt class="text-ink-muted">{{ t('p6.media.lib.license') }}</dt><dd>{{ t(`p6.media.license.${selected.license}`) }}</dd>
          <dt class="text-ink-muted">{{ t('p6.media.lib.size') }}</dt><dd>{{ selected.width ?? '?' }}×{{ selected.height ?? '?' }} · {{ formatBytes(selected.bytes) }}</dd>
          <dt class="text-ink-muted">MIME</dt><dd>{{ selected.mime ?? '—' }}</dd>
          <dt class="text-ink-muted">{{ t('p6.media.lib.usage') }}</dt><dd>{{ selected.usage_count ?? 0 }}</dd>
          <dt class="text-ink-muted">{{ t('common.createdAt') }}</dt><dd>{{ formatDateTime(selected.created_at) }}</dd>
          <template v-if="selected.ai_model"><dt class="text-ink-muted">{{ t('p6.media.lib.model') }}</dt><dd>{{ selected.ai_provider }} / {{ selected.ai_model }}</dd></template>
        </dl>
        <div v-if="selected.versions?.length">
          <p class="mb-1 text-xs font-medium">{{ t('p6.media.lib.versions') }}</p>
          <ul class="space-y-0.5 text-xs text-ink-muted">
            <li v-for="v in selected.versions" :key="v.kind">{{ v.kind }} · {{ v.mime }} · {{ v.width }}×{{ v.height }} · {{ formatBytes(v.bytes) }}</li>
          </ul>
        </div>
        <template v-if="canEdit">
          <AppInput v-model="edit.title" :label="t('p6.media.lib.titleField')" />
          <AppInput v-model="edit.alt_text" :label="t('p6.media.lib.alt')" :hint="t('p6.media.lib.altHint')" />
          <AppInput v-model="edit.caption" :label="t('p6.media.lib.caption')" />
          <AppInput v-model="edit.attribution" :label="t('p6.media.lib.attribution')" />
          <div class="flex flex-wrap gap-2">
            <span class="text-xs text-ink-muted">{{ t('p6.media.lib.crop') }}</span>
            <AppButton v-for="r in ['16:9', '1:1', '4:3']" :key="r" size="sm" variant="ghost" :disabled="selected.status !== 'ready'" @click="crop(r)">{{ r }}</AppButton>
          </div>
        </template>
      </div>
      <template v-if="canEdit && selected" #footer>
        <AppButton variant="danger" @click="confirmDelete = true">{{ t('common.delete') }}</AppButton>
        <AppButton :loading="saving" @click="save">{{ t('common.save') }}</AppButton>
      </template>
    </Drawer>
    <ConfirmDialog :open="confirmDelete" :title="t('p6.media.lib.deleteTitle')" :message="t('p6.media.lib.deleteBody')" danger @close="confirmDelete = false" @confirm="remove" />
  </div>
</template>
