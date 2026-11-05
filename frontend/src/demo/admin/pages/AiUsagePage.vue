<script setup lang="ts">
import { computed, ref } from 'vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatCard from '../../ui/StatCard.vue'
import ProgressBar from '../../ui/ProgressBar.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import LineChart from '../../ui/charts/LineChart.vue'
import BarChart from '../../ui/charts/BarChart.vue'
import { db } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { fmtCompact, fmtNumber, fmtPct, fmtUsd } from '../../format'
import { downloadFile, toCsv } from '../../download'
import { adminCrumbs, wsName } from '../useAdmin'

const toast = useToast()
const { loading, error, reload } = useDemoQuery()

const range = ref('30')
const provider = ref('')
const days = computed(() => [...new Set(db.aiUsage.map((u) => u.date))].sort().slice(-Number(range.value)))
const rows = computed(() => db.aiUsage.filter((u) => days.value.includes(u.date) && (!provider.value || u.providerCode === provider.value)))
const totals = computed(() => {
  const r = rows.value
  const req = r.reduce((s, u) => s + u.requests, 0)
  return {
    cost: r.reduce((s, u) => s + u.costMicroUsd, 0) / 1e6,
    requests: req,
    tokens: r.reduce((s, u) => s + u.inputTokens + u.outputTokens, 0),
    errorRate: req ? (r.reduce((s, u) => s + u.errors, 0) / req) * 100 : 0,
  }
})
const budget = computed(() => db.systemSettings.globalAiBudgetUsd)
const monthCost = computed(() => db.aiUsage.reduce((s, u) => s + u.costMicroUsd, 0) / 1e6)
const providers = computed(() => db.aiProviders.filter((p) => !provider.value || p.code === provider.value))
const costChart = computed(() => ({
  labels: days.value.map((d) => `${d.slice(8, 10)}/${d.slice(5, 7)}`),
  series: providers.value.map((p) => ({ name: p.name, values: days.value.map((d) => rows.value.filter((u) => u.date === d && u.providerCode === p.code).reduce((s, u) => s + u.costMicroUsd, 0) / 1e6) })),
}))
const taskChart = computed(() => {
  const tasks = db.taskRoutes
  return {
    labels: tasks.map((t) => t.label),
    series: [{ name: 'Chi phí (USD)', values: tasks.map((t) => rows.value.filter((u) => u.taskKey === t.taskKey).reduce((s, u) => s + u.costMicroUsd, 0) / 1e6) }],
  }
})

// Per-workspace breakdown
const byWorkspace = computed(() =>
  db.workspaces.map((w) => {
    const r = rows.value.filter((u) => u.workspaceId === w.id)
    const req = r.reduce((s, u) => s + u.requests, 0)
    return {
      id: w.id,
      name: wsName(w.id),
      requests: req,
      tokens: r.reduce((s, u) => s + u.inputTokens + u.outputTokens, 0),
      cost: r.reduce((s, u) => s + u.costMicroUsd, 0) / 1e6,
      errors: r.reduce((s, u) => s + u.errors, 0),
      errorRate: req ? (r.reduce((s, u) => s + u.errors, 0) / req) * 100 : 0,
    }
  }),
)
const table = useTable(() => byWorkspace.value, {
  search: (w) => w.name,
  filters: { initial: { usage: '' }, match: { usage: (w, v) => (v === 'active' ? w.requests > 0 : w.requests === 0) } },
  sorts: { name: (w) => w.name, requests: (w) => w.requests, tokens: (w) => w.tokens, cost: (w) => w.cost, errorRate: (w) => w.errorRate },
  defaultSort: { key: 'cost', dir: 'desc' },
})
function exportCsv() {
  downloadFile(
    `ai-usage-${range.value}d-demo.csv`,
    toCsv(table.sorted.value.map((w) => ({ 'Khách hàng': w.name, Requests: w.requests, Tokens: w.tokens, 'Chi phí USD (giả lập)': w.cost.toFixed(2), 'Lỗi': w.errors }))),
    'text/csv;charset=utf-8',
  )
  toast.success('Đã xuất báo cáo chi phí AI (dữ liệu demo).')
}
</script>

<template>
  <div>
    <PageHeader title="AI Usage & Costs" description="Mọi lời gọi AI đi qua AI Gateway được ghi lại theo khách hàng, tác vụ và nhà cung cấp. Số liệu là giả lập." :crumbs="adminCrumbs({ label: 'AI Usage & Costs' })">
      <template #actions>
        <AppButton variant="secondary" @click="exportCsv"><AppIcon name="download" :size="16" /> Xuất CSV</AppButton>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" :rows="6" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <div v-else class="space-y-6">
      <div class="flex flex-wrap items-center gap-3">
        <FilterSelect v-model="range" label="Khoảng thời gian" :options="[{ value: '7', label: '7 ngày' }, { value: '14', label: '14 ngày' }, { value: '30', label: '30 ngày' }]" />
        <FilterSelect v-model="provider" label="Nhà cung cấp" :options="[{ value: '', label: 'Tất cả' }, ...db.aiProviders.map((p) => ({ value: p.code, label: p.name }))]" />
      </div>

      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Chi phí" :value="fmtUsd(totals.cost)" icon="coins" :hint="`${range} ngày`" />
        <StatCard label="Requests" :value="fmtCompact(totals.requests)" icon="activity" />
        <StatCard label="Tokens" :value="fmtCompact(totals.tokens)" icon="cpu" />
        <StatCard label="Tỷ lệ lỗi" :value="fmtPct(totals.errorRate, 2)" icon="alert" />
      </div>

      <AppCard title="Ngân sách AI tháng này">
        <div class="mb-1.5 flex flex-wrap justify-between gap-2 text-sm">
          <span>{{ fmtUsd(monthCost) }} / {{ fmtUsd(budget) }}</span>
          <span class="text-ink-muted">Cảnh báo ở {{ db.systemSettings.alertThresholdPct }}% · Khi vượt: {{ { alert: 'chỉ cảnh báo', pause_batch: 'tạm dừng job hàng loạt', pause_all: 'tạm dừng mọi job AI' }[db.systemSettings.actionOnExceed] }}</span>
        </div>
        <ProgressBar :value="monthCost" :max="budget" tone="auto" label="Ngân sách AI" />
        <RouterLink to="/settings" class="mt-3 inline-block text-sm text-brand-700 hover:underline">Đổi ngân sách trong Cài đặt hệ thống →</RouterLink>
      </AppCard>

      <AppCard title="Chi phí theo nhà cung cấp (USD/ngày)">
        <LineChart :labels="costChart.labels" :series="costChart.series" :format="(v) => fmtUsd(v)" :height="260" />
      </AppCard>
      <AppCard title="Chi phí theo tác vụ (USD)">
        <BarChart :labels="taskChart.labels" :series="taskChart.series" :format="(v) => fmtUsd(v, 0)" :height="240" />
      </AppCard>

      <AppCard title="Theo khách hàng" :padded="false">
        <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
          <SearchInput v-model="table.q.value" placeholder="Tìm khách hàng…" />
          <FilterSelect v-model="table.filters.usage" label="Sử dụng" :options="[{ value: '', label: 'Tất cả' }, { value: 'active', label: 'Có dùng' }, { value: 'idle', label: 'Không dùng' }]" />
        </Toolbar>
        <DataTable :table="table" :columns="[{ key: 'name', label: 'Khách hàng', sortable: true }, { key: 'requests', label: 'Requests', sortable: true, align: 'right' }, { key: 'tokens', label: 'Tokens', sortable: true, align: 'right', hideBelow: 'md' }, { key: 'errorRate', label: 'Lỗi', sortable: true, align: 'right', hideBelow: 'sm' }, { key: 'cost', label: 'Chi phí', sortable: true, align: 'right' }]" empty-title="Không có dữ liệu">
          <template #cell-requests="{ row }"><span class="tabular-nums">{{ fmtNumber(row.requests) }}</span></template>
          <template #cell-tokens="{ row }"><span class="tabular-nums">{{ fmtCompact(row.tokens) }}</span></template>
          <template #cell-errorRate="{ row }"><span class="tabular-nums" :class="row.errorRate > 1 ? 'text-danger-600' : 'text-ink-muted'">{{ fmtPct(row.errorRate, 2) }}</span></template>
          <template #cell-cost="{ row }"><span class="font-medium tabular-nums">{{ fmtUsd(row.cost) }}</span></template>
        </DataTable>
      </AppCard>
    </div>
  </div>
</template>
