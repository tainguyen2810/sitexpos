<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import Sparkline from '../../ui/charts/Sparkline.vue'
import { db, demoMutation } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { options } from '../../labels'
import { fmtRelative } from '../../format'
import type { DemoSite } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const router = useRouter()
const toast = useToast()
const { ws, base, sites, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const plan = computed(() => db.plans.find((p) => p.code === ws.value.planCode)!)
const atLimit = computed(() => plan.value.limits.sites !== -1 && sites.value.length >= plan.value.limits.sites)

const table = useTable(() => sites.value, {
  search: (s) => `${s.name} ${s.url}`,
  filters: { initial: { status: '' }, match: { status: (s, v) => s.status === v } },
  sorts: { name: (s) => s.name, status: (s) => s.status, posts: (s) => s.postsCount, lastSeen: (s) => s.lastSeenAt ?? '' },
  defaultSort: { key: 'name', dir: 'asc' },
})
const columns = [
  { key: 'name', label: 'Website', sortable: true },
  { key: 'status', label: 'Trạng thái', sortable: true },
  { key: 'versions', label: 'WP / Plugin', hideBelow: 'md' as const },
  { key: 'posts', label: 'Bài viết', sortable: true, align: 'right' as const, hideBelow: 'sm' as const },
  { key: 'health', label: 'Độ trễ 14 ngày', hideBelow: 'lg' as const },
  { key: 'lastSeen', label: 'Lần cuối phản hồi', sortable: true, hideBelow: 'md' as const },
  { key: 'actions', label: '', align: 'right' as const },
]

const checking = ref<string | null>(null)
async function healthCheck(s: DemoSite) {
  checking.value = s.id
  try {
    await demoMutation(() => {
      const ok = s.status !== 'disconnected' && s.status !== 'pending_pairing'
      const latency = ok ? Math.round(200 + Math.random() * (s.status === 'degraded' ? 1500 : 400)) : 0
      s.health.push({ at: new Date().toISOString(), ok, latencyMs: latency, error: ok ? undefined : 'connection_refused' })
      s.health = s.health.slice(-14)
      if (ok) {
        s.lastSeenAt = new Date().toISOString()
        s.latencyMs = latency
      }
      if (ok) toast.success(`${s.name}: website phản hồi sau ${latency} ms (kiểm tra mô phỏng).`)
      else toast.error(`${s.name}: không kết nối được website. Kiểm tra plugin SiteX Post.`)
    }, 600)
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    checking.value = null
  }
}

// Edit
const editing = ref<DemoSite | null>(null)
const form = useForm(() => ({ name: '', approvalMode: 'manual' }), { name: [rules.required(), rules.maxLength(80)] })
function openEdit(s: DemoSite) {
  editing.value = s
  form.reset({ name: s.name, approvalMode: s.approvalMode })
}
async function saveEdit() {
  const s = editing.value
  if (!s) return
  await form
    .submit(async () => {
      await demoMutation(() => {
        s.name = String(form.values.name).trim()
        s.approvalMode = form.values.approvalMode as DemoSite['approvalMode']
      })
      toast.success('Đã cập nhật website.')
      editing.value = null
    })
    .catch((e: Error) => toast.error(e.message))
}

// Disconnect / remove
const removing = ref<DemoSite | null>(null)
const removingBusy = ref(false)
async function confirmRemove() {
  const s = removing.value
  if (!s) return
  removingBusy.value = true
  try {
    await demoMutation(() => {
      s.status = 'revoked'
      s.gscConnected = false
    })
    toast.success(`Đã thu hồi kết nối ${s.name}. Khóa ký của plugin không còn hiệu lực.`)
    removing.value = null
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    removingBusy.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader title="Website WordPress" description="Quản lý các website đã kết nối qua plugin SiteX Post: trạng thái, phiên bản, sức khỏe kết nối." :crumbs="crumbs({ label: 'Website' })">
      <template #actions>
        <AppButton :disabled="atLimit" :title="atLimit ? 'Đã đạt giới hạn website của gói' : undefined" @click="router.push(`${base}/connect`)"><AppIcon name="plus" :size="16" /> Kết nối website</AppButton>
      </template>
    </PageHeader>

    <div v-if="atLimit" class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-amber-200 bg-warning-50 px-4 py-3 text-sm text-amber-900">
      <AppIcon name="alert" :size="16" />
      <span class="flex-1">Gói {{ plan.name }} cho phép tối đa {{ plan.limits.sites }} website. Nâng cấp gói để kết nối thêm.</span>
      <RouterLink :to="`${base}/billing`" class="font-medium underline">Xem các gói</RouterLink>
    </div>

    <AppCard :padded="false">
      <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
        <SearchInput v-model="table.q.value" placeholder="Tìm theo tên hoặc tên miền…" />
        <FilterSelect v-model="table.filters.status" label="Trạng thái" :options="options('site')" />
      </Toolbar>
      <DataTable :table="table" :columns="columns" :loading="loading" :error="error" empty-title="Chưa có website nào" empty-description="Kết nối website WordPress đầu tiên để bắt đầu tự động hóa SEO." clickable @retry="reload" @row-click="(s) => router.push(`${base}/sites/${s.id}`)">
        <template #cell-name="{ row }">
          <div class="min-w-0">
            <p class="font-medium text-ink">{{ row.name }}</p>
            <p class="truncate text-xs text-ink-muted">{{ row.url.replace('https://', '') }}</p>
          </div>
        </template>
        <template #cell-status="{ row }"><StatusBadge group="site" :value="row.status" /></template>
        <template #cell-versions="{ row }"><span class="text-xs text-ink-muted">WP {{ row.wpVersion }} · v{{ row.pluginVersion }}</span></template>
        <template #cell-posts="{ row }"><span class="tabular-nums">{{ row.postsCount }}</span> <span class="text-xs text-ink-muted">({{ row.managedPosts }} SiteX)</span></template>
        <template #cell-health="{ row }"><Sparkline :values="row.health.map((h) => h.latencyMs)" :color="row.status === 'connected' ? '#1baf7a' : '#eb6834'" /></template>
        <template #cell-lastSeen="{ row }"><span class="text-ink-muted">{{ fmtRelative(row.lastSeenAt) }}</span></template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-1" @click.stop>
            <AppButton v-if="row.status === 'pending_pairing'" size="sm" @click="router.push(`${base}/connect?site=${row.id}`)">Tiếp tục ghép nối</AppButton>
            <template v-else>
              <AppButton size="sm" variant="ghost" :loading="checking === row.id" :disabled="row.status === 'revoked'" title="Kiểm tra kết nối" @click="healthCheck(row)"><AppIcon name="refresh" :size="16" /></AppButton>
              <AppButton size="sm" variant="ghost" title="Sửa" @click="openEdit(row)"><AppIcon name="edit" :size="16" /></AppButton>
              <AppButton size="sm" variant="ghost" :disabled="row.status === 'revoked'" title="Thu hồi kết nối" @click="removing = row"><AppIcon name="trash" :size="16" /></AppButton>
            </template>
          </div>
        </template>
        <template #empty-action>
          <AppButton @click="router.push(`${base}/connect`)"><AppIcon name="plug" :size="16" /> Kết nối website</AppButton>
        </template>
      </DataTable>
    </AppCard>

    <AppModal :open="!!editing" title="Sửa thông tin website" @close="editing = null">
      <form id="site-edit" class="space-y-4" @submit.prevent="saveEdit">
        <AppInput v-model="form.values.name" label="Tên hiển thị" :error="form.errors.name" required />
        <AppSelect v-model="form.values.approvalMode" label="Chế độ duyệt bài" :options="[{ value: 'manual', label: 'Thủ công – biên tập viên duyệt trước khi đăng' }, { value: 'auto', label: 'Tự động – đăng khi đã qua kiểm chứng' }]" />
        <p class="text-xs text-ink-muted">Kể cả chế độ tự động, bài chưa qua kiểm chứng (fact-check) sẽ không bao giờ được đăng.</p>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="editing = null">Hủy</AppButton>
        <AppButton type="submit" form="site-edit" :loading="form.submitting.value">Lưu thay đổi</AppButton>
      </template>
    </AppModal>

    <ConfirmDialog :open="!!removing" title="Thu hồi kết nối website?" :message="`SiteX Post sẽ ngừng đăng bài lên ${removing?.name ?? ''}. Plugin trên website cần được ghép nối lại nếu muốn dùng tiếp.`" confirm-label="Thu hồi" danger :loading="removingBusy" @close="removing = null" @confirm="confirmRemove" />
  </div>
</template>
