<script setup lang="ts">
import { computed, ref } from 'vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '../../ui/PageHeader.vue'
import StatCard from '../../ui/StatCard.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import LineChart from '../../ui/charts/LineChart.vue'
import BarChart from '../../ui/charts/BarChart.vue'
import ProgressBar from '../../ui/ProgressBar.vue'
import { db } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { gscDaily, sumRange } from '../../data/analytics'
import { fmtCompact, fmtDateTime, fmtNumber, fmtRelative } from '../../format'
import { useWorkspace } from '../useWorkspace'

const { ws, base, mine, sites, siteName, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()
const range = ref<7 | 28 | 90>(28)

const articles = computed(() => mine(db.articles))
const gscSites = computed(() => sites.value.filter((s) => s.gscConnected))
const series = computed(() => {
  const all = gscSites.value.map((s) => gscDaily(s, 90))
  const days = all[0]?.length ?? 0
  const merged = Array.from({ length: days }, (_, i) => ({
    date: all[0]![i]!.date,
    clicks: all.reduce((s, d) => s + d[i]!.clicks, 0),
    impressions: all.reduce((s, d) => s + d[i]!.impressions, 0),
    position: all.reduce((s, d) => s + d[i]!.position, 0) / all.length,
    ctr: 0,
  }))
  return merged
})
const current = computed(() => series.value.slice(-range.value))
const previous = computed(() => series.value.slice(-range.value * 2, -range.value))
const cur = computed(() => sumRange(current.value))
const prev = computed(() => sumRange(previous.value))
const delta = (a: number, b: number) => (b ? ((a - b) / b) * 100 : null)

const connected = computed(() => sites.value.filter((s) => s.status === 'connected').length)
const publishedThisMonth = computed(() => articles.value.filter((a) => a.publishedAt && new Date(a.publishedAt).getMonth() === new Date().getMonth()).length)
const awaiting = computed(() => articles.value.filter((a) => ['awaiting_approval', 'ready_for_review'].includes(a.status)))
const pipeline = computed(() => {
  const groups: [string, string[]][] = [
    ['Dàn ý', ['brief']],
    ['Đang viết', ['drafting']],
    ['Kiểm chứng', ['verifying', 'needs_revision']],
    ['Chờ duyệt', ['ready_for_review', 'awaiting_approval', 'approved']],
    ['Lên lịch', ['scheduled', 'publishing']],
    ['Đã đăng', ['published']],
  ]
  return { labels: groups.map((g) => g[0]), values: groups.map(([, st]) => articles.value.filter((a) => st.includes(a.status)).length) }
})
const upcoming = computed(() =>
  mine(db.calendar)
    .filter((c) => new Date(c.plannedDate).getTime() >= Date.now() - 3_600_000 && c.status !== 'done')
    .sort((a, b) => a.plannedDate.localeCompare(b.plannedDate))
    .slice(0, 5),
)
const recent = computed(() => [...articles.value].sort((a, b) => b.updatedAt.localeCompare(a.updatedAt)).slice(0, 6))
const credits = computed(() => ws.value.credits)
const plan = computed(() => db.plans.find((p) => p.code === ws.value.planCode)!)
const articleUsage = computed(() => db.usage.find((u) => u.workspaceId === ws.value.id && u.metric === 'articles')?.used ?? 0)

const onboarding = computed(() => [
  { done: sites.value.some((s) => s.status === 'connected'), label: 'Kết nối website WordPress đầu tiên', to: `${base.value}/connect` },
  { done: mine(db.campaigns).length > 0, label: 'Tạo chiến dịch SEO', to: `${base.value}/campaigns` },
  { done: mine(db.keywords).length > 0, label: 'Chọn từ khóa mục tiêu', to: `${base.value}/keywords` },
  { done: articles.value.length > 0, label: 'Viết bài đầu tiên với AI Writer', to: `${base.value}/articles` },
])
const isNew = computed(() => onboarding.value.some((s) => !s.done))
</script>

<template>
  <div>
    <PageHeader :title="`Xin chào, ${ws.ownerName.split(' ').slice(-1)[0]} 👋`" description="Tổng quan hiệu quả SEO và tiến độ nội dung của workspace." :crumbs="crumbs({ label: 'Dashboard' })">
      <template #actions>
        <RouterLink :to="`${base}/topics`"><AppButton variant="secondary"><AppIcon name="sparkles" :size="16" /> Tìm ý tưởng</AppButton></RouterLink>
        <RouterLink :to="`${base}/articles?new=1`"><AppButton><AppIcon name="plus" :size="16" /> Viết bài mới</AppButton></RouterLink>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" :rows="6" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <div v-else class="space-y-6">
      <AppCard v-if="isNew" title="Bắt đầu với SiteX Post" subtitle="Hoàn thành các bước sau để chạy chiến dịch SEO đầu tiên.">
        <ul class="grid gap-2 sm:grid-cols-2">
          <li v-for="s in onboarding" :key="s.label">
            <RouterLink :to="s.to" class="flex items-center gap-3 rounded-lg border border-line px-3 py-2.5 text-sm hover:border-brand-200 hover:bg-brand-50/40">
              <span class="grid h-6 w-6 place-items-center rounded-full" :class="s.done ? 'bg-success-600 text-white' : 'border border-line text-ink-subtle'"><AppIcon v-if="s.done" name="check" :size="14" /></span>
              <span :class="s.done ? 'text-ink-muted line-through' : 'font-medium'">{{ s.label }}</span>
              <AppIcon name="chevronRight" :size="16" class="ml-auto text-ink-subtle" />
            </RouterLink>
          </li>
        </ul>
      </AppCard>

      <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        <StatCard label="Website kết nối" :value="`${connected}/${sites.length}`" icon="globe" :to="`${base}/sites`" hint="đang hoạt động" />
        <StatCard label="Bài đã đăng tháng này" :value="publishedThisMonth" icon="send" :to="`${base}/publishing`" />
        <StatCard label="Chờ biên tập / duyệt" :value="awaiting.length" icon="pen" :to="`${base}/articles?status=awaiting_approval`" />
        <StatCard label="Lượt click (GSC)" :value="gscSites.length ? fmtCompact(cur.clicks) : '—'" icon="chart" :delta="gscSites.length ? delta(cur.clicks, prev.clicks) : null" :hint="gscSites.length ? `${range} ngày, mô phỏng` : 'Chưa kết nối GSC'" :to="`${base}/analytics`" />
        <StatCard label="Credit còn lại" :value="fmtNumber(credits)" icon="coins" :to="`${base}/usage`" :hint="`Gói ${plan.name}`" />
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <AppCard class="lg:col-span-2" title="Hiệu quả tìm kiếm" subtitle="Dữ liệu Google Search Console mô phỏng – tổng các website đã kết nối">
          <template #actions>
            <div class="flex rounded-lg border border-line p-0.5 text-xs" role="group" aria-label="Khoảng thời gian">
              <button v-for="r in [7, 28, 90] as const" :key="r" type="button" class="rounded-md px-2.5 py-1" :class="range === r ? 'bg-brand-600 text-white' : 'text-ink-muted hover:bg-surface-muted'" @click="range = r">{{ r }} ngày</button>
            </div>
          </template>
          <EmptyState v-if="!gscSites.length" title="Chưa có dữ liệu Search Console" description="Kết nối Google Search Console cho website để xem lượt click và hiển thị." icon="chart">
            <RouterLink :to="`${base}/analytics`" class="text-sm font-medium text-brand-700 hover:underline">Kết nối Search Console</RouterLink>
          </EmptyState>
          <template v-else>
            <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
              <div><p class="text-xs text-ink-muted">Click</p><p class="text-lg font-semibold tabular-nums">{{ fmtNumber(cur.clicks) }}</p></div>
              <div><p class="text-xs text-ink-muted">Hiển thị</p><p class="text-lg font-semibold tabular-nums">{{ fmtCompact(cur.impressions) }}</p></div>
              <div><p class="text-xs text-ink-muted">CTR TB</p><p class="text-lg font-semibold tabular-nums">{{ cur.ctr.toFixed(2).replace('.', ',') }}%</p></div>
              <div><p class="text-xs text-ink-muted">Vị trí TB</p><p class="text-lg font-semibold tabular-nums">{{ cur.position.toFixed(1).replace('.', ',') }}</p></div>
            </div>
            <LineChart :labels="current.map((d) => d.date.slice(5).split('-').reverse().join('/'))" :series="[{ name: 'Lượt click', values: current.map((d) => d.clicks) }]" area :height="200" />
          </template>
        </AppCard>

        <AppCard title="Tiến độ nội dung" subtitle="Số bài theo giai đoạn pipeline">
          <BarChart :labels="pipeline.labels" :series="[{ name: 'Bài viết', values: pipeline.values }]" :height="200" />
          <div class="mt-6 border-t border-line pt-4">
            <div class="mb-1 flex justify-between text-sm"><span class="text-ink-muted">Bài viết tháng này</span><span class="font-medium tabular-nums">{{ articleUsage }}/{{ plan.limits.articles }}</span></div>
            <ProgressBar :value="articleUsage" :max="plan.limits.articles" tone="auto" label="Hạn mức bài viết" />
          </div>
        </AppCard>
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <AppCard class="lg:col-span-2" title="Hoạt động gần đây" :padded="false">
          <template #actions><RouterLink :to="`${base}/articles`" class="text-sm text-brand-700 hover:underline">Xem tất cả</RouterLink></template>
          <EmptyState v-if="!recent.length" title="Chưa có bài viết" description="Tạo bài viết đầu tiên từ ý tưởng hoặc từ khóa." icon="file" />
          <ul v-else class="divide-y divide-line">
            <li v-for="a in recent" :key="a.id">
              <RouterLink :to="`${base}/articles/${a.id}`" class="flex items-center gap-3 px-5 py-3 hover:bg-surface-muted/60">
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium">{{ a.title }}</p>
                  <p class="text-xs text-ink-muted">{{ siteName(a.siteId) }} · cập nhật {{ fmtRelative(a.updatedAt) }}</p>
                </div>
                <StatusBadge group="article" :value="a.status" />
              </RouterLink>
            </li>
          </ul>
        </AppCard>

        <div class="space-y-6">
          <AppCard title="Sắp đăng" :padded="false">
            <template #actions><RouterLink :to="`${base}/calendar`" class="text-sm text-brand-700 hover:underline">Lịch</RouterLink></template>
            <p v-if="!upcoming.length" class="px-5 py-6 text-center text-sm text-ink-muted">Chưa có lịch đăng sắp tới.</p>
            <ul v-else class="divide-y divide-line">
              <li v-for="c in upcoming" :key="c.id" class="flex gap-3 px-5 py-3">
                <div class="w-12 shrink-0 rounded-lg bg-brand-50 py-1 text-center text-brand-700">
                  <p class="text-[10px] uppercase">Th{{ new Date(c.plannedDate).getMonth() + 1 }}</p>
                  <p class="text-base font-semibold leading-tight">{{ new Date(c.plannedDate).getDate() }}</p>
                </div>
                <div class="min-w-0">
                  <p class="line-clamp-2 text-sm">{{ c.title }}</p>
                  <p class="text-xs text-ink-muted">{{ fmtDateTime(c.plannedDate) }}</p>
                </div>
              </li>
            </ul>
          </AppCard>
          <AppCard title="Sức khỏe website" :padded="false">
            <ul class="divide-y divide-line">
              <li v-for="s in sites" :key="s.id">
                <RouterLink :to="`${base}/sites/${s.id}`" class="flex items-center gap-3 px-5 py-3 hover:bg-surface-muted/60">
                  <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="{ connected: 'bg-success-600', degraded: 'bg-warning-600', disconnected: 'bg-danger-600', pending_pairing: 'bg-slate-300', revoked: 'bg-slate-300' }[s.status]" />
                  <span class="min-w-0 flex-1 truncate text-sm">{{ s.name }}</span>
                  <StatusBadge group="site" :value="s.status" />
                </RouterLink>
              </li>
            </ul>
          </AppCard>
        </div>
      </div>
    </div>
  </div>
</template>
