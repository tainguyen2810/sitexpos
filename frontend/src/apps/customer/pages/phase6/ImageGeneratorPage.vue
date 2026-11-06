<script setup lang="ts">
import { computed, reactive, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ASPECT_RATIOS, IMAGE_STYLES, IMAGE_TYPES, mediaApi, microUsd, type GeneratePreview, type ImageJob } from '@/shared/api/media'
import { seoApi, type SeoArticleRow } from '@/shared/api/seo'
import { newIdempotencyKey } from '@/shared/api/publishing'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import { usePolling } from '../writer/useWriter'
import ArticlePicker from './ArticlePicker.vue'
import SectionNav from './SectionNav.vue'
import { usePhase6Errors, usePhase6Perms, useSites } from './usePhase6'

const { t } = useI18n()
const { workspaceId, canEdit } = usePhase6Perms()
const { toastError, toast } = usePhase6Errors()
const sites = useSites({ required: true })
const articles = shallowRef<SeoArticleRow[]>([])
const form = reactive({
  article_id: '', image_type: 'featured', aspect_ratio: '16:9', style: 'editorial_concept', quality: 'standard' as 'standard' | 'high',
  image_count: '1', brand_colors: '', context: '', title: '',
})
const preview = shallowRef<GeneratePreview | null>(null)
const prompt = ref('')
const previewing = ref(false)
const generating = ref(false)
const idemKey = ref(newIdempotencyKey('img'))

watch(() => sites.siteId.value, async (sid) => {
  articles.value = sid ? await seoApi.articles(workspaceId.value, sid).catch(() => []) : []
}, { immediate: true })

const payload = computed(() => ({
  site_id: sites.siteId.value || undefined,
  article_id: form.article_id || undefined,
  image_type: form.image_type,
  aspect_ratio: form.aspect_ratio,
  style: form.style,
  quality: form.quality,
  image_count: Number(form.image_count) || 1,
  brand_colors: form.brand_colors.split(/[\s,]+/).filter((c) => /^#[0-9a-fA-F]{6}$/.test(c)).slice(0, 5),
  context: form.context || undefined,
  title: form.title || undefined,
}))
watch(payload, () => { preview.value = null; idemKey.value = newIdempotencyKey('img') }, { deep: true })

async function doPreview() {
  previewing.value = true
  try {
    preview.value = await mediaApi.previewGeneration(workspaceId.value, payload.value)
    prompt.value = preview.value.prompt
  } catch (e) {
    toastError(e)
  } finally {
    previewing.value = false
  }
}
async function generate() {
  generating.value = true
  try {
    const edited = preview.value && prompt.value.trim() !== preview.value.prompt.trim() ? prompt.value.trim() : undefined
    await mediaApi.generate(workspaceId.value, { ...payload.value, prompt: edited }, idemKey.value)
    toast.success(t('p6.media.gen.queued'))
    idemKey.value = newIdempotencyKey('img')
    await jobs.reload()
  } catch (e) {
    toastError(e)
  } finally {
    generating.value = false
  }
}
async function regenerate(j: ImageJob) {
  try {
    await mediaApi.regenerate(workspaceId.value, j.id)
    await jobs.reload()
  } catch (e) {
    toastError(e)
  }
}

const jobs = useCursorList((cursor) => mediaApi.generations(workspaceId.value, { cursor }))
void jobs.reload()
const running = computed(() => jobs.items.value.some((j) => j.status === 'queued' || j.status === 'running'))
usePolling(() => jobs.reload(), running, 5000)

const opts = (keys: readonly string[], prefix: string) => keys.map((k) => ({ value: k, label: t(`${prefix}.${k}`) }))
const ratioOpts = ASPECT_RATIOS.map((r) => ({ value: r, label: r }))
const tone = (s: string) => (s === 'succeeded' ? 'success' : s === 'failed' || s === 'blocked' ? 'danger' : s === 'canceled' ? 'neutral' : 'brand') as 'success' | 'danger' | 'neutral' | 'brand'
</script>

<template>
  <div>
    <PageHeader :title="t('p6.media.gen.title')" :description="t('p6.media.gen.subtitle')" />
    <SectionNav section="media" active="generator" />
    <p class="mb-4 rounded-lg bg-warning-50 px-4 py-3 text-sm text-amber-900" data-testid="ai-label-notice">{{ t('p6.media.gen.notice') }}</p>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
      <AppCard :title="t('p6.media.gen.form')" class="lg:col-span-2">
        <LoadingState v-if="sites.loading.value" />
        <ErrorState v-else-if="sites.error.value" :error="sites.error.value" @retry="sites.reload" />
        <EmptyState v-else-if="!sites.sites.value.length" icon="globe" :title="t('p6.common.noSite')" />
        <form v-else class="space-y-3" @submit.prevent="doPreview">
          <AppSelect v-model="sites.siteId.value" :label="t('p6.common.site')" :options="sites.options.value" />
          <ArticlePicker v-model="form.article_id" :articles="articles" :label="t('p6.media.gen.article')" />
          <AppSelect v-model="form.image_type" :label="t('p6.media.gen.type')" :options="opts(IMAGE_TYPES, 'p6.media.imageType')" />
          <div class="">
            <AppSelect v-model="form.aspect_ratio" :label="t('p6.media.gen.ratio')" :options="ratioOpts" />
            <AppSelect v-model="form.style" :label="t('p6.media.gen.style')" :options="opts(IMAGE_STYLES, 'p6.media.style')" />
            <AppSelect v-model="form.quality" :label="t('p6.media.gen.quality')" :options="[{ value: 'standard', label: t('p6.media.quality.standard') }, { value: 'high', label: t('p6.media.quality.high') }]" />
            <AppSelect v-model="form.image_count" :label="t('p6.media.gen.count')" :options="['1', '2', '3', '4'].map((v) => ({ value: v, label: v }))" />
          </div>
          <AppInput v-model="form.title" :label="t('p6.media.gen.subject')" :hint="t('p6.media.gen.subjectHint')" />
          <AppInput v-model="form.context" :label="t('p6.media.gen.context')" />
          <AppInput v-model="form.brand_colors" :label="t('p6.media.gen.colors')" placeholder="#0F766E, #F59E0B" />
          <AppButton type="submit" variant="secondary" :loading="previewing" block data-testid="preview-prompt">{{ t('p6.media.gen.preview') }}</AppButton>
        </form>
      </AppCard>
      <div class="space-y-6 lg:col-span-3">
        <AppCard v-if="preview" :title="t('p6.media.gen.promptTitle')" :subtitle="t('p6.media.gen.promptHint')">
          <textarea v-model="prompt" rows="7" class="w-full rounded-lg border border-line p-3 font-mono text-xs" data-testid="prompt-editor" />
          <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm">
            <span>{{ t('p6.media.gen.estimate') }}: <strong>{{ microUsd(preview.estimate_micro_usd) }}</strong></span>
            <span v-if="!preview.models.length" class="text-danger-600" data-testid="no-image-model">{{ t('p6.media.gen.noModel') }}</span>
            <span v-else class="text-xs text-ink-muted">{{ preview.models.map((m) => m.model_key).join(', ') }}</span>
            <AppButton v-if="canEdit" :loading="generating" :disabled="!preview.models.length || !preview.limits.ai_images_enabled" data-testid="generate" @click="generate">{{ t('p6.media.gen.generate') }}</AppButton>
          </div>
        </AppCard>
        <AppCard :title="t('p6.media.gen.jobs')" :padded="false">
          <LoadingState v-if="jobs.loading.value && !jobs.loaded.value" />
          <ErrorState v-else-if="jobs.error.value" :error="jobs.error.value" @retry="jobs.reload" />
          <EmptyState v-else-if="!jobs.items.value.length" icon="sparkles" :title="t('p6.media.gen.empty')" />
          <ul v-else class="divide-y divide-line">
            <li v-for="j in jobs.items.value" :key="j.id" class="space-y-2 px-5 py-4 text-sm">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-medium">{{ t(`p6.media.imageType.${j.image_type}`) }} · {{ j.aspect_ratio }} · v{{ j.version }}</span>
                <span class="flex items-center gap-2">
                  <span class="text-xs text-ink-muted">{{ microUsd(j.cost_micro_usd ?? j.estimated_cost_micro_usd) }} · {{ formatDateTime(j.created_at) }}</span>
                  <AppBadge :tone="tone(j.status)">{{ t(`p6.media.jobStatus.${j.status}`) }}</AppBadge>
                </span>
              </div>
              <p v-if="j.error_code" class="text-xs text-danger-600">{{ j.error_message ?? j.error_code }}</p>
              <div v-if="j.assets.length" class="flex flex-wrap gap-2">
                <figure v-for="a in j.assets" :key="a.id" class="relative w-32">
                  <img :src="a.thumbnail_url ?? ''" :alt="a.alt_text ?? ''" class="h-20 w-32 rounded object-cover" loading="lazy" />
                  <span class="absolute left-1 top-1 rounded bg-black/70 px-1 text-[10px] text-white">{{ t('p6.media.aiLabel') }}</span>
                </figure>
              </div>
              <AppButton v-if="canEdit && (j.status === 'succeeded' || j.status === 'failed')" size="sm" variant="ghost" @click="regenerate(j)">{{ t('p6.media.gen.regenerate') }}</AppButton>
            </li>
          </ul>
          <div v-if="jobs.next.value" class="border-t border-line p-3 text-center">
            <AppButton variant="ghost" size="sm" :loading="jobs.loadingMore.value" @click="jobs.more">{{ t('common.loadMore') }}</AppButton>
          </div>
        </AppCard>
      </div>
    </div>
  </div>
</template>
