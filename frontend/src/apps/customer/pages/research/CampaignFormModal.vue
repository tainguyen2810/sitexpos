<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { researchApi, splitList, GOALS, type Campaign, type Goal } from '@/shared/api/research'
import type { Site } from '@/shared/api/sites'
import { useApiForm } from '@/shared/composables/useApiForm'
import AppModal from '@/shared/ui/AppModal.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppButton from '@/shared/ui/AppButton.vue'

const props = defineProps<{ open: boolean; workspaceId: string; sites: Site[]; campaign?: Campaign | null }>()
const emit = defineEmits<{ close: []; saved: [campaign: Campaign] }>()
const { t } = useI18n()
const form = useApiForm()
const { errors, formError, submitting } = form

const blank = () => ({ site_id: '', name: '', industry: '', market: 'VN', language: 'vi', products: '', services: '', audiences: '', brand_terms: '', goals: ['traffic'] as Goal[] })
const draft = ref(blank())

watch(
  () => props.open,
  (o) => {
    if (!o) return
    form.reset()
    const c = props.campaign
    draft.value = c
      ? { site_id: c.site_id, name: c.name, industry: c.industry, market: c.market, language: c.language, products: c.products.join(', '), services: c.services.join(', '),
          audiences: c.audiences.join(', '), brand_terms: c.brand_terms.join(', '), goals: [...c.goals] }
      : { ...blank(), site_id: props.sites[0]?.id ?? '' }
  },
  { immediate: true },
)

const siteOptions = computed(() => props.sites.map((s) => ({ value: s.id, label: `${s.name} — ${s.url}` })))
const languageOptions = computed(() => [{ value: 'vi', label: 'Tiếng Việt' }, { value: 'en', label: 'English' }])

function toggleGoal(g: Goal) {
  const list = draft.value.goals
  draft.value.goals = list.includes(g) ? list.filter((x) => x !== g) : [...list, g]
}

async function save() {
  const d = draft.value
  const payload = {
    name: d.name.trim(), industry: d.industry.trim(), market: d.market.trim().toUpperCase(), language: d.language,
    products: splitList(d.products), services: splitList(d.services), audiences: splitList(d.audiences), brand_terms: splitList(d.brand_terms), goals: d.goals,
  }
  const res = await form.submit(() =>
    props.campaign ? researchApi.updateCampaign(props.workspaceId, props.campaign.id, payload) : researchApi.createCampaign(props.workspaceId, { ...payload, site_id: d.site_id }),
  )
  if (res) emit('saved', res)
}
</script>

<template>
  <AppModal :open="open" :title="campaign ? t('common.edit') : t('research.campaigns.new')" width="lg" @close="emit('close')">
    <form id="campaign-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
      <p v-if="formError" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600 sm:col-span-2" role="alert">{{ formError }}</p>
      <AppSelect v-if="!campaign" v-model="draft.site_id" class="sm:col-span-2" :label="t('research.campaigns.site')" :options="siteOptions" :error="errors.site_id" />
      <AppInput v-model="draft.name" :label="t('research.campaigns.name')" required :error="errors.name" />
      <AppInput v-model="draft.industry" :label="t('research.campaigns.industry')" :placeholder="t('research.campaigns.industryHint')" required :error="errors.industry" />
      <AppInput v-model="draft.market" :label="t('research.campaigns.market')" placeholder="VN" :error="errors.market" />
      <AppSelect v-model="draft.language" :label="t('research.campaigns.language')" :options="languageOptions" :error="errors.language" />
      <AppInput v-model="draft.products" :label="t('research.campaigns.products')" :hint="t('research.campaigns.listHint')" :error="errors.products" />
      <AppInput v-model="draft.services" :label="t('research.campaigns.services')" :hint="t('common.optional')" :error="errors.services" />
      <AppInput v-model="draft.audiences" :label="t('research.campaigns.audiences')" :hint="t('research.campaigns.listHint')" :error="errors.audiences" />
      <AppInput v-model="draft.brand_terms" :label="t('research.campaigns.brandTerms')" :hint="t('common.optional')" :error="errors.brand_terms" />
      <fieldset class="sm:col-span-2">
        <legend class="mb-2 text-sm font-medium text-ink">{{ t('research.campaigns.goals') }}</legend>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="g in GOALS"
            :key="g"
            type="button"
            class="rounded-full border px-3 py-1 text-sm"
            :class="draft.goals.includes(g) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-line text-ink-muted hover:border-brand-200'"
            :aria-pressed="draft.goals.includes(g)"
            @click="toggleGoal(g)"
          >
            {{ t(`research.campaigns.goal.${g}`) }}
          </button>
        </div>
      </fieldset>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</AppButton>
      <AppButton type="submit" form="campaign-form" :loading="submitting">{{ t('common.save') }}</AppButton>
    </template>
  </AppModal>
</template>
