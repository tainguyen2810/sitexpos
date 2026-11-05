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
import DemoErrorState from '../../ui/DemoErrorState.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import { db } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { fmtDateTime } from '../../format'
import { downloadFile, toCsv } from '../../download'
import type { DemoAuditLog } from '../../data/types'
import { adminCrumbs, wsName } from '../useAdmin'

const toast = useToast()
const { loading, error, reload } = useDemoQuery()
const ACTOR: Record<DemoAuditLog['actorType'], [string, 'neutral' | 'brand' | 'warning']> = { user: ['Khách hàng', 'neutral'], staff: ['Nhân viên', 'warning'], system: ['Hệ thống', 'brand'] }
const domain = (a: string) => (a.startsWith('platform.') ? a.split('.')[1]! : a.split('.')[0]!)

const table = useTable(() => db.auditLogs, {
  search: (l) => `${l.action} ${l.actorName} ${l.subject} ${l.ip} ${wsName(l.workspaceId)}`,
  filters: {
    initial: { actor: '', domain: '', workspace: '', range: '' },
    match: {
      actor: (l, v) => l.actorType === v,
      domain: (l, v) => domain(l.action) === v,
      workspace: (l, v) => (v === 'none' ? l.workspaceId === null : l.workspaceId === v),
      range: (l, v) => Date.now() - new Date(l.createdAt).getTime() <= Number(v) * 3_600_000,
    },
  },
  sorts: { at: (l) => l.createdAt, action: (l) => l.action },
  defaultSort: { key: 'at', dir: 'desc' },
})
const domainOptions = computed(() => [{ value: '', label: 'Tất cả' }, ...[...new Set(db.auditLogs.map((l) => domain(l.action)))].sort().map((d) => ({ value: d, label: d }))])
const wsOptions = computed(() => [{ value: '', label: 'Tất cả' }, { value: 'none', label: 'Hệ thống (không thuộc khách hàng)' }, ...db.workspaces.map((w) => ({ value: w.id, label: w.name }))])
const viewing = ref<DemoAuditLog | null>(null)

function exportCsv() {
  downloadFile(
    `audit-logs-demo-${new Date().toISOString().slice(0, 10)}.csv`,
    toCsv(table.sorted.value.map((l) => ({ 'Thời gian': fmtDateTime(l.createdAt), 'Người thực hiện': l.actorName, Loại: ACTOR[l.actorType][0], 'Hành động': l.action, 'Đối tượng': l.subject, 'Khách hàng': wsName(l.workspaceId), IP: l.ip }))),
    'text/csv;charset=utf-8',
  )
  toast.success(`Đã xuất ${table.total.value} bản ghi audit.`)
}
</script>

<template>
  <div>
    <PageHeader title="Audit Logs" description="Nhật ký bất biến cho mọi thao tác quan trọng của khách hàng, nhân viên và hệ thống. Không thể sửa hoặc xóa." :crumbs="adminCrumbs({ label: 'Audit Logs' })">
      <template #actions>
        <AppButton variant="secondary" :disabled="!table.total.value" @click="exportCsv"><AppIcon name="download" :size="16" /> Xuất CSV</AppButton>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" :rows="8" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <AppCard v-else :padded="false">
      <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
        <SearchInput v-model="table.q.value" placeholder="Hành động, người dùng, IP…" />
        <FilterSelect v-model="table.filters.actor" label="Người thực hiện" :options="[{ value: '', label: 'Tất cả' }, { value: 'user', label: 'Khách hàng' }, { value: 'staff', label: 'Nhân viên' }, { value: 'system', label: 'Hệ thống' }]" />
        <FilterSelect v-model="table.filters.domain" label="Phân hệ" :options="domainOptions" />
        <FilterSelect v-model="table.filters.workspace" label="Khách hàng" :options="wsOptions" />
        <FilterSelect v-model="table.filters.range" label="Thời gian" :options="[{ value: '', label: 'Tất cả' }, { value: '24', label: '24 giờ' }, { value: '168', label: '7 ngày' }, { value: '720', label: '30 ngày' }]" />
      </Toolbar>
      <DataTable :table="table" clickable :columns="[{ key: 'at', label: 'Thời gian', sortable: true }, { key: 'actor', label: 'Người thực hiện' }, { key: 'action', label: 'Hành động', sortable: true }, { key: 'subject', label: 'Đối tượng', hideBelow: 'lg' }, { key: 'ws', label: 'Khách hàng', hideBelow: 'md' }, { key: 'ip', label: 'IP', hideBelow: 'lg' }]" empty-title="Không có bản ghi phù hợp" @row-click="(l) => (viewing = l)">
        <template #cell-at="{ row }"><span class="whitespace-nowrap text-ink-muted">{{ fmtDateTime(row.createdAt) }}</span></template>
        <template #cell-actor="{ row }"><p>{{ row.actorName }}</p><AppBadge :tone="ACTOR[row.actorType][1]">{{ ACTOR[row.actorType][0] }}</AppBadge></template>
        <template #cell-action="{ row }"><code class="text-xs">{{ row.action }}</code></template>
        <template #cell-subject="{ row }"><span class="line-clamp-1 max-w-xs text-ink-muted">{{ row.subject }}</span></template>
        <template #cell-ws="{ row }"><span class="text-ink-muted">{{ wsName(row.workspaceId) }}</span></template>
        <template #cell-ip="{ row }"><code class="text-xs text-ink-muted">{{ row.ip }}</code></template>
      </DataTable>
    </AppCard>

    <AppModal :open="!!viewing" title="Chi tiết bản ghi" @close="viewing = null">
      <pre v-if="viewing" class="overflow-auto rounded-lg bg-surface-muted p-4 text-xs leading-relaxed">{{ JSON.stringify({ ...viewing, workspace: wsName(viewing.workspaceId), _demo: true }, null, 2) }}</pre>
      <template #footer><AppButton variant="secondary" @click="viewing = null">Đóng</AppButton></template>
    </AppModal>
  </div>
</template>
