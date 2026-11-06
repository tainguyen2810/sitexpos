<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import Drawer from '../../ui/Drawer.vue'
import DTextarea from '../../ui/DTextarea.vue'
import Tabs from '../../ui/Tabs.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import { db, demoMutation } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { options } from '../../labels'
import { fmtDateTime, fmtRelative } from '../../format'
import type { DemoTicket } from '../../data/types'
import { STAFF, adminCrumbs, staffAudit, wsName } from '../useAdmin'

const toast = useToast()
const router = useRouter()
const { loading, error, reload } = useDemoQuery()
const tab = ref('tickets')
const tabs = computed(() => [
  { key: 'tickets', label: 'Ticket hỗ trợ', count: db.tickets.filter((t) => t.status !== 'resolved').length },
  { key: 'tools', label: 'Công cụ' },
])

// Tickets
const PRIORITY_RANK = { urgent: 0, high: 1, normal: 2, low: 3 }
const table = useTable(() => db.tickets, {
  search: (t) => `${t.subject} ${t.requester} ${t.category} ${wsName(t.workspaceId)}`,
  filters: { initial: { status: '', priority: '', category: '' }, match: { status: (t, v) => t.status === v, priority: (t, v) => t.priority === v, category: (t, v) => t.category === v } },
  sorts: { created: (t) => t.createdAt, priority: (t) => PRIORITY_RANK[t.priority] },
  defaultSort: { key: 'priority', dir: 'asc' },
})
const categoryOptions = computed(() => [{ value: '', label: 'Tất cả' }, ...[...new Set(db.tickets.map((t) => t.category))].map((c) => ({ value: c, label: c }))])
const openId = ref<string | null>(null)
const ticket = computed(() => db.tickets.find((t) => t.id === openId.value) ?? null)
const reply = useForm(() => ({ body: '', kind: 'staff' }), { body: [rules.required('Nhập nội dung.'), rules.minLength(5), rules.maxLength(2000)] })
function open(t: DemoTicket) {
  openId.value = t.id
  reply.reset()
}
async function send() {
  const t = ticket.value!
  await reply
    .submit(async () => {
      await demoMutation(() => {
        t.messages.push({ from: reply.values.kind as 'staff' | 'note', author: STAFF.name, body: reply.values.body.trim(), at: new Date().toISOString() })
        if (reply.values.kind === 'staff' && t.status === 'open') t.status = 'pending'
      })
      toast.success(reply.values.kind === 'note' ? 'Đã thêm ghi chú nội bộ.' : 'Đã gửi phản hồi (demo không gửi email).')
      reply.reset()
    })
    .catch((e: Error) => toast.error(e.message))
}
async function setField(field: 'status' | 'priority', value: string) {
  const t = ticket.value!
  try {
    await demoMutation(() => {
      ;(t as unknown as Record<string, string>)[field] = value
      staffAudit(`support.ticket.${field}_changed`, `${t.subject} → ${value}`, t.workspaceId)
    })
    toast.success('Đã cập nhật ticket.')
  } catch (e) {
    toast.error((e as Error).message)
  }
}

// Tools
const lookup = useForm(() => ({ email: '' }), { email: [rules.required(), rules.email()] })
const lookupResult = ref<{ workspace: string; role: string; id: string }[] | null>(null)
async function runLookup() {
  await lookup
    .submit(async () => {
      await demoMutation(() => undefined)
      const email = lookup.values.email.trim().toLowerCase()
      lookupResult.value = db.members.filter((m) => m.email.toLowerCase() === email).map((m) => ({ workspace: wsName(m.workspaceId), role: m.role, id: m.workspaceId }))
    })
    .catch((e: Error) => toast.error(e.message))
}
const toolWs = ref('')
const wsOptions = computed(() => [{ value: '', label: 'Chọn khách hàng…' }, ...db.workspaces.map((w) => ({ value: w.id, label: w.name }))])
const toolBusy = ref<string | null>(null)
async function tool(key: string, fn: () => string) {
  if (!toolWs.value) return toast.error('Chọn khách hàng trước.')
  toolBusy.value = key
  try {
    const msg = await demoMutation(fn, 600)
    staffAudit(`support.tool.${key}`, wsName(toolWs.value), toolWs.value)
    toast.success(msg)
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    toolBusy.value = null
  }
}
const recalcUsage = () =>
  tool('recalc_usage', () => {
    const id = toolWs.value
    const set = (metric: string, used: number) => {
      const u = db.usage.find((x) => x.workspaceId === id && x.metric === metric)
      if (u) u.used = used
    }
    set('sites', db.sites.filter((s) => s.workspaceId === id).length)
    set('seats', db.members.filter((m) => m.workspaceId === id).length)
    return 'Đã tính lại bộ đếm hạn mức từ dữ liệu thực tế.'
  })
const clearCache = () => tool('clear_cache', () => 'Đã xóa cache của workspace (mô phỏng).')
const resyncSites = () =>
  tool('resync_sites', () => {
    const sites = db.sites.filter((s) => s.workspaceId === toolWs.value && s.status !== 'pending_pairing' && s.status !== 'revoked')
    sites.forEach((s) => (s.lastSeenAt = new Date().toISOString()))
    return `Đã đồng bộ lại ${sites.length} website (mô phỏng).`
  })
const unpauseJobs = () =>
  tool('requeue_failed', () => {
    const failed = db.failedJobs.filter((j) => j.workspaceId === toolWs.value)
    db.failedJobs = db.failedJobs.filter((j) => j.workspaceId !== toolWs.value)
    return failed.length ? `Đã đưa ${failed.length} job lỗi của khách hàng về hàng đợi.` : 'Khách hàng này không có job lỗi.'
  })
</script>

<template>
  <div>
    <PageHeader title="Công cụ hỗ trợ" description="Xử lý ticket và các công cụ khắc phục nhanh cho khách hàng. Mọi thao tác được ghi audit log." :crumbs="adminCrumbs({ label: 'Công cụ hỗ trợ' })" />

    <LoadingState v-if="loading" :rows="6" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <div v-else class="space-y-6">
      <Tabs v-model="tab" :tabs="tabs" />

      <AppCard v-if="tab === 'tickets'" :padded="false">
        <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
          <SearchInput v-model="table.q.value" placeholder="Tiêu đề, khách hàng…" />
          <FilterSelect v-model="table.filters.status" label="Trạng thái" :options="options('ticket')" />
          <FilterSelect v-model="table.filters.priority" label="Ưu tiên" :options="options('priority')" />
          <FilterSelect v-model="table.filters.category" label="Chủ đề" :options="categoryOptions" />
        </Toolbar>
        <DataTable :table="table" clickable :columns="[{ key: 'subject', label: 'Ticket' }, { key: 'ws', label: 'Khách hàng', hideBelow: 'md' }, { key: 'priority', label: 'Ưu tiên', sortable: true }, { key: 'created', label: 'Tạo', sortable: true, hideBelow: 'sm' }, { key: 'status', label: 'Trạng thái' }]" empty-title="Không có ticket phù hợp" @row-click="open">
          <template #cell-subject="{ row }"><p class="font-medium">{{ row.subject }}</p><p class="text-xs text-ink-muted">{{ row.category }} · {{ row.messages.length }} tin nhắn</p></template>
          <template #cell-ws="{ row }"><span class="text-ink-muted">{{ wsName(row.workspaceId) }}</span></template>
          <template #cell-priority="{ row }"><StatusBadge group="priority" :value="row.priority" /></template>
          <template #cell-created="{ row }"><span class="whitespace-nowrap text-ink-muted">{{ fmtRelative(row.createdAt) }}</span></template>
          <template #cell-status="{ row }"><StatusBadge group="ticket" :value="row.status" /></template>
        </DataTable>
      </AppCard>

      <div v-else class="grid gap-6 lg:grid-cols-2">
        <AppCard title="Tra cứu người dùng theo email">
          <form class="flex flex-wrap items-end gap-2" novalidate @submit.prevent="runLookup">
            <AppInput v-model="lookup.values.email" type="email" label="Email" class="min-w-0 flex-1" :error="lookup.errors.email" placeholder="ten@khachhang.example" />
            <AppButton type="submit" :loading="lookup.submitting.value"><AppIcon name="search" :size="16" /> Tra cứu</AppButton>
          </form>
          <div v-if="lookupResult" class="mt-4 text-sm">
            <p v-if="!lookupResult.length" class="text-ink-muted">Không tìm thấy người dùng với email này.</p>
            <ul v-else class="divide-y divide-line rounded-lg border border-line">
              <li v-for="r in lookupResult" :key="r.id" class="flex items-center justify-between gap-2 px-3 py-2">
                <span>{{ r.workspace }} <StatusBadge group="role" :value="r.role" /></span>
                <AppButton size="sm" variant="ghost" @click="router.push({ path: '/customers', query: { q: r.workspace } })">Mở</AppButton>
              </li>
            </ul>
          </div>
          <p class="mt-3 text-xs text-ink-subtle">Gợi ý: thử email chủ sở hữu trong trang Khách hàng.</p>
        </AppCard>

        <AppCard title="Khắc phục nhanh">
          <AppSelect v-model="toolWs" label="Khách hàng" :options="wsOptions" />
          <div class="mt-4 grid gap-2 sm:grid-cols-2">
            <AppButton variant="secondary" :loading="toolBusy === 'recalc_usage'" @click="recalcUsage"><AppIcon name="refresh" :size="16" /> Tính lại hạn mức</AppButton>
            <AppButton variant="secondary" :loading="toolBusy === 'clear_cache'" @click="clearCache"><AppIcon name="trash" :size="16" /> Xóa cache</AppButton>
            <AppButton variant="secondary" :loading="toolBusy === 'resync_sites'" @click="resyncSites"><AppIcon name="globe" :size="16" /> Đồng bộ website</AppButton>
            <AppButton variant="secondary" :loading="toolBusy === 'requeue_failed'" @click="unpauseJobs"><AppIcon name="play" :size="16" /> Chạy lại job lỗi</AppButton>
          </div>
          <p class="mt-3 text-xs text-ink-subtle">Các công cụ chỉ thay đổi dữ liệu demo trong trình duyệt này.</p>
        </AppCard>
      </div>
    </div>

    <Drawer :open="!!ticket" :title="ticket?.subject ?? ''" :subtitle="ticket ? `${wsName(ticket.workspaceId)} · ${ticket.requester}` : ''" @close="openId = null">
      <div v-if="ticket" class="space-y-4">
        <div class="grid grid-cols-2 gap-3">
          <AppSelect :model-value="ticket.status" label="Trạng thái" :options="options('ticket').slice(1)" @update:model-value="(v) => setField('status', v)" />
          <AppSelect :model-value="ticket.priority" label="Ưu tiên" :options="options('priority').slice(1)" @update:model-value="(v) => setField('priority', v)" />
        </div>
        <ol class="space-y-3">
          <li v-for="(m, i) in ticket.messages" :key="i" class="rounded-lg p-3 text-sm" :class="m.from === 'customer' ? 'bg-surface-muted' : m.from === 'note' ? 'border border-amber-200 bg-warning-50' : 'bg-brand-50'">
            <p class="mb-1 flex flex-wrap items-center justify-between gap-2 text-xs text-ink-muted"><span class="font-medium text-ink">{{ m.author }}<span v-if="m.from === 'note'"> · ghi chú nội bộ</span></span>{{ fmtDateTime(m.at) }}</p>
            <p class="whitespace-pre-line">{{ m.body }}</p>
          </li>
        </ol>
        <form class="space-y-2 border-t border-line pt-4" novalidate @submit.prevent="send">
          <div class="flex gap-4 text-sm">
            <label class="flex items-center gap-1.5"><input v-model="reply.values.kind" type="radio" value="staff" /> Trả lời khách</label>
            <label class="flex items-center gap-1.5"><input v-model="reply.values.kind" type="radio" value="note" /> Ghi chú nội bộ</label>
          </div>
          <DTextarea v-model="reply.values.body" :rows="4" :error="reply.errors.body" :placeholder="reply.values.kind === 'note' ? 'Chỉ nhân viên thấy…' : 'Nội dung phản hồi…'" />
          <AppButton type="submit" :loading="reply.submitting.value"><AppIcon name="send" :size="16" /> {{ reply.values.kind === 'note' ? 'Lưu ghi chú' : 'Gửi phản hồi' }}</AppButton>
        </form>
      </div>
    </Drawer>
  </div>
</template>
