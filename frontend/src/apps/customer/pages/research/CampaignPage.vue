<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { researchApi, isActiveRun, runTone, type Campaign, type ResearchRun } from '@/shared/api/research'
import { siteApi } from '@/shared/api/sites'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useToast } from '@/shared/composables/useToast'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Tabs from '@/shared/ui/Tabs.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import { useWorkspaceStore } from '../../stores/workspace'
import CampaignFormModal from './CampaignFormModal.vue'
import OverviewTab from './tabs/OverviewTab.vue'
import IntelligenceTab from './tabs/IntelligenceTab.vue'
import RunsTab from './tabs/RunsTab.vue'
import KeywordsTab from './tabs/KeywordsTab.vue'
import IdeasTab from './tabs/IdeasTab.vue'
import ClustersTab from './tabs/ClustersTab.vue'
import CalendarTab from './tabs/CalendarTab.vue'
import SettingsTab from './tabs/SettingsTab.vue'

const TABS = ['overview', 'intelligence', 'runs', 'keywords', 'ideas', 'clusters', 'calendar', 'settings'] as const
type Tab = (typeof TABS)[number]

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const ws = useWorkspaceStore()
const id = ws.current!.id
const campaignId = String(route.params.campaignId)
const readOnly = computed(() => !!ws.current?.impersonating)
const canOperate = computed(() => ws.can('content.edit') && !readOnly.value)
const canManage = computed(() => ws.can('sites.manage') && !readOnly.value)

const campaign = useAsync(() => researchApi.campaign(id, campaignId))
const sites = useAsync(() => siteApi.list(id))
const siteName = computed(() => sites.data.value?.find((s) => s.id === campaign.data.value?.site_id)?.name ?? '')

const tab = computed<Tab>({
  get: () => (TABS as readonly string[]).includes(String(route.params.tab)) ? (route.params.tab as Tab) : 'overview',
  set: (v) => void router.replace({ name: 'research-campaign', params: { workspaceId: id, campaignId, tab: v === 'overview' ? undefined : v }, query: {} }),
})
const tabs = computed(() => TABS.map((k) => ({ key: k, label: t(`research.tabs.${k}`) })))

// ---- active run: start, poll, refresh tabs when it finishes ----
const active = ref<ResearchRun | null>(null)
const refreshKey = ref(0)
let timer: ReturnType<typeof setTimeout> | null = null
async function poll() {
  if (!active.value) return
  try {
    active.value = await researchApi.run(id, campaignId, active.value.id)
  } catch {
    /* transient — keep polling */
  }
  if (isActiveRun(active.value)) {
    timer = setTimeout(poll, 3000)
  } else {
    refreshKey.value++
    void campaign.run()
  }
}
async function loadActive() {
  try {
    const page = await researchApi.runs(id, campaignId, { per_page: 1 })
    const last = page.data[0] ?? null
    if (last && isActiveRun(last)) {
      active.value = last
      timer = setTimeout(poll, 3000)
    }
  } catch {
    /* the runs tab shows errors */
  }
}
void loadActive()
onBeforeUnmount(() => timer && clearTimeout(timer))

const starting = ref(false)
async function startRun(refreshProfile = false) {
  starting.value = true
  try {
    const key = typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `run-${Date.now()}-${Math.random().toString(36).slice(2)}`
    const res = await researchApi.startRun(id, campaignId, key, refreshProfile)
    active.value = res.research_run
    toast.success(t('research.run.started'))
    timer = setTimeout(poll, 2000)
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  } finally {
    starting.value = false
  }
}
async function cancelRun() {
  if (!active.value) return
  try {
    active.value = await researchApi.cancelRun(id, campaignId, active.value.id)
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  }
}
function onRetried(run: ResearchRun) {
  active.value = run
  timer = setTimeout(poll, 2000)
}

// ---- campaign actions ----
const editOpen = ref(false)
const deleteOpen = ref(false)
const deleting = ref(false)
function onSaved(c: Campaign) {
  editOpen.value = false
  campaign.data.value = c
}
async function toggleStatus() {
  const c = campaign.data.value
  if (!c) return
  try {
    campaign.data.value = await researchApi.updateCampaign(id, c.id, { status: c.status === 'active' ? 'paused' : 'active' })
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  }
}
async function remove() {
  deleting.value = true
  try {
    await researchApi.deleteCampaign(id, campaignId)
    await router.replace({ name: 'research', params: { workspaceId: id } })
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  } finally {
    deleting.value = false
    deleteOpen.value = false
  }
}

// Search Console OAuth comes back with ?gsc=<result>
watch(
  () => route.query.gsc,
  (v) => {
    if (typeof v !== 'string') return
    const known = ['connected', 'needs_property', 'denied', 'invalid_state']
    const msg = known.includes(v) ? t(`research.settings.gscResult.${v}`) : t('research.settings.gscResult.other', { code: v })
    if (v === 'connected' || v === 'needs_property') toast.success(msg)
    else toast.error(msg)
  },
  { immediate: true },
)
</script>

<template>
  <div>
    <LoadingState v-if="campaign.loading.value && !campaign.data.value" :rows="5" />
    <AppCard v-else-if="campaign.error.value"><ErrorState :error="campaign.error.value" @retry="campaign.run" /></AppCard>
    <template v-else-if="campaign.data.value">
      <PageHeader
        :title="campaign.data.value.name"
        :description="`${campaign.data.value.industry} · ${siteName}`"
        :crumbs="[{ label: t('research.title'), to: `/w/${id}/research` }, { label: campaign.data.value.name }]"
      >
        <template #actions>
          <AppBadge v-if="campaign.data.value.is_demo" tone="warning">DEMO</AppBadge>
          <AppBadge :tone="campaign.data.value.status === 'active' ? 'success' : 'neutral'">{{ t(`research.campaigns.status.${campaign.data.value.status}`) }}</AppBadge>
          <AppButton v-if="canOperate" variant="secondary" size="sm" @click="editOpen = true"><AppIcon name="edit" :size="14" /> {{ t('common.edit') }}</AppButton>
          <AppButton v-if="canOperate && campaign.data.value.status !== 'archived'" variant="secondary" size="sm" @click="toggleStatus">
            <AppIcon :name="campaign.data.value.status === 'active' ? 'pause' : 'play'" :size="14" />
            {{ campaign.data.value.status === 'active' ? t('research.campaigns.pause') : t('research.campaigns.resume') }}
          </AppButton>
          <AppButton v-if="canManage" variant="ghost" size="sm" @click="deleteOpen = true"><AppIcon name="trash" :size="14" /></AppButton>
          <AppButton
            v-if="canOperate"
            data-testid="start-research"
            :loading="starting"
            :disabled="isActiveRun(active) || campaign.data.value.status !== 'active'"
            @click="startRun(false)"
          >
            <AppIcon name="sparkles" :size="16" /> {{ t('research.run.start') }}
          </AppButton>
        </template>
      </PageHeader>

      <AppCard v-if="active && isActiveRun(active)" class="mb-4">
        <div class="flex flex-wrap items-center gap-3">
          <AppBadge :tone="runTone(active.status)">{{ t(`research.run.status.${active.status}`) }}</AppBadge>
          <span class="text-sm text-ink">{{ active.stage_label ?? t('research.run.starting') }}</span>
          <span class="ml-auto text-sm tabular-nums text-ink-muted">{{ active.progress }}%</span>
          <AppButton v-if="canOperate" size="sm" variant="secondary" @click="cancelRun">{{ t('research.run.cancel') }}</AppButton>
        </div>
        <ProgressBar class="mt-3" :value="active.progress" :label="active.stage_label ?? ''" />
      </AppCard>

      <div class="mb-4"><Tabs v-model="tab" :tabs="tabs" /></div>

      <OverviewTab v-if="tab === 'overview'" :key="`o${refreshKey}`" :workspace-id="id" :campaign="campaign.data.value" @navigate="(v: Tab) => (tab = v)" />
      <IntelligenceTab v-else-if="tab === 'intelligence'" :key="`i${refreshKey}`" :workspace-id="id" :campaign="campaign.data.value" :can-operate="canOperate" @refresh-profile="startRun(true)" />
      <RunsTab v-else-if="tab === 'runs'" :key="`r${refreshKey}`" :workspace-id="id" :campaign="campaign.data.value" :can-operate="canOperate" @retried="onRetried" />
      <KeywordsTab v-else-if="tab === 'keywords'" :key="`k${refreshKey}`" :workspace-id="id" :campaign="campaign.data.value" :can-operate="canOperate" />
      <IdeasTab v-else-if="tab === 'ideas'" :key="`d${refreshKey}`" :workspace-id="id" :campaign="campaign.data.value" :can-operate="canOperate" />
      <ClustersTab v-else-if="tab === 'clusters'" :key="`c${refreshKey}`" :workspace-id="id" :campaign="campaign.data.value" :can-operate="canOperate" />
      <CalendarTab v-else-if="tab === 'calendar'" :key="`l${refreshKey}`" :workspace-id="id" :campaign="campaign.data.value" :can-operate="canOperate" />
      <SettingsTab v-else :workspace-id="id" :campaign="campaign.data.value" :can-operate="canOperate" :can-manage="canManage" />

      <CampaignFormModal :open="editOpen" :workspace-id="id" :sites="sites.data.value ?? []" :campaign="campaign.data.value" @close="editOpen = false" @saved="onSaved" />
      <ConfirmDialog
        :open="deleteOpen"
        :title="t('research.campaigns.delete')"
        :message="t('research.campaigns.deleteConfirm', { name: campaign.data.value.name })"
        danger
        :loading="deleting"
        @close="deleteOpen = false"
        @confirm="remove"
      />
    </template>
  </div>
</template>
