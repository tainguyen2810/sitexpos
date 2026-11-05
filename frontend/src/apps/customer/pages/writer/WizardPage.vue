<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { CONTENT_TYPES, formatUsd, idempotencyKey, splitLines, usdToMicro, writerApi, type ArticleInput, type Depth, type ReviewMode, type SearchIntent, type WriterContentType, type WriterIdea } from '@/shared/api/writer'
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
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import WTextarea from './components/WTextarea.vue'
import WriterNav from './components/WriterNav.vue'
import { useWriterPerms } from './useWriter'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { workspaceId: id, canEdit } = useWriterPerms()
const { errors, formError, submitting, submit } = useApiForm()

const GROUPS = ['source', 'seo', 'style', 'research', 'control', 'confirm'] as const
type Group = (typeof GROUPS)[number]
const STEPS: Record<Group, string[]> = {
  source: ['site', 'campaign', 'idea'],
  seo: ['primary', 'secondary', 'intent'],
  style: ['audience', 'contentType', 'tone', 'language'],
  research: ['length', 'researchDepth', 'internalLinks'],
  control: ['brandRules', 'budget', 'reviewMode'],
  confirm: ['confirm'],
}
const FIELD_GROUP: Record<string, Group> = {
  site_id: 'source', campaign_id: 'source', content_idea_id: 'source', topic: 'source', title: 'source',
  primary_keyword: 'seo', secondary_keywords: 'seo', search_intent: 'seo',
  audience: 'style', content_type: 'style', tone: 'style', writing_preset_id: 'style', language: 'style', country: 'style',
  target_words: 'research', content_depth: 'research', research_depth: 'research', source_urls: 'research', internal_links: 'research',
  brand_rules: 'control', brand_domains: 'control', cta: 'control', notes: 'control', budget_limit_micro_usd: 'control', review_mode: 'control',
}
const step = ref(0)
const group = computed(() => GROUPS[step.value]!)

const options = useAsync(() => writerApi.options(id.value))
const sites = useAsync(() => siteApi.list(id.value))
const presets = useAsync(() => writerApi.presets(id.value))
const overview = useAsync(() => writerApi.overview(id.value))
const campaigns = ref<Campaign[]>([])
const ideas = ref<WriterIdea[]>([])
const ideasLoading = ref(false)

const f = reactive({
  site_id: '', campaign_id: '', source: 'topic' as 'idea' | 'topic', content_idea_id: '', topic: '', title: '',
  primary_keyword: '', secondary: '', search_intent: 'informational' as SearchIntent,
  audience: '', content_type: 'blog_article' as WriterContentType, tone: '', writing_preset_id: '', language: 'vi' as 'vi' | 'en', country: 'VN',
  target_words: '1500', content_depth: 'standard' as Depth, research_depth: 'standard' as Depth,
  source_urls: '', ai_source_discovery: true, allow_web_search: false, links_enabled: true, links_max: '5',
  brand_rules: '', brand_domains: '', cta: '', notes: '', ai_review_notes: true,
  budget_usd: '', review_mode: 'step_by_step' as ReviewMode, start: true,
})
const key = idempotencyKey() // one key per wizard session: a double submit creates one article

watch(() => options.data.value, (o) => {
  if (o && f.budget_usd === '') f.budget_usd = String(o.limits.default_article_budget_micro_usd / 1_000_000)
})
watch(() => sites.data.value, (s) => {
  if (s?.length && !f.site_id) f.site_id = String(route.query.site ?? s[0]!.id)
})
watch(() => f.site_id, async (sid) => {
  campaigns.value = []
  if (!sid) return
  const page = await researchApi.campaigns(id.value, { site_id: sid, per_page: 100, status: 'active' }).catch(() => null)
  campaigns.value = (page?.data ?? []).filter((c) => c.site_id === sid)
  const wanted = String(route.query.campaign ?? '')
  if (wanted && campaigns.value.some((c) => c.id === wanted)) f.campaign_id = wanted
})
watch(() => f.campaign_id, async (cid) => {
  ideas.value = []
  f.content_idea_id = ''
  if (!cid) {
    f.source = 'topic'
    return
  }
  ideasLoading.value = true
  ideas.value = await writerApi.ideas(id.value, cid).catch(() => [])
  ideasLoading.value = false
  if (ideas.value.some((i) => !i.article_id)) f.source = 'idea'
  const wanted = String(route.query.idea ?? '')
  if (wanted && ideas.value.some((i) => i.id === wanted && !i.article_id)) pickIdea(wanted)
})
const idea = computed(() => ideas.value.find((i) => i.id === f.content_idea_id) ?? null)
function pickIdea(ideaId: string) {
  f.source = 'idea'
  f.content_idea_id = ideaId
  const i = ideas.value.find((x) => x.id === ideaId)
  if (!i) return
  f.title = i.title
  f.primary_keyword = i.primary_keyword
  f.secondary = (i.secondary_keywords ?? []).join(', ')
  if (i.search_intent) f.search_intent = i.search_intent
  if (i.audience) f.audience = i.audience
}
watch(() => f.writing_preset_id, (pid) => {
  const p = presets.data.value?.find((x) => x.id === pid)
  if (!p) return
  if (p.tone) f.tone = p.tone
  const d = p.defaults
  if (d.content_type && (CONTENT_TYPES as readonly string[]).includes(d.content_type)) f.content_type = d.content_type as WriterContentType
  if (d.target_words) f.target_words = String(d.target_words)
  if (d.research_depth) f.research_depth = d.research_depth
  if (d.content_depth) f.content_depth = d.content_depth
  if (d.budget_limit_micro_usd) f.budget_usd = String(d.budget_limit_micro_usd / 1_000_000)
  if (p.cta && !f.cta) f.cta = p.cta
})

const estimate = useAsync(() => writerApi.estimate(id.value, Number(f.target_words) || 1500), { immediate: false })
watch(() => f.target_words, () => void estimate.run(), { immediate: true })

const siteOptions = computed(() => (sites.data.value ?? []).map((s) => ({ value: s.id, label: s.name })))
const campaignOptions = computed(() => [{ value: '', label: t('writer.wizard.noCampaign') }, ...campaigns.value.map((c) => ({ value: c.id, label: c.name }))])
const intentOptions = computed(() => (['informational', 'commercial', 'transactional', 'navigational'] as const).map((v) => ({ value: v, label: t(`writer.intent.${v}`) })))
const typeOptions = computed(() => (options.data.value?.content_types ?? CONTENT_TYPES).map((v) => ({ value: v, label: t(`writer.contentType.${v}`) })))
const depthOptions = computed(() => (['basic', 'standard', 'deep'] as const).map((v) => ({
  value: v, label: `${t(`writer.depth.${v}`)}${options.data.value ? ` — ${t('writer.wizard.maxSources', { n: options.data.value.limits.max_sources[v] })}` : ''}`,
})))
const contentDepthOptions = computed(() => (['basic', 'standard', 'deep'] as const).map((v) => ({ value: v, label: t(`writer.depth.${v}`) })))
const presetOptions = computed(() => [{ value: '', label: t('writer.wizard.noPreset') }, ...(presets.data.value ?? []).map((p) => ({ value: p.id, label: p.name + (p.is_default ? ` (${t('writer.presets.default')})` : '') }))])
const reviewOptions = computed(() => (['step_by_step', 'brief_only', 'final_only'] as const).map((v) => ({ value: v, label: t(`writer.reviewMode.${v}`) })))
const languageOptions = [{ value: 'vi', label: 'Tiếng Việt' }, { value: 'en', label: 'English' }]

const canNext = computed(() => {
  if (group.value === 'source') return !!f.site_id && (f.source === 'idea' ? !!f.content_idea_id : f.topic.trim().length >= 5)
  if (group.value === 'seo') return f.source === 'idea' || f.primary_keyword.trim().length >= 2 || f.topic.trim().length >= 5
  return true
})

function payload(): ArticleInput {
  const useIdea = f.source === 'idea' && !!f.content_idea_id
  return {
    site_id: f.site_id,
    campaign_id: f.campaign_id || null,
    content_idea_id: useIdea ? f.content_idea_id : null,
    topic: useIdea ? null : f.topic.trim(),
    title: f.title.trim() || null,
    primary_keyword: f.primary_keyword.trim() || null,
    secondary_keywords: splitLines(f.secondary).slice(0, 15),
    search_intent: f.search_intent,
    content_type: f.content_type,
    audience: f.audience.trim() || null,
    tone: f.tone.trim() || null,
    writing_preset_id: f.writing_preset_id || null,
    language: f.language,
    country: f.country.trim().toUpperCase().slice(0, 2) || 'VN',
    target_words: Number(f.target_words) || 1500,
    content_depth: f.content_depth,
    research_depth: f.research_depth,
    source_urls: splitLines(f.source_urls.replace(/\s+/g, '\n')).slice(0, 20),
    ai_source_discovery: f.ai_source_discovery,
    allow_web_search: f.allow_web_search,
    internal_links: { enabled: f.links_enabled, max: Math.max(0, Math.min(15, Number(f.links_max) || 0)) },
    brand_rules: f.brand_rules.split('\n').map((s) => s.trim()).filter(Boolean).slice(0, 20),
    brand_domains: splitLines(f.brand_domains).slice(0, 10),
    cta: f.cta.trim() || null,
    notes: f.notes.trim() || null,
    ai_review_notes: f.ai_review_notes,
    budget_limit_micro_usd: usdToMicro(Number(f.budget_usd) || 0),
    review_mode: f.review_mode,
    start: f.start,
  }
}

async function create() {
  const a = await submit(() => writerApi.create(id.value, payload(), key))
  if (a) {
    void router.push({ name: 'writer-article', params: { workspaceId: id.value, articleId: a.id } })
    return
  }
  // Jump back to the first step that has a field error.
  const first = Object.keys(errors).find((k) => errors[k])
  const g = first ? FIELD_GROUP[first.split('.')[0]!] : undefined
  if (g) step.value = GROUPS.indexOf(g)
}
const fieldErr = (k: string) => errors[k] ?? Object.entries(errors).find(([key, v]) => v && key.startsWith(`${k}.`))?.[1]
</script>

<template>
  <div>
    <PageHeader :title="t('writer.wizard.title')" :crumbs="[{ label: t('writer.title'), to: `/w/${id}/writer` }, { label: t('writer.nav.wizard') }]" />
    <WriterNav active="wizard" />

    <EmptyState v-if="!canEdit" icon="lock" :title="t('errors.forbidden')" />
    <LoadingState v-else-if="options.loading.value || sites.loading.value" />
    <ErrorState v-else-if="options.error.value || sites.error.value" :error="options.error.value ?? sites.error.value" @retry="() => { options.run(); sites.run() }" />
    <EmptyState v-else-if="!sites.data.value?.length" icon="globe" :title="t('research.campaigns.needSite')">
      <RouterLink :to="{ name: 'sites', params: { workspaceId: id } }" class="text-sm font-medium text-brand-600 underline">{{ t('nav.sites') }}</RouterLink>
    </EmptyState>

    <div v-else class="grid gap-6 lg:grid-cols-[14rem_1fr]">
      <ol class="flex gap-2 overflow-x-auto lg:block lg:space-y-1" :aria-label="t('writer.wizard.title')">
        <li v-for="(g, i) in GROUPS" :key="g">
          <button
            type="button"
            class="w-full whitespace-nowrap rounded-lg px-3 py-2 text-left text-sm"
            :class="i === step ? 'bg-brand-50 font-medium text-brand-700' : i < step ? 'text-ink' : 'text-ink-muted'"
            :aria-current="i === step ? 'step' : undefined"
            :disabled="i > step && !canNext"
            @click="step = i"
          >
            {{ i + 1 }}. {{ t(`writer.wizard.groups.${g}`) }}
          </button>
          <ul class="hidden pl-6 text-xs text-ink-muted lg:block">
            <li v-for="s in STEPS[g]" :key="s">{{ t(`writer.wizard.steps.${s}`) }}</li>
          </ul>
        </li>
      </ol>

      <AppCard>
        <p class="mb-1 text-xs text-ink-muted">{{ t('writer.wizard.stepOf', { n: step + 1, total: GROUPS.length }) }}</p>
        <ProgressBar class="mb-5" :value="step + 1" :max="GROUPS.length" />
        <p v-if="formError" class="mb-4 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ formError }}</p>

        <form id="wizard" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="step < GROUPS.length - 1 ? step++ : create()">
          <!-- 1–3 -->
          <template v-if="group === 'source'">
            <AppSelect v-model="f.site_id" :label="t('writer.wizard.steps.site')" :options="siteOptions" :error="fieldErr('site_id')" />
            <AppSelect v-model="f.campaign_id" :label="t('writer.wizard.steps.campaign')" :options="campaignOptions" :error="fieldErr('campaign_id')" />
            <fieldset class="sm:col-span-2">
              <legend class="mb-2 text-sm font-medium">{{ t('writer.wizard.steps.idea') }}</legend>
              <div class="mb-3 flex gap-2">
                <AppButton size="sm" :variant="f.source === 'idea' ? 'primary' : 'secondary'" :disabled="!f.campaign_id" @click="f.source = 'idea'">{{ t('writer.wizard.useIdea') }}</AppButton>
                <AppButton size="sm" :variant="f.source === 'topic' ? 'primary' : 'secondary'" @click="f.source = 'topic'">{{ t('writer.wizard.useTopic') }}</AppButton>
              </div>
              <template v-if="f.source === 'idea' && f.campaign_id">
                <LoadingState v-if="ideasLoading" />
                <p v-else-if="!ideas.length" class="text-sm text-ink-muted">{{ t('writer.wizard.noIdeas') }}</p>
                <ul v-else class="max-h-80 divide-y divide-line overflow-y-auto rounded-lg border border-line" data-testid="idea-list">
                  <li v-for="i in ideas" :key="i.id">
                    <label class="flex cursor-pointer items-start gap-3 px-3 py-2 text-sm" :class="i.article_id ? 'opacity-60' : 'hover:bg-surface-muted'">
                      <input type="radio" name="idea" class="mt-1" :value="i.id" :checked="f.content_idea_id === i.id" :disabled="!!i.article_id" @change="pickIdea(i.id)" />
                      <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ i.title }}</span>
                        <span class="block text-xs text-ink-muted">{{ i.primary_keyword }}</span>
                      </span>
                      <AppBadge v-if="i.article_id" tone="neutral">{{ t('writer.wizard.ideaTaken') }}</AppBadge>
                    </label>
                  </li>
                </ul>
                <p v-if="fieldErr('content_idea_id')" class="mt-1 text-xs text-danger-600">{{ fieldErr('content_idea_id') }}</p>
              </template>
              <template v-else>
                <AppInput v-model="f.topic" :label="t('writer.wizard.topic')" :placeholder="t('writer.wizard.topicHint')" :error="fieldErr('topic')" />
              </template>
            </fieldset>
            <AppInput v-model="f.title" class="sm:col-span-2" :label="t('writer.wizard.titleOptional')" :hint="t('common.optional')" :error="fieldErr('title')" />
          </template>

          <!-- 4–6 -->
          <template v-if="group === 'seo'">
            <AppInput v-model="f.primary_keyword" :label="t('writer.wizard.steps.primary')" :error="fieldErr('primary_keyword')" />
            <AppSelect v-model="f.search_intent" :label="t('writer.wizard.steps.intent')" :options="intentOptions" :error="fieldErr('search_intent')" />
            <WTextarea v-model="f.secondary" class="sm:col-span-2" :label="t('writer.wizard.steps.secondary')" :hint="t('writer.wizard.secondaryHint')" :error="fieldErr('secondary_keywords')" />
          </template>

          <!-- 7–10 -->
          <template v-if="group === 'style'">
            <AppInput v-model="f.audience" class="sm:col-span-2" :label="t('writer.wizard.steps.audience')" :error="fieldErr('audience')" />
            <AppSelect v-model="f.content_type" :label="t('writer.wizard.steps.contentType')" :options="typeOptions" :error="fieldErr('content_type')" />
            <AppSelect v-model="f.writing_preset_id" :label="t('writer.wizard.preset')" :options="presetOptions" :error="fieldErr('writing_preset_id')" />
            <AppInput v-model="f.tone" class="sm:col-span-2" :label="t('writer.wizard.steps.tone')" :placeholder="t('writer.wizard.tonePlaceholder')" :error="fieldErr('tone')" />
            <AppSelect v-model="f.language" :label="t('writer.wizard.steps.language')" :options="languageOptions" :error="fieldErr('language')" />
            <AppInput v-model="f.country" :label="t('writer.wizard.country')" :error="fieldErr('country')" />
          </template>

          <!-- 11–13 -->
          <template v-if="group === 'research'">
            <AppInput v-model="f.target_words" type="number" :label="t('writer.wizard.targetWords')" :error="fieldErr('target_words')" />
            <AppSelect v-model="f.content_depth" :label="t('writer.wizard.contentDepth')" :options="contentDepthOptions" :error="fieldErr('content_depth')" />
            <AppSelect v-model="f.research_depth" class="sm:col-span-2" :label="t('writer.wizard.steps.researchDepth')" :options="depthOptions" :error="fieldErr('research_depth')" />
            <WTextarea v-model="f.source_urls" class="sm:col-span-2" :rows="4" :label="t('writer.wizard.sourceUrls')" :hint="t('writer.wizard.sourceUrlsHint')" :error="fieldErr('source_urls')" placeholder="https://" />
            <div class="sm:col-span-2">
              <Toggle v-model="f.ai_source_discovery" :label="t('writer.wizard.aiDiscovery')" :description="t('writer.wizard.aiDiscoveryHint')" />
              <Toggle v-model="f.allow_web_search" :label="t('writer.wizard.webSearch')" :description="t('writer.wizard.webSearchHint')" />
              <Toggle v-model="f.links_enabled" :label="t('writer.wizard.steps.internalLinks')" :description="t('writer.wizard.linksEnabled')" />
            </div>
            <AppInput v-if="f.links_enabled" v-model="f.links_max" type="number" :label="t('writer.wizard.linksMax')" :error="fieldErr('internal_links')" />
          </template>

          <!-- 14–16 -->
          <template v-if="group === 'control'">
            <WTextarea v-model="f.brand_rules" class="sm:col-span-2" :label="t('writer.wizard.steps.brandRules')" :hint="t('writer.wizard.brandRulesHint')" :error="fieldErr('brand_rules')" />
            <AppInput v-model="f.brand_domains" :label="t('writer.wizard.brandDomains')" :hint="t('common.optional')" :error="fieldErr('brand_domains')" />
            <AppInput v-model="f.cta" :label="t('writer.wizard.cta')" :hint="t('common.optional')" :error="fieldErr('cta')" />
            <WTextarea v-model="f.notes" class="sm:col-span-2" :label="t('writer.wizard.notes')" :hint="t('common.optional')" :error="fieldErr('notes')" />
            <AppInput
v-model="f.budget_usd" type="number" :label="t('writer.wizard.budget')" :error="fieldErr('budget_limit_micro_usd')"
              :hint="options.data.value ? t('writer.wizard.budgetHint', { max: formatUsd(options.data.value.limits.max_article_budget_micro_usd) }) : undefined" />
            <AppSelect v-model="f.review_mode" :label="t('writer.wizard.steps.reviewMode')" :options="reviewOptions" :error="fieldErr('review_mode')" />
            <div class="sm:col-span-2"><Toggle v-model="f.ai_review_notes" :label="t('writer.wizard.aiReviewNotes')" /></div>
          </template>

          <!-- 17 -->
          <template v-if="group === 'confirm'">
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:col-span-2 sm:grid-cols-2" data-testid="wizard-summary">
              <div><dt class="text-ink-muted">{{ t('writer.wizard.steps.site') }}</dt><dd>{{ siteOptions.find((s) => s.value === f.site_id)?.label }}</dd></div>
              <div><dt class="text-ink-muted">{{ t('writer.wizard.steps.idea') }}</dt><dd>{{ idea?.title ?? f.topic }}</dd></div>
              <div><dt class="text-ink-muted">{{ t('writer.wizard.steps.primary') }}</dt><dd>{{ f.primary_keyword || idea?.primary_keyword || '—' }}</dd></div>
              <div><dt class="text-ink-muted">{{ t('writer.wizard.steps.contentType') }}</dt><dd>{{ t(`writer.contentType.${f.content_type}`) }}</dd></div>
              <div><dt class="text-ink-muted">{{ t('writer.wizard.steps.researchDepth') }}</dt><dd>{{ t(`writer.depth.${f.research_depth}`) }}</dd></div>
              <div><dt class="text-ink-muted">{{ t('writer.wizard.steps.reviewMode') }}</dt><dd>{{ t(`writer.reviewMode.${f.review_mode}`) }}</dd></div>
              <div><dt class="text-ink-muted">{{ t('writer.wizard.steps.budget') }}</dt><dd>{{ formatUsd(usdToMicro(Number(f.budget_usd) || 0)) }}</dd></div>
              <div v-if="estimate.data.value"><dt class="text-ink-muted">{{ t('writer.wizard.estimate', { cost: formatUsd(estimate.data.value.estimated_cost_micro_usd) }) }}</dt><dd class="text-xs text-ink-muted">{{ estimate.data.value.note }}</dd></div>
            </dl>
            <p v-if="overview.data.value" class="text-sm sm:col-span-2" :class="overview.data.value.quota.remaining > 0 ? 'text-ink-muted' : 'text-danger-600'">
              {{ t('writer.wizard.quota', { remaining: overview.data.value.quota.remaining, limit: overview.data.value.quota.limit }) }}
            </p>
            <p class="text-sm text-ink-muted sm:col-span-2">{{ t('writer.wizard.duplicate') }}</p>
            <div class="sm:col-span-2"><Toggle v-model="f.start" :label="t('writer.wizard.startNow')" /></div>
          </template>
        </form>

        <div class="mt-6 flex justify-between gap-2 border-t border-line pt-4">
          <AppButton variant="secondary" :disabled="step === 0" @click="step--">{{ t('common.back') }}</AppButton>
          <AppButton v-if="step < GROUPS.length - 1" :disabled="!canNext" data-testid="wizard-next" @click="step++">{{ t('common.next') }}</AppButton>
          <AppButton v-else type="submit" form="wizard" :loading="submitting" :disabled="!canNext" data-testid="wizard-create">{{ t('writer.wizard.create') }}</AppButton>
        </div>
      </AppCard>
    </div>
  </div>
</template>
