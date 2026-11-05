<script setup lang="ts">
import { computed, reactive, ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { DESCRIPTION_HINT, lengthTone, SCHEMA_TYPES, seoApi, TITLE_HINT, type SchemaType, type SeoMetadata } from '@/shared/api/seo'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import ArticlePicker from './ArticlePicker.vue'
import SectionNav from './SectionNav.vue'
import { useArticleParam } from './useArticleParam'
import { usePhase6Errors, usePhase6Perms } from './usePhase6'

const { t } = useI18n()
const { workspaceId, canEdit } = usePhase6Perms()
const { toastError, toast } = usePhase6Errors()
const meta = shallowRef<SeoMetadata | null>(null)
const loading = ref(false)
const error = shallowRef<unknown>(null)
const saving = ref(false)
const form = reactive({
  seo_title: '', meta_description: '', slug: '', canonical_url: '', focus_keyword: '', secondary: '', og_title: '', og_description: '',
  robots_noindex: false, robots_nofollow: false, schema_types: [] as SchemaType[],
})
function fill(m: SeoMetadata) {
  meta.value = m
  Object.assign(form, {
    seo_title: m.seo_title ?? '', meta_description: m.meta_description ?? '', slug: m.slug ?? '', canonical_url: m.canonical_url ?? '', focus_keyword: m.focus_keyword ?? '',
    secondary: (m.secondary_keywords ?? []).join(', '), og_title: m.og_title ?? '', og_description: m.og_description ?? '',
    robots_noindex: m.robots_noindex, robots_nofollow: m.robots_nofollow, schema_types: [...(m.schema_types ?? [])],
  })
}
async function load(id: string) {
  meta.value = null
  if (!id) return
  loading.value = true
  error.value = null
  try {
    fill(await seoApi.metadata(workspaceId.value, id))
  } catch (e) {
    error.value = e
  } finally {
    loading.value = false
  }
}
const { articles, articleId } = useArticleParam('seo-metadata', (id) => void load(id))

const policy = computed(() => meta.value?.site_policy)
async function save() {
  if (!meta.value) return
  saving.value = true
  try {
    const data = {
      lock_version: meta.value.lock_version, seo_title: form.seo_title || null, meta_description: form.meta_description || null, slug: form.slug || null,
      focus_keyword: form.focus_keyword || null, secondary_keywords: form.secondary.split(',').map((s) => s.trim()).filter(Boolean).slice(0, 10),
      og_title: form.og_title || null, og_description: form.og_description || null, schema_types: form.schema_types,
      ...(policy.value?.allow_canonical_override ? { canonical_url: form.canonical_url || null } : {}),
      ...(policy.value?.allow_robots_override ? { robots_noindex: form.robots_noindex, robots_nofollow: form.robots_nofollow } : {}),
    }
    const site_policy = meta.value.site_policy
    fill({ ...(await seoApi.updateMetadata(workspaceId.value, articleId.value, data)), site_policy })
    toast.success(t('common.saved'))
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
async function regenerate() {
  try {
    const site_policy = meta.value?.site_policy
    fill({ ...(await seoApi.generateMetadata(workspaceId.value, articleId.value)), site_policy })
    toast.success(t('p6.seo.meta.regenerated'))
  } catch (e) {
    toastError(e)
  }
}
const isManual = (f: string) => (meta.value?.manual_fields ?? []).includes(f)
function toggleType(type: SchemaType) {
  form.schema_types = form.schema_types.includes(type) ? form.schema_types.filter((x) => x !== type) : [...form.schema_types, type]
}
</script>

<template>
  <div>
    <PageHeader :title="t('p6.seo.meta.title')" :description="t('p6.seo.meta.subtitle')" />
    <SectionNav section="seo" active="metadata" />
    <AppCard class="mb-6"><ArticlePicker v-model="articleId" :articles="articles" /></AppCard>
    <EmptyState v-if="!articleId" icon="file" :title="t('p6.common.pickArticleFirst')" />
    <LoadingState v-else-if="loading" />
    <ErrorState v-else-if="error" :error="error" @retry="load(articleId)" />
    <div v-else-if="meta" class="grid gap-6 lg:grid-cols-3">
      <AppCard :title="t('p6.seo.meta.fields')" class="lg:col-span-2">
        <form class="space-y-3" @submit.prevent="save">
          <div>
            <AppInput v-model="form.seo_title" :label="t('p6.seo.meta.seoTitle')" :disabled="!canEdit" />
            <p class="mt-1 flex gap-2 text-xs"><AppBadge :tone="lengthTone(form.seo_title.length, TITLE_HINT)">{{ form.seo_title.length }}/{{ TITLE_HINT }}</AppBadge><span v-if="isManual('seo_title')" class="text-ink-muted">{{ t('p6.seo.meta.manual') }}</span></p>
          </div>
          <div>
            <label class="block text-sm font-medium">{{ t('p6.seo.meta.description') }}</label>
            <textarea v-model="form.meta_description" rows="3" :disabled="!canEdit" class="mt-1 w-full rounded-lg border border-line p-2 text-sm" />
            <p class="mt-1 flex gap-2 text-xs"><AppBadge :tone="lengthTone(form.meta_description.length, DESCRIPTION_HINT)">{{ form.meta_description.length }}/{{ DESCRIPTION_HINT }}</AppBadge><span v-if="isManual('meta_description')" class="text-ink-muted">{{ t('p6.seo.meta.manual') }}</span></p>
          </div>
          <AppInput v-model="form.slug" :label="t('p6.seo.meta.slug')" :disabled="!canEdit" :hint="t('p6.seo.meta.slugHint')" />
          <AppInput v-model="form.focus_keyword" :label="t('p6.seo.meta.focus')" :disabled="!canEdit" />
          <AppInput v-model="form.secondary" :label="t('p6.seo.meta.secondary')" :disabled="!canEdit" />
          <AppInput v-model="form.og_title" :label="t('p6.seo.meta.ogTitle')" :disabled="!canEdit" />
          <AppInput v-model="form.og_description" :label="t('p6.seo.meta.ogDescription')" :disabled="!canEdit" />
          <AppInput v-model="form.canonical_url" :label="t('p6.seo.meta.canonical')" :disabled="!canEdit || !policy?.allow_canonical_override" :hint="policy?.allow_canonical_override ? t('p6.seo.meta.canonicalHint') : t('p6.seo.meta.policyLocked')" />
          <div class="rounded-lg border border-line p-3">
            <Toggle v-model="form.robots_noindex" :label="t('p6.seo.meta.noindex')" :description="policy?.allow_robots_override ? t('p6.seo.meta.noindexHint') : t('p6.seo.meta.policyLocked')" :disabled="!canEdit || !policy?.allow_robots_override" />
            <Toggle v-model="form.robots_nofollow" :label="t('p6.seo.meta.nofollow')" :disabled="!canEdit || !policy?.allow_robots_override" />
          </div>
          <div>
            <p class="text-sm font-medium">{{ t('p6.seo.meta.schemaTypes') }}</p>
            <div class="mt-1 flex flex-wrap gap-2">
              <label v-for="s in SCHEMA_TYPES" :key="s" class="flex items-center gap-1 text-sm"><input type="checkbox" :checked="form.schema_types.includes(s)" :disabled="!canEdit" @change="toggleType(s)" />{{ s }}</label>
            </div>
            <p class="mt-1 text-xs text-ink-muted">{{ t('p6.seo.meta.schemaHint') }}</p>
          </div>
          <div v-if="canEdit" class="flex justify-end gap-2">
            <AppButton variant="secondary" @click="regenerate">{{ t('p6.seo.meta.regenerate') }}</AppButton>
            <AppButton type="submit" :loading="saving" data-testid="save-metadata">{{ t('common.save') }}</AppButton>
          </div>
        </form>
      </AppCard>
      <AppCard :title="t('p6.seo.meta.snippet')" :subtitle="t('p6.seo.meta.snippetHint')">
        <div class="rounded-lg border border-line p-3" data-testid="serp-snippet">
          <p class="truncate text-xs text-success-600">/{{ form.slug }}/</p>
          <p class="truncate text-base text-brand-700">{{ form.seo_title || '—' }}</p>
          <p class="line-clamp-3 text-sm text-ink-muted">{{ form.meta_description || t('p6.seo.meta.noDescription') }}</p>
        </div>
        <p class="mt-3 text-xs text-ink-muted">{{ t('p6.seo.meta.source', { source: meta.source }) }}</p>
      </AppCard>
    </div>
  </div>
</template>
