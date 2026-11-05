<script setup lang="ts">
import { computed, ref } from 'vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import StatCard from '../../ui/StatCard.vue'
import DTextarea from '../../ui/DTextarea.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import BarChart from '../../ui/charts/BarChart.vue'
import { db, demoMutation, demoSettings, newId } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { label, options } from '../../labels'
import { fmtCompact, fmtDateTime, fmtVnd } from '../../format'
import { downloadFile, toCsv } from '../../download'
import type { DemoTransaction } from '../../data/types'
import { adminCrumbs, mrrMinor, staffAudit, wsName } from '../useAdmin'

const toast = useToast()
const { loading, error, reload } = useDemoQuery()

const table = useTable(() => db.transactions, {
  search: (t) => `${t.gatewayRef} ${wsName(t.workspaceId)} ${t.method}`,
  filters: {
    initial: { status: '', type: '', workspace: '', range: '' },
    match: {
      status: (t, v) => t.status === v,
      type: (t, v) => t.type === v,
      workspace: (t, v) => t.workspaceId === v,
      range: (t, v) => Date.now() - new Date(t.createdAt).getTime() <= Number(v) * 86_400_000,
    },
  },
  sorts: { at: (t) => t.createdAt, amount: (t) => t.amountMinor },
  defaultSort: { key: 'at', dir: 'desc' },
})
const wsOptions = computed(() => [{ value: '', label: 'Tất cả' }, ...db.workspaces.map((w) => ({ value: w.id, label: w.name }))])

const last30 = computed(() => db.transactions.filter((t) => Date.now() - new Date(t.createdAt).getTime() <= 30 * 86_400_000))
const revenue30 = computed(() => last30.value.filter((t) => t.status === 'succeeded' && t.type !== 'refund').reduce((s, t) => s + t.amountMinor, 0))
const refunds30 = computed(() => last30.value.filter((t) => t.type === 'refund').reduce((s, t) => s + Math.abs(t.amountMinor), 0))
const failed30 = computed(() => last30.value.filter((t) => t.status === 'failed').length)
const chart = computed(() => {
  const weeks = Array.from({ length: 8 }, (_, i) => 7 - i)
  const inWeek = (t: DemoTransaction, w: number) => {
    const age = (Date.now() - new Date(t.createdAt).getTime()) / 86_400_000
    return age >= w * 7 && age < (w + 1) * 7 && t.status === 'succeeded'
  }
  return {
    labels: weeks.map((w) => (w === 0 ? 'Tuần này' : `${w} tuần trước`)),
    series: [
      { name: 'Thanh toán gói', values: weeks.map((w) => db.transactions.filter((t) => t.type === 'charge' && inWeek(t, w)).reduce((s, t) => s + t.amountMinor, 0)) },
      { name: 'Mua credit', values: weeks.map((w) => db.transactions.filter((t) => t.type === 'credit_purchase' && inWeek(t, w)).reduce((s, t) => s + t.amountMinor, 0)) },
    ],
  }
})

// Refund
const refunding = ref<DemoTransaction | null>(null)
const reason = ref('')
const reasonError = ref('')
const busy = ref(false)
function openRefund(t: DemoTransaction) {
  refunding.value = t
  reason.value = ''
  reasonError.value = ''
}
async function refund() {
  const t = refunding.value!
  if (reason.value.trim().length < 10) {
    reasonError.value = 'Nhập lý do tối thiểu 10 ký tự.'
    return
  }
  busy.value = true
  try {
    await demoMutation(() => {
      t.status = 'refunded'
      const inv = db.invoices.find((i) => i.id === t.invoiceId)
      if (inv) inv.status = 'refunded'
      db.transactions.push({ id: newId('txn'), workspaceId: t.workspaceId, invoiceId: t.invoiceId, type: 'refund', amountMinor: -t.amountMinor, status: 'succeeded', gatewayRef: `demo_re_${Date.now()}`, method: t.method, createdAt: new Date().toISOString(), failureReason: null })
      staffAudit('platform.payment.refunded', `${t.gatewayRef}: ${reason.value.trim()}`, t.workspaceId)
    }, 500)
    toast.success(`Đã hoàn ${fmtVnd(t.amountMinor)} cho ${wsName(t.workspaceId)} (mô phỏng, không có tiền thật).`)
    refunding.value = null
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    busy.value = false
  }
}
// Retry failed charge
const retrying = ref<string | null>(null)
async function retry(t: DemoTransaction) {
  retrying.value = t.id
  try {
    await demoMutation(() => {
      const ok = !demoSettings.failRequests && Math.random() > 0.3
      t.status = ok ? 'succeeded' : 'failed'
      t.failureReason = ok ? null : 'Thẻ bị từ chối: insufficient_funds (mô phỏng)'
      const inv = db.invoices.find((i) => i.id === t.invoiceId)
      if (ok && inv) {
        inv.status = 'paid'
        inv.paidAt = new Date().toISOString()
      }
      staffAudit('platform.payment.retried', `${t.gatewayRef}: ${ok ? 'thành công' : 'thất bại'}`, t.workspaceId)
    }, 600)
    if (t.status === 'succeeded') toast.success('Thu lại thành công.')
    else toast.error(t.failureReason ?? 'Thu lại thất bại.')
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    retrying.value = null
  }
}
function exportCsv() {
  downloadFile(
    'giao-dich-demo.csv',
    toCsv(table.sorted.value.map((t) => ({ 'Thời gian': fmtDateTime(t.createdAt), 'Khách hàng': wsName(t.workspaceId), Loại: label('txnType', t.type), 'Số tiền (VND)': t.amountMinor, 'Trạng thái': label('txn', t.status), 'Phương thức': t.method, 'Mã cổng (demo)': t.gatewayRef }))),
    'text/csv;charset=utf-8',
  )
  toast.success(`Đã xuất ${table.total.value} giao dịch.`)
}
</script>

<template>
  <div>
    <PageHeader title="Thanh toán & giao dịch" description="Mọi giao dịch qua cổng thanh toán mô phỏng (demo_gateway). Không có tiền thật." :crumbs="adminCrumbs({ label: 'Thanh toán & giao dịch' })">
      <template #actions><AppButton variant="secondary" :disabled="!table.total.value" @click="exportCsv"><AppIcon name="download" :size="16" /> Xuất CSV</AppButton></template>
    </PageHeader>

    <LoadingState v-if="loading" :rows="6" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <div v-else class="space-y-6">
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="MRR" :value="fmtVnd(mrrMinor())" icon="trending" hint="giả lập" />
        <StatCard label="Doanh thu 30 ngày" :value="fmtVnd(revenue30)" icon="receipt" />
        <StatCard label="Hoàn tiền 30 ngày" :value="fmtVnd(refunds30)" icon="refresh" />
        <StatCard label="Giao dịch lỗi 30 ngày" :value="failed30" icon="alert" />
      </div>
      <AppCard title="Doanh thu theo tuần (VND)">
        <BarChart :labels="chart.labels" :series="chart.series" :format="(v) => fmtCompact(v)" :height="220" />
      </AppCard>
      <AppCard :padded="false">
        <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
          <SearchInput v-model="table.q.value" placeholder="Mã giao dịch, khách hàng…" />
          <FilterSelect v-model="table.filters.status" label="Trạng thái" :options="options('txn')" />
          <FilterSelect v-model="table.filters.type" label="Loại" :options="options('txnType')" />
          <FilterSelect v-model="table.filters.workspace" label="Khách hàng" :options="wsOptions" />
          <FilterSelect v-model="table.filters.range" label="Thời gian" :options="[{ value: '', label: 'Tất cả' }, { value: '7', label: '7 ngày' }, { value: '30', label: '30 ngày' }, { value: '90', label: '90 ngày' }]" />
        </Toolbar>
        <DataTable :table="table" :columns="[{ key: 'at', label: 'Thời gian', sortable: true }, { key: 'ws', label: 'Khách hàng' }, { key: 'type', label: 'Loại', hideBelow: 'md' }, { key: 'method', label: 'Phương thức', hideBelow: 'lg' }, { key: 'amount', label: 'Số tiền', sortable: true, align: 'right' }, { key: 'status', label: 'Trạng thái' }, { key: 'actions', label: '', align: 'right' }]" empty-title="Không có giao dịch phù hợp">
          <template #cell-at="{ row }"><span class="whitespace-nowrap text-ink-muted">{{ fmtDateTime(row.createdAt) }}</span><br /><code class="text-[11px] text-ink-subtle">{{ row.gatewayRef }}</code></template>
          <template #cell-ws="{ row }">{{ wsName(row.workspaceId) }}</template>
          <template #cell-type="{ row }"><span class="text-ink-muted">{{ label('txnType', row.type) }}</span></template>
          <template #cell-method="{ row }"><span class="text-ink-muted">{{ row.method }}</span></template>
          <template #cell-amount="{ row }"><span class="tabular-nums" :class="row.amountMinor < 0 ? 'text-danger-600' : ''">{{ fmtVnd(row.amountMinor) }}</span></template>
          <template #cell-status="{ row }">
            <StatusBadge group="txn" :value="row.status" />
            <p v-if="row.failureReason" class="mt-1 max-w-[220px] text-xs text-danger-600">{{ row.failureReason }}</p>
          </template>
          <template #cell-actions="{ row }">
            <AppButton v-if="row.status === 'succeeded' && row.type !== 'refund'" size="sm" variant="ghost" @click="openRefund(row)">Hoàn tiền</AppButton>
            <AppButton v-else-if="row.status === 'failed'" size="sm" variant="ghost" :loading="retrying === row.id" @click="retry(row)">Thu lại</AppButton>
          </template>
        </DataTable>
      </AppCard>
    </div>

    <AppModal :open="!!refunding" title="Hoàn tiền giao dịch" width="sm" @close="refunding = null">
      <p class="text-sm text-ink-muted">Hoàn toàn bộ <strong class="text-ink">{{ fmtVnd(refunding?.amountMinor ?? 0) }}</strong> cho {{ wsName(refunding?.workspaceId ?? null) }}. Hóa đơn liên quan chuyển sang “Đã hoàn tiền”.</p>
      <DTextarea v-model="reason" class="mt-3" label="Lý do" :rows="3" :error="reasonError" />
      <template #footer>
        <AppButton variant="secondary" @click="refunding = null">Hủy</AppButton>
        <AppButton variant="danger" :loading="busy" @click="refund">Hoàn tiền</AppButton>
      </template>
    </AppModal>
  </div>
</template>
