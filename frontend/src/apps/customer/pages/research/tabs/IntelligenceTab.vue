<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { researchApi, KNOWLEDGE_KINDS, type Campaign, type Knowledge, type KnowledgeKind } from '@/shared/api/research'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useToast } from '@/shared/composables/useToast'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'

const props = defineProps<{ workspaceId: string; campaign: Campaign; canOperate: boolean }>()
const emit = defineEmits<{ refreshProfile: [] }>()
const { t } = useI18n()
const toast = useToast()
const intel = useAsync(() => researchApi.intelligence(props.workspaceId, props.campaign.id))

const grouped = computed(() => {
  const out: { kind: KnowledgeKind; items: Knowledge[] }[] = []
  for (const kind of KNOWLEDGE_KINDS) {
    const items = (intel.data.value?.knowledge ?? []).filter((k) => k.kind === kind)
    if (items.length) out.push({ kind, items })
  }
  return out
})
const statusTone = (s: Knowledge['status']) => (({ suggested: 'warning', confirmed: 'success', rejected: 'neutral' }) as const)[s]

async function setStatus(k: Knowledge, status: Knowledge['status']) {
  try {
    const updated = status === 'rejected' ? (await researchApi.rejectKnowledge(props.workspaceId, props.campaign.id, k.id), { ...k, status }) : await researchApi.updateKnowledge(props.workspaceId, props.campaign.id, k.id, { status })
    Object.assign(k, updated)
    intel.data.value = { ...intel.data.value!, knowledge: [...intel.data.value!.knowledge] }
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  }
}

// add / edit
const modal = ref<{ mode: 'add' | 'edit'; item?: Knowledge } | null>(null)
const draft = ref<{ kind: KnowledgeKind; value: string }>({ kind: 'audience', value: '' })
const form = useApiForm()
const kindOptions = computed(() => KNOWLEDGE_KINDS.map((k) => ({ value: k, label: t(`research.intel.kind.${k}`) })))
function openAdd() {
  form.reset()
  draft.value = { kind: 'audience', value: '' }
  modal.value = { mode: 'add' }
}
function openEdit(k: Knowledge) {
  form.reset()
  draft.value = { kind: k.kind, value: k.value }
  modal.value = { mode: 'edit', item: k }
}
async function save() {
  const m = modal.value
  if (!m) return
  const res = await form.submit(() =>
    m.mode === 'add'
      ? researchApi.addKnowledge(props.workspaceId, props.campaign.id, draft.value)
      : researchApi.updateKnowledge(props.workspaceId, props.campaign.id, m.item!.id, { value: draft.value.value }),
  )
  if (!res) return
  modal.value = null
  await intel.run()
}
</script>

<template>
  <LoadingState v-if="intel.loading.value && !intel.data.value" :rows="4" />
  <AppCard v-else-if="intel.error.value"><ErrorState :error="intel.error.value" @retry="intel.run" /></AppCard>
  <div v-else-if="intel.data.value" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <AppCard :title="t('research.intel.profile')">
      <p v-if="!intel.data.value.profile" class="text-sm text-ink-muted">{{ t('research.intel.noProfile') }}</p>
      <template v-else>
        <p class="font-medium text-ink">{{ intel.data.value.profile.title ?? '—' }}</p>
        <p v-if="intel.data.value.profile.tagline" class="text-sm text-ink-muted">{{ intel.data.value.profile.tagline }}</p>
        <p v-if="intel.data.value.profile.description" class="mt-2 text-sm">{{ intel.data.value.profile.description }}</p>
        <dl class="mt-4 space-y-1 text-xs text-ink-muted">
          <div v-for="(v, k) in intel.data.value.profile.stats" :key="k" class="flex justify-between"><dt>{{ k }}</dt><dd class="tabular-nums">{{ v }}</dd></div>
          <div v-if="intel.data.value.profile.sitemap_url" class="flex justify-between"><dt>{{ t('research.intel.sitemap', { n: intel.data.value.profile.sitemap_url_count }) }}</dt></div>
          <div class="flex justify-between"><dt>{{ t('research.keywords.collected') }}</dt><dd>{{ formatDateTime(intel.data.value.profile.last_crawled_at) }}</dd></div>
        </dl>
        <div v-if="intel.data.value.profile.covered_topics.length" class="mt-4">
          <p class="mb-1 text-xs font-medium text-ink-muted">{{ t('research.intel.covered') }}</p>
          <div class="flex flex-wrap gap-1"><AppBadge v-for="c in intel.data.value.profile.covered_topics.slice(0, 20)" :key="c">{{ c }}</AppBadge></div>
        </div>
      </template>
      <AppButton v-if="canOperate" class="mt-4" variant="secondary" size="sm" @click="emit('refreshProfile')"><AppIcon name="refresh" :size="14" /> {{ t('research.run.refreshProfile') }}</AppButton>
    </AppCard>

    <AppCard class="lg:col-span-2" :title="t('research.intel.knowledge')" :subtitle="t('research.intel.knowledgeHint')">
      <div v-if="canOperate" class="mb-3 flex justify-end"><AppButton size="sm" @click="openAdd"><AppIcon name="plus" :size="14" /> {{ t('research.intel.add') }}</AppButton></div>
      <EmptyState v-if="!grouped.length" icon="building" :title="t('research.noData')" :description="t('research.intel.noProfile')" />
      <div v-for="g in grouped" :key="g.kind" class="mb-4">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t(`research.intel.kind.${g.kind}`) }}</p>
        <ul class="divide-y divide-line rounded-lg border border-line">
          <li v-for="k in g.items" :key="k.id" class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm" :class="k.status === 'rejected' ? 'opacity-60' : ''">
            <span class="min-w-0 flex-1" :class="k.status === 'rejected' ? 'line-through' : ''">{{ k.value }}</span>
            <span class="text-xs text-ink-muted">{{ t(`research.intel.source.${k.source}`) }}<template v-if="k.confidence !== null"> · {{ t('research.intel.confidence', { n: k.confidence }) }}</template></span>
            <AppBadge :tone="statusTone(k.status)">{{ t(`research.intel.status.${k.status}`) }}</AppBadge>
            <template v-if="canOperate">
              <AppButton v-if="k.status !== 'confirmed'" size="sm" variant="ghost" @click="setStatus(k, 'confirmed')"><AppIcon name="check" :size="14" /> {{ t('research.intel.confirm') }}</AppButton>
              <AppButton v-if="k.status !== 'rejected'" size="sm" variant="ghost" @click="setStatus(k, 'rejected')"><AppIcon name="x" :size="14" /> {{ t('research.intel.reject') }}</AppButton>
              <AppButton v-else size="sm" variant="ghost" @click="setStatus(k, 'suggested')">{{ t('research.intel.restore') }}</AppButton>
              <AppButton size="sm" variant="ghost" :aria-label="t('common.edit')" @click="openEdit(k)"><AppIcon name="edit" :size="14" /></AppButton>
            </template>
          </li>
        </ul>
      </div>
    </AppCard>

    <AppModal :open="!!modal" :title="modal?.mode === 'edit' ? t('common.edit') : t('research.intel.add')" @close="modal = null">
      <form id="knowledge-form" class="space-y-4" @submit.prevent="save">
        <p v-if="form.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ form.formError.value }}</p>
        <AppSelect v-if="modal?.mode === 'add'" v-model="draft.kind" :label="t('research.intel.kindLabel')" :options="kindOptions" :error="form.errors.kind" />
        <AppInput v-model="draft.value" :label="t('research.intel.value')" required :error="form.errors.value" />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="modal = null">{{ t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="knowledge-form" :loading="form.submitting.value">{{ t('common.save') }}</AppButton>
      </template>
    </AppModal>
  </div>
</template>
