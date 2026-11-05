<script setup lang="ts">
import { computed } from 'vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '../../ui/PageHeader.vue'
import StatCard from '../../ui/StatCard.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import ProgressBar from '../../ui/ProgressBar.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import LineChart from '../../ui/charts/LineChart.vue'
import DonutChart from '../../ui/charts/DonutChart.vue'
import BarChart from '../../ui/charts/BarChart.vue'
import { db } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { fmtNumber, fmtRelative, fmtUsd, fmtVnd } from '../../format'
import { adminCrumbs, mrrMinor, planName, wsName } from '../useAdmin'

const { loading, error, reload } = useDemoQuery()

const activeSites = computed(() => db.sites.filter((s) => s.status === 'connected').length)
const problemSites = computed(() => db.sites.filter((s) => s.status === 'degraded' || s.status === 'disconnected').length)
const days = computed(() => [...new Set(db.aiUsage.map((u) => u.date))].sort())
const costByDay = computed(() => days.value.map((d) => db.aiUsage.filter((u) => u.date === d).reduce((s, u) => s + u.costMicroUsd, 0) / 1e6))
const cost30 = computed(() => costByDay.value.reduce((s, v) => s + v, 0))
const budget = computed(() => db.systemSettings.globalAiBudgetUsd)
const published = computed(() => db.articles.filter((a) => a.status === 'published').length)
const planMix = computed(() => db.plans.map((p) => ({ name: p.name, value: db.subscriptions.filter((s) => s.planCode === p.code).length })).filter((x) => x.value > 0))
const articlesByWs = computed(() => {
  const statuses = [
    { name: 'Đã đăng', match: ['published'] },
    { name: 'Đang xử lý', match: ['brief', 'drafting', 'verifying', 'needs_revision', 'ready_for_review', 'awaiting_approval'] },
    { name: 'Đã duyệt / lên lịch', match: ['approved', 'scheduled', 'publishing'] },
  ]
  return {
    labels: db.workspaces.map((w) => w.name.replace(' (Demo)', '')),
    series: statuses.map((s) => ({ name: s.name, values: db.workspaces.map((w) => db.articles.filter((a) => a.workspaceId === w.id && s.match.includes(a.status)).length) })),
  }
})
const recent = computed(() => db.auditLogs.slice(0, 6))
const queueDepth = computed(() => db.queueJobs.filter((j) => j.status === 'queued' || j.status === 'running').length)
</script>

<template>
  <div>
    <PageHeader title="Tổng quan hệ thống" description="Sức khỏe nền tảng, doanh thu và chi phí AI. Toàn bộ số liệu là dữ liệu demo." :crumbs="adminCrumbs({ label: 'Tổng quan' })" />

    <LoadingState v-if="loading" :rows="6" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <div v-else class="space-y-6">
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Khách hàng" :value="db.workspaces.length" icon="building" :hint="`${db.workspaces.filter((w) => w.status === 'trialing').length} đang dùng thử`" to="/customers" />
        <StatCard label="Website đang kết nối" :value="`${activeSites}/${db.sites.length}`" icon="globe" :hint="problemSites ? `${problemSites} cần kiểm tra` : 'Tất cả ổn định'" to="/websites" />
        <StatCard label="MRR (giả lập)" :value="fmtVnd(mrrMinor())" icon="trending" hint="từ gói đang hoạt động" to="/payments" />
        <StatCard label="Chi phí AI 30 ngày" :value="fmtUsd(cost30)" icon="cpu" :hint="`ngân sách ${fmtUsd(budget)}`" to="/ai-usage" />
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <AppCard class="lg:col-span-2" title="Chi phí AI theo ngày (USD, giả lập)">
          <LineChart :labels="days.map((d) => `${d.slice(8, 10)}/${d.slice(5, 7)}`)" :series="[{ name: 'Chi phí AI', values: costByDay }]" :format="(v) => fmtUsd(v)" area :height="240" />
          <div class="mt-4">
            <div class="mb-1 flex justify-between text-sm"><span class="text-ink-muted">Đã dùng ngân sách tháng</span><span class="tabular-nums">{{ Math.round((cost30 / budget) * 100) }}%</span></div>
            <ProgressBar :value="cost30" :max="budget" tone="auto" label="Ngân sách AI" />
          </div>
        </AppCard>
        <AppCard title="Khách hàng theo gói">
          <DonutChart :segments="planMix" center-label="khách hàng" />
        </AppCard>
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <AppCard class="lg:col-span-2" title="Bài viết theo khách hàng">
          <BarChart :labels="articlesByWs.labels" :series="articlesByWs.series" :height="240" />
        </AppCard>
        <AppCard title="Sức khỏe hệ thống">
          <ul class="space-y-3 text-sm">
            <li v-for="p in db.aiProviders" :key="p.code" class="flex items-center justify-between gap-2">
              <span class="flex items-center gap-2"><AppIcon name="cpu" :size="16" class="text-ink-subtle" />{{ p.name }}</span>
              <StatusBadge group="provider" :value="p.enabled ? p.status : 'down'" />
            </li>
            <li class="flex items-center justify-between gap-2 border-t border-line pt-3">
              <RouterLink to="/queues" class="flex items-center gap-2 hover:underline"><AppIcon name="server" :size="16" class="text-ink-subtle" />Job đang chờ/chạy</RouterLink>
              <span class="font-medium tabular-nums">{{ queueDepth }}</span>
            </li>
            <li class="flex items-center justify-between gap-2">
              <RouterLink to="/failed-jobs" class="flex items-center gap-2 hover:underline"><AppIcon name="bug" :size="16" class="text-ink-subtle" />Job thất bại</RouterLink>
              <span class="font-medium tabular-nums" :class="db.failedJobs.length ? 'text-danger-600' : ''">{{ db.failedJobs.length }}</span>
            </li>
            <li class="flex items-center justify-between gap-2">
              <RouterLink to="/support" class="flex items-center gap-2 hover:underline"><AppIcon name="lifebuoy" :size="16" class="text-ink-subtle" />Ticket đang mở</RouterLink>
              <span class="font-medium tabular-nums">{{ db.tickets.filter((t) => t.status === 'open').length }}</span>
            </li>
            <li class="flex items-center justify-between gap-2">
              <span class="flex items-center gap-2"><AppIcon name="send" :size="16" class="text-ink-subtle" />Bài đã đăng (toàn hệ thống)</span>
              <span class="font-medium tabular-nums">{{ fmtNumber(published) }}</span>
            </li>
          </ul>
        </AppCard>
      </div>

      <div class="grid gap-6 lg:grid-cols-2">
        <AppCard title="Khách hàng" :padded="false">
          <ul class="divide-y divide-line">
            <li v-for="w in db.workspaces" :key="w.id" class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
              <div class="min-w-0"><p class="truncate font-medium">{{ w.name }}</p><p class="text-xs text-ink-muted">{{ planName(w.planCode) }} · {{ db.sites.filter((s) => s.workspaceId === w.id).length }} website</p></div>
              <StatusBadge group="workspace" :value="w.status" />
            </li>
          </ul>
        </AppCard>
        <AppCard title="Hoạt động gần đây" :padded="false">
          <template #actions><RouterLink to="/audit-logs" class="text-sm text-brand-700 hover:underline">Xem tất cả</RouterLink></template>
          <ul class="divide-y divide-line">
            <li v-for="l in recent" :key="l.id" class="px-5 py-3 text-sm">
              <p><span class="font-medium">{{ l.actorName }}</span> · <code class="text-xs">{{ l.action }}</code></p>
              <p class="text-xs text-ink-muted">{{ wsName(l.workspaceId) }} · {{ fmtRelative(l.createdAt) }}</p>
            </li>
          </ul>
        </AppCard>
      </div>
    </div>
  </div>
</template>
