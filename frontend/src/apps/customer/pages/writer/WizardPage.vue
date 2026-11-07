<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  BUILTIN_STYLES, CONTENT_TYPES, LENGTHS, formatUsd, idempotencyKey, lengthOf, readingMinutes, splitLines, usdToMicro, writerApi,
  type ApprovalMode, type ArticleInput, type BuiltinStyleKey, type Depth, type LengthKey, type ReviewMode, type SearchIntent, type WriterContentType, type WriterIdea,
} from '@/shared/api/writer'
import { researchApi, type Campaign } from '@/shared/api/research'
import { siteApi } from '@/shared/api/sites'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import WTextarea from './components/WTextarea.vue'
import WriterNav from './components/WriterNav.vue'
import StylePicker, { type StyleChoice } from './components/StylePicker.vue'
import { useWriterPerms } from './useWriter'

/**
 * Simple writer (v1.0.9): three questions (topic, style, length) and a few
 * plain-language switches. Everything else is optional under "Tùy chỉnh thêm";
 * left empty, the AI decides. The request is the same ArticleInput as before.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { workspaceId: id, canEdit } = useWriterPerms()
const { errors, formError, submitting, submit } = useApiForm()

const ADVANCED_FIELDS = ['audience', 'search_intent', 'primary_keyword', 'secondary_keywords', 'content_type', 'language', 'country', 'source_urls',
  'cta', 'brand_rules', 'notes', 'research_depth', 'budget_limit_micro_usd', 'review_mode', 'approval_mode', 'title', 'internal_links']

const options = useAsync(() => writerApi.options(id.value))
const sites = useAsync(() => siteApi.list(id.value))
const presets = useAsync(() => writerApi.presets(id.value))
const overview = useAsync(() => writerApi.overview(id.value))
const campaigns = ref<Campaign[]>([])
const ideas = ref<WriterIdea[]>([])
const ideasLoading = ref(false)
const showIdeas = ref(!!route.query.campaign || !!route.query.idea)
const advanced = ref(false)

const f = reactive({
  site_id: '', campaign_id: '', content_idea_id: '', topic: '', title: '',
  style: null as StyleChoice, length: 'medium' as LengthKey,
  auto_images: true, allow_web_search: true, links_enabled: true,
  audience: '', search_intent: 'informational' as SearchIntent, primary_keyword: '', secondary: '',
  content_type: 'blog_article' as WriterContentType, language: 'vi' as 'vi' | 'en', country: 'VN',
  source_urls: '', cta: '', brand_rules: '', notes: '',
  research_depth: 'standard' as Depth, budget_usd: '', review_mode: 'final_only' as ReviewMode, approval_mode: 'manual' as ApprovalMode,
})
const key = idempotencyKey() // one key per page visit: a double submit creates one article

watch(() => options.data.value, (o) => {
  if (o && f.budget_usd === '') f.budget_usd = String(o.limits.default_article_budget_micro_usd / 1_000_000)
})
watch(() => sites.data.value, (s) => {
  if (s?.length && !f.site_id) f.site_id = String(route.query.site ?? s[0]!.id)
})
// Default style: ?style=preset:<id>|builtin:<key> (from the Styles page), else the default preset,
// else the first style of yours, else "Thân thiện".
watch(() => presets.data.value, (list) => {
  if (!list || f.style) return
  const [kind, value] = String(route.query.style ?? '').split(':')
  if (kind === 'builtin' && (BUILTIN_STYLES as readonly string[]).includes(value ?? '')) {
    f.style = { kind: 'builtin', key: value as BuiltinStyleKey }
    return
  }
  const p = (kind === 'preset' ? list.find((x) => x.id === value) : undefined) ?? list.find((x) => x.is_default) ?? list[0]
  f.style = p ? { kind: 'preset', id: p.id } : { kind: 'builtin', key: 'friendly' }
}, { immediate: true })
watch(() => presets.error.value, (e) => {
  if (e && !f.style) f.style = { kind: 'builtin', key: 'friendly' }
})

watch(() => f.site_id, async (sid) => {
  campaigns.value = []
  f.campaign_id = ''
  if (!sid) return
  const page = await researchApi.campaigns(id.value, { site_id: sid, per_page: 100, status: 'active' }).catch(() => null)
  campaigns.value = (page?.data ?? []).filter((c) => c.site_id === sid)
  const wanted = String(route.query.campaign ?? '')
  f.campaign_id = wanted && campaigns.value.some((c) => c.id === wanted) ? wanted : (campaigns.value[0]?.id ?? '')
})
watch(() => f.campaign_id, async (cid) => {
  ideas.value = []
  f.content_idea_id = ''
  if (!cid) return
  ideasLoading.value = true
  ideas.value = await writerApi.ideas(id.value, cid).catch(() => [])
  ideasLoading.value = false
  const wanted = String(route.query.idea ?? '')
  if (wanted && ideas.value.some((i) => i.id === wanted && !i.article_id)) pickIdea(wanted)
})
const freeIdeas = computed(() => ideas.value.filter((i) => !i.article_id).length)
const idea = computed(() => ideas.value.find((i) => i.id === f.content_idea_id) ?? null)
function pickIdea(ideaId: string) {
  const i = ideas.value.find((x) => x.id === ideaId)
  if (!i) return
  f.content_idea_id = ideaId
  f.topic = i.title
  f.primary_keyword = i.primary_keyword
  f.secondary = (i.secondary_keywords ?? []).join(', ')
  if (i.search_intent) f.search_intent = i.search_intent
  if (i.audience) f.audience = i.audience
}
function clearIdea() {
  f.content_idea_id = ''
}
// Typing a different topic detaches the chosen idea.
watch(() => f.topic, (v) => {
  if (idea.value && v.trim() !== idea.value.title) f.content_idea_id = ''
})

// A style of yours carries its own defaults (length, depth, budget, CTA).
watch(() => f.style, (s) => {
  if (s?.kind !== 'preset') return
  const p = presets.data.value?.find((x) => x.id === s.id)
  if (!p) return
  const d = p.defaults
  if (d.content_type && (CONTENT_TYPES as readonly string[]).includes(d.content_type)) f.content_type = d.content_type as WriterContentType
  if (d.target_words) f.length = lengthOf(d.target_words)
  if (d.research_depth) f.research_depth = d.research_depth
  if (d.budget_limit_micro_usd) f.budget_usd = String(d.budget_limit_micro_usd / 1_000_000)
  if (typeof d.auto_images === 'boolean') f.auto_images = d.auto_images
  if (p.cta && !f.cta) f.cta = p.cta
})

const words = computed(() => LENGTHS[f.length])
const estimate = useAsync(() => writerApi.estimate(id.value, words.value), { immediate: false })
watch(words, () => void estimate.run(), { immediate: true })

const siteOptions = computed(() => (sites.data.value ?? []).map((s) => ({ value: s.id, label: s.name })))
const campaignOptions = computed(() => campaigns.value.map((c) => ({ value: c.id, label: c.name })))
const intentOptions = computed(() => (['informational', 'commercial', 'transactional', 'navigational'] as const).map((v) => ({ value: v, label: t(`writer.simple.intent.${v}`) })))
const typeOptions = computed(() => (options.data.value?.content_types ?? CONTENT_TYPES).map((v) => ({ value: v, label: t(`writer.contentType.${v}`) })))
const languageOptions = [{ value: 'vi', label: 'Tiếng Việt' }, { value: 'en', label: 'English' }]
const autoApprove = computed(() => options.data.value?.auto_approve ?? { enabled: false, min_quality: 0 })
const maxSources = computed(() => options.data.value?.limits.max_sources ?? { basic: 5, standard: 10, deep: 20 })
const quota = computed(() => overview.data.value?.quota ?? null)
const styleName = computed(() => {
  const s = f.style
  if (!s) return '—'
  return s.kind === 'preset' ? (presets.data.value?.find((p) => p.id === s.id)?.name ?? '—') : t(`writer.builtin.${s.key}.name`)
})

const canCreate = computed(() => !!f.site_id && (!!f.content_idea_id || f.topic.trim().length >= 5) && (quota.value === null || quota.value.remaining > 0))

function payload(): ArticleInput {
  const useIdea = !!f.content_idea_id
  const s = f.style
  return {
    site_id: f.site_id,
    campaign_id: useIdea ? (f.campaign_id || null) : null,
    content_idea_id: useIdea ? f.content_idea_id : null,
    topic: useIdea ? null : f.topic.trim(),
    title: f.title.trim() || null,
    primary_keyword: f.primary_keyword.trim() || null,
    secondary_keywords: splitLines(f.secondary).slice(0, 15),
    search_intent: f.search_intent,
    content_type: f.content_type,
    audience: f.audience.trim() || null,
    writing_preset_id: s?.kind === 'preset' ? s.id : null,
    builtin_style: s?.kind === 'builtin' ? s.key : null,
    auto_images: f.auto_images,
    language: f.language,
    country: f.country.trim().toUpperCase().slice(0, 2) || 'VN',
    target_words: words.value,
    research_depth: f.research_depth,
    source_urls: splitLines(f.source_urls.replace(/\s+/g, '\n')).slice(0, 20),
    ai_source_discovery: true,
    allow_web_search: f.allow_web_search,
    internal_links: { enabled: f.links_enabled, max: 5 },
    brand_rules: f.brand_rules.split('\n').map((x) => x.trim()).filter(Boolean).slice(0, 20),
    cta: f.cta.trim() || null,
    notes: f.notes.trim() || null,
    budget_limit_micro_usd: usdToMicro(Number(f.budget_usd) || 0),
    review_mode: f.review_mode,
    approval_mode: f.approval_mode,
    start: true,
  }
}

async function create() {
  if (!canCreate.value) return
  const a = await submit(() => writerApi.create(id.value, payload(), key))
  if (a) {
    void router.push({ name: 'writer-article', params: { workspaceId: id.value, articleId: a.id } })
    return
  }
  // An error on an optional field: open "Tùy chỉnh thêm" so the user sees it.
  if (Object.keys(errors).some((k) => errors[k] && ADVANCED_FIELDS.includes(k.split('.')[0]!))) advanced.value = true
}
const fieldErr = (k: string) => errors[k] ?? Object.entries(errors).find(([key, v]) => v && key.startsWith(`${k}.`))?.[1]
const teachTo = computed(() => ({ name: 'writer-presets', params: { workspaceId: id.value }, query: { learn: '1' } }))
const seg = (active: boolean) => ['flex-1 px-3 py-2.5 text-center text-sm transition sm:flex-none sm:px-5', active ? 'bg-brand-600 text-white' : 'bg-surface text-ink hover:bg-surface-muted']
const choice = (active: boolean) => ['flex flex-col rounded-xl border-[1.5px] p-3 text-left text-sm', active ? 'border-brand-600 bg-brand-50 ring-4 ring-brand-100' : 'border-line bg-surface hover:border-brand-300']
</script>

<template>
  <div>
    <PageHeader :title="t('writer.simple.title')" :description="t('writer.simple.subtitle')" :crumbs="[{ label: t('writer.title'), to: `/w/${id}/writer` }, { label: t('writer.nav.wizard') }]" />
    <WriterNav active="wizard" />

    <EmptyState v-if="!canEdit" icon="lock" :title="t('errors.forbidden')" />
    <LoadingState v-else-if="options.loading.value || sites.loading.value" />
    <ErrorState v-else-if="options.error.value || sites.error.value" :error="options.error.value ?? sites.error.value" @retry="() => { options.run(); sites.run() }" />
    <EmptyState v-else-if="!sites.data.value?.length" icon="globe" :title="t('research.campaigns.needSite')">
      <RouterLink :to="{ name: 'sites', params: { workspaceId: id } }" class="text-sm font-medium text-brand-600 underline">{{ t('nav.sites') }}</RouterLink>
    </EmptyState>

    <form v-else class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[1fr_20rem]" novalidate data-testid="simple-writer" @submit.prevent="create">
      <AppCard>
        <p v-if="formError" class="mb-4 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ formError }}</p>

        <!-- 1. Topic -->
        <section class="pb-6">
          <h2 class="flex items-center gap-2 text-base font-semibold"><span class="grid h-6 w-6 place-items-center rounded-full bg-brand-600 text-xs text-white">1</span>{{ t('writer.simple.q1') }}</h2>
          <p class="mb-3 mt-1 text-sm text-ink-muted sm:ml-8">{{ t('writer.simple.q1Help') }}</p>
          <div class="sm:ml-8">
            <AppInput v-model="f.topic" :label="t('writer.simple.q1')" class="[&_label]:sr-only [&_input]:py-3 [&_input]:text-base" :placeholder="t('writer.simple.topicPlaceholder')" :error="fieldErr('topic') ?? fieldErr('content_idea_id')" data-testid="topic" />
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm">
              <label class="flex items-center gap-2 text-ink-muted">
                {{ t('writer.simple.publishTo') }}
                <select v-model="f.site_id" class="rounded-md border border-line bg-surface px-2 py-1 text-sm font-medium text-ink" data-testid="site">
                  <option v-for="s in siteOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
              </label>
              <button v-if="campaigns.length" type="button" class="font-medium text-brand-600 hover:underline" :aria-expanded="showIdeas" @click="showIdeas = !showIdeas">
                💡 {{ t('writer.simple.fromIdeas', { n: freeIdeas }) }}
              </button>
            </div>
            <div v-if="showIdeas && campaigns.length" class="mt-3 rounded-lg border border-line p-3">
              <AppSelect v-if="campaigns.length > 1" v-model="f.campaign_id" class="mb-2" :label="t('writer.simple.campaign')" :options="campaignOptions" />
              <LoadingState v-if="ideasLoading" />
              <p v-else-if="!ideas.length" class="text-sm text-ink-muted">{{ t('writer.wizard.noIdeas') }}</p>
              <ul v-else class="max-h-64 divide-y divide-line overflow-y-auto" data-testid="idea-list">
                <li v-for="i in ideas" :key="i.id">
                  <label class="flex cursor-pointer items-start gap-3 px-1 py-2 text-sm" :class="i.article_id ? 'opacity-60' : 'hover:bg-surface-muted'">
                    <input type="radio" name="idea" class="mt-1" :value="i.id" :checked="f.content_idea_id === i.id" :disabled="!!i.article_id" @change="pickIdea(i.id)" />
                    <span class="min-w-0 flex-1"><span class="block font-medium">{{ i.title }}</span><span class="block text-xs text-ink-muted">{{ i.primary_keyword }}</span></span>
                    <AppBadge v-if="i.article_id" tone="neutral">{{ t('writer.wizard.ideaTaken') }}</AppBadge>
                  </label>
                </li>
              </ul>
              <button v-if="f.content_idea_id" type="button" class="mt-2 text-xs text-ink-muted underline" @click="clearIdea">{{ t('writer.simple.clearIdea') }}</button>
            </div>
          </div>
        </section>

        <!-- 2. Style -->
        <section class="border-t border-line py-6">
          <h2 class="flex items-center gap-2 text-base font-semibold"><span class="grid h-6 w-6 place-items-center rounded-full bg-brand-600 text-xs text-white">2</span>{{ t('writer.simple.q2') }}</h2>
          <p class="mb-3 mt-1 text-sm text-ink-muted sm:ml-8">{{ t('writer.simple.q2Help') }}</p>
          <div class="sm:ml-8">
            <StylePicker v-model="f.style" :presets="presets.data.value ?? []" :teach-to="teachTo" />
            <p v-if="fieldErr('writing_preset_id') || fieldErr('builtin_style')" class="mt-1 text-xs text-danger-600">{{ fieldErr('writing_preset_id') ?? fieldErr('builtin_style') }}</p>
          </div>
        </section>

        <!-- 3. Length -->
        <section class="border-t border-line py-6">
          <h2 class="flex items-center gap-2 text-base font-semibold"><span class="grid h-6 w-6 place-items-center rounded-full bg-brand-600 text-xs text-white">3</span>{{ t('writer.simple.q3') }}</h2>
          <p class="mb-3 mt-1 text-sm text-ink-muted sm:ml-8">{{ t('writer.simple.q3Help') }}</p>
          <div class="flex overflow-hidden rounded-lg border border-line-strong sm:ml-8 sm:inline-flex" role="radiogroup" :aria-label="t('writer.simple.q3')">
            <button
              v-for="(w, k) in LENGTHS" :key="k" type="button" role="radio" :aria-checked="f.length === k" :class="seg(f.length === k)" class="border-l border-line-strong first:border-l-0"
              :data-testid="`length-${k}`" @click="f.length = k"
            >
              <span class="block font-medium">{{ t(`writer.simple.length.${k}`) }}</span>
              <span class="block text-[11px] opacity-75">{{ t('writer.simple.lengthHint', { words: w.toLocaleString('vi-VN'), min: readingMinutes(w) }) }}</span>
            </button>
          </div>
          <p v-if="fieldErr('target_words')" class="mt-1 text-xs text-danger-600 sm:ml-8">{{ fieldErr('target_words') }}</p>
        </section>

        <!-- Switches -->
        <section class="border-t border-line py-5">
          <h2 class="text-sm font-semibold">{{ t('writer.simple.auto') }}</h2>
          <div class="mt-1 divide-y divide-dashed divide-line sm:ml-8">
            <Toggle v-model="f.auto_images" :label="t('writer.simple.images')" :description="t('writer.simple.imagesHelp')" />
            <Toggle v-model="f.allow_web_search" :label="t('writer.simple.web')" :description="t('writer.simple.webHelp')" />
            <Toggle v-model="f.links_enabled" :label="t('writer.simple.links')" :description="t('writer.simple.linksHelp')" />
          </div>
        </section>

        <!-- Optional settings -->
        <section class="border-t border-line pt-5">
          <button
            type="button" class="flex w-full items-center justify-between gap-2 rounded-lg border border-dashed border-line-strong px-4 py-3 text-left text-sm"
            :aria-expanded="advanced" data-testid="advanced-toggle" @click="advanced = !advanced"
          >
            <span><span class="font-medium text-ink">⚙️ {{ t('writer.simple.advanced') }}</span> <span class="text-ink-muted">{{ t('writer.simple.advancedHint') }}</span></span>
            <span aria-hidden="true" class="text-ink-muted">{{ advanced ? '▾' : '▸' }}</span>
          </button>

          <div v-if="advanced" class="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-2" data-testid="advanced">
            <p class="text-sm text-ink-muted sm:col-span-2">{{ t('writer.simple.advancedLead') }}</p>
            <AppInput v-model="f.audience" :label="t('writer.simple.audience')" :placeholder="t('writer.simple.audiencePh')" :hint="t('writer.simple.audienceHelp')" :error="fieldErr('audience')" />
            <AppSelect v-model="f.search_intent" :label="t('writer.simple.intentLabel')" :options="intentOptions" :hint="t('writer.simple.intentHelp')" :error="fieldErr('search_intent')" />
            <AppInput v-model="f.primary_keyword" :label="t('writer.simple.keyword')" :placeholder="t('writer.simple.keywordPh')" :hint="t('writer.simple.keywordHelp')" :error="fieldErr('primary_keyword')" />
            <AppInput v-model="f.secondary" :label="t('writer.simple.secondary')" :hint="t('writer.simple.secondaryHelp')" :error="fieldErr('secondary_keywords')" />
            <AppSelect v-model="f.content_type" :label="t('writer.simple.type')" :options="typeOptions" :hint="t('writer.simple.typeHelp')" :error="fieldErr('content_type')" />
            <div class="grid grid-cols-2 gap-3">
              <AppSelect v-model="f.language" :label="t('writer.simple.language')" :options="languageOptions" :error="fieldErr('language')" />
              <AppInput v-model="f.country" :label="t('writer.simple.country')" :hint="t('writer.simple.countryHelp')" :error="fieldErr('country')" />
            </div>
            <AppInput v-model="f.title" class="sm:col-span-2" :label="t('writer.simple.titleLabel')" :hint="t('writer.simple.titleHelp')" :error="fieldErr('title')" />
            <WTextarea v-model="f.source_urls" class="sm:col-span-2" :rows="3" :label="t('writer.simple.sources')" :hint="t('writer.simple.sourcesHelp')" :error="fieldErr('source_urls')" placeholder="https://" />
            <AppInput v-model="f.cta" class="sm:col-span-2" :label="t('writer.simple.cta')" :placeholder="t('writer.simple.ctaPh')" :hint="t('writer.simple.ctaHelp')" :error="fieldErr('cta')" />
            <WTextarea v-model="f.brand_rules" class="sm:col-span-2" :rows="3" :label="t('writer.simple.rules')" :hint="t('writer.simple.rulesHelp')" :error="fieldErr('brand_rules')" :placeholder="t('writer.simple.rulesPh')" />
            <WTextarea v-model="f.notes" class="sm:col-span-2" :rows="2" :label="t('writer.simple.notes')" :hint="t('writer.simple.notesHelp')" :error="fieldErr('notes')" />

            <fieldset>
              <legend class="mb-1.5 text-sm font-medium">{{ t('writer.simple.depth') }}</legend>
              <div class="flex overflow-hidden rounded-lg border border-line-strong" role="radiogroup" :aria-label="t('writer.simple.depth')">
                <button
                  v-for="d in (['basic', 'standard', 'deep'] as const)" :key="d" type="button" role="radio" :aria-checked="f.research_depth === d"
                  :class="seg(f.research_depth === d)" class="border-l border-line-strong first:border-l-0" @click="f.research_depth = d"
                >
                  <span class="block font-medium">{{ t(`writer.simple.depthOpt.${d}`) }}</span>
                  <span class="block text-[11px] opacity-75">{{ t('writer.simple.depthSources', { n: maxSources[d] }) }}</span>
                </button>
              </div>
              <p class="mt-1 text-xs text-ink-muted">{{ t('writer.simple.depthHelp') }}</p>
            </fieldset>
            <AppInput
              v-model="f.budget_usd" type="number" :label="t('writer.simple.budget')" :error="fieldErr('budget_limit_micro_usd')"
              :hint="options.data.value ? t('writer.simple.budgetHelp', { max: formatUsd(options.data.value.limits.max_article_budget_micro_usd) }) : undefined"
            />

            <fieldset class="sm:col-span-2">
              <legend class="mb-1.5 text-sm font-medium">{{ t('writer.simple.review') }}</legend>
              <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-3" role="radiogroup" :aria-label="t('writer.simple.review')">
                <button
                  v-for="m in (['final_only', 'brief_only', 'step_by_step'] as const)" :key="m" type="button" role="radio" :aria-checked="f.review_mode === m"
                  :class="choice(f.review_mode === m)" @click="f.review_mode = m"
                >
                  <span class="font-semibold">{{ t(`writer.simple.reviewOpt.${m}.name`) }}</span>
                  <span class="text-xs text-ink-muted">{{ t(`writer.simple.reviewOpt.${m}.desc`) }}</span>
                </button>
              </div>
            </fieldset>
            <div v-if="autoApprove.enabled" class="sm:col-span-2">
              <Toggle
                :model-value="f.approval_mode === 'auto'" data-testid="approval-mode" :label="t('writer.simple.autoApprove')"
                :description="t('writer.autoApprove.hint', { min: autoApprove.min_quality })" @update:model-value="(v: boolean) => (f.approval_mode = v ? 'auto' : 'manual')"
              />
            </div>
          </div>
        </section>
      </AppCard>

      <!-- Summary -->
      <aside class="flex flex-col gap-4 lg:sticky lg:top-4">
        <AppCard>
          <h2 class="mb-1 font-semibold">{{ t('writer.wizard.summary') }}</h2>
          <dl class="space-y-2.5 text-sm" data-testid="wizard-summary">
            <div><dt class="text-xs text-ink-muted">{{ t('writer.simple.sumTopic') }}</dt><dd class="font-medium">{{ idea?.title ?? (f.topic.trim() || '—') }}</dd></div>
            <div><dt class="text-xs text-ink-muted">{{ t('writer.simple.sumStyle') }}</dt><dd class="font-medium">{{ styleName }}</dd></div>
            <div>
              <dt class="text-xs text-ink-muted">{{ t('writer.simple.sumLength') }}</dt>
              <dd class="font-medium">{{ t(`writer.simple.length.${f.length}`) }} · ~{{ words.toLocaleString('vi-VN') }} {{ t('writer.simple.words') }}<template v-if="f.auto_images"> · {{ t('writer.simple.withImages') }}</template></dd>
            </div>
            <div>
              <dt class="text-xs text-ink-muted">{{ t('writer.simple.sumCost') }}</dt>
              <dd class="font-medium">
                {{ estimate.data.value ? `≈ ${formatUsd(estimate.data.value.estimated_cost_micro_usd)}` : '—' }}
                <span class="text-xs font-normal text-ink-muted">{{ t('writer.simple.costMax', { max: formatUsd(usdToMicro(Number(f.budget_usd) || 0)) }) }}</span>
              </dd>
            </div>
          </dl>
          <div class="my-4 h-px bg-line" />
          <p v-if="quota" class="mb-3 text-sm" :class="quota.remaining > 0 ? 'text-ink-muted' : 'text-danger-600'">
            {{ t('writer.wizard.quota', { remaining: quota.remaining, limit: quota.limit }) }}
          </p>
          <AppButton type="submit" block class="py-3 text-base" :loading="submitting" :disabled="!canCreate" data-testid="wizard-create">✨ {{ t('writer.simple.start') }}</AppButton>
          <p class="mt-2 text-center text-xs text-ink-muted">{{ t('writer.simple.startHelp') }}</p>
        </AppCard>
        <p class="rounded-lg bg-brand-50 px-3 py-2.5 text-sm text-brand-700">🛡️ {{ t('writer.simple.noFabrication') }}</p>
      </aside>
    </form>
  </div>
</template>
