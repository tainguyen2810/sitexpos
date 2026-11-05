<script setup lang="ts">
import { computed, ref } from 'vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import { db, demoMutation, newId } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { label, options } from '../../labels'
import { fmtDate, fmtRelative } from '../../format'
import type { DemoMember, WorkspaceRole } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const toast = useToast()
const { ws, base, mine, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const members = computed(() => mine(db.members))
const plan = computed(() => db.plans.find((p) => p.code === ws.value.planCode)!)
const seatLimit = computed(() => plan.value.limits.seats)
const seatsFull = computed(() => seatLimit.value !== -1 && members.value.length >= seatLimit.value)
// In the demo the signed-in user is the workspace owner.
const isMe = (m: DemoMember) => m.email === ws.value.ownerEmail

const ROLE_ORDER: WorkspaceRole[] = ['OWNER', 'ADMIN', 'EDITOR', 'VIEWER']
const table = useTable(() => members.value, {
  search: (m) => `${m.name} ${m.email}`,
  filters: { initial: { role: '', status: '' }, match: { role: (m, v) => m.role === v, status: (m, v) => m.status === v } },
  sorts: { name: (m) => m.name, role: (m) => ROLE_ORDER.indexOf(m.role), active: (m) => m.lastActiveAt ?? '' },
  defaultSort: { key: 'role', dir: 'asc' },
})
const assignable = [
  { value: 'ADMIN', label: 'Quản trị viên' },
  { value: 'EDITOR', label: 'Biên tập viên' },
  { value: 'VIEWER', label: 'Người xem' },
]

function syncSeats() {
  const u = db.usage.find((x) => x.workspaceId === ws.value.id && x.metric === 'seats')
  if (u) u.used = members.value.length
}
function audit(action: string, subject: string) {
  db.auditLogs.unshift({ id: newId('log'), workspaceId: ws.value.id, actorName: ws.value.ownerName, actorType: 'user', action, subject, ip: '203.0.113.10', createdAt: new Date().toISOString() })
}

// Invite
const inviteOpen = ref(false)
const invite = useForm(() => ({ name: '', email: '', role: 'EDITOR' }), {
  name: [rules.required(), rules.minLength(2)],
  email: [rules.required(), rules.email(), (v) => (members.value.some((m) => m.email.toLowerCase() === String(v).trim().toLowerCase()) ? 'Email này đã có trong workspace.' : true)],
  role: [rules.required()],
})
function openInvite() {
  if (seatsFull.value) return toast.error(`Gói ${plan.value.name} cho phép tối đa ${seatLimit.value} thành viên. Nâng cấp gói để mời thêm.`)
  invite.reset()
  inviteOpen.value = true
}
async function sendInvite() {
  await invite
    .submit(async () => {
      await demoMutation(() => {
        const now = new Date().toISOString()
        db.members.push({ id: newId('mem'), workspaceId: ws.value.id, name: invite.values.name.trim(), email: invite.values.email.trim().toLowerCase(), role: invite.values.role as WorkspaceRole, status: 'invited', lastActiveAt: null, joinedAt: now })
        syncSeats()
        audit('member.invited', invite.values.email)
      })
      toast.success(`Đã gửi lời mời tới ${invite.values.email} (demo: không gửi email thật).`)
      inviteOpen.value = false
    })
    .catch((e: Error) => toast.error(e.message))
}

// Change role
const editing = ref<DemoMember | null>(null)
const newRole = ref('EDITOR')
const savingRole = ref(false)
function openRole(m: DemoMember) {
  editing.value = m
  newRole.value = m.role
}
async function saveRole() {
  const m = editing.value
  if (!m) return
  savingRole.value = true
  try {
    await demoMutation(() => {
      m.role = newRole.value as WorkspaceRole
      audit('member.role_changed', `${m.email} → ${newRole.value}`)
    })
    toast.success(`${m.name} giờ là ${label('role', m.role)}.`)
    editing.value = null
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    savingRole.value = false
  }
}

// Remove / resend
const removing = ref<DemoMember | null>(null)
const busy = ref(false)
async function confirmRemove() {
  const m = removing.value
  if (!m) return
  busy.value = true
  try {
    await demoMutation(() => {
      db.members.splice(db.members.indexOf(m), 1)
      syncSeats()
      audit(m.status === 'invited' ? 'member.invite_revoked' : 'member.removed', m.email)
    })
    toast.success(m.status === 'invited' ? `Đã thu hồi lời mời ${m.email}.` : `Đã xóa ${m.name} khỏi workspace.`)
    removing.value = null
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    busy.value = false
  }
}
const resending = ref<string | null>(null)
async function resend(m: DemoMember) {
  resending.value = m.id
  try {
    await demoMutation(() => {
      m.joinedAt = new Date().toISOString()
      audit('member.invite_resent', m.email)
    })
    toast.success(`Đã gửi lại lời mời tới ${m.email}. Lời mời có hiệu lực 7 ngày.`)
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    resending.value = null
  }
}

const PERMS: [string, boolean[]][] = [
  ['Xem bài viết, báo cáo, analytics', [true, true, true, true]],
  ['Viết, sửa bài và dùng công cụ AI', [true, true, true, false]],
  ['Duyệt và lên lịch đăng bài', [true, true, false, false]],
  ['Kết nối/ngắt website, quản lý thành viên', [true, true, false, false]],
  ['Thanh toán, đổi gói, xóa workspace', [true, false, false, false]],
]
</script>

<template>
  <div>
    <PageHeader title="Thành viên" description="Mời đồng đội và phân quyền theo vai trò. Lời mời trong demo không gửi email thật." :crumbs="crumbs({ label: 'Thành viên' })">
      <template #actions>
        <AppButton @click="openInvite"><AppIcon name="plus" :size="16" /> Mời thành viên</AppButton>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" :rows="5" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <div v-else class="space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-surface p-4 text-sm">
        <p>
          Đang dùng <strong class="tabular-nums">{{ members.length }}</strong> / {{ seatLimit === -1 ? 'không giới hạn' : seatLimit }} chỗ của gói <strong>{{ plan.name }}</strong>
          <span class="text-ink-muted">(tính cả lời mời đang chờ)</span>
        </p>
        <RouterLink v-if="seatsFull" :to="`${base}/billing`" class="font-medium text-brand-700 hover:underline">Nâng cấp để thêm chỗ →</RouterLink>
      </div>

      <AppCard :padded="false">
        <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
          <SearchInput v-model="table.q.value" placeholder="Tìm tên hoặc email…" />
          <FilterSelect v-model="table.filters.role" label="Vai trò" :options="options('role')" />
          <FilterSelect v-model="table.filters.status" label="Trạng thái" :options="options('member')" />
        </Toolbar>
        <DataTable :table="table" :columns="[{ key: 'name', label: 'Thành viên', sortable: true }, { key: 'role', label: 'Vai trò', sortable: true }, { key: 'status', label: 'Trạng thái', hideBelow: 'sm' }, { key: 'active', label: 'Hoạt động gần nhất', sortable: true, hideBelow: 'md' }, { key: 'actions', label: '', align: 'right' }]" empty-title="Không tìm thấy thành viên" empty-description="Thử đổi từ khóa hoặc bỏ bộ lọc.">
          <template #cell-name="{ row }">
            <div class="flex items-center gap-3">
              <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">{{ row.name.split(' ').slice(-1)[0]?.[0] }}</span>
              <div class="min-w-0">
                <p class="truncate font-medium">{{ row.name }} <span v-if="isMe(row)" class="text-xs font-normal text-ink-muted">(bạn)</span></p>
                <p class="truncate text-xs text-ink-muted">{{ row.email }}</p>
              </div>
            </div>
          </template>
          <template #cell-role="{ row }"><StatusBadge group="role" :value="row.role" /></template>
          <template #cell-status="{ row }"><StatusBadge group="member" :value="row.status" /></template>
          <template #cell-active="{ row }">
            <span class="text-ink-muted">{{ row.status === 'invited' ? `Mời ${fmtDate(row.joinedAt)}` : row.lastActiveAt ? fmtRelative(row.lastActiveAt) : '—' }}</span>
          </template>
          <template #cell-actions="{ row }">
            <div v-if="row.role !== 'OWNER'" class="flex justify-end gap-1">
              <AppButton v-if="row.status === 'invited'" size="sm" variant="ghost" :loading="resending === row.id" @click="resend(row)">Gửi lại</AppButton>
              <AppButton size="sm" variant="ghost" title="Đổi vai trò" @click="openRole(row)"><AppIcon name="edit" :size="16" /></AppButton>
              <AppButton size="sm" variant="ghost" :title="row.status === 'invited' ? 'Thu hồi lời mời' : 'Xóa khỏi workspace'" @click="removing = row"><AppIcon name="trash" :size="16" class="text-danger-600" /></AppButton>
            </div>
            <span v-else class="text-xs text-ink-subtle">Không thể đổi</span>
          </template>
        </DataTable>
      </AppCard>

      <AppCard title="Quyền theo vai trò">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[520px] text-sm">
            <thead>
              <tr class="text-left text-xs text-ink-muted">
                <th class="py-2 font-medium">Quyền</th>
                <th v-for="r in ROLE_ORDER" :key="r" class="py-2 text-center font-medium">{{ label('role', r) }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="[perm, allowed] in PERMS" :key="perm">
                <td class="py-2">{{ perm }}</td>
                <td v-for="(ok, i) in allowed" :key="i" class="py-2 text-center">
                  <AppIcon v-if="ok" name="check" :size="16" class="inline text-success-600" /><span v-else class="text-ink-subtle" aria-label="Không">–</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>
    </div>

    <AppModal :open="inviteOpen" title="Mời thành viên" @close="inviteOpen = false">
      <form id="invite" class="space-y-4" novalidate @submit.prevent="sendInvite">
        <AppInput v-model="invite.values.name" label="Họ tên" :error="invite.errors.name" />
        <AppInput v-model="invite.values.email" type="email" label="Email" placeholder="ten@congty.example" :error="invite.errors.email" />
        <AppSelect v-model="invite.values.role" label="Vai trò" :options="assignable" :error="invite.errors.role" />
        <p class="text-xs text-ink-muted">Lời mời hết hạn sau 7 ngày. Chỉ Chủ sở hữu mới có quyền thanh toán.</p>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="inviteOpen = false">Hủy</AppButton>
        <AppButton type="submit" form="invite" :loading="invite.submitting.value">Gửi lời mời</AppButton>
      </template>
    </AppModal>

    <AppModal :open="!!editing" :title="`Đổi vai trò – ${editing?.name}`" width="sm" @close="editing = null">
      <AppSelect v-model="newRole" label="Vai trò mới" :options="assignable" />
      <template #footer>
        <AppButton variant="secondary" @click="editing = null">Hủy</AppButton>
        <AppButton :loading="savingRole" :disabled="newRole === editing?.role" @click="saveRole">Lưu</AppButton>
      </template>
    </AppModal>

    <ConfirmDialog
      :open="!!removing"
      :title="removing?.status === 'invited' ? 'Thu hồi lời mời?' : `Xóa ${removing?.name}?`"
      :message="removing?.status === 'invited' ? `Liên kết mời gửi tới ${removing?.email} sẽ không dùng được nữa.` : 'Người này mất quyền truy cập ngay. Bài viết họ đã tạo vẫn được giữ lại.'"
      :confirm-label="removing?.status === 'invited' ? 'Thu hồi' : 'Xóa thành viên'"
      danger
      :loading="busy"
      @close="removing = null"
      @confirm="confirmRemove"
    />
  </div>
</template>
