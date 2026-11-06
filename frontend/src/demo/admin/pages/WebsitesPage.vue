<script setup lang="ts">
import { computed, ref } from 'vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import StatCard from '../../ui/StatCard.vue'
import Drawer from '../../ui/Drawer.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import Sparkline from '../../ui/charts/Sparkline.vue'
import { db, demoMutation, demoSettings } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { options } from '../../labels'
import { fmtDateTime, fmtNumber, fmtRelative } from '../../format'
import type { DemoSite } from '../../data/types'
import { adminCrumbs, staffAudit, wsName } from '../useAdmin'

const toast = useToast()
const { loading, error, reload } = useDemoQuery()

const table = useTable(() => db.sites, {
  search: (s) => `${s.name} ${s.url} ${wsName(s.workspaceId)}`,
  filters: { initial: { status: '', workspace: '', plugin: '' }, match: { status: (s, v) => s.status === v, workspace: (s, v) => s.workspaceId === v, plugin: (s, v) => (v === 'old' ? s.pluginVersion < '1.2.0' : s.pluginVersion >= '1.2.0') } },
  sorts: { name: (s) => s.name, seen: (s) => s.lastSeenAt ?? '', latency: (s) => s.latencyMs, posts: (s) => s.managedPosts },
  defaultSort: { key: 'seen', dir: 'desc' },
})
const wsOptions = computed(() => [{ value: '', label: 'Tất cả' }, ...db.workspaces.map((w) => ({ value: w.id, label: w.name }))])
const counts = computed(() => ({
  connected: db.sites.filter((s) => s.status === 'connected').length,
  problems: db.sites.filter((s) => s.status === 'degraded' || s.status === 'disconnected').length,
  pending: db.sites.filter((s) => s.status === 'pending_pairing').length,
  oldPlugin: db.sites.filter((s) => s.pluginVersion && s.pluginVersion < '1.2.0').length,
}))

const selected = ref<DemoSite | null>(null)
const checking = ref<string | null>(null)
async function healthCheck(s: DemoSite) {
  if (s.status === 'pending_pairing' || s.status === 'revoked') return toast.error('Website chưa được ghép nối, không thể kiểm tra.')
  checking.value = s.id
  try {
    await demoMutation(() => {
      const ok = !demoSettings.failRequests && Math.random() > 0.2
      const latency = Math.round(120 + Math.random() * 500)
      s.health.unshift({ at: new Date().toISOString(), ok, latencyMs: latency, error: ok ? undefined : 'Timeout 10s (mô phỏng)' })
      s.health = s.health.slice(0, 24)
      s.latencyMs = latency
      if (ok) {
        s.lastSeenAt = new Date().toISOString()
        s.status = 'connected'
      } else s.status = 'degraded'
      staffAudit('platform.site.health_checked', `${s.name}: ${ok ? 'OK' : 'lỗi'}`, s.workspaceId)
    }, 700)
    toast[s.status === 'connected' ? 'success' : 'error'](s.status === 'connected' ? `${s.name} phản hồi tốt (${s.latencyMs} ms).` : `${s.name} không phản hồi ổn định.`)
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    checking.value = null
  }
}
const revoking = ref<DemoSite | null>(null)
const busy = ref(false)
async function revoke() {
  const s = revoking.value!
  busy.value = true
  try {
    await demoMutation(() => {
      s.status = 'revoked'
      db.publishJobs.filter((j) => j.siteId === s.id && j.status === 'queued').forEach((j) => (j.status = 'canceled'))
      staffAudit('platform.site.revoked', s.name, s.workspaceId)
    })
    toast.success(`Đã thu hồi khóa kết nối của ${s.name}. Lịch đăng đang chờ đã bị hủy.`)
    revoking.value = null
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader title="Quản lý website" description="Mọi website WordPress đã kết nối trên nền tảng, tình trạng plugin và độ trễ." :crumbs="adminCrumbs({ label: 'Website' })" />

    <LoadingState v-if="loading" :rows="6" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <div v-else class="space-y-6">
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Đang kết nối" :value="counts.connected" icon="globe" />
        <StatCard label="Cần kiểm tra" :value="counts.problems" icon="alert" hint="không ổn định / mất kết nối" />
        <StatCard label="Chờ ghép nối" :value="counts.pending" icon="plug" />
        <StatCard label="Plugin cũ (< 1.2.0)" :value="counts.oldPlugin" icon="refresh" hint="nên cập nhật" />
      </div>
      <AppCard :padded="false">
        <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
          <SearchInput v-model="table.q.value" placeholder="Tên, URL, khách hàng…" />
          <FilterSelect v-model="table.filters.status" label="Trạng thái" :options="options('site')" />
          <FilterSelect v-model="table.filters.workspace" label="Khách hàng" :options="wsOptions" />
          <FilterSelect v-model="table.filters.plugin" label="Plugin" :options="[{ value: '', label: 'Tất cả' }, { value: 'old', label: 'Phiên bản cũ' }, { value: 'new', label: 'Mới nhất' }]" />
        </Toolbar>
        <DataTable :table="table" clickable :columns="[{ key: 'name', label: 'Website', sortable: true }, { key: 'ws', label: 'Khách hàng', hideBelow: 'md' }, { key: 'versions', label: 'WP / Plugin', hideBelow: 'lg' }, { key: 'posts', label: 'Bài quản lý', sortable: true, align: 'right', hideBelow: 'lg' }, { key: 'latency', label: 'Độ trễ', sortable: true, align: 'right', hideBelow: 'sm' }, { key: 'seen', label: 'Lần cuối', sortable: true, hideBelow: 'md' }, { key: 'status', label: 'Trạng thái' }]" empty-title="Không có website phù hợp" @row-click="(s) => (selected = s)">
          <template #cell-name="{ row }"><p class="font-medium">{{ row.name }}</p><p class="text-xs text-ink-muted">{{ row.url }}</p></template>
          <template #cell-ws="{ row }"><span class="text-ink-muted">{{ wsName(row.workspaceId) }}</span></template>
          <template #cell-versions="{ row }"><span class="text-xs text-ink-muted">{{ row.wpVersion || '—' }} / <span :class="row.pluginVersion && row.pluginVersion < '1.2.0' ? 'text-warning-600' : ''">{{ row.pluginVersion || '—' }}</span></span></template>
          <template #cell-posts="{ row }"><span class="tabular-nums">{{ fmtNumber(row.managedPosts) }}</span></template>
          <template #cell-latency="{ row }"><span class="tabular-nums" :class="row.latencyMs > 600 ? 'text-warning-600' : ''">{{ row.status === 'pending_pairing' ? '—' : `${row.latencyMs} ms` }}</span></template>
          <template #cell-seen="{ row }"><span class="text-ink-muted">{{ fmtRelative(row.lastSeenAt) }}</span></template>
          <template #cell-status="{ row }"><StatusBadge group="site" :value="row.status" /></template>
        </DataTable>
      </AppCard>
    </div>

    <Drawer :open="!!selected" :title="selected?.name ?? ''" :subtitle="selected?.url" @close="selected = null">
      <div v-if="selected" class="space-y-5 text-sm">
        <div class="flex items-center gap-2"><StatusBadge group="site" :value="selected.status" /><span class="text-ink-muted">{{ wsName(selected.workspaceId) }}</span></div>
        <dl class="grid grid-cols-2 gap-3">
          <div class="rounded-lg bg-surface-muted p-3"><dt class="text-xs text-ink-muted">WordPress</dt><dd class="font-medium">{{ selected.wpVersion || '—' }}</dd></div>
          <div class="rounded-lg bg-surface-muted p-3"><dt class="text-xs text-ink-muted">Plugin SiteX</dt><dd class="font-medium">{{ selected.pluginVersion || '—' }}</dd></div>
          <div class="rounded-lg bg-surface-muted p-3"><dt class="text-xs text-ink-muted">PHP</dt><dd class="font-medium">{{ selected.phpVersion || '—' }}</dd></div>
          <div class="rounded-lg bg-surface-muted p-3"><dt class="text-xs text-ink-muted">Kết nối từ</dt><dd class="font-medium">{{ fmtDateTime(selected.connectedAt) }}</dd></div>
        </dl>
        <div>
          <div class="mb-2 flex items-center justify-between"><h3 class="font-medium">Độ trễ health check</h3><Sparkline :values="[...selected.health].reverse().map((h) => h.latencyMs)" :width="140" :height="32" /></div>
          <ul class="max-h-64 divide-y divide-line overflow-y-auto rounded-lg border border-line">
            <li v-for="(h, i) in selected.health" :key="i" class="flex items-center justify-between gap-2 px-3 py-2">
              <span class="flex items-center gap-2"><AppIcon :name="h.ok ? 'check' : 'alert'" :size="14" :class="h.ok ? 'text-success-600' : 'text-danger-600'" />{{ fmtDateTime(h.at) }}</span>
              <span class="text-xs text-ink-muted">{{ h.ok ? `${h.latencyMs} ms` : h.error }}</span>
            </li>
            <li v-if="!selected.health.length" class="px-3 py-4 text-center text-ink-muted">Chưa có lần kiểm tra nào.</li>
          </ul>
        </div>
      </div>
      <template #footer>
        <AppButton variant="secondary" :loading="checking === selected?.id" @click="healthCheck(selected!)"><AppIcon name="activity" :size="16" /> Kiểm tra ngay</AppButton>
        <AppButton variant="danger" :disabled="selected?.status === 'revoked'" @click="revoking = selected">Thu hồi kết nối</AppButton>
      </template>
    </Drawer>

    <ConfirmDialog :open="!!revoking" :title="`Thu hồi kết nối ${revoking?.name}?`" message="Khóa ký của website bị vô hiệu ngay. Khách hàng cần ghép nối lại bằng mã mới. Dùng khi phát hiện lạm dụng hoặc theo yêu cầu khách." confirm-label="Thu hồi" danger :loading="busy" @close="revoking = null" @confirm="revoke" />
  </div>
</template>
