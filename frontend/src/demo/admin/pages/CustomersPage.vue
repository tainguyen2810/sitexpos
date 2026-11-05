<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
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
import DemoErrorState from '../../ui/DemoErrorState.vue'
import { db, demoMutation, newId } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { options } from '../../labels'
import { fmtDate, fmtDateTime, fmtNumber, fmtVnd } from '../../format'
import { DEMO_CUSTOMER_URL } from '../../index'
import type { DemoWorkspace } from '../../data/types'
import { adminCrumbs, planName, staffAudit } from '../useAdmin'

const toast = useToast()
const route = useRoute()
const { loading, error, reload } = useDemoQuery()

const sub = (w: DemoWorkspace) => db.subscriptions.find((s) => s.workspaceId === w.id)
const count = <T extends { workspaceId: string }>(list: T[], id: string) => list.filter((x) => x.workspaceId === id).length
const revenue = (id: string) => db.transactions.filter((t) => t.workspaceId === id && t.status === 'succeeded' && t.type !== 'refund').reduce((s, t) => s + t.amountMinor, 0)

const table = useTable(() => db.workspaces, {
  search: (w) => `${w.name} ${w.ownerName} ${w.ownerEmail} ${w.slug}`,
  filters: { initial: { status: '', plan: '' }, match: { status: (w, v) => w.status === v, plan: (w, v) => w.planCode === v } },
  sorts: { name: (w) => w.name, created: (w) => w.createdAt, credits: (w) => w.credits, revenue: (w) => revenue(w.id) },
  defaultSort: { key: 'created', dir: 'desc' },
})
if (typeof route.query.q === 'string') table.q.value = route.query.q
const planOptions = computed(() => [{ value: '', label: 'Tất cả' }, ...db.plans.map((p) => ({ value: p.code, label: p.name }))])

const selected = ref<DemoWorkspace | null>(null)
const busy = ref(false)
async function run(fn: () => void, msg: string) {
  busy.value = true
  try {
    await demoMutation(fn)
    toast.success(msg)
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    busy.value = false
  }
}

// Suspend / reactivate (needs a reason)
const suspendOpen = ref(false)
const suspendReason = ref('')
const suspendError = ref('')
async function toggleSuspend() {
  const w = selected.value!
  if (w.status !== 'suspended' && suspendReason.value.trim().length < 10) {
    suspendError.value = 'Nhập lý do tối thiểu 10 ký tự (được ghi vào audit log).'
    return
  }
  const suspending = w.status !== 'suspended'
  await run(() => {
    w.status = suspending ? 'suspended' : sub(w)?.status === 'trialing' ? 'trialing' : 'active'
    staffAudit(suspending ? 'platform.workspace.suspended' : 'platform.workspace.reactivated', suspending ? `${w.name}: ${suspendReason.value.trim()}` : w.name, w.id)
  }, suspending ? `Đã tạm khóa ${w.name}. Job của workspace sẽ dừng.` : `Đã mở khóa ${w.name}.`)
  suspendOpen.value = false
}
function openSuspend() {
  suspendReason.value = ''
  suspendError.value = ''
  suspendOpen.value = true
}

// Grant credits
const grantOpen = ref(false)
const grant = useForm(() => ({ amount: '100', reason: '' }), {
  amount: [rules.required(), (v) => (/^-?\d+$/.test(String(v)) && Number(v) !== 0 ? true : 'Nhập số nguyên khác 0.'), rules.min(-10000), rules.max(10000)],
  reason: [rules.required(), rules.minLength(10)],
})
async function submitGrant() {
  const w = selected.value!
  await grant
    .submit(async () => {
      const delta = Number(grant.values.amount)
      if (w.credits + delta < 0) throw new Error(`Không thể trừ quá số dư hiện tại (${fmtNumber(w.credits)}).`)
      await demoMutation(() => {
        w.credits += delta
        db.credits.push({ id: newId('cr'), workspaceId: w.id, delta, balanceAfter: w.credits, reason: delta > 0 ? 'grant' : 'expire', note: `Điều chỉnh bởi hỗ trợ: ${grant.values.reason.trim()}`, createdAt: new Date().toISOString() })
        staffAudit('platform.credits.adjusted', `${w.name}: ${delta > 0 ? '+' : ''}${delta}`, w.id)
      })
      toast.success(`Đã ${delta > 0 ? 'cộng' : 'trừ'} ${fmtNumber(Math.abs(delta))} credit cho ${w.name}.`)
      grantOpen.value = false
    })
    .catch((e: Error) => toast.error(e.message))
}

// Change plan
const planOpen = ref(false)
const newPlan = ref('')
async function savePlan() {
  const w = selected.value!
  const s = sub(w)
  await run(() => {
    w.planCode = newPlan.value
    if (s) s.planCode = newPlan.value
    staffAudit('platform.subscription.changed', `${w.name} → ${planName(newPlan.value)}`, w.id)
  }, `${w.name} đã chuyển sang gói ${planName(newPlan.value)} (không phát sinh thanh toán).`)
  planOpen.value = false
}

// Impersonation (read-only support view, reason required)
const impOpen = ref(false)
const imp = useForm(() => ({ reason: '' }), { reason: [rules.required(), rules.minLength(15)] })
async function startImpersonation() {
  const w = selected.value!
  await imp
    .submit(async () => {
      await demoMutation(() => staffAudit('platform.impersonation.started', `${w.name}: ${imp.values.reason.trim()}`, w.id))
      window.open(`${DEMO_CUSTOMER_URL}/w/${w.id}/dashboard`, '_blank', 'noopener')
      toast.success('Đã mở phiên hỗ trợ ở tab mới (ghi audit log). Phiên hết hạn sau 30 phút trong bản thật.')
      impOpen.value = false
    })
    .catch((e: Error) => toast.error(e.message))
}
</script>

<template>
  <div>
    <PageHeader title="Quản lý khách hàng" description="Workspace của khách hàng, gói cước và các thao tác hỗ trợ. Mọi thao tác đều được ghi audit log." :crumbs="adminCrumbs({ label: 'Khách hàng' })" />

    <LoadingState v-if="loading" :rows="6" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <AppCard v-else :padded="false">
      <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
        <SearchInput v-model="table.q.value" placeholder="Tên, chủ sở hữu, email…" />
        <FilterSelect v-model="table.filters.status" label="Trạng thái" :options="options('workspace')" />
        <FilterSelect v-model="table.filters.plan" label="Gói" :options="planOptions" />
      </Toolbar>
      <DataTable
        :table="table"
        clickable
        :columns="[{ key: 'name', label: 'Khách hàng', sortable: true }, { key: 'plan', label: 'Gói' }, { key: 'sites', label: 'Website', align: 'right', hideBelow: 'md' }, { key: 'credits', label: 'Credit', sortable: true, align: 'right', hideBelow: 'md' }, { key: 'revenue', label: 'Doanh thu', sortable: true, align: 'right', hideBelow: 'lg' }, { key: 'created', label: 'Ngày tạo', sortable: true, hideBelow: 'lg' }, { key: 'status', label: 'Trạng thái' }]"
        empty-title="Không có khách hàng phù hợp"
        empty-description="Thử đổi từ khóa hoặc bộ lọc."
        @row-click="(w) => (selected = w)"
      >
        <template #cell-name="{ row }">
          <p class="font-medium">{{ row.name }}</p>
          <p class="text-xs text-ink-muted">{{ row.ownerName }} · {{ row.ownerEmail }}</p>
        </template>
        <template #cell-plan="{ row }">{{ planName(row.planCode) }}</template>
        <template #cell-sites="{ row }"><span class="tabular-nums">{{ count(db.sites, row.id) }}</span></template>
        <template #cell-credits="{ row }"><span class="tabular-nums">{{ fmtNumber(row.credits) }}</span></template>
        <template #cell-revenue="{ row }"><span class="tabular-nums">{{ fmtVnd(revenue(row.id)) }}</span></template>
        <template #cell-created="{ row }"><span class="text-ink-muted">{{ fmtDate(row.createdAt) }}</span></template>
        <template #cell-status="{ row }"><StatusBadge group="workspace" :value="row.status" /></template>
      </DataTable>
    </AppCard>

    <Drawer :open="!!selected" :title="selected?.name ?? ''" :subtitle="selected ? `${selected.industry} · tạo ${fmtDate(selected.createdAt)}` : ''" @close="selected = null">
      <div v-if="selected" class="space-y-5 text-sm">
        <div class="flex flex-wrap gap-2"><StatusBadge group="workspace" :value="selected.status" /><StatusBadge group="subscription" :value="sub(selected)?.status" /></div>
        <dl class="grid grid-cols-2 gap-3">
          <div class="rounded-lg bg-surface-muted p-3"><dt class="text-xs text-ink-muted">Gói</dt><dd class="font-semibold">{{ planName(selected.planCode) }}</dd></div>
          <div class="rounded-lg bg-surface-muted p-3"><dt class="text-xs text-ink-muted">Credit</dt><dd class="font-semibold tabular-nums">{{ fmtNumber(selected.credits) }}</dd></div>
          <div class="rounded-lg bg-surface-muted p-3"><dt class="text-xs text-ink-muted">Website</dt><dd class="font-semibold">{{ count(db.sites, selected.id) }}</dd></div>
          <div class="rounded-lg bg-surface-muted p-3"><dt class="text-xs text-ink-muted">Thành viên</dt><dd class="font-semibold">{{ count(db.members, selected.id) }}</dd></div>
          <div class="rounded-lg bg-surface-muted p-3"><dt class="text-xs text-ink-muted">Bài viết</dt><dd class="font-semibold">{{ count(db.articles, selected.id) }}</dd></div>
          <div class="rounded-lg bg-surface-muted p-3"><dt class="text-xs text-ink-muted">Doanh thu</dt><dd class="font-semibold">{{ fmtVnd(revenue(selected.id)) }}</dd></div>
        </dl>
        <div>
          <h3 class="mb-2 font-medium">Liên hệ</h3>
          <p>{{ selected.ownerName }} – {{ selected.ownerEmail }}</p>
          <p class="text-ink-muted">{{ selected.phone }} · {{ selected.timezone }}</p>
        </div>
        <div>
          <h3 class="mb-2 font-medium">Website</h3>
          <ul class="space-y-1.5">
            <li v-for="s in db.sites.filter((x) => x.workspaceId === selected!.id)" :key="s.id" class="flex items-center justify-between gap-2">
              <span class="truncate">{{ s.name }} <span class="text-xs text-ink-muted">{{ s.url }}</span></span>
              <StatusBadge group="site" :value="s.status" />
            </li>
          </ul>
        </div>
        <div>
          <h3 class="mb-2 font-medium">Hoạt động gần đây</h3>
          <ul class="space-y-1 text-xs text-ink-muted">
            <li v-for="l in db.auditLogs.filter((x) => x.workspaceId === selected!.id).slice(0, 5)" :key="l.id"><code>{{ l.action }}</code> · {{ l.actorName }} · {{ fmtDateTime(l.createdAt) }}</li>
          </ul>
        </div>
      </div>
      <template #footer>
        <AppButton variant="secondary" @click="(imp.reset(), (impOpen = true))"><AppIcon name="eye" :size="16" /> Xem như khách hàng</AppButton>
        <AppButton variant="secondary" @click="(grant.reset(), (grantOpen = true))"><AppIcon name="coins" :size="16" /> Điều chỉnh credit</AppButton>
        <AppButton variant="secondary" @click="((newPlan = selected!.planCode), (planOpen = true))"><AppIcon name="layers" :size="16" /> Đổi gói</AppButton>
        <AppButton :variant="selected?.status === 'suspended' ? 'primary' : 'danger'" @click="openSuspend">{{ selected?.status === 'suspended' ? 'Mở khóa' : 'Tạm khóa' }}</AppButton>
      </template>
    </Drawer>

    <AppModal :open="suspendOpen" :title="selected?.status === 'suspended' ? `Mở khóa ${selected?.name}?` : `Tạm khóa ${selected?.name}?`" width="sm" @close="suspendOpen = false">
      <p class="text-sm text-ink-muted">{{ selected?.status === 'suspended' ? 'Khách hàng sẽ dùng lại được ngay, job được tiếp tục.' : 'Khách hàng không thể đăng nhập, mọi job AI và lịch đăng bài bị dừng.' }}</p>
      <DTextarea v-if="selected?.status !== 'suspended'" v-model="suspendReason" class="mt-3" label="Lý do" :rows="3" :error="suspendError" />
      <template #footer>
        <AppButton variant="secondary" @click="suspendOpen = false">Hủy</AppButton>
        <AppButton :variant="selected?.status === 'suspended' ? 'primary' : 'danger'" :loading="busy" @click="toggleSuspend">Xác nhận</AppButton>
      </template>
    </AppModal>

    <AppModal :open="grantOpen" :title="`Điều chỉnh credit – ${selected?.name}`" width="sm" @close="grantOpen = false">
      <form id="grant" class="space-y-3" novalidate @submit.prevent="submitGrant">
        <AppInput v-model="grant.values.amount" label="Số credit (+ cộng, − trừ)" :error="grant.errors.amount" :hint="`Số dư hiện tại: ${fmtNumber(selected?.credits ?? 0)}`" />
        <DTextarea v-model="grant.values.reason" label="Lý do" :rows="2" :error="grant.errors.reason" placeholder="Ví dụ: hoàn credit do job AI lỗi #123" />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="grantOpen = false">Hủy</AppButton>
        <AppButton type="submit" form="grant" :loading="grant.submitting.value">Lưu</AppButton>
      </template>
    </AppModal>

    <AppModal :open="planOpen" :title="`Đổi gói – ${selected?.name}`" width="sm" @close="planOpen = false">
      <AppSelect v-model="newPlan" label="Gói mới" :options="db.plans.map((p) => ({ value: p.code, label: `${p.name}${p.isPublic ? '' : ' (riêng)'} – ${p.priceMonthMinor ? fmtVnd(p.priceMonthMinor) + '/tháng' : 'liên hệ'}` }))" />
      <p class="mt-2 text-xs text-ink-muted">Đổi gói thủ công không tạo hóa đơn. Dùng cho hợp đồng riêng hoặc hỗ trợ.</p>
      <template #footer>
        <AppButton variant="secondary" @click="planOpen = false">Hủy</AppButton>
        <AppButton :disabled="newPlan === selected?.planCode" :loading="busy" @click="savePlan">Đổi gói</AppButton>
      </template>
    </AppModal>

    <AppModal :open="impOpen" title="Mở phiên hỗ trợ" width="sm" @close="impOpen = false">
      <form id="imp" novalidate @submit.prevent="startImpersonation">
        <p class="mb-3 text-sm text-ink-muted">Bạn sẽ xem workspace <strong>{{ selected?.name }}</strong> như khách hàng. Lý do được lưu vào audit log và khách hàng có thể xem.</p>
        <DTextarea v-model="imp.values.reason" label="Lý do hỗ trợ" :rows="3" :error="imp.errors.reason" placeholder="Ví dụ: ticket #tk… – kiểm tra lỗi kết nối website" />
        <p class="mt-2 text-xs text-ink-subtle">Demo: mở ứng dụng khách hàng ở tab mới (dữ liệu mẫu độc lập theo từng ứng dụng).</p>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="impOpen = false">Hủy</AppButton>
        <AppButton type="submit" form="imp" :loading="imp.submitting.value">Bắt đầu</AppButton>
      </template>
    </AppModal>
  </div>
</template>
