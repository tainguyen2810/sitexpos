<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi } from '@/shared/api/admin'
import { adminAiApi, formatUsd } from '@/shared/api/ai'
import { systemApi, type HealthStatus } from '@/shared/api/system'
import { useAsync } from '@/shared/composables/useAsync'
import { useSessionStore } from '@/shared/stores/session'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppIcon, { type IconName } from '@/shared/ui/AppIcon.vue'
import StatCard from '@/shared/ui/StatCard.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LineChart from '@/shared/ui/charts/LineChart.vue'

const { t } = useI18n()
const session = useSessionStore()
const perms = computed(() => session.user?.platform_permissions ?? [])
const can = (p: string) => perms.value.includes(p)

// Each block loads independently and only when the staff role may see it (the API checks again).
const dash = useAsync(() => adminApi.dashboard())
const usage = useAsync(() => (can('platform.ai.view_costs') ? adminAiApi.usage({ days: 30 }) : Promise.resolve(null)))
const jobs = useAsync(() => (can('platform.jobs.manage') ? adminAiApi.jobsOverview() : Promise.resolve(null)))
const health = useAsync(() => (can('platform.system.view') ? systemApi.health() : Promise.resolve(null)))

const nf = new Intl.NumberFormat('vi-VN')
const jobIssues = computed(() => (jobs.data.value ? jobs.data.value.stuck + jobs.data.value.dead_letters_open + jobs.data.value.failed_jobs : 0))
const chartLabels = computed(() => (usage.data.value?.by_day ?? []).map((d) => d.date.slice(5).split('-').reverse().join('/')))
const chartValues = computed(() => (usage.data.value?.by_day ?? []).map((d) => d.cost_micro_usd / 1_000_000))
const hasUsage = computed(() => (usage.data.value?.totals.requests ?? 0) > 0)
const usd = (v: number) => `$${v.toFixed(2)}`

const order: Record<HealthStatus, number> = { critical: 0, warning: 1, unknown: 2, ok: 3 }
const checks = computed(() => [...(health.data.value?.checks ?? [])].sort((a, b) => order[a.status] - order[b.status]))
const issues = computed(() => checks.value.filter((c) => c.status === 'critical' || c.status === 'warning').length)
const tone = (s: HealthStatus) => (s === 'ok' ? 'success' : s === 'warning' ? 'warning' : s === 'critical' ? 'danger' : 'neutral')
const dot = (s: HealthStatus) => (s === 'ok' ? 'bg-success-500' : s === 'warning' ? 'bg-warning-500' : s === 'critical' ? 'bg-danger-500' : 'bg-slate-300')

const shortcuts = computed(() =>
  (
    [
      { label: t('nav.workspaces'), to: '/workspaces', icon: 'building', p: 'platform.tenants.view' },
      { label: t('nav.aiProviders'), to: '/ai/providers', icon: 'cpu', p: 'platform.ai.manage' },
      { label: t('nav.aiSettings'), to: '/ai/settings', icon: 'sparkles', p: 'platform.ai.view_costs' },
      { label: t('nav.billing'), to: '/billing/plans', icon: 'card', p: 'platform.billing.manage' },
      { label: t('nav.jobs'), to: '/jobs', icon: 'layers', p: 'platform.jobs.manage' },
      { label: t('nav.system'), to: '/system/health', icon: 'server', p: 'platform.system.view' },
    ] as { label: string; to: string; icon: IconName; p: string }[]
  ).filter((s) => can(s.p)),
)
</script>

<template>
  <div class="space-y-6">
    <PageHeader
      :title="$t('admin.dashboard')"
      :description="dash.data.value ? `${t('adminHome.subtitle')} ${t('admin.generatedAt', { time: formatDateTime(dash.data.value.generated_at) })}.` : t('adminHome.subtitle')"
    />

    <LoadingState v-if="dash.loading.value" :rows="2" />
    <ErrorState v-else-if="dash.error.value" :error="dash.error.value" @retry="dash.run" />
    <div v-else-if="dash.data.value" class="grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard :label="t('adminHome.kpi.workspaces')" :value="nf.format(dash.data.value.workspaces.total)" icon="building" :hint="t('adminHome.kpi.workspacesHint', { n: dash.data.value.workspaces.new_7d })" :to="can('platform.tenants.view') ? '/workspaces' : undefined" />
      <StatCard :label="t('adminHome.kpi.users')" :value="nf.format(dash.data.value.users.total)" icon="users" :hint="t('adminHome.kpi.usersHint', { n: dash.data.value.users.verified })" :to="can('platform.users.view') ? '/users' : undefined" />
      <StatCard v-if="usage.data.value" :label="t('adminHome.kpi.aiCost')" :value="formatUsd(usage.data.value.totals.cost_micro_usd)" icon="coins" :hint="t('adminHome.kpi.aiCostHint', { n: nf.format(usage.data.value.totals.requests) })" to="/ai/usage" />
      <StatCard v-else :label="t('admin.pendingInvitations')" :value="nf.format(dash.data.value.invitations_pending)" icon="mail" />
      <StatCard v-if="jobs.data.value" :label="t('adminHome.kpi.jobs')" :value="nf.format(jobIssues)" icon="alert" :hint="t('adminHome.kpi.jobsHint', { stuck: jobs.data.value.stuck, dead: jobs.data.value.dead_letters_open })" to="/jobs" />
      <StatCard v-else :label="t('admin.newUsers30')" :value="nf.format(dash.data.value.users.new_30d)" icon="user" />
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <AppCard v-if="can('platform.ai.view_costs')" class="lg:col-span-2" :title="t('adminHome.chart.title')" :subtitle="t('adminHome.chart.subtitle')">
        <LoadingState v-if="usage.loading.value" :rows="3" />
        <ErrorState v-else-if="usage.error.value" :error="usage.error.value" @retry="usage.run" />
        <EmptyState v-else-if="!hasUsage" :title="t('adminHome.chart.empty')" icon="chart" />
        <LineChart v-else :labels="chartLabels" :series="[{ name: t('adminHome.chart.cost'), values: chartValues }]" area :height="200" :format="usd" />
      </AppCard>

      <AppCard
        v-if="can('platform.system.view')"
        :title="t('adminHome.health.title')"
        :subtitle="health.data.value ? (issues ? t('adminHome.health.issues', { n: issues }) : t('adminHome.health.allOk')) : undefined"
        :padded="false"
      >
        <template #actions><RouterLink to="/system/health" class="text-sm font-medium text-brand-700 hover:underline">{{ t('adminHome.health.all') }}</RouterLink></template>
        <div v-if="health.loading.value" class="p-5"><LoadingState :rows="4" /></div>
        <div v-else-if="health.error.value" class="p-5"><ErrorState :error="health.error.value" @retry="health.run" /></div>
        <ul v-else class="max-h-80 divide-y divide-line overflow-y-auto">
          <li v-for="c in checks" :key="c.key" class="flex items-center gap-3 px-5 py-2.5">
            <span class="size-2.5 shrink-0 rounded-full" :class="dot(c.status)" />
            <span class="min-w-0 flex-1 truncate text-sm text-ink">{{ t(`sys.health.checks.${c.key}`) }}</span>
            <AppBadge :tone="tone(c.status)">{{ t(`adminHome.status.${c.status}`) }}</AppBadge>
          </li>
        </ul>
      </AppCard>
    </div>

    <AppCard v-if="shortcuts.length" :title="t('adminHome.shortcuts.title')">
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <RouterLink v-for="s in shortcuts" :key="s.to" :to="s.to" class="flex flex-col items-start gap-2 rounded-lg border border-line p-3 text-sm font-medium text-ink hover:border-brand-200 hover:bg-brand-50/40">
          <span class="grid size-9 place-items-center rounded-lg bg-brand-50 text-brand-600"><AppIcon :name="s.icon" :size="18" /></span>
          {{ s.label }}
        </RouterLink>
      </div>
    </AppCard>
  </div>
</template>
