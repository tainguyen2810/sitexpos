<script setup lang="ts">
import { computed, reactive, ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { PROVENANCES, splitLines, usdToMicro, writerApi, type Preset, type PresetInput, type Provenance } from '@/shared/api/writer'
import { siteApi } from '@/shared/api/sites'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import SearchInput from '@/shared/ui/SearchInput.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import WTextarea from './components/WTextarea.vue'
import WriterNav from './components/WriterNav.vue'
import { useWriterErrors, useWriterPerms } from './useWriter'

const { t } = useI18n()
const { workspaceId: id, canEdit } = useWriterPerms()
const { toastError, toast } = useWriterErrors()
const site = ref('')
const q = ref('')
const presets = useAsync(() => writerApi.presets(id.value, { site_id: site.value }))
const sites = useAsync(() => siteApi.list(id.value))
const filtered = computed(() => (presets.data.value ?? []).filter((p) => !q.value.trim() || p.name.toLowerCase().includes(q.value.trim().toLowerCase())))
const siteOptions = computed(() => [{ value: '', label: t('writer.presets.allSites') }, ...(sites.data.value ?? []).map((s) => ({ value: s.id, label: s.name }))])
const siteName = (sid: string | null) => (sid ? sites.data.value?.find((s) => s.id === sid)?.name ?? '' : t('writer.presets.allSites'))
function onSite(v: string) {
  site.value = v
  void presets.run()
}

const open = ref(false)
const editing = shallowRef<Preset | null>(null)
const { errors, formError, submitting, submit } = useApiForm()
const f = reactive({
  name: '', site_id: '', is_default: false, tone: '', writing_style: '', preferred: '', forbidden: '', customers: '', geo: '', guidelines: '', cta: '',
  facts: [] as { text: string; provenance: Provenance; source_url: string }[],
  d_type: '', d_words: '', d_depth: '', d_budget: '',
})
function edit(p: Preset | null) {
  editing.value = p
  Object.assign(f, {
    name: p?.name ?? '', site_id: p?.site_id ?? '', is_default: p?.is_default ?? false, tone: p?.tone ?? '', writing_style: p?.writing_style ?? '',
    preferred: (p?.preferred_terms ?? []).join(', '), forbidden: (p?.forbidden_phrases ?? []).join(', '), customers: (p?.target_customers ?? []).join('\n'),
    geo: p?.geographic_context ?? '', guidelines: p?.editorial_guidelines ?? '', cta: p?.cta ?? '',
    facts: (p?.facts ?? []).map((x) => ({ text: x.text, provenance: x.provenance, source_url: x.source_url ?? '' })),
    d_type: p?.defaults.content_type ?? '', d_words: p?.defaults.target_words ? String(p.defaults.target_words) : '',
    d_depth: p?.defaults.research_depth ?? '', d_budget: p?.defaults.budget_limit_micro_usd ? String(p.defaults.budget_limit_micro_usd / 1_000_000) : '',
  })
  open.value = true
}
function payload(): PresetInput {
  const defaults: Preset['defaults'] = {}
  if (f.d_type) defaults.content_type = f.d_type
  if (f.d_words) defaults.target_words = Number(f.d_words)
  if (f.d_depth) defaults.research_depth = f.d_depth as Preset['defaults']['research_depth']
  if (f.d_budget) defaults.budget_limit_micro_usd = usdToMicro(Number(f.d_budget))
  return {
    name: f.name.trim(), site_id: f.site_id || null, is_default: f.is_default, tone: f.tone || null, writing_style: f.writing_style || null,
    preferred_terms: splitLines(f.preferred), forbidden_phrases: splitLines(f.forbidden), target_customers: f.customers.split('\n').map((s) => s.trim()).filter(Boolean),
    geographic_context: f.geo || null, editorial_guidelines: f.guidelines || null, cta: f.cta || null,
    // The server stores a fact as `user_provided` unless it has a source URL — provenance is never upgraded client-side.
    facts: f.facts.filter((x) => x.text.trim()).map((x) => ({ text: x.text.trim(), provenance: x.provenance, source_url: x.source_url.trim() || null })),
    defaults,
  }
}
async function save() {
  const r = await submit(() => (editing.value ? writerApi.updatePreset(id.value, editing.value.id, payload()) : writerApi.createPreset(id.value, payload())))
  if (r) {
    open.value = false
    toast.success(t('common.saved'))
    await presets.run()
  }
}
const deleting = shallowRef<Preset | null>(null)
const acting = ref(false)
async function remove() {
  if (!deleting.value) return
  acting.value = true
  try {
    await writerApi.deletePreset(id.value, deleting.value.id)
    deleting.value = null
    await presets.run()
  } catch (e) {
    toastError(e)
  } finally {
    acting.value = false
  }
}
const provenanceOptions = computed(() => PROVENANCES.map((p) => ({ value: p, label: t(`writer.provenance.${p}`) })))
const formSiteOptions = computed(() => [{ value: '', label: t('writer.presets.allSites') }, ...(sites.data.value ?? []).map((s) => ({ value: s.id, label: s.name }))])
const typeOptions = computed(() => [{ value: '', label: '—' }, ...['blog_article', 'how_to', 'product_guide', 'comparison', 'troubleshooting', 'faq', 'pillar', 'cluster', 'service', 'buying_guide'].map((c) => ({ value: c, label: t(`writer.contentType.${c}`) }))])
const depthOptions = computed(() => [{ value: '', label: '—' }, ...(['basic', 'standard', 'deep'] as const).map((d) => ({ value: d, label: t(`writer.depth.${d}`) }))])
const provTone = (p: Provenance) => (p === 'ai_inferred' ? 'warning' : p === 'source_verified' ? 'success' : 'neutral') as 'warning' | 'success' | 'neutral'
</script>

<template>
  <div>
    <PageHeader :title="t('writer.presets.title')" :description="t('writer.presets.subtitle')">
      <template #actions>
        <AppButton v-if="canEdit" data-testid="new-preset" @click="edit(null)"><AppIcon name="plus" :size="16" /> {{ t('writer.presets.new') }}</AppButton>
      </template>
    </PageHeader>
    <WriterNav active="presets" />

    <AppCard :padded="false">
      <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
        <SearchInput v-model="q" />
        <FilterSelect :model-value="site" :label="t('nav.sites')" :options="siteOptions" @update:model-value="onSite" />
      </div>
      <LoadingState v-if="presets.loading.value && !presets.data.value" class="p-5" />
      <ErrorState v-else-if="presets.error.value" :error="presets.error.value" class="p-5" @retry="presets.run" />
      <EmptyState v-else-if="!filtered.length" icon="pen" :title="t('writer.presets.empty')" :description="t('writer.presets.subtitle')" />
      <ul v-else class="divide-y divide-line" data-testid="preset-list">
        <li v-for="p in filtered" :key="p.id" class="flex flex-wrap items-start gap-3 px-5 py-4">
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium">{{ p.name }}
              <AppBadge v-if="p.is_default" tone="brand">{{ t('writer.presets.default') }}</AppBadge>
              <AppBadge v-if="p.is_demo" tone="warning">{{ t('writer.demo') }}</AppBadge>
            </p>
            <p class="text-xs text-ink-muted">{{ siteName(p.site_id) }}<template v-if="p.tone"> · {{ p.tone }}</template></p>
            <ul v-if="p.facts.length" class="mt-2 space-y-1 text-xs">
              <li v-for="(x, i) in p.facts.slice(0, 3)" :key="i" class="flex items-start gap-2"><AppBadge :tone="provTone(x.provenance)">{{ t(`writer.provenance.${x.provenance}`) }}</AppBadge> {{ x.text }}</li>
              <li v-if="p.facts.length > 3" class="text-ink-muted">+{{ p.facts.length - 3 }}</li>
            </ul>
          </div>
          <div v-if="canEdit" class="flex gap-1">
            <AppButton size="sm" variant="secondary" @click="edit(p)">{{ t('common.edit') }}</AppButton>
            <AppButton size="sm" variant="ghost" :aria-label="t('common.delete')" @click="deleting = p"><AppIcon name="trash" :size="14" /></AppButton>
          </div>
        </li>
      </ul>
    </AppCard>

    <AppModal :open="open" :title="editing ? editing.name : t('writer.presets.new')" width="xl" @close="open = false">
      <form id="preset-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
        <p v-if="formError" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600 sm:col-span-2" role="alert">{{ formError }}</p>
        <AppInput v-model="f.name" :label="t('writer.presets.name')" required :error="errors.name" />
        <AppSelect v-model="f.site_id" :label="t('nav.sites')" :options="formSiteOptions" :error="errors.site_id" />
        <AppInput v-model="f.tone" :label="t('writer.presets.tone')" :error="errors.tone" />
        <div class="flex items-end"><Toggle v-model="f.is_default" :label="t('writer.presets.makeDefault')" /></div>
        <WTextarea v-model="f.writing_style" class="sm:col-span-2" :label="t('writer.presets.style')" :error="errors.writing_style" />
        <AppInput v-model="f.preferred" :label="t('writer.presets.preferred')" :hint="t('research.campaigns.listHint')" :error="errors.preferred_terms" />
        <AppInput v-model="f.forbidden" :label="t('writer.presets.forbidden')" :hint="t('research.campaigns.listHint')" :error="errors.forbidden_phrases" />
        <div class="sm:col-span-2">
          <p class="text-sm font-medium">{{ t('writer.presets.facts') }}</p>
          <p class="mb-2 text-xs text-ink-muted">{{ t('writer.presets.factHint') }}</p>
          <div v-for="(x, i) in f.facts" :key="i" class="mb-2 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_12rem_1fr_auto]">
            <AppInput v-model="x.text" :placeholder="t('writer.presets.factText')" :error="errors[`facts.${i}.text`]" />
            <AppSelect v-model="x.provenance" :options="provenanceOptions" />
            <AppInput v-model="x.source_url" :placeholder="t('writer.presets.factSource')" :error="errors[`facts.${i}.source_url`]" />
            <AppButton size="sm" variant="ghost" :aria-label="t('common.delete')" @click="f.facts.splice(i, 1)">✕</AppButton>
          </div>
          <AppButton size="sm" variant="ghost" @click="f.facts.push({ text: '', provenance: 'user_provided', source_url: '' })">+ {{ t('common.add') }}</AppButton>
        </div>
        <WTextarea v-model="f.customers" :label="t('writer.presets.customers')" />
        <AppInput v-model="f.geo" :label="t('writer.presets.geo')" />
        <WTextarea v-model="f.guidelines" class="sm:col-span-2" :rows="4" :label="t('writer.presets.guidelines')" />
        <AppInput v-model="f.cta" class="sm:col-span-2" :label="t('writer.presets.cta')" />
        <fieldset class="grid grid-cols-1 gap-3 sm:col-span-2 sm:grid-cols-4">
          <legend class="mb-1 text-sm font-medium">{{ t('writer.presets.defaults') }}</legend>
          <AppSelect v-model="f.d_type" :label="t('writer.wizard.steps.contentType')" :options="typeOptions" />
          <AppInput v-model="f.d_words" type="number" :label="t('writer.wizard.targetWords')" :error="errors['defaults.target_words']" />
          <AppSelect v-model="f.d_depth" :label="t('writer.wizard.steps.researchDepth')" :options="depthOptions" />
          <AppInput v-model="f.d_budget" type="number" :label="t('writer.wizard.budget')" :error="errors['defaults.budget_limit_micro_usd']" />
        </fieldset>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="preset-form" :loading="submitting">{{ t('common.save') }}</AppButton>
      </template>
    </AppModal>
    <ConfirmDialog :open="!!deleting" :title="t('common.delete')" :message="t('writer.presets.deleteConfirm', { name: deleting?.name ?? '' })" danger :loading="acting" @close="deleting = null" @confirm="remove" />
  </div>
</template>
