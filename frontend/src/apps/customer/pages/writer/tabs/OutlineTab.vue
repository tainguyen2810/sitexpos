<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { writerApi, type OutlineSection } from '@/shared/api/writer'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useArticle } from '../articleContext'
import { useWriterErrors } from '../useWriter'

const BLOCKS = ['paragraphs', 'list', 'table', 'faq', 'comparison', 'callout', 'specs', 'steps'] as const
const { t } = useI18n()
const { workspaceId: ws, articleId: id, article, reload, busy, canEdit } = useArticle()
const { toast } = useWriterErrors()
const { formError, submitting, submit } = useApiForm()
const outline = useAsync(() => writerApi.outline(ws, id))
watch(() => article.value?.status, () => void outline.run())

const sections = ref<OutlineSection[]>([])
let original = ''
watch(() => outline.data.value?.current, (o) => {
  sections.value = o ? (JSON.parse(JSON.stringify(o.sections)) as OutlineSection[]) : []
  original = JSON.stringify(sections.value)
}, { immediate: true })
const current = computed(() => outline.data.value?.current ?? null)
const editable = computed(() => canEdit.value && !busy.value && article.value?.status === 'OUTLINING' && current.value?.status !== 'approved')
const dirty = computed(() => JSON.stringify(sections.value) !== original)

function move(i: number, d: -1 | 1) {
  const j = i + d
  if (j < 0 || j >= sections.value.length || sections.value[j]!.key === 'intro' || sections.value[i]!.key === 'intro') return
  const s = [...sections.value]
  ;[s[i], s[j]] = [s[j]!, s[i]!]
  sections.value = s
}
function add() {
  sections.value.push({ key: `new${sections.value.length + 1}`, heading: '', purpose: '', key_questions: [], required_facts: [], evidence_refs: [], word_range: [120, 240], internal_links: [], blocks: ['paragraphs'], subheadings: [] })
}
function toggleBlock(s: OutlineSection, b: string) {
  s.blocks = s.blocks.includes(b) ? s.blocks.filter((x) => x !== b) : [...s.blocks, b]
}
const subs = (s: OutlineSection) => s.subheadings.join('\n')
const setSubs = (s: OutlineSection, v: string) => (s.subheadings = v.split('\n').map((x) => x.trim()).filter(Boolean).slice(0, 10))

async function approve() {
  const r = await submit(() => writerApi.approveOutline(ws, id, dirty.value ? sections.value : undefined))
  if (r) {
    toast.success(t('writer.brief.approved'))
    await Promise.all([reload(), outline.run()])
  }
}
</script>

<template>
  <LoadingState v-if="outline.loading.value && !outline.data.value" />
  <ErrorState v-else-if="outline.error.value" :error="outline.error.value" @retry="outline.run" />
  <EmptyState v-else-if="!current" icon="list" :title="t('writer.outline.empty')" :description="t('writer.outline.emptyBody')" />
  <AppCard v-else>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <h3 class="text-sm font-semibold">{{ t('writer.brief.version', { n: current.version }) }}</h3>
      <AppBadge :tone="current.status === 'approved' ? 'success' : 'warning'">{{ current.status === 'approved' ? t('writer.brief.approved') : current.status }}</AppBadge>
      <span v-for="w in current.warnings" :key="w" class="text-xs text-warning-600">{{ w }}</span>
    </div>
    <p v-if="!editable && current.status === 'approved'" class="mb-4 text-sm text-ink-muted">{{ t('writer.outline.readonly') }}</p>
    <p v-if="formError" class="mb-4 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ formError }}</p>

    <ol class="space-y-3" data-testid="outline-sections">
      <li v-for="(s, i) in sections" :key="s.key + i" class="rounded-lg border border-line p-3">
        <div class="flex flex-wrap items-center gap-2">
          <span class="rounded bg-surface-muted px-1.5 font-mono text-xs">{{ s.key === 'intro' ? t('writer.outline.intro') : `H2 · ${i}` }}</span>
          <AppInput v-if="editable && s.key !== 'intro'" v-model="s.heading" class="min-w-0 flex-1" :placeholder="t('writer.outline.heading')" />
          <span v-else class="flex-1 font-medium">{{ s.heading || t('writer.outline.intro') }}</span>
          <template v-if="editable && s.key !== 'intro'">
            <AppButton size="sm" variant="ghost" :aria-label="t('writer.outline.moveUp')" @click="move(i, -1)">↑</AppButton>
            <AppButton size="sm" variant="ghost" :aria-label="t('writer.outline.moveDown')" @click="move(i, 1)">↓</AppButton>
            <AppButton size="sm" variant="ghost" :aria-label="t('writer.outline.remove')" @click="sections.splice(i, 1)">✕</AppButton>
          </template>
        </div>
        <div class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
          <AppInput v-if="editable" v-model="s.purpose" :label="t('writer.outline.purpose')" />
          <p v-else-if="s.purpose" class="text-ink-muted">{{ s.purpose }}</p>
          <div class="flex items-end gap-2">
            <template v-if="editable">
              <AppInput :model-value="String(s.word_range[0])" type="number" :label="t('writer.outline.words')" @update:model-value="(v) => (s.word_range = [Number(v) || 40, Math.max(Number(v) || 40, s.word_range[1])])" />
              <AppInput :model-value="String(s.word_range[1])" type="number" @update:model-value="(v) => (s.word_range = [s.word_range[0], Number(v) || s.word_range[0]])" />
            </template>
            <span v-else class="text-xs text-ink-muted">{{ t('writer.outline.words') }}: {{ s.word_range[0] }}–{{ s.word_range[1] }}</span>
          </div>
        </div>
        <div class="mt-2 flex flex-wrap gap-1">
          <button
v-for="b in BLOCKS" :key="b" type="button" class="rounded-full border px-2 py-0.5 text-xs" :disabled="!editable"
            :class="s.blocks.includes(b) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-line text-ink-muted'" :aria-pressed="s.blocks.includes(b)" @click="toggleBlock(s, b)">
            {{ t(`writer.outline.block.${b}`) }}
          </button>
        </div>
        <div v-if="s.required_facts.length" class="mt-2 text-xs">
          <p class="font-medium text-ink-muted">{{ t('writer.outline.facts') }}</p>
          <ul class="list-disc pl-5">
            <li v-for="(f, k) in s.required_facts" :key="k">{{ f.fact }}
              <span v-if="f.evidence_refs.length" class="font-mono text-ink-muted">[{{ f.evidence_refs.join(', ') }}]</span>
              <span v-else class="text-warning-600">({{ t('writer.outline.needsEvidence') }})</span>
            </li>
          </ul>
        </div>
        <div v-if="editable" class="mt-2">
          <label class="text-xs font-medium text-ink-muted">{{ t('writer.outline.subheadings') }}
            <textarea class="mt-1 block w-full rounded-lg border border-line px-2 py-1 text-sm" rows="2" :value="subs(s)" @input="setSubs(s, ($event.target as HTMLTextAreaElement).value)" />
          </label>
        </div>
        <ul v-else-if="s.subheadings.length" class="mt-2 list-disc pl-5 text-xs text-ink-muted"><li v-for="h in s.subheadings" :key="h">H3 · {{ h }}</li></ul>
      </li>
    </ol>
    <div v-if="editable" class="mt-4 flex flex-wrap justify-between gap-2 border-t border-line pt-4">
      <AppButton variant="ghost" size="sm" @click="add">+ {{ t('writer.outline.addSection') }}</AppButton>
      <AppButton :loading="submitting" data-testid="approve-outline" @click="approve">{{ dirty ? t('writer.outline.saveApprove') : t('writer.outline.approve') }}</AppButton>
    </div>
  </AppCard>
</template>
