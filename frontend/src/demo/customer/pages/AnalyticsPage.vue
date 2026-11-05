<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import Tabs from '../../ui/Tabs.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import LineChart from '../../ui/charts/LineChart.vue'
import Sparkline from '../../ui/charts/Sparkline.vue'
import { db, demoMutation, newId, sleep } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { gscDaily, gscTopPages, gscTopQueries, sumRange } from '../../data/analytics'
import { fmtCompact, fmtNumber } from '../../format'
import { slugify } from '../../data/seed'
import { useWorkspace } from '../useWorkspace'

const router = useRouter()
const toast = useToast()
const { ws, base, sites, mine, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const eligible = computed(() => sites.value.filter((s) => s.status === 'connected' || s.status === 'degraded'))
const siteId = ref(eligible.value.find((s) => s.gscConnected)?.id ?? eligible.value[0]?.id ?? '')
const site = computed(() => sites.value.find((s) => s.id === siteId.value))
const range = ref(28)
const metric = ref<'clicks' | 'impressions' | 'ctr' | 'position'>('clicks')
const daily = computed(() => (site.value?.gscConnected ? gscDaily(site.value, 180) : []))
const current = computed(() => daily.value.slice(-range.value))
const previous = computed(() => daily.value.slice(-range.value * 2, -range.value))
const cur = computed(() => sumRange(current.value))
const prev = computed(() => sumRange(previous.value))
const pct = (a: number, b: number) => (b ? ((a - b) / b) * 100 : 0)
const metricDefs = computed(() => [
  { key: 'clicks' as const, label: 'Lượt click', value: fmtNumber(cur.value.clicks), delta: pct(cur.value.clicks, prev.value.clicks), good: true },
  { key: 'impressions' as const, label: 'Lượt hiển thị', value: fmtCompact(cur.value.impressions), delta: pct(cur.value.impressions, prev.value.impressions), good: true },
  { key: 'ctr' as const, label: 'CTR trung bình', value: `${cur.value.ctr.toFixed(2).replace('.', ',')}%`, delta: pct(cur.value.ctr, prev.value.ctr), good: true },
  { key: 'position' as const, label: 'Vị trí trung bình', value: cur.value.position.toFixed(1).replace('.', ','), delta: pct(cur.value.position, prev.value.position), good: false },
])
const chartValues = computed(() => current.value.map((d) => (metric.value === 'ctr' ? (d.clicks / Math.max(1, d.impressions)) * 100 : d[metric.value])))
const chartFormat = computed(() => (metric.value === 'ctr' ? (v: number) => `${v.toFixed(1)}%` : metric.value === 'position' ? (v: number) => v.toFixed(1) : (v: number) => fmtCompact(v)))

const tab = ref('queries')
const queries = computed(() => (site.value?.gscConnected ? gscTopQueries(site.value, mine(db.keywords)) : []))
const pages = computed(() => (site.value?.gscConnected ? gscTopPages(site.value, mine(db.articles)) : []))
const qTable = useTable(() => queries.value.map((q) => ({ ...q, id: q.query })), {
  search: (q) => q.query,
  sorts: { clicks: (q) => q.clicks, impressions: (q) => q.impressions, ctr: (q) => q.ctr, position: (q) => q.position },
  defaultSort: { key: 'clicks', dir: 'desc' },
})
const pTable = useTable(() => pages.value.map((p) => ({ ...p, id: p.url })), {
  search: (p) => `${p.title} ${p.url}`,
  sorts: { clicks: (p) => p.clicks, impressions: (p) => p.impressions, ctr: (p) => p.ctr, position: (p) => p.position },
  defaultSort: { key: 'clicks', dir: 'desc' },
})
const suggestions = computed(() =>
  queries.value
    .filter((q) => q.change < -10)
    .slice(0, 4)
    .map((q) => ({ ...q, reason: q.position > 10 ? 'Vị trí ngoài top 10, có thể cải thiện nội dung' : 'Lượt click giảm so với kỳ trước' })),
)
async function createRefresh(query: string) {
  const s = site.value
  if (!s) return
  try {
    const id = await demoMutation(() => {
      const title = `Cập nhật nội dung: ${query}`
      const now = new Date().toISOString()
      const a = { id: newId('art'), workspaceId: ws.value.id, siteId: s.id, campaignId: null, ideaId: null, keywordId: db.keywords.find((k) => k.keyword === query)?.id ?? null, clusterId: null, title, slug: slugify(title), metaDescription: '', status: 'brief' as const, verificationStatus: 'pending' as const, seoScore: 0, wordCount: 0, sections: [], versions: [], featuredMediaId: null, authorName: ws.value.ownerName, scheduledAt: null, publishedAt: null, wpUrl: null, createdAt: now, updatedAt: now }
      db.articles.unshift(a)
      return a.id
    })
    toast.success('Đã tạo bài cập nhật từ đề xuất Search Console.')
    void router.push(`${base.value}/articles/${id}`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}

// Simulated Google OAuth
const connectOpen = ref(false)
const propertyOptions = computed(() => {
  if (!site.value) return []
  const host = new URL(site.value.url).hostname
  return [
    { value: `sc-domain:${host}`, label: `sc-domain:${host}` },
    { value: `${site.value.url}/`, label: `${site.value.url}/ (URL prefix)` },
  ]
})
const property = ref('')
const consent = ref(false)
const connecting = ref(false)
const connectError = ref('')
function openConnect() {
  property.value = site.value ? `sc-domain:${new URL(site.value.url).hostname}` : ''
  consent.value = false
  connectError.value = ''
  connectOpen.value = true
}
async function doConnect() {
  if (!consent.value) {
    connectError.value = 'Bạn cần đồng ý cấp quyền đọc dữ liệu Search Console.'
    return
  }
  connecting.value = true
  try {
    await sleep(900)
    await demoMutation(() => {
      if (site.value) site.value.gscConnected = true
    })
    toast.success('Đã kết nối Google Search Console (mô phỏng, quyền chỉ đọc).')
    connectOpen.value = false
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    connecting.value = false
  }
}
const disconnectOpen = ref(false)
async function disconnect() {
  try {
    await demoMutation(() => {
      if (site.value) site.value.gscConnected = false
    })
    toast.success('Đã ngắt kết nối Search Console và thu hồi token (mô phỏng).')
  } catch (e) {
    toast.error((e as Error).message)
  }
  disconnectOpen.value = false
}
const syncing = ref(false)
async function sync() {
  syncing.value = true
  try {
    await demoMutation(() => undefined, 900)
    toast.success('Đã đồng bộ dữ liệu mới nhất (dữ liệu GSC thường trễ 2–3 ngày).')
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    syncing.value = false
  }
}
const label = (d: string) => d.slice(5).split('-').reverse().join('/')
</script>

<template>
  <div>
    <PageHeader title="Google Search Console" description="Hiệu quả tìm kiếm tự nhiên của website. Trong demo, số liệu GSC là dữ liệu mô phỏng." :crumbs="crumbs({ label: 'Search Console' })">
      <template #actions>
        <label v-if="eligible.length" class="text-sm"><span class="sr-only">Website</span>
          <select v-model="siteId" class="rounded-lg border border-line bg-surface py-2 pl-3 pr-8 text-sm">
            <option v-for="s in eligible" :key="s.id" :value="s.id">{{ s.name }}{{ s.gscConnected ? '' : ' (chưa kết nối GSC)' }}</option>
          </select>
        </label>
        <template v-if="site?.gscConnected">
          <AppButton variant="secondary" :loading="syncing" @click="sync"><AppIcon name="refresh" :size="16" /> Đồng bộ</AppButton>
          <AppButton variant="ghost" @click="disconnectOpen = true">Ngắt kết nối</AppButton>
        </template>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" :rows="6" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <AppCard v-else-if="!eligible.length"><EmptyState title="Chưa có website đã kết nối" description="Kết nối website WordPress trước, sau đó liên kết Google Search Console." icon="chart" /></AppCard>
    <AppCard v-else-if="!site?.gscConnected">
      <EmptyState title="Chưa kết nối Google Search Console" :description="`Kết nối GSC cho ${site?.name} để xem lượt click, hiển thị, từ khóa và nhận đề xuất làm mới nội dung.`" icon="chart">
        <AppButton @click="openConnect"><AppIcon name="link" :size="16" /> Kết nối Search Console</AppButton>
      </EmptyState>
    </AppCard>
    <template v-else>
      <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="flex rounded-lg border border-line bg-surface p-0.5 text-sm" role="group" aria-label="Khoảng thời gian">
          <button v-for="r in [7, 28, 90]" :key="r" type="button" class="rounded-md px-3 py-1.5" :class="range === r ? 'bg-brand-600 text-white' : 'text-ink-muted hover:bg-surface-muted'" @click="range = r">{{ r }} ngày</button>
        </div>
        <span class="text-xs text-ink-muted">So sánh với {{ range }} ngày trước đó</span>
      </div>
      <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <button v-for="m in metricDefs" :key="m.key" type="button" class="rounded-xl border bg-surface p-4 text-left shadow-sm" :class="metric === m.key ? 'border-brand-500 ring-2 ring-brand-100' : 'border-line hover:border-brand-200'" :aria-pressed="metric === m.key" @click="metric = m.key">
          <p class="text-sm text-ink-muted">{{ m.label }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums">{{ m.value }}</p>
          <p class="mt-1 text-xs font-medium" :class="(m.good ? m.delta >= 0 : m.delta <= 0) ? 'text-success-600' : 'text-danger-600'">{{ m.delta >= 0 ? '▲' : '▼' }} {{ Math.abs(m.delta).toFixed(1).replace('.', ',') }}%</p>
        </button>
      </div>
      <AppCard :title="metricDefs.find((m) => m.key === metric)?.label" subtitle="Theo ngày · dữ liệu mô phỏng" class="mb-6">
        <LineChart :labels="current.map((d) => label(d.date))" :series="[{ name: metricDefs.find((m) => m.key === metric)?.label ?? '', values: chartValues }]" :format="chartFormat" :invert="metric === 'position'" area :height="240" />
        <p v-if="metric === 'position'" class="mt-2 text-xs text-ink-muted">Trục vị trí đảo ngược: càng cao càng tốt (vị trí 1 ở trên cùng).</p>
      </AppCard>

      <div class="grid gap-6 xl:grid-cols-3">
        <AppCard :padded="false" class="xl:col-span-2">
          <div class="px-5 pt-3"><Tabs v-model="tab" :tabs="[{ key: 'queries', label: 'Truy vấn', count: queries.length }, { key: 'pages', label: 'Trang', count: pages.length }]" /></div>
          <template v-if="tab === 'queries'">
            <Toolbar :count="qTable.total.value" :filtered="qTable.hasActiveFilters.value" @clear="qTable.clearFilters()"><SearchInput v-model="qTable.q.value" placeholder="Tìm truy vấn…" /></Toolbar>
            <DataTable :table="qTable" :columns="[{ key: 'query', label: 'Truy vấn' }, { key: 'clicks', label: 'Click', sortable: true, align: 'right' }, { key: 'impressions', label: 'Hiển thị', sortable: true, align: 'right', hideBelow: 'sm' }, { key: 'ctr', label: 'CTR', sortable: true, align: 'right', hideBelow: 'md' }, { key: 'position', label: 'Vị trí', sortable: true, align: 'right' }]" empty-title="Chưa có truy vấn">
              <template #cell-query="{ row }"><span class="font-medium">{{ row.query }}</span> <span class="text-xs" :class="row.change >= 0 ? 'text-success-600' : 'text-danger-600'">{{ row.change >= 0 ? '+' : '' }}{{ row.change }}%</span></template>
              <template #cell-clicks="{ row }"><span class="tabular-nums">{{ fmtNumber(row.clicks) }}</span></template>
              <template #cell-impressions="{ row }"><span class="tabular-nums text-ink-muted">{{ fmtNumber(row.impressions) }}</span></template>
              <template #cell-ctr="{ row }"><span class="tabular-nums text-ink-muted">{{ row.ctr.toFixed(1).replace('.', ',') }}%</span></template>
              <template #cell-position="{ row }"><span class="tabular-nums">{{ row.position.toFixed(1).replace('.', ',') }}</span></template>
            </DataTable>
          </template>
          <template v-else>
            <Toolbar :count="pTable.total.value" :filtered="pTable.hasActiveFilters.value" @clear="pTable.clearFilters()"><SearchInput v-model="pTable.q.value" placeholder="Tìm trang…" /></Toolbar>
            <DataTable :table="pTable" :columns="[{ key: 'title', label: 'Trang' }, { key: 'trend', label: 'Xu hướng', hideBelow: 'md' }, { key: 'clicks', label: 'Click', sortable: true, align: 'right' }, { key: 'ctr', label: 'CTR', sortable: true, align: 'right', hideBelow: 'sm' }, { key: 'position', label: 'Vị trí', sortable: true, align: 'right' }]" empty-title="Chưa có trang">
              <template #cell-title="{ row }">
                <p class="line-clamp-1 font-medium">{{ row.title }} <span v-if="row.managed" class="rounded bg-brand-50 px-1 text-[10px] font-medium text-brand-700">SiteX</span></p>
                <p class="truncate text-xs text-ink-muted">{{ row.url }}</p>
              </template>
              <template #cell-trend="{ row }"><Sparkline :values="row.trend" /></template>
              <template #cell-clicks="{ row }"><span class="tabular-nums">{{ fmtNumber(row.clicks) }}</span></template>
              <template #cell-ctr="{ row }"><span class="tabular-nums text-ink-muted">{{ row.ctr.toFixed(1).replace('.', ',') }}%</span></template>
              <template #cell-position="{ row }"><span class="tabular-nums">{{ row.position.toFixed(1).replace('.', ',') }}</span></template>
            </DataTable>
          </template>
        </AppCard>
        <AppCard title="Đề xuất làm mới nội dung" subtitle="Dựa trên số liệu GSC (mô phỏng)">
          <p v-if="!suggestions.length" class="text-sm text-ink-muted">Không có truy vấn nào giảm mạnh.</p>
          <ul v-else class="space-y-3">
            <li v-for="s in suggestions" :key="s.query" class="rounded-lg border border-line p-3">
              <p class="text-sm font-medium">{{ s.query }}</p>
              <p class="mt-0.5 text-xs text-ink-muted">{{ s.reason }} · click {{ s.change }}% · vị trí {{ s.position.toFixed(1) }}</p>
              <AppButton class="mt-2" size="sm" variant="secondary" @click="createRefresh(s.query)"><AppIcon name="pen" :size="14" /> Tạo bài cập nhật</AppButton>
            </li>
          </ul>
        </AppCard>
      </div>
    </template>

    <AppModal :open="connectOpen" title="Kết nối Google Search Console" @close="connectOpen = false">
      <div class="space-y-4">
        <div class="rounded-lg border border-line p-4 text-sm">
          <p class="mb-1 font-medium">Đăng nhập Google (mô phỏng)</p>
          <p class="text-ink-muted">Tài khoản: {{ ws.ownerEmail }}</p>
        </div>
        <AppSelect v-model="property" label="Thuộc tính (property)" :options="propertyOptions" />
        <label class="flex items-start gap-2 text-sm"><input v-model="consent" type="checkbox" class="mt-0.5" @change="connectError = ''" /> Cho phép SiteX Post <strong>chỉ đọc</strong> dữ liệu hiệu suất tìm kiếm của thuộc tính này.</label>
        <p v-if="connectError" class="text-xs text-danger-600">{{ connectError }}</p>
        <p class="text-xs text-ink-subtle">Demo: không chuyển hướng tới Google, không lưu token thật.</p>
      </div>
      <template #footer>
        <AppButton variant="secondary" @click="connectOpen = false">Hủy</AppButton>
        <AppButton :loading="connecting" @click="doConnect">Kết nối</AppButton>
      </template>
    </AppModal>
    <ConfirmDialog :open="disconnectOpen" title="Ngắt kết nối Search Console?" message="SiteX sẽ thu hồi token và ngừng đồng bộ dữ liệu. Dữ liệu đã đồng bộ vẫn được giữ." confirm-label="Ngắt kết nối" danger @close="disconnectOpen = false" @confirm="disconnect" />
  </div>
</template>
