<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { workspaceApi } from '@/shared/api/workspaces'
import { writerApi, type ArticleStatus } from '@/shared/api/writer'
import { siteApi } from '@/shared/api/sites'
import { analyticsApi, defaultPeriod } from '@/shared/api/analytics'
import { billingApi, formatCredits } from '@/shared/api/billing'
import { publishingApi } from '@/shared/api/publishing'
import { useAsync } from '@/shared/composables/useAsync'
import { useSessionStore } from '@/shared/stores/session'
import { formatRelative } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import StatCard from '@/shared/ui/StatCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LineChart from '@/shared/ui/charts/LineChart.vue'
import StatusBadge from './writer/components/StatusBadge.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { useAttentionStore } from '../stores/attention'
import { useAttentionItems } from '../composables/useAttentionItems'

const { t } = useI18n()
const ws = useWorkspaceStore()
const session = useSessionStore()
const attention = useAttentionStore()
const id = computed(() => ws.current!.id)
const base = computed(() => `/w/${id.value}`)
const { items: todo } = useAttentionItems(() => id.value)

// Every block loads on its own: a forbidden or failing source only empties its own card.
const dash = useAsync(() => workspaceApi.dashboard(id.value))
const overview = useAsync(() => writerApi.overview(id.value))
const sites = useAsync(() => siteApi.list(id.value))
const recent = useAsync(() => writerApi.articles(id.value, { per_page: 6 }).then((r) => r.data.slice(0, 6)))
const gsc = useAsync(() => analyticsApi.overview(id.value, defaultPeriod()).then((r) => r.data))
const billing = useAsync(() => billingApi.overview(id.value))
const published = useAsync(() => publishingApi.jobs(id.value, { status: 'PUBLISHED' }).then((r) => r.data.length))
void attention.load(id.value, true)

const firstName = computed(() => (session.user?.name ?? '').trim().split(/\s+/).pop() ?? '')

const hasGsc = computed(() => !!gsc.data.value?.has_data)
const connectedSites = computed(() => (sites.data.value ?? []).filter((s) => s.status === 'connected' || s.status === 'degraded').length)
const brokenSites = computed(() => (sites.data.value ?? []).filter((s) => s.status === 'degraded' || s.status === 'revoked').length)
const totalArticles = computed(() => Object.values(overview.data.value?.status_counts ?? {}).reduce((a, b) => a + (b ?? 0), 0))

const steps = computed(() => {
  const list = [
    { key: 'connect_site', done: connectedSites.value > 0, to: `${base.value}/sites` },
    { key: 'first_article', done: totalArticles.value > 0, to: `${base.value}/writer/new` },
    { key: 'connect_gsc', done: hasGsc.value, to: `${base.value}/analytics/connections` },
    { key: 'first_publish', done: (published.data.value ?? 0) > 0, to: `${base.value}/publishing` },
  ]
  const invite = dash.data.value?.onboarding.find((s) => s.key === 'invite_team')
  if (invite) list.push({ key: 'invite_team', done: invite.done, to: `${base.value}/members` })
  return list
})
const stepsDone = computed(() => steps.value.filter((s) => s.done).length)
const showSteps = computed(() => !overview.loading.value && !sites.loading.value && stepsDone.value < steps.value.length)

const STAGES: { key: string; statuses: ArticleStatus[]; color: string }[] = [
  { key: 'research', statuses: ['IDEA_SELECTED', 'RESEARCHING', 'RESEARCH_COMPLETE', 'BRIEF_READY'], color: 'bg-sky-500' },
  { key: 'writing', statuses: ['OUTLINING', 'WRITING', 'REVISING'], color: 'bg-indigo-500' },
  { key: 'checking', statuses: ['FACT_CHECKING', 'QUALITY_REVIEW'], color: 'bg-violet-500' },
  { key: 'review', statuses: ['NEEDS_HUMAN_REVIEW'], color: 'bg-amber-500' },
  { key: 'approved', statuses: ['APPROVED'], color: 'bg-emerald-500' },
  { key: 'blocked', statuses: ['FAILED'], color: 'bg-rose-500' },
]
const pipeline = computed(() => {
  const counts = overview.data.value?.status_counts ?? {}
  return STAGES.map((s) => ({ ...s, n: s.statuses.reduce((sum, st) => sum + (counts[st] ?? 0), 0) }))
})
const pipelineTotal = computed(() => pipeline.value.reduce((a, s) => a + s.n, 0))

const chartLabels = computed(() => (gsc.data.value?.series ?? []).map((p) => p.date.slice(5).split('-').reverse().join('/')))
const chartValues = computed(() => (gsc.data.value?.series ?? []).map((p) => p.clicks))
const nf = new Intl.NumberFormat('vi-VN')

const siteDot: Record<string, string> = { connected: 'bg-success-500', degraded: 'bg-warning-500', revoked: 'bg-danger-500', pending_pairing: 'bg-slate-300' }
const siteTone = (s: string) => (s === 'connected' ? 'success' : s === 'degraded' ? 'warning' : s === 'revoked' ? 'danger' : 'neutral')
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ t('home.hello', { name: firstName }) }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ todo.length ? t('home.subtitleTodo', { n: todo.length }) : t('home.subtitleClear') }}</p>
      </div>
      <RouterLink :to="`${base}/research`" class="inline-flex items-center gap-2 rounded-lg bg-surface px-3.5 py-2 text-sm font-semibold text-ink shadow-xs ring-1 ring-inset ring-line-strong hover:bg-surface-muted">
        <AppIcon name="sparkles" :size="16" />{{ t('home.findIdeas') }}
      </RouterLink>
    </div>

    <div class="grid grid-cols-1 gap-6" :class="showSteps ? 'lg:grid-cols-3' : ''">
      <AppCard :class="showSteps ? 'lg:col-span-2' : ''" :title="t('home.todo')" :padded="false" data-testid="todo">
        <ul v-if="todo.length" class="divide-y divide-line">
          <li v-for="x in todo" :key="x.key" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 sm:flex-nowrap">
            <span class="grid size-10 shrink-0 place-items-center rounded-lg" :class="x.tone"><AppIcon :name="x.icon" :size="18" /></span>
            <div class="min-w-[12rem] flex-1 sm:min-w-0">
              <p class="text-sm font-semibold text-ink">{{ x.title }}</p>
              <p class="truncate text-sm text-ink-muted">{{ x.desc }}</p>
            </div>
            <RouterLink :to="x.to" class="ml-14 shrink-0 rounded-lg px-3 py-1.5 text-sm font-semibold text-brand-700 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 sm:ml-0">{{ x.cta }}</RouterLink>
          </li>
        </ul>
        <div v-else class="flex items-center gap-3 px-5 py-6 text-sm text-ink-muted">
          <span class="grid size-10 place-items-center rounded-lg bg-success-50 text-success-600"><AppIcon name="check" :size="18" /></span>
          {{ t('home.todoEmpty') }}
        </div>
      </AppCard>

      <AppCard v-if="showSteps" :title="t('home.start.title')" :subtitle="t('home.start.progress', { done: stepsDone, total: steps.length })" data-testid="getting-started">
        <div class="mb-4 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-success-500 transition-all" :style="{ width: `${(stepsDone / steps.length) * 100}%` }" /></div>
        <ul class="space-y-2">
          <li v-for="s in steps" :key="s.key">
            <RouterLink :to="s.to" class="flex items-center gap-3 rounded-lg border border-line px-3 py-2.5 text-sm hover:border-brand-200 hover:bg-brand-50/40">
              <span class="grid size-6 shrink-0 place-items-center rounded-full" :class="s.done ? 'bg-success-600 text-white' : 'border-2 border-line-strong'"><AppIcon v-if="s.done" name="check" :size="14" /></span>
              <span :class="s.done ? 'text-ink-muted line-through' : 'font-medium text-ink'">{{ t(`home.steps.${s.key}`) }}</span>
              <AppIcon v-if="!s.done" name="chevronRight" :size="16" class="ml-auto text-ink-subtle" />
            </RouterLink>
          </li>
        </ul>
      </AppCard>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard
        :label="t('home.kpi.sites')"
        :value="sites.data.value ? `${connectedSites}/${sites.data.value.length}` : '—'"
        icon="globe"
        :hint="sites.data.value ? (brokenSites ? t('home.kpi.sitesBroken', { n: brokenSites }) : connectedSites ? t('home.kpi.sitesOk') : t('home.kpi.sitesNone')) : undefined"
        :to="`${base}/sites`"
      />
      <StatCard
        :label="t('writer.overview.quota')"
        :value="overview.data.value ? `${overview.data.value.quota.used}/${overview.data.value.quota.limit}` : '—'"
        icon="pen"
        :hint="overview.data.value ? t('home.kpi.quotaHint', { n: overview.data.value.quota.remaining }) : undefined"
        :to="`${base}/writer`"
      />
      <StatCard
        :label="t('home.kpi.clicks')"
        :value="hasGsc ? nf.format(gsc.data.value!.totals.clicks) : t('an.common.noData')"
        icon="chart"
        :delta="hasGsc ? gsc.data.value!.change.clicks : null"
        :hint="hasGsc ? t('home.kpi.clicksHint') : t('home.kpi.noGsc')"
        :to="`${base}/analytics`"
      />
      <StatCard
        :label="t('home.kpi.credits')"
        :value="billing.data.value ? formatCredits(billing.data.value.wallet.available_mc) : '—'"
        icon="coins"
        :hint="billing.data.value?.entitlements.plan_name ? t('home.kpi.plan', { plan: billing.data.value.entitlements.plan_name }) : undefined"
        :to="`${base}/billing/credits`"
      />
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <AppCard class="lg:col-span-2" :title="t('home.chart.title')" :subtitle="t('home.chart.subtitle')">
        <LoadingState v-if="gsc.loading.value" :rows="3" />
        <EmptyState v-else-if="!hasGsc" :title="t('home.chart.emptyTitle')" :description="t('home.chart.emptyBody')" icon="chart">
          <RouterLink :to="`${base}/analytics/connections`" class="text-sm font-semibold text-brand-700 hover:underline">{{ t('home.chart.connect') }}</RouterLink>
        </EmptyState>
        <LineChart v-else :labels="chartLabels" :series="[{ name: t('home.chart.clicks'), values: chartValues }]" area :height="200" />
      </AppCard>

      <AppCard :title="t('home.pipeline.title')" :subtitle="t('home.pipeline.subtitle', { n: pipelineTotal })">
        <LoadingState v-if="overview.loading.value" :rows="3" />
        <ErrorState v-else-if="overview.error.value" :error="overview.error.value" @retry="overview.run" />
        <p v-else-if="!pipelineTotal" class="py-6 text-center text-sm text-ink-muted">{{ t('home.pipeline.empty') }}</p>
        <template v-else>
          <div class="mb-5 flex h-3 overflow-hidden rounded-full bg-slate-100">
            <div v-for="p in pipeline.filter((x) => x.n)" :key="p.key" :class="p.color" :style="{ width: `${(p.n / pipelineTotal) * 100}%` }" />
          </div>
          <ul class="space-y-2.5">
            <li v-for="p in pipeline" :key="p.key" class="flex items-center gap-2.5 text-sm">
              <span class="size-2.5 rounded-full" :class="p.color" />
              <span class="text-ink-muted">{{ t(`home.stages.${p.key}`) }}</span>
              <span class="ml-auto font-semibold tabular-nums text-ink">{{ p.n }}</span>
            </li>
          </ul>
        </template>
      </AppCard>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <AppCard class="lg:col-span-2" :title="t('home.recent.title')" :padded="false">
        <template #actions><RouterLink :to="`${base}/writer`" class="text-sm font-medium text-brand-700 hover:underline">{{ t('home.recent.all') }}</RouterLink></template>
        <div v-if="recent.loading.value" class="p-5"><LoadingState :rows="4" /></div>
        <div v-else-if="recent.error.value" class="p-5"><ErrorState :error="recent.error.value" @retry="recent.run" /></div>
        <EmptyState v-else-if="!recent.data.value?.length" :title="t('home.recent.empty')" icon="file">
          <RouterLink :to="`${base}/writer/new`" class="text-sm font-semibold text-brand-700 hover:underline">{{ t('shell.writeNew') }}</RouterLink>
        </EmptyState>
        <ul v-else class="divide-y divide-line">
          <li v-for="a in recent.data.value" :key="a.id">
            <RouterLink :to="`${base}/writer/articles/${a.id}`" class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50">
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">{{ a.title }}</p>
                <p class="text-xs text-ink-muted">{{ (sites.data.value ?? []).find((s) => s.id === a.site_id)?.name ?? '' }} · {{ t('home.recent.updated', { when: formatRelative(a.updated_at) }) }}</p>
              </div>
              <StatusBadge :status="a.status" />
            </RouterLink>
          </li>
        </ul>
      </AppCard>

      <AppCard :title="t('home.sites.title')" :padded="false">
        <template #actions><RouterLink :to="`${base}/sites`" class="text-sm font-medium text-brand-700 hover:underline">{{ t('home.sites.manage') }}</RouterLink></template>
        <div v-if="sites.loading.value" class="p-5"><LoadingState :rows="3" /></div>
        <div v-else-if="sites.error.value" class="p-5"><ErrorState :error="sites.error.value" @retry="sites.run" /></div>
        <EmptyState v-else-if="!sites.data.value?.length" :title="t('home.sites.empty')" icon="globe">
          <RouterLink :to="`${base}/sites`" class="text-sm font-semibold text-brand-700 hover:underline">{{ t('home.sites.add') }}</RouterLink>
        </EmptyState>
        <ul v-else class="divide-y divide-line">
          <li v-for="s in sites.data.value" :key="s.id">
            <RouterLink :to="`${base}/sites/${s.id}`" class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50">
              <span class="size-2.5 shrink-0 rounded-full" :class="siteDot[s.status] ?? 'bg-slate-300'" />
              <span class="min-w-0 flex-1 truncate text-sm text-ink">{{ s.name }}</span>
              <AppBadge :tone="siteTone(s.status)">{{ t(`sites.status.${s.status}`) }}</AppBadge>
            </RouterLink>
          </li>
        </ul>
      </AppCard>
    </div>
  </div>
</template>
