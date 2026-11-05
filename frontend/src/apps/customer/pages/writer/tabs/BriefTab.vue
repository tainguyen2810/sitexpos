<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { writerApi, type BriefData } from '@/shared/api/writer'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import WTextarea from '../components/WTextarea.vue'
import { useArticle } from '../articleContext'
import { useWriterErrors } from '../useWriter'

const { t } = useI18n()
const { workspaceId: ws, articleId: id, article, reload, busy, canEdit } = useArticle()
const { toast } = useWriterErrors()
const { errors, formError, submitting, submit } = useApiForm()
const brief = useAsync(() => writerApi.brief(ws, id))
watch(() => article.value?.status, () => void brief.run())

const lines = (v: string) => v.split('\n').map((s) => s.trim()).filter(Boolean)
const refs = (v: string) => [...new Set(v.split(/[^0-9]+/).map(Number).filter((n) => n > 0))]
const form = reactive({ titles: '', audience: '', main_question: '', supporting: '', angle: '', differentiation: '', missing: '', cta: '', faq: '', links: '',
  structure: [] as { heading: string; level: number; purpose: string }[], facts: [] as { fact: string; refs: string }[] })
let original = ''
function fill(d: BriefData) {
  form.titles = d.title_suggestions.join('\n')
  form.audience = d.audience ?? ''
  form.main_question = d.main_question ?? ''
  form.supporting = (d.supporting_questions ?? []).join('\n')
  form.angle = d.content_angle ?? ''
  form.differentiation = d.differentiation ?? ''
  form.missing = (d.missing_information ?? []).join('\n')
  form.cta = d.cta ?? ''
  form.faq = (d.faq ?? []).map((f) => (typeof f === 'string' ? f : JSON.stringify(f))).join('\n')
  form.links = (d.internal_links ?? []).map((l) => `${l.url} | ${l.anchor ?? ''}`).join('\n')
  form.structure = (d.structure ?? []).map((s) => ({ ...s }))
  form.facts = (d.key_facts ?? []).map((f) => ({ fact: f.fact, refs: f.evidence_refs.join(', ') }))
  original = JSON.stringify(form)
}
watch(() => brief.data.value?.current, (b) => b && fill(b.data), { immediate: true })
const current = computed(() => brief.data.value?.current ?? null)
const editable = computed(() => canEdit.value && !busy.value && current.value?.status !== 'approved' && ['BRIEF_READY', 'RESEARCH_COMPLETE'].includes(article.value?.status ?? ''))
const dirty = computed(() => JSON.stringify(form) !== original)

function edits(): Partial<BriefData> {
  return {
    title_suggestions: lines(form.titles).slice(0, 6), audience: form.audience, main_question: form.main_question,
    supporting_questions: lines(form.supporting).slice(0, 15), content_angle: form.angle, differentiation: form.differentiation,
    structure: form.structure.filter((s) => s.heading.trim()), key_facts: form.facts.filter((f) => f.fact.trim()).map((f) => ({ fact: f.fact, evidence_refs: refs(f.refs) })),
    missing_information: lines(form.missing).slice(0, 30), cta: form.cta || null, faq: lines(form.faq).slice(0, 15),
    internal_links: lines(form.links).map((l) => { const [url, anchor] = l.split('|').map((x) => x.trim()); return { url: url ?? '', anchor: anchor ?? '' } }).filter((l) => l.url),
  }
}
async function approve() {
  const r = await submit(() => writerApi.approveBrief(ws, id, dirty.value ? edits() : undefined))
  if (r) {
    toast.success(t('writer.brief.approved'))
    await Promise.all([reload(), brief.run()])
  }
}
const addStructure = () => form.structure.push({ heading: '', level: 2, purpose: '' })
const addFact = () => form.facts.push({ fact: '', refs: '' })
const showErrors = ref(true)
</script>

<template>
  <LoadingState v-if="brief.loading.value && !brief.data.value" />
  <ErrorState v-else-if="brief.error.value" :error="brief.error.value" @retry="brief.run" />
  <EmptyState v-else-if="!current" icon="file" :title="t('writer.brief.empty')" :description="t('writer.brief.emptyBody')" />
  <div v-else class="grid gap-4 lg:grid-cols-[1fr_16rem]">
    <AppCard>
      <div class="mb-4 flex flex-wrap items-center gap-2">
        <h3 class="text-sm font-semibold">{{ t('writer.brief.version', { n: current.version }) }}</h3>
        <AppBadge :tone="current.status === 'approved' ? 'success' : 'warning'">{{ current.status === 'approved' ? t('writer.brief.approved') : current.status }}</AppBadge>
        <span class="text-xs text-ink-muted">{{ current.generated_by_model ?? '' }}</span>
      </div>
      <p v-if="!editable && current.status === 'approved'" class="mb-4 text-sm text-ink-muted">{{ t('writer.brief.readonly') }}</p>
      <p v-if="formError" class="mb-4 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ formError }}</p>
      <ul v-if="showErrors && Object.values(errors).some(Boolean)" class="mb-4 rounded-lg bg-danger-50 px-3 py-2 text-xs text-danger-600">
        <li v-for="(m, k) in errors" v-show="m" :key="k">{{ k }}: {{ m }}</li>
      </ul>
      <fieldset :disabled="!editable" class="grid gap-4 sm:grid-cols-2">
        <WTextarea v-model="form.titles" class="sm:col-span-2" :label="t('writer.brief.titles')" />
        <AppInput v-model="form.audience" :label="t('writer.brief.audience')" />
        <AppInput v-model="form.main_question" :label="t('writer.brief.mainQuestion')" />
        <WTextarea v-model="form.supporting" class="sm:col-span-2" :label="t('writer.brief.supporting')" />
        <WTextarea v-model="form.angle" :label="t('writer.brief.angle')" />
        <WTextarea v-model="form.differentiation" :label="t('writer.brief.differentiation')" />
        <div class="sm:col-span-2">
          <p class="mb-2 text-sm font-medium">{{ t('writer.brief.structure') }}</p>
          <div v-for="(s, i) in form.structure" :key="i" class="mb-2 grid gap-2 sm:grid-cols-[1fr_5rem_1fr]">
            <AppInput v-model="s.heading" :placeholder="t('writer.outline.heading')" />
            <select v-model.number="s.level" class="rounded-lg border border-line bg-surface px-2 text-sm" :aria-label="t('writer.outline.heading')"><option :value="2">H2</option><option :value="3">H3</option></select>
            <AppInput v-model="s.purpose" :placeholder="t('writer.outline.purpose')" />
          </div>
          <AppButton v-if="editable" size="sm" variant="ghost" @click="addStructure">+ {{ t('writer.brief.addRow') }}</AppButton>
        </div>
        <div class="sm:col-span-2">
          <p class="text-sm font-medium">{{ t('writer.brief.keyFacts') }}</p>
          <p class="mb-2 text-xs text-ink-muted">{{ t('writer.brief.keyFactsHint') }}</p>
          <div v-for="(f, i) in form.facts" :key="i" class="mb-2 grid gap-2 sm:grid-cols-[1fr_8rem]">
            <AppInput v-model="f.fact" :placeholder="t('writer.brief.keyFacts')" />
            <AppInput v-model="f.refs" :placeholder="t('writer.brief.refs')" />
          </div>
          <AppButton v-if="editable" size="sm" variant="ghost" @click="addFact">+ {{ t('writer.brief.addRow') }}</AppButton>
        </div>
        <WTextarea v-model="form.missing" class="sm:col-span-2" :label="t('writer.brief.missing')" />
        <WTextarea v-model="form.links" :label="t('writer.brief.links')" placeholder="https://… | anchor" />
        <WTextarea v-model="form.faq" :label="t('writer.brief.faq')" />
        <AppInput v-model="form.cta" class="sm:col-span-2" :label="t('writer.wizard.cta')" />
      </fieldset>
      <div v-if="editable" class="mt-6 flex justify-end border-t border-line pt-4">
        <AppButton :loading="submitting" data-testid="approve-brief" @click="approve">{{ dirty ? t('writer.brief.saveApprove') : t('writer.brief.approve') }}</AppButton>
      </div>
    </AppCard>
    <div class="space-y-4">
      <AppCard v-if="current && (current.warnings.length || current.data.risks?.length)">
        <h3 class="mb-2 text-sm font-semibold">{{ t('writer.brief.warnings') }}</h3>
        <ul class="space-y-1 text-xs text-warning-600">
          <li v-for="w in current?.warnings ?? []" :key="w">{{ w }}</li>
          <li v-for="r in current?.data.risks ?? []" :key="r">{{ r }}</li>
        </ul>
      </AppCard>
      <AppCard v-if="brief.data.value!.history.length > 1">
        <h3 class="mb-2 text-sm font-semibold">{{ t('common.status') }}</h3>
        <ul class="space-y-1 text-xs">
          <li v-for="h in brief.data.value!.history" :key="h.id">v{{ h.version }} · {{ h.status }} · {{ formatDateTime(h.created_at) }}</li>
        </ul>
      </AppCard>
    </div>
  </div>
</template>
