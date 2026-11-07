<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { STYLE_ORIGINS, STYLE_ORIGIN_ICONS, writerApi, type Preset, type StyleOrigin, type StyleProfile } from '@/shared/api/writer'
import { siteApi, waitForJob, type Site, type SiteAuthor, type SiteContentItem, type SiteContentQuery, type SiteTaxonomy } from '@/shared/api/sites'
import { ApiError } from '@/shared/api/client'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import SearchInput from '@/shared/ui/SearchInput.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import WTextarea from './WTextarea.vue'

/**
 * "Dạy AI văn phong mới" in three steps (v1.0.9):
 * 1. where to learn from (one post, a category, an author, the whole blog, products, pasted text);
 * 2. which posts (picked automatically, the user can untick);
 * 3. the AI's plain-language summary → save (only then is it used).
 * With no `preset`, a new style (preset) is created on start.
 */
const MAX = 20
const PICK: Record<StyleOrigin, number> = { post: 1, category: 10, author: 10, blog: 15, product: 10, paste: 10 }
const MIN_PASTE = 300

const props = defineProps<{ open: boolean; workspaceId: string; preset: Preset | null; sites: Site[] }>()
const emit = defineEmits<{ close: []; done: [presetId: string]; edit: [presetId: string] }>()
const { t } = useI18n()

const step = ref<1 | 2 | 3>(1)
const origin = ref<StyleOrigin>('blog')
const siteId = ref('')
const q = ref('')
const items = ref<SiteContentItem[]>([])
const loading = ref(false)
const selected = ref<string[]>([])
const categories = ref<SiteTaxonomy[]>([])
const authors = ref<SiteAuthor[]>([])
const termId = ref('')
const authorId = ref('')
const samples = reactive<{ title: string; text: string }[]>([])
const name = ref('')
const nameTouched = ref(false)
const running = ref(false)
const message = ref<{ tone: 'info' | 'error'; text: string } | null>(null)
const target = ref<Preset | null>(null) // the preset being taught (created on first start when new)
const makeDefault = ref(false)
const saving = ref(false)

const siteOptions = computed(() => props.sites.map((s) => ({ value: s.id, label: s.name })))
const pasted = computed(() => samples.filter((s) => s.text.trim().length >= MIN_PASTE))
const total = computed(() => (origin.value === 'paste' ? pasted.value.length : selected.value.length))
const canStart = computed(() => total.value >= 1 && total.value <= MAX && !running.value && name.value.trim().length >= 2)
const draft = computed<StyleProfile | null>(() => target.value?.style_draft ?? null)
const usedCount = computed(() => target.value?.style_sources?.used?.length ?? 0)
const skippedCount = computed(() => target.value?.style_sources?.skipped?.length ?? 0)
const categoryOptions = computed(() => [{ value: '', label: t('writer.styles.learn.pickCategory') }, ...categories.value.map((c) => ({ value: String(c.wp_term_id), label: `${c.name} — ${t('writer.styles.samples', { n: c.count })}` }))])
const authorOptions = computed(() => [{ value: '', label: t('writer.styles.learn.pickAuthor') }, ...authors.value.map((a) => ({ value: String(a.wp_author_id), label: `${a.name} — ${t('writer.styles.samples', { n: a.posts })}` }))])
const sourceLabel = computed(() => {
  if (origin.value === 'category') return categories.value.find((c) => String(c.wp_term_id) === termId.value)?.name ?? ''
  if (origin.value === 'author') return authors.value.find((a) => String(a.wp_author_id) === authorId.value)?.name ?? ''
  if (origin.value === 'post') return items.value.find((i) => i.id === selected.value[0])?.title ?? ''
  return props.sites.find((s) => s.id === siteId.value)?.name ?? ''
})

watch(() => props.open, (o) => {
  if (!o) return
  step.value = 1
  origin.value = 'blog'
  target.value = props.preset
  siteId.value = props.preset?.site_id ?? props.sites[0]?.id ?? ''
  name.value = props.preset?.name ?? ''
  nameTouched.value = !!props.preset
  makeDefault.value = props.preset?.is_default ?? false
  samples.splice(0, samples.length, { title: '', text: '' })
  message.value = null
  reset()
})
watch(sourceLabel, (l) => {
  if (!nameTouched.value) name.value = l ? t('writer.styles.learn.defaultName', { name: l }).slice(0, 120) : ''
})

function reset() {
  items.value = []
  selected.value = []
  termId.value = ''
  authorId.value = ''
  q.value = ''
}
function chooseOrigin(o: StyleOrigin) {
  origin.value = o
}
async function next() {
  reset()
  message.value = null
  step.value = 2
  if (!siteId.value) return
  if (origin.value === 'category') {
    categories.value = await siteApi.taxonomies(props.workspaceId, siteId.value).then((r) => r.filter((c) => c.taxonomy === 'category' && c.count > 0)).catch(() => [])
  } else if (origin.value === 'author') {
    authors.value = await siteApi.authors(props.workspaceId, siteId.value).catch(() => [])
  } else if (origin.value !== 'paste') {
    void load()
  }
}
watch(siteId, () => {
  if (step.value === 2) void next()
})
watch(termId, () => void load())
watch(authorId, () => void load())
let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(() => void load(), 300)
})

async function load() {
  if (!siteId.value || step.value !== 2 || origin.value === 'paste') return
  if (origin.value === 'category' && !termId.value) return
  if (origin.value === 'author' && !authorId.value) return
  const query: SiteContentQuery = { 'filter[post_type]': origin.value === 'product' ? 'product' : 'post', 'filter[status]': 'publish', per_page: MAX, q: q.value.trim() || undefined }
  if (origin.value === 'category') query['filter[term]'] = Number(termId.value)
  if (origin.value === 'author') query['filter[author]'] = Number(authorId.value)
  loading.value = true
  try {
    const r = await siteApi.content(props.workspaceId, siteId.value, query)
    items.value = r.data.filter((p) => p.url)
    // Picked automatically (newest first); a single post is chosen by the user.
    selected.value = origin.value === 'post' ? [] : items.value.slice(0, PICK[origin.value]).map((p) => p.id)
  } catch {
    items.value = []
  } finally {
    loading.value = false
  }
}
function toggle(itemId: string) {
  if (origin.value === 'post') {
    selected.value = [itemId]
    return
  }
  const i = selected.value.indexOf(itemId)
  if (i >= 0) selected.value.splice(i, 1)
  else if (selected.value.length < MAX) selected.value.push(itemId)
}

async function start() {
  if (!canStart.value) return
  running.value = true
  message.value = { tone: 'info', text: t('writer.presets.learn.running') }
  try {
    if (!target.value) target.value = await writerApi.createPreset(props.workspaceId, { name: name.value.trim(), site_id: siteId.value || null })
    else if (name.value.trim() !== target.value.name) target.value = await writerApi.updatePreset(props.workspaceId, target.value.id, { name: name.value.trim() })
    const preset = target.value
    const isPaste = origin.value === 'paste'
    const job = await writerApi.learnStyle(props.workspaceId, preset.id, {
      site_id: isPaste ? null : siteId.value || null,
      content_item_ids: isPaste ? [] : selected.value,
      samples: isPaste ? pasted.value.map((s) => ({ title: s.title.trim() || null, text: s.text.trim() })) : [],
      origin: { type: origin.value, label: isPaste ? null : sourceLabel.value || null },
    })
    const run = await waitForJob(() => siteApi.jobRun(props.workspaceId, job.id), { intervalMs: 2000, timeoutMs: 180000 })
    target.value = await writerApi.preset(props.workspaceId, preset.id)
    if (run.status === 'succeeded' && target.value.style_draft) {
      message.value = null
      step.value = 3
    } else if (run.status === 'failed') {
      message.value = { tone: 'error', text: t('writer.presets.learn.failed', { msg: run.error?.message ?? run.error?.code ?? '' }) }
    } else {
      message.value = { tone: 'info', text: t('writer.presets.learn.stillRunning') }
    }
  } catch (e) {
    message.value = { tone: 'error', text: e instanceof ApiError ? e.message : t('writer.presets.learn.failed', { msg: '' }) }
  } finally {
    running.value = false
  }
}

async function save() {
  const p = target.value
  if (!p?.style_draft) return
  saving.value = true
  try {
    const profile: StyleProfile = {}
    for (const [k, v] of Object.entries(p.style_draft)) {
      if (!['generated_at', 'model', 'cost_micro_usd', 'sample_count'].includes(k)) (profile as Record<string, unknown>)[k] = v
    }
    await writerApi.updatePreset(props.workspaceId, p.id, { style_profile: profile, is_default: makeDefault.value })
    emit('done', p.id)
  } catch (e) {
    message.value = { tone: 'error', text: e instanceof ApiError ? e.message : t('writer.presets.learn.failed', { msg: '' }) }
  } finally {
    saving.value = false
  }
}
function again() {
  step.value = 1
  message.value = null
}
const list = (v: unknown): string[] => (Array.isArray(v) ? v.map(String).filter(Boolean) : [])
const stepClass = (n: number) => (step.value === n ? 'bg-brand-50 font-semibold text-brand-700' : step.value > n ? 'bg-surface-muted text-success-600' : 'bg-surface-muted text-ink-muted')
</script>

<template>
  <AppModal :open="open" :title="preset ? t('writer.styles.learn.titleAgain', { name: preset.name }) : t('writer.styles.teach')" width="lg" @close="emit('close')">
    <div class="space-y-4" data-testid="learn-style">
      <ol class="grid grid-cols-3 gap-2 text-xs sm:text-sm">
        <li :class="['rounded-lg px-3 py-2', stepClass(1)]">{{ step > 1 ? `✓ ${t(`writer.styles.origin.${origin}`)}` : `1. ${t('writer.styles.learn.step1')}` }}</li>
        <li :class="['rounded-lg px-3 py-2', stepClass(2)]">{{ step > 2 ? `✓ ${t('writer.styles.samples', { n: usedCount || total })}` : `2. ${t('writer.styles.learn.step2')}` }}</li>
        <li :class="['rounded-lg px-3 py-2', stepClass(3)]">3. {{ t('writer.styles.learn.step3') }}</li>
      </ol>
      <p v-if="message" :class="['rounded-lg px-3 py-2 text-sm', message.tone === 'error' ? 'bg-danger-50 text-danger-600' : 'bg-brand-50 text-brand-700']" role="status">{{ message.text }}</p>

      <!-- Step 1: where from -->
      <template v-if="step === 1">
        <p class="text-sm text-ink-muted">{{ t('writer.styles.learn.ownOnly') }}</p>
        <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-3" role="radiogroup" :aria-label="t('writer.styles.learn.step1')">
          <button
            v-for="o in STYLE_ORIGINS" :key="o" type="button" role="radio" :aria-checked="origin === o" :data-testid="`origin-${o}`"
            :class="['flex flex-col rounded-xl border-[1.5px] p-3 text-left', origin === o ? 'border-brand-600 bg-brand-50 ring-4 ring-brand-100' : 'border-line hover:border-brand-300']"
            @click="chooseOrigin(o)"
          >
            <span class="text-xl leading-none" aria-hidden="true">{{ STYLE_ORIGIN_ICONS[o] }}</span>
            <span class="mt-1.5 text-sm font-semibold">{{ t(`writer.styles.origin.${o}`) }}</span>
            <span class="text-xs text-ink-muted">{{ t(`writer.styles.originHelp.${o}`) }}</span>
          </button>
        </div>
      </template>

      <!-- Step 2: which posts -->
      <template v-else-if="step === 2">
        <AppSelect v-if="origin !== 'paste' && sites.length > 1" v-model="siteId" :label="t('writer.presets.learn.site')" :options="siteOptions" />
        <p v-if="origin !== 'paste' && !sites.length" class="text-sm text-ink-muted">{{ t('writer.presets.learn.noPosts') }}</p>

        <AppSelect v-if="origin === 'category'" v-model="termId" :label="t('writer.styles.learn.whichCategory')" :options="categoryOptions" data-testid="learn-category" />
        <template v-if="origin === 'author'">
          <AppSelect v-if="authors.length" v-model="authorId" :label="t('writer.styles.learn.whichAuthor')" :options="authorOptions" data-testid="learn-author" />
          <p v-else class="rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-700" data-testid="no-authors">{{ t('writer.styles.learn.noAuthors') }}</p>
        </template>
        <SearchInput v-if="origin === 'post' || origin === 'blog' || origin === 'product'" v-model="q" />

        <template v-if="origin !== 'paste'">
          <LoadingState v-if="loading" />
          <template v-else-if="items.length">
            <div class="flex items-center justify-between text-sm">
              <span class="font-medium">{{ origin === 'post' ? t('writer.styles.learn.pickOne') : t('writer.styles.learn.willRead', { n: selected.length }) }}</span>
              <span v-if="origin !== 'post'" class="text-xs text-ink-muted">{{ t('writer.styles.learn.untick') }}</span>
            </div>
            <ul class="max-h-64 divide-y divide-line overflow-y-auto rounded-lg border border-line" data-testid="learn-posts">
              <li v-for="p in items" :key="p.id">
                <label class="flex cursor-pointer items-center gap-2.5 px-3 py-2 text-sm hover:bg-surface-muted">
                  <input :type="origin === 'post' ? 'radio' : 'checkbox'" name="learn-item" :checked="selected.includes(p.id)" @change="toggle(p.id)" />
                  <span class="min-w-0 flex-1 truncate">{{ p.title }}</span>
                  <span v-if="p.published_at" class="text-xs text-ink-muted">{{ new Date(p.published_at).toLocaleDateString('vi-VN') }}</span>
                </label>
              </li>
            </ul>
          </template>
          <p v-else-if="(origin !== 'category' || termId) && (origin !== 'author' || authorId) && (origin !== 'author' || authors.length)" class="text-sm text-ink-muted">{{ t('writer.presets.learn.noPosts') }}</p>
        </template>

        <template v-else>
          <div v-for="(s, i) in samples" :key="i" class="space-y-2 rounded-lg border border-line p-3">
            <div class="flex gap-2">
              <AppInput v-model="s.title" class="flex-1" :placeholder="t('writer.presets.learn.pasteTitle')" />
              <AppButton v-if="samples.length > 1" size="sm" variant="ghost" :aria-label="t('common.delete')" @click="samples.splice(i, 1)">✕</AppButton>
            </div>
            <WTextarea v-model="s.text" :rows="5" :placeholder="t('writer.presets.learn.pasteText')" />
          </div>
          <AppButton v-if="samples.length < 10" size="sm" variant="ghost" @click="samples.push({ title: '', text: '' })">+ {{ t('writer.presets.learn.addSample') }}</AppButton>
        </template>

        <AppInput v-model="name" :label="t('writer.styles.learn.name')" :placeholder="t('writer.styles.learn.namePh')" data-testid="learn-name" @update:model-value="nameTouched = true" />
      </template>

      <!-- Step 3: result -->
      <template v-else-if="draft">
        <p class="rounded-lg bg-success-50 px-3 py-2 text-sm text-success-700">
          {{ t('writer.styles.learn.result', { n: usedCount }) }}<template v-if="skippedCount"> {{ t('writer.presets.learnedStyle.skipped', { n: skippedCount }) }}.</template>
        </p>
        <dl class="divide-y divide-line text-sm" data-testid="learn-result">
          <div v-if="draft.summary" class="grid grid-cols-1 gap-1 py-2.5 sm:grid-cols-[9rem_1fr]"><dt class="text-ink-muted">{{ t('writer.styles.trait.summary') }}</dt><dd>{{ draft.summary }}</dd></div>
          <div v-if="draft.addressing" class="grid grid-cols-1 gap-1 py-2.5 sm:grid-cols-[9rem_1fr]"><dt class="text-ink-muted">{{ t('writer.styles.trait.addressing') }}</dt><dd>{{ draft.addressing }}</dd></div>
          <div v-if="draft.sentence_style || draft.paragraph_style" class="grid grid-cols-1 gap-1 py-2.5 sm:grid-cols-[9rem_1fr]"><dt class="text-ink-muted">{{ t('writer.styles.trait.sentences') }}</dt><dd>{{ [draft.sentence_style, draft.paragraph_style].filter(Boolean).join(' ') }}</dd></div>
          <div v-if="draft.intro_pattern" class="grid grid-cols-1 gap-1 py-2.5 sm:grid-cols-[9rem_1fr]"><dt class="text-ink-muted">{{ t('writer.styles.trait.intro') }}</dt><dd>{{ draft.intro_pattern }}</dd></div>
          <div v-if="draft.conclusion_pattern" class="grid grid-cols-1 gap-1 py-2.5 sm:grid-cols-[9rem_1fr]"><dt class="text-ink-muted">{{ t('writer.styles.trait.outro') }}</dt><dd>{{ draft.conclusion_pattern }}</dd></div>
          <div v-if="list(draft.signature_phrases).length || list(draft.vocabulary).length" class="grid grid-cols-1 gap-1 py-2.5 sm:grid-cols-[9rem_1fr]">
            <dt class="text-ink-muted">{{ t('writer.styles.trait.uses') }}</dt>
            <dd class="flex flex-wrap gap-1.5"><span v-for="w in [...list(draft.signature_phrases), ...list(draft.vocabulary)].slice(0, 8)" :key="w" class="rounded-full bg-surface-muted px-2 py-0.5 text-xs">{{ w }}</span></dd>
          </div>
          <div v-if="list(draft.avoid).length || list(draft.donts).length" class="grid grid-cols-1 gap-1 py-2.5 sm:grid-cols-[9rem_1fr]">
            <dt class="text-ink-muted">{{ t('writer.styles.trait.avoid') }}</dt>
            <dd class="flex flex-wrap gap-1.5"><span v-for="w in [...list(draft.avoid), ...list(draft.donts)].slice(0, 8)" :key="w" class="rounded-full bg-surface-muted px-2 py-0.5 text-xs">{{ w }}</span></dd>
          </div>
        </dl>
        <Toggle v-model="makeDefault" :label="t('writer.styles.learn.makeDefault')" :description="t('writer.styles.learn.makeDefaultHelp')" />
      </template>
    </div>

    <template #footer>
      <template v-if="step === 1">
        <AppButton variant="ghost" @click="emit('close')">{{ t('common.cancel') }}</AppButton>
        <AppButton data-testid="learn-next" @click="next">{{ t('common.next') }} →</AppButton>
      </template>
      <template v-else-if="step === 2">
        <AppButton variant="ghost" class="mr-auto" :disabled="running" @click="again">← {{ t('common.back') }}</AppButton>
        <AppButton :disabled="!canStart" :loading="running" data-testid="learn-start" @click="start">✨ {{ t('writer.styles.learn.start') }}</AppButton>
      </template>
      <template v-else>
        <AppButton variant="ghost" class="mr-auto" @click="again">{{ t('writer.styles.learn.again') }}</AppButton>
        <AppButton v-if="target" variant="secondary" @click="emit('edit', target.id)">✏️ {{ t('writer.styles.learn.editDetails') }}</AppButton>
        <AppButton :loading="saving" data-testid="learn-save" @click="save">{{ t('writer.styles.learn.save') }}</AppButton>
      </template>
    </template>
  </AppModal>
</template>
