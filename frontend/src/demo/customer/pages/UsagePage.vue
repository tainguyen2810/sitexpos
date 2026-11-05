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
import BarChart from '../../ui/charts/BarChart.vue'
import DonutChart from '../../ui/charts/DonutChart.vue'
import DemoCheckoutModal from '../components/DemoCheckoutModal.vue'
import { db, demoMutation, newId } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { LIMIT_LABELS } from '../../labels'
import { fmtDate, fmtDateTime, fmtNumber, fmtVnd } from '../../format'
import { downloadFile, toCsv } from '../../download'
import type { DemoCreditEntry, LimitKey } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const toast = useToast()
const { ws, base, mine, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const sub = computed(() => db.subscriptions.find((s) => s.workspaceId === ws.value.id)!)
const plan = computed(() => db.plans.find((p) => p.code === sub.value.planCode)!)
const limitKeys = Object.keys(LIMIT_LABELS) as LimitKey[]
const counters = computed(() =>
  limitKeys.map((k) => {
    const used = db.usage.find((u) => u.workspaceId === ws.value.id && u.metric === k)?.used ?? 0
    const limit = plan.value.limits[k]
    return { key: k, label: LIMIT_LABELS[k]!, used, limit, pct: limit === -1 ? 0 : Math.round((used / Math.max(1, limit)) * 100) }
  }),
)
const nearLimit = computed(() => counters.value.filter((c) => c.limit !== -1 && c.pct >= 80))

const ledger = computed(() => mine(db.credits))
const consumed30 = computed(() => {
  const since = Date.now() - 30 * 86_400_000
  return ledger.value.filter((c) => c.delta < 0 && new Date(c.createdAt).getTime() >= since).reduce((s, c) => s - c.delta, 0)
})

// Daily consumption, last 14 days, stacked by activity type (from the ledger notes).
const ACTIVITY = ['Viết bài', 'Kiểm chứng', 'Ảnh AI', 'Từ khóa', 'Nghiên cứu'] as const
const activityOf = (note: string) => {
  const n = note.toLowerCase()
  if (n.includes('viết')) return 'Viết bài'
  if (n.includes('kiểm chứng')) return 'Kiểm chứng'
  if (n.includes('ảnh')) return 'Ảnh AI'
  if (n.includes('từ khóa')) return 'Từ khóa'
  return 'Nghiên cứu'
}
const days = computed(() => {
  const out: string[] = []
  for (let i = 13; i >= 0; i--) out.push(new Date(Date.now() - i * 86_400_000).toISOString().slice(0, 10))
  return out
})
const chart = computed(() => {
  const series = ACTIVITY.map((name) => ({ name, values: days.value.map(() => 0) }))
  for (const c of ledger.value) {
    if (c.reason !== 'consume') continue
    const idx = days.value.indexOf(c.createdAt.slice(0, 10))
    if (idx < 0) continue
    series.find((s) => s.name === activityOf(c.note))!.values[idx]! += -c.delta
  }
  return { labels: days.value.map((d) => `${d.slice(8, 10)}/${d.slice(5, 7)}`), series }
})
const byActivity = computed(() =>
  ACTIVITY.map((name) => ({ name, value: ledger.value.filter((c) => c.reason === 'consume' && activityOf(c.note) === name).reduce((s, c) => s - c.delta, 0) })).filter((s) => s.value > 0),
)

const table = useTable(() => ledger.value, {
  search: (c) => c.note,
  filters: { initial: { reason: '', direction: '' }, match: { reason: (c, v) => c.reason === v, direction: (c, v) => (v === 'in' ? c.delta > 0 : c.delta < 0) } },
  sorts: { at: (c) => c.createdAt, delta: (c) => c.delta },
  defaultSort: { key: 'at', dir: 'desc' },
})
const REASONS: Record<DemoCreditEntry['reason'], string> = { purchase: 'Mua thêm', grant: 'Cấp theo gói', consume: 'Sử dụng', refund: 'Hoàn lại', expire: 'Hết hạn' }
const reasonOptions = [{ value: '', label: 'Tất cả' }, ...Object.entries(REASONS).map(([value, label]) => ({ value, label }))]
function exportCsv() {
  downloadFile(
    `credit-ledger-${ws.value.slug}-demo.csv`,
    toCsv(table.sorted.value.map((c) => ({ 'Thời gian': fmtDateTime(c.createdAt), Loại: REASONS[c.reason], 'Nội dung': c.note, 'Thay đổi': c.delta, 'Số dư': c.balanceAfter }))),
    'text/csv;charset=utf-8',
  )
  toast.success('Đã xuất lịch sử credit (dữ liệu demo).')
}

// Buy credits (simulated checkout)
const PACKS = [
  { credits: 200, priceMinor: 190_000 },
  { credits: 500, priceMinor: 450_000, best: true },
  { credits: 1500, priceMinor: 1_200_000 },
]
const pack = ref<(typeof PACKS)[number] | null>(null)
const buyOpen = ref(false)
function buy(p: (typeof PACKS)[number]) {
  if (ws.value.status === 'suspended') return toast.error('Workspace đang bị tạm khóa, không thể mua thêm credit.')
  pack.value = p
  buyOpen.value = true
}
async function onPaid(method: string) {
  const p = pack.value
  if (!p) return
  try {
    await demoMutation(() => {
      const now = new Date().toISOString()
      const total = Math.round(p.priceMinor * 1.1)
      const invId = newId('inv')
      db.invoices.push({ id: invId, workspaceId: ws.value.id, number: `SXP-DEMO-${Math.floor(5000 + Math.random() * 4999)}`, status: 'paid', description: `Gói ${p.credits} credit (demo)`, subtotalMinor: p.priceMinor, taxMinor: total - p.priceMinor, totalMinor: total, gateway: 'demo_gateway', issuedAt: now, paidAt: now })
      db.transactions.push({ id: newId('txn'), workspaceId: ws.value.id, invoiceId: invId, type: 'credit_purchase', amountMinor: total, status: 'succeeded', gatewayRef: `demo_ch_${Date.now()}`, method, createdAt: now, failureReason: null })
      ws.value.credits += p.credits
      db.credits.push({ id: newId('cr'), workspaceId: ws.value.id, delta: p.credits, balanceAfter: ws.value.credits, reason: 'purchase', note: `Mua gói ${p.credits} credit (thanh toán demo)`, createdAt: now })
    })
    toast.success(`Đã cộng ${fmtNumber(p.credits)} credit. Hóa đơn demo nằm trong mục Gói cước.`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}
const COSTS = [
  ['Viết một bài AI', 25],
  ['Nghiên cứu chủ đề', 10],
  ['Kiểm chứng nội dung', 8],
  ['Tạo một ảnh AI', 6],
  ['Tra cứu một từ khóa', 2],
] as const
</script>

<template>
  <div>
    <PageHeader title="Mức sử dụng & Credit" description="Theo dõi hạn mức của gói và lịch sử credit. Số liệu trong demo là giả lập." :crumbs="crumbs({ label: 'Mức sử dụng & Credit' })">
      <template #actions>
        <RouterLink :to="`${base}/billing`"><AppButton variant="secondary">Xem gói cước</AppButton></RouterLink>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" :rows="5" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <div v-else class="space-y-6">
      <div v-if="nearLimit.length" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-warning-50 p-4 text-sm text-amber-900" role="status">
        <AppIcon name="alert" :size="18" class="mt-0.5 shrink-0" />
        <p>Sắp chạm hạn mức: <strong>{{ nearLimit.map((c) => `${c.label} (${c.pct}%)`).join(', ') }}</strong>. Khi đạt 100%, tác vụ mới bị tạm dừng cho đến kỳ sau hoặc khi nâng cấp gói.</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <StatCard label="Credit còn lại" :value="fmtNumber(ws.credits)" icon="coins" hint="Dùng cho tác vụ AI (mô phỏng)" />
        <StatCard label="Đã dùng 30 ngày" :value="fmtNumber(consumed30)" icon="activity" hint="credit" />
        <StatCard label="Kỳ hiện tại" :value="plan.name" icon="calendar" :hint="`đến ${fmtDate(sub.currentPeriodEnd)}`" :to="`${base}/billing`" />
      </div>

      <AppCard title="Hạn mức của gói" :subtitle="`Gói ${plan.name} · đặt lại vào ${fmtDate(sub.currentPeriodEnd)}`">
        <div class="grid gap-x-8 gap-y-5 md:grid-cols-2">
          <div v-for="c in counters" :key="c.key">
            <div class="mb-1.5 flex items-baseline justify-between text-sm">
              <span class="font-medium">{{ c.label }}</span>
              <span class="tabular-nums text-ink-muted">{{ fmtNumber(c.used) }} / {{ c.limit === -1 ? '∞' : fmtNumber(c.limit) }}</span>
            </div>
            <ProgressBar :value="c.limit === -1 ? 0 : c.used" :max="c.limit === -1 ? 1 : c.limit" tone="auto" :label="c.label" />
          </div>
        </div>
      </AppCard>

      <div class="grid gap-6 lg:grid-cols-3">
        <AppCard class="lg:col-span-2" title="Credit đã dùng theo ngày" subtitle="14 ngày gần nhất">
          <BarChart v-if="chart.series.some((s) => s.values.some((v) => v > 0))" :labels="chart.labels" :series="chart.series" :height="240" />
          <p v-else class="py-16 text-center text-sm text-ink-muted">Chưa có credit nào được dùng trong 14 ngày qua.</p>
        </AppCard>
        <AppCard title="Theo loại tác vụ">
          <DonutChart v-if="byActivity.length" :segments="byActivity" center-label="credit" />
          <p v-else class="py-16 text-center text-sm text-ink-muted">Chưa có dữ liệu.</p>
        </AppCard>
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <AppCard class="lg:col-span-2" title="Lịch sử credit" :padded="false">
          <template #actions>
            <AppButton size="sm" variant="secondary" :disabled="!table.total.value" @click="exportCsv"><AppIcon name="download" :size="16" /> CSV</AppButton>
          </template>
          <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
            <SearchInput v-model="table.q.value" placeholder="Tìm nội dung…" />
            <FilterSelect v-model="table.filters.reason" label="Loại" :options="reasonOptions" />
            <FilterSelect v-model="table.filters.direction" label="Chiều" :options="[{ value: '', label: 'Tất cả' }, { value: 'in', label: 'Cộng vào' }, { value: 'out', label: 'Trừ đi' }]" />
          </Toolbar>
          <DataTable :table="table" :columns="[{ key: 'at', label: 'Thời gian', sortable: true }, { key: 'note', label: 'Nội dung' }, { key: 'reason', label: 'Loại', hideBelow: 'md' }, { key: 'delta', label: 'Thay đổi', sortable: true, align: 'right' }, { key: 'balance', label: 'Số dư', align: 'right', hideBelow: 'sm' }]" empty-title="Không có giao dịch credit" empty-description="Thử bỏ bộ lọc hoặc mua thêm credit.">
            <template #cell-at="{ row }"><span class="whitespace-nowrap text-ink-muted">{{ fmtDateTime(row.createdAt) }}</span></template>
            <template #cell-reason="{ row }"><span class="text-ink-muted">{{ REASONS[row.reason] }}</span></template>
            <template #cell-delta="{ row }"><span class="font-medium tabular-nums" :class="row.delta > 0 ? 'text-success-600' : 'text-ink'">{{ row.delta > 0 ? '+' : '' }}{{ fmtNumber(row.delta) }}</span></template>
            <template #cell-balance="{ row }"><span class="tabular-nums text-ink-muted">{{ fmtNumber(row.balanceAfter) }}</span></template>
          </DataTable>
        </AppCard>
        <div class="space-y-6">
          <AppCard title="Mua thêm credit" subtitle="Giá minh họa, chưa gồm VAT">
            <div class="space-y-2">
              <button v-for="p in PACKS" :key="p.credits" type="button" class="flex w-full items-center justify-between rounded-lg border p-3 text-left transition hover:border-brand-200 hover:bg-brand-50" :class="p.best ? 'border-brand-200' : 'border-line'" @click="buy(p)">
                <span><span class="block font-semibold">{{ fmtNumber(p.credits) }} credit</span><span v-if="p.best" class="text-xs text-brand-700">Tiết kiệm nhất cho nhóm nhỏ</span></span>
                <span class="text-sm font-medium">{{ fmtVnd(p.priceMinor) }}</span>
              </button>
            </div>
          </AppCard>
          <AppCard title="Bảng giá credit (tham khảo)">
            <ul class="space-y-2 text-sm">
              <li v-for="[name, cost] in COSTS" :key="name" class="flex justify-between"><span class="text-ink-muted">{{ name }}</span><span class="font-medium tabular-nums">{{ cost }}</span></li>
            </ul>
            <p class="mt-3 text-xs text-ink-subtle">Credit chỉ bị trừ khi tác vụ hoàn tất; tác vụ lỗi được hoàn lại.</p>
          </AppCard>
        </div>
      </div>
    </div>

    <DemoCheckoutModal :open="buyOpen" :title="`Mua ${pack ? fmtNumber(pack.credits) : ''} credit`" :description="`Gói ${pack?.credits} credit, không hết hạn trong kỳ demo`" :amount-minor="pack ? Math.round(pack.priceMinor * 1.1) : 0" :saved-card="sub.paymentMethod" @close="buyOpen = false" @paid="onPaid" />
  </div>
</template>
