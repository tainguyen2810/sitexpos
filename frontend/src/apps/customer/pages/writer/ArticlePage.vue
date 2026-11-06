<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { BUSY_STATUSES, formatUsd, isActiveWorkflow, nextTab, usdToMicro, writerApi, type ArticleDetail } from '@/shared/api/writer'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import Tabs from '@/shared/ui/Tabs.vue'
import StatusBadge from './components/StatusBadge.vue'
import WorkflowProgress from './components/WorkflowProgress.vue'
import OverviewTab from './tabs/OverviewTab.vue'
import ResearchTab from './tabs/ResearchTab.vue'
import BriefTab from './tabs/BriefTab.vue'
import OutlineTab from './tabs/OutlineTab.vue'
import EditorTab from './tabs/EditorTab.vue'
import FactCheckTab from './tabs/FactCheckTab.vue'
import QualityTab from './tabs/QualityTab.vue'
import VersionsTab from './tabs/VersionsTab.vue'
import { provideArticle } from './articleContext'
import { usePolling, useWriterErrors, useWriterPerms } from './useWriter'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { workspaceId, canEdit, canApprove } = useWriterPerms()
const { toastError, toast } = useWriterErrors()
const articleId = String(route.params.articleId)

const article = shallowRef<ArticleDetail | null>(null)
const error = shallowRef<unknown>(null)
const loading = ref(true)
async function reload() {
  try {
    article.value = await writerApi.article(workspaceId.value, articleId)
    error.value = null
  } catch (e) {
    if (!article.value) error.value = e
  } finally {
    loading.value = false
  }
}
void reload().then(() => {
  if (!route.params.tab && article.value) void router.replace({ params: { ...route.params, tab: nextTab(article.value) } })
})

const busy = computed(() => !!article.value && (isActiveWorkflow(article.value.workflow) || BUSY_STATUSES.includes(article.value.status)))
usePolling(reload, busy, 4000)
provideArticle({ workspaceId: workspaceId.value, articleId, article, reload, busy, canEdit, canApprove })

const TABS = ['overview', 'research', 'brief', 'outline', 'editor', 'fact-check', 'quality', 'versions'] as const
const COMPONENTS = { overview: OverviewTab, research: ResearchTab, brief: BriefTab, outline: OutlineTab, editor: EditorTab, 'fact-check': FactCheckTab, quality: QualityTab, versions: VersionsTab }
const tab = computed({
  get: () => (TABS as readonly string[]).includes(String(route.params.tab)) ? (String(route.params.tab) as (typeof TABS)[number]) : 'overview',
  set: (v: string) => void router.replace({ params: { ...route.params, tab: v } }),
})
const tabs = computed(() => TABS.map((k) => ({
  key: k,
  label: t(`writer.article.tabs.${k}`),
  count: k === 'fact-check' && article.value ? article.value.unresolved_critical + article.value.unresolved_high || undefined : undefined,
})))

const acting = ref(false)
async function act(fn: () => Promise<unknown>, done?: string) {
  acting.value = true
  try {
    await fn()
    await reload()
    if (done) toast.success(done)
  } catch (e) {
    toastError(e)
  } finally {
    acting.value = false
  }
}
const resume = () => act(() => writerApi.resume(workspaceId.value, articleId))
const cancelWorkflow = () => act(() => writerApi.cancelWorkflow(workspaceId.value, articleId))

const budgetOpen = ref(false)
const budgetUsd = ref('')
function openBudget() {
  budgetUsd.value = article.value ? String(article.value.budget.limit_micro_usd / 1_000_000) : ''
  budgetOpen.value = true
}
async function saveBudget() {
  await act(() => writerApi.update(workspaceId.value, articleId, { budget_limit_micro_usd: usdToMicro(Number(budgetUsd.value) || 0) }), t('common.saved'))
  budgetOpen.value = false
}
</script>

<template>
  <div>
    <LoadingState v-if="loading" />
    <ErrorState v-else-if="error || !article" :error="error" @retry="reload" />
    <template v-else>
      <PageHeader :title="article.title" :crumbs="[{ label: t('writer.title'), to: `/w/${workspaceId}/writer` }, { label: article.title }]">
        <template #actions>
          <StatusBadge :status="article.status" />
          <AppBadge v-if="article.is_demo" tone="warning">{{ t('writer.demo') }}</AppBadge>
        </template>
      </PageHeader>

      <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-[1fr_18rem]">
        <AppCard>
          <WorkflowProgress v-if="article.workflow" :workflow="article.workflow" compact />
          <p v-else class="text-sm text-ink-muted">{{ t('writer.article.noWorkflow') }}</p>
          <p v-if="article.waiting_for" class="mt-3 rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-600" data-testid="waiting-for">
            {{ t(`writer.waiting.${article.waiting_for}`) }}
          </p>
          <p v-if="article.blocked_reason" class="mt-3 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">
            {{ t(`writer.blocked.${article.blocked_reason}`, article.blocked_reason) }}
          </p>
          <p v-if="article.status === 'FAILED' && article.last_error" class="mt-3 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">
            {{ t('writer.article.lastError') }}: {{ article.last_error.message || article.last_error.code }}
          </p>
          <div v-if="canEdit" class="mt-3 flex flex-wrap gap-2">
            <AppButton v-if="article.status === 'FAILED' || article.blocked_reason" size="sm" :loading="acting" @click="resume">{{ t('writer.article.resume') }}</AppButton>
            <AppButton v-if="isActiveWorkflow(article.workflow)" size="sm" variant="secondary" :loading="acting" @click="cancelWorkflow">{{ t('writer.article.cancelWorkflow') }}</AppButton>
          </div>
        </AppCard>
        <AppCard>
          <p class="text-sm text-ink-muted">{{ t('writer.article.budget') }}</p>
          <p class="mt-1 text-lg font-semibold tabular-nums">{{ formatUsd(article.budget.spent_micro_usd) }} <span class="text-sm font-normal text-ink-muted">/ {{ formatUsd(article.budget.limit_micro_usd) }}</span></p>
          <ProgressBar class="mt-2" tone="auto" :value="article.budget.spent_micro_usd" :max="article.budget.limit_micro_usd" />
          <p class="mt-2 text-xs text-ink-muted">{{ t('writer.article.updated') }}: {{ formatDateTime(article.updated_at) }}</p>
          <AppButton v-if="canEdit && !['APPROVED', 'CANCELLED'].includes(article.status)" class="mt-2" size="sm" variant="ghost" @click="openBudget">{{ t('writer.article.raiseBudget') }}</AppButton>
        </AppCard>
      </div>

      <Tabs v-model="tab" :tabs="tabs" class="mb-4" />
      <KeepAlive :max="3">
        <component :is="COMPONENTS[tab]" :key="tab" />
      </KeepAlive>

      <AppModal :open="budgetOpen" :title="t('writer.article.raiseBudget')" width="sm" @close="budgetOpen = false">
        <AppInput v-model="budgetUsd" type="number" :label="t('writer.wizard.budget')" />
        <template #footer>
          <AppButton variant="secondary" @click="budgetOpen = false">{{ t('common.cancel') }}</AppButton>
          <AppButton :loading="acting" @click="saveBudget">{{ t('common.save') }}</AppButton>
        </template>
      </AppModal>
    </template>
  </div>
</template>
