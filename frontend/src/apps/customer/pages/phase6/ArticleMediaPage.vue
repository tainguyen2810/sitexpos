<script setup lang="ts">
import { computed, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { mediaApi, placementAllowsAsset, placementReady, PLACEMENT_SLOTS, type MediaAsset, type Placement, type PlacementSlot, type PlacementView } from '@/shared/api/media'
import { seoApi, type SeoArticleRow } from '@/shared/api/seo'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import ArticlePicker from './ArticlePicker.vue'
import SectionNav from './SectionNav.vue'
import { usePhase6Errors, usePhase6Perms } from './usePhase6'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { workspaceId, canEdit } = usePhase6Perms()
const { toastError, toast } = usePhase6Errors()
const articles = shallowRef<SeoArticleRow[]>([])
const articleId = ref(typeof route.params.articleId === 'string' ? route.params.articleId : '')
const view = shallowRef<PlacementView | null>(null)
const items = ref<Placement[]>([])
const loading = ref(false)
const error = shallowRef<unknown>(null)
const saving = ref(false)
const suggesting = ref(false)

void seoApi.articles(workspaceId.value).then((a) => (articles.value = a)).catch((e) => (error.value = e))

async function load() {
  if (!articleId.value) { view.value = null; return }
  loading.value = true
  error.value = null
  try {
    view.value = await mediaApi.placements(workspaceId.value, articleId.value)
    items.value = view.value.placements.map((p) => ({ ...p }))
  } catch (e) {
    error.value = e
  } finally {
    loading.value = false
  }
}
watch(articleId, (id) => {
  void router.replace({ name: 'media-article', params: { workspaceId: workspaceId.value, articleId: id || undefined } })
  void load()
}, { immediate: true })

async function suggest() {
  suggesting.value = true
  try {
    const r = await mediaApi.suggestPlacements(workspaceId.value, articleId.value)
    items.value = r.placements.map((p) => ({ ...p }))
    toast.success(t('p6.media.art.suggested', { n: r.suggested }))
  } catch (e) {
    toastError(e)
  } finally {
    suggesting.value = false
  }
}
async function save() {
  saving.value = true
  try {
    const r = await mediaApi.savePlacements(workspaceId.value, articleId.value, items.value)
    items.value = r.placements.map((p) => ({ ...p }))
    toast.success(t('common.saved'))
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
function add(slot: PlacementSlot, sectionKey: string | null = null, heading: string | null = null) {
  items.value = [...items.value, { id: null, slot, section_key: sectionKey, section_heading: heading, position: items.value.length, status: 'accepted', alt_text: null, caption: null, asset: null }]
}
function remove(p: Placement) {
  if (p.id) p.delete = true
  else items.value = items.value.filter((x) => x !== p)
}
const visible = computed(() => items.value.filter((p) => !p.delete))
const notReady = computed(() => visible.value.filter((p) => !placementReady(p)).length)
const autofill = computed(() => view.value?.autofill ?? null)
const autofillTone = computed(() => ({ running: 'bg-brand-50 text-brand-700', completed: 'bg-success-50 text-success-700', incomplete: 'bg-warning-50 text-warning-700', failed: 'bg-danger-50 text-danger-600' })[autofill.value?.status ?? 'running'])
const autofillText = computed(() => {
  const a = autofill.value
  if (!a) return ''
  return t(`p6.media.art.autofill.${a.status}`, { filled: a.filled, target: a.target, pending: a.pending, library: a.library_count, ai: a.ai_count, reason: a.error?.message ?? '' })
})

// Asset picker
const picking = ref<Placement | null>(null)
const library = shallowRef<MediaAsset[]>([])
async function pick(p: Placement) {
  picking.value = p
  try {
    library.value = (await mediaApi.list(workspaceId.value, { cursor: null, site_id: view.value?.article.site_id, status: 'ready' })).data
  } catch (e) {
    toastError(e)
  }
}
function choose(a: MediaAsset) {
  if (!picking.value) return
  picking.value.asset = a
  if (!picking.value.alt_text) picking.value.alt_text = a.alt_text
  picking.value = null
}
const statusTone = (s: string) => (s === 'accepted' ? 'success' : s === 'rejected' ? 'neutral' : 'brand') as 'success' | 'neutral' | 'brand'
</script>

<template>
  <div>
    <PageHeader :title="t('p6.media.art.title')" :description="t('p6.media.art.subtitle')" />
    <SectionNav section="media" active="articles" />
    <AppCard class="mb-6">
      <ArticlePicker v-model="articleId" :articles="articles" />
    </AppCard>
    <EmptyState v-if="!articleId" icon="file" :title="t('p6.common.pickArticleFirst')" />
    <LoadingState v-else-if="loading" />
    <ErrorState v-else-if="error" :error="error" @retry="load" />
    <template v-else-if="view">
      <p v-if="autofill" :class="['mb-4 rounded-lg px-3 py-2 text-sm', autofillTone]" role="status" data-testid="autofill-status">{{ autofillText }}</p>
      <div class="mb-4 flex flex-wrap items-center gap-2">
        <AppBadge v-if="notReady" tone="warning" data-testid="placements-not-ready">{{ t('p6.media.art.notReady', { n: notReady }) }}</AppBadge>
        <AppBadge v-else tone="success">{{ t('p6.media.art.ready') }}</AppBadge>
        <div class="ml-auto flex gap-2">
          <AppButton v-if="canEdit" variant="secondary" size="sm" :loading="suggesting" data-testid="suggest-placements" @click="suggest">{{ t('p6.media.art.suggest') }}</AppButton>
          <AppButton v-if="canEdit" size="sm" :loading="saving" data-testid="save-placements" @click="save">{{ t('common.save') }}</AppButton>
        </div>
      </div>
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <AppCard :title="t('p6.media.art.structure')" :padded="false">
          <ul class="divide-y divide-line text-sm">
            <li class="flex items-center justify-between px-5 py-2"><span>{{ t('p6.media.slot.featured') }}</span><AppButton v-if="canEdit" size="sm" variant="ghost" @click="add('featured')">+</AppButton></li>
            <li v-for="s in view.sections" :key="s.key" class="flex items-center justify-between gap-2 px-5 py-2">
              <span class="truncate">{{ s.heading || t('p6.media.slot.intro') }} <span class="text-xs text-ink-muted">· {{ s.words }}</span></span>
              <AppButton v-if="canEdit" size="sm" variant="ghost" @click="add(s.key === 'intro' ? 'intro' : 'section', s.key, s.heading)">+</AppButton>
            </li>
          </ul>
        </AppCard>
        <AppCard :title="t('p6.media.art.placements')" class="lg:col-span-2" :padded="false">
          <EmptyState v-if="!visible.length" icon="image" :title="t('p6.media.art.empty')" />
          <ul v-else class="divide-y divide-line">
            <li v-for="(p, i) in visible" :key="p.id ?? `new-${i}`" class="flex flex-wrap gap-4 px-5 py-4 text-sm" data-testid="placement-row">
              <button type="button" class="relative h-20 w-32 shrink-0 overflow-hidden rounded border border-line bg-surface-muted" :disabled="!canEdit" @click="pick(p)">
                <img v-if="p.asset?.thumbnail_url" :src="p.asset.thumbnail_url" :alt="p.alt_text ?? ''" class="h-full w-full object-cover" />
                <span v-else class="text-xs text-ink-muted">{{ t('p6.media.art.chooseImage') }}</span>
                <span v-if="p.asset?.is_ai_generated" class="absolute left-1 top-1 rounded bg-black/70 px-1 text-[10px] text-white">{{ t('p6.media.aiLabel') }}</span>
              </button>
              <div class="min-w-0 flex-1 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                  <select v-model="p.slot" :disabled="!canEdit" class="rounded border border-line px-2 py-1 text-xs">
                    <option v-for="s in PLACEMENT_SLOTS" :key="s" :value="s">{{ t(`p6.media.slot.${s}`) }}</option>
                  </select>
                  <span class="truncate text-xs text-ink-muted">{{ p.section_heading ?? '' }}</span>
                  <select v-model="p.status" :disabled="!canEdit" class="rounded border border-line px-2 py-1 text-xs">
                    <option v-for="s in ['suggested', 'accepted', 'rejected']" :key="s" :value="s">{{ t(`p6.media.placementStatus.${s}`) }}</option>
                  </select>
                  <AppBadge :tone="statusTone(p.status)">{{ t(`p6.media.placementStatus.${p.status}`) }}</AppBadge>
                </div>
                <input v-model="p.alt_text" :disabled="!canEdit" :placeholder="t('p6.media.lib.alt')" class="w-full rounded border border-line px-2 py-1 text-sm" />
                <input v-model="p.caption" :disabled="!canEdit" :placeholder="t('p6.media.lib.caption')" class="w-full rounded border border-line px-2 py-1 text-sm" />
                <p v-if="p.asset && !placementAllowsAsset(p.slot, p.asset)" class="text-xs text-danger-600">{{ t('p6.media.art.notAllowed') }}</p>
                <p v-else-if="!placementReady(p)" class="text-xs text-warning-600">{{ t('p6.media.art.needsAlt') }}</p>
              </div>
              <AppButton v-if="canEdit" size="sm" variant="ghost" @click="remove(p)">{{ t('common.delete') }}</AppButton>
            </li>
          </ul>
        </AppCard>
      </div>
    </template>

    <AppModal :open="!!picking" :title="t('p6.media.art.chooseImage')" width="xl" @close="picking = null">
      <EmptyState v-if="!library.length" icon="image" :title="t('p6.media.lib.empty')" />
      <ul v-else class="divide-y divide-line">
        <li v-for="a in library" :key="a.id">
          <button type="button" class="block w-full text-left disabled:opacity-40" :disabled="!!picking && !placementAllowsAsset(picking.slot, a)" @click="choose(a)">
            <img :src="a.thumbnail_url ?? ''" :alt="a.alt_text ?? ''" class="h-24 w-full rounded object-cover" />
            <p class="truncate text-xs">{{ a.title }}</p>
            <p v-if="a.is_ai_generated" class="text-[10px] text-ink-muted">{{ t('p6.media.aiLabel') }}</p>
          </button>
        </li>
      </ul>
    </AppModal>
  </div>
</template>
