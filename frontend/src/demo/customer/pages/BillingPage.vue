<script setup lang="ts">
import { computed, ref } from 'vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
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
import DemoCheckoutModal from '../components/DemoCheckoutModal.vue'
import { db, demoMutation, newId } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { LIMIT_LABELS, options } from '../../labels'
import { fmtDate, fmtVnd } from '../../format'
import { downloadFile } from '../../download'
import type { DemoInvoice, DemoPlan, LimitKey } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const toast = useToast()
const { ws, mine, sites, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const sub = computed(() => db.subscriptions.find((s) => s.workspaceId === ws.value.id)!)
const plan = computed(() => db.plans.find((p) => p.code === sub.value.planCode)!)
const publicPlans = computed(() => db.plans.filter((p) => p.isPublic && p.isActive))
const interval = ref<'month' | 'year'>(sub.value.interval)
const price = (p: DemoPlan) => (interval.value === 'month' ? p.priceMonthMinor : p.priceYearMinor)
const rank = (code: string) => db.plans.findIndex((p) => p.code === code)
const members = computed(() => mine(db.members).length)

// Plan change
const target = ref<DemoPlan | null>(null)
const checkoutOpen = ref(false)
const downgradeOpen = ref(false)
function choose(p: DemoPlan) {
  if (p.code === plan.value.code && interval.value === sub.value.interval) return
  const blockers: string[] = []
  if (p.limits.sites !== -1 && sites.value.length > p.limits.sites) blockers.push(`${sites.value.length} website (gói cho phép ${p.limits.sites})`)
  if (p.limits.seats !== -1 && members.value > p.limits.seats) blockers.push(`${members.value} thành viên (gói cho phép ${p.limits.seats})`)
  if (blockers.length) {
    toast.error(`Không thể chuyển sang ${p.name}: đang dùng ${blockers.join(', ')}. Hãy giảm bớt trước.`)
    return
  }
  target.value = p
  if (rank(p.code) > rank(plan.value.code) || sub.value.status === 'trialing') checkoutOpen.value = true
  else downgradeOpen.value = true
}
function recordPayment(amount: number, description: string, method: string, type: 'charge' | 'credit_purchase' = 'charge') {
  const now = new Date().toISOString()
  const inv: DemoInvoice = {
    id: newId('inv'),
    workspaceId: ws.value.id,
    number: `SXP-DEMO-${Math.floor(5000 + Math.random() * 4999)}`,
    status: 'paid',
    description,
    subtotalMinor: Math.round(amount / 1.1),
    taxMinor: amount - Math.round(amount / 1.1),
    totalMinor: amount,
    gateway: 'demo_gateway',
    issuedAt: now,
    paidAt: now,
  }
  db.invoices.push(inv)
  db.transactions.push({ id: newId('txn'), workspaceId: ws.value.id, invoiceId: inv.id, type, amountMinor: amount, status: 'succeeded', gatewayRef: `demo_ch_${Date.now()}`, method, createdAt: now, failureReason: null })
}
async function onPaid(method: string) {
  const p = target.value
  if (!p) return
  await demoMutation(() => {
    const amount = Math.round(price(p) * 1.1)
    recordPayment(amount, `Gói ${p.name} – ${interval.value === 'month' ? 'tháng' : '12 tháng'} (demo)`, method)
    const s = sub.value
    s.planCode = p.code
    s.interval = interval.value
    s.status = 'active'
    s.cancelAtPeriodEnd = false
    s.currentPeriodStart = new Date().toISOString()
    s.currentPeriodEnd = new Date(Date.now() + (interval.value === 'month' ? 30 : 365) * 86_400_000).toISOString()
    if (!s.paymentMethod && method.startsWith('Thẻ')) s.paymentMethod = { brand: 'VISA', last4: method.slice(-4), exp: '12/29' }
    ws.value.planCode = p.code
    if (ws.value.status === 'trialing') ws.value.status = 'active'
    ws.value.credits += p.monthlyCredits
    db.credits.push({ id: newId('cr'), workspaceId: ws.value.id, delta: p.monthlyCredits, balanceAfter: ws.value.credits, reason: 'grant', note: `Credit gói ${p.name}`, createdAt: new Date().toISOString() })
  }).catch((e: Error) => toast.error(e.message))
  toast.success(`Đã chuyển sang gói ${p.name}. Hóa đơn demo đã được tạo.`)
}
async function confirmDowngrade() {
  const p = target.value
  if (!p) return
  try {
    await demoMutation(() => {
      sub.value.planCode = p.code
      sub.value.interval = interval.value
      ws.value.planCode = p.code
    })
    toast.success(`Gói ${p.name} sẽ áp dụng từ ${fmtDate(sub.value.currentPeriodEnd)} (demo áp dụng ngay).`)
  } catch (e) {
    toast.error((e as Error).message)
  }
  downgradeOpen.value = false
}
const cancelOpen = ref(false)
async function toggleCancel() {
  try {
    await demoMutation(() => (sub.value.cancelAtPeriodEnd = !sub.value.cancelAtPeriodEnd))
    toast.success(sub.value.cancelAtPeriodEnd ? `Gói sẽ dừng vào ${fmtDate(sub.value.currentPeriodEnd)}.` : 'Đã tiếp tục gia hạn tự động.')
  } catch (e) {
    toast.error((e as Error).message)
  }
  cancelOpen.value = false
}

// Billing profile
const profile = useForm(() => ({ company: ws.value.name.replace(' (Demo)', ''), taxCode: '0312345678', email: ws.value.ownerEmail, address: '123 Đường Mẫu, Quận 1, TP.HCM (địa chỉ demo)' }), {
  company: [rules.required()],
  taxCode: [(v) => (!v || /^\d{10}(-\d{3})?$/.test(String(v)) ? true : 'Mã số thuế gồm 10 số hoặc 10 số-3 số.')],
  email: [rules.required(), rules.email()],
  address: [rules.required(), rules.minLength(10)],
})
async function saveProfile() {
  await profile
    .submit(async () => {
      await demoMutation(() => undefined)
      toast.success('Đã lưu thông tin xuất hóa đơn.')
    })
    .catch((e: Error) => toast.error(e.message))
}

// Invoices
const invoices = computed(() => mine(db.invoices))
const table = useTable(() => invoices.value, {
  search: (i) => `${i.number} ${i.description}`,
  filters: { initial: { status: '' }, match: { status: (i, v) => i.status === v } },
  sorts: { issued: (i) => i.issuedAt, total: (i) => i.totalMinor },
  defaultSort: { key: 'issued', dir: 'desc' },
})
function downloadInvoice(i: DemoInvoice) {
  const html = `<!doctype html><meta charset="utf-8"><title>${i.number}</title><body style="font-family:sans-serif;max-width:640px;margin:40px auto">
<p style="background:#fef3c7;padding:8px">HÓA ĐƠN DEMO – không có giá trị pháp lý</p>
<h1>SiteX Post Cloud</h1><p>Số: ${i.number}<br>Ngày: ${fmtDate(i.issuedAt)}<br>Khách hàng: ${ws.value.name}</p>
<table width="100%" border="1" cellpadding="6" style="border-collapse:collapse"><tr><td>${i.description}</td><td align="right">${fmtVnd(i.subtotalMinor)}</td></tr>
<tr><td>VAT 10%</td><td align="right">${fmtVnd(i.taxMinor)}</td></tr><tr><th align="left">Tổng</th><th align="right">${fmtVnd(i.totalMinor)}</th></tr></table></body>`
  downloadFile(`${i.number}.html`, html, 'text/html;charset=utf-8')
  toast.success(`Đã tải hóa đơn ${i.number} (demo).`)
}
const limitKeys = Object.keys(LIMIT_LABELS) as LimitKey[]
const fmtLimit = (n: number) => (n === -1 ? 'Không giới hạn' : n.toLocaleString('vi-VN'))
</script>

<template>
  <div>
    <PageHeader title="Gói cước & Thanh toán" description="Quản lý gói dịch vụ, phương thức thanh toán và hóa đơn. Giá trong demo là giá minh họa." :crumbs="crumbs({ label: 'Gói cước & Thanh toán' })" />

    <LoadingState v-if="loading" :rows="5" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <div v-else class="space-y-6">
      <div class="grid gap-6 lg:grid-cols-3">
        <AppCard class="lg:col-span-2" title="Gói hiện tại">
          <div class="flex flex-wrap items-start gap-4">
            <div class="flex-1">
              <p class="flex items-center gap-2 text-2xl font-semibold">{{ plan.name }} <StatusBadge group="subscription" :value="sub.status" /></p>
              <p class="mt-1 text-sm text-ink-muted">{{ fmtVnd(sub.interval === 'month' ? plan.priceMonthMinor : plan.priceYearMinor) }} / {{ sub.interval === 'month' ? 'tháng' : 'năm' }} · chu kỳ {{ fmtDate(sub.currentPeriodStart) }} – {{ fmtDate(sub.currentPeriodEnd) }}</p>
              <p v-if="sub.status === 'trialing'" class="mt-2 text-sm text-brand-700">Đang dùng thử – hết hạn {{ fmtDate(sub.currentPeriodEnd) }}. Chọn gói để tiếp tục.</p>
              <p v-if="sub.cancelAtPeriodEnd" class="mt-2 text-sm text-danger-600">Gói sẽ dừng vào {{ fmtDate(sub.currentPeriodEnd) }}.</p>
            </div>
            <AppButton v-if="sub.status !== 'trialing'" variant="secondary" @click="cancelOpen = true">{{ sub.cancelAtPeriodEnd ? 'Tiếp tục gia hạn' : 'Hủy gia hạn' }}</AppButton>
          </div>
          <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div v-for="k in limitKeys" :key="k" class="rounded-lg bg-surface-muted p-3"><dt class="text-xs text-ink-muted">{{ LIMIT_LABELS[k] }}</dt><dd class="font-semibold">{{ fmtLimit(plan.limits[k]) }}</dd></div>
          </dl>
        </AppCard>
        <AppCard title="Phương thức thanh toán">
          <div v-if="sub.paymentMethod" class="flex items-center gap-3 rounded-lg border border-line p-3">
            <span class="grid h-9 w-12 place-items-center rounded bg-slate-900 text-[10px] font-bold text-white">{{ sub.paymentMethod.brand }}</span>
            <div class="text-sm"><p class="font-medium">•••• {{ sub.paymentMethod.last4 }}</p><p class="text-xs text-ink-muted">Hết hạn {{ sub.paymentMethod.exp }} · thẻ demo</p></div>
          </div>
          <p v-else class="text-sm text-ink-muted">Chưa có phương thức thanh toán.</p>
          <p class="mt-3 text-xs text-ink-subtle">Cổng thanh toán thật chưa được chọn (quyết định còn mở). Demo không lưu thông tin thẻ.</p>
        </AppCard>
      </div>

      <AppCard title="Chọn gói" subtitle="Giá minh họa, chưa bao gồm VAT">
        <template #actions>
          <div class="flex rounded-lg border border-line p-0.5 text-sm" role="group" aria-label="Chu kỳ">
            <button type="button" class="rounded-md px-3 py-1" :class="interval === 'month' ? 'bg-brand-600 text-white' : 'text-ink-muted'" @click="interval = 'month'">Theo tháng</button>
            <button type="button" class="rounded-md px-3 py-1" :class="interval === 'year' ? 'bg-brand-600 text-white' : 'text-ink-muted'" @click="interval = 'year'">Theo năm <span class="text-xs">(-17%)</span></button>
          </div>
        </template>
        <div class="grid gap-4 md:grid-cols-3">
          <div v-for="p in publicPlans" :key="p.code" class="relative flex flex-col rounded-xl border p-5" :class="p.code === plan.code ? 'border-brand-500 ring-2 ring-brand-100' : 'border-line'">
            <AppBadge v-if="p.highlight" tone="brand" class="absolute -top-2.5 left-5">Phổ biến</AppBadge>
            <p class="text-lg font-semibold">{{ p.name }}</p>
            <p class="text-sm text-ink-muted">{{ p.description }}</p>
            <p class="my-4 text-2xl font-semibold">{{ fmtVnd(price(p)) }}<span class="text-sm font-normal text-ink-muted">/{{ interval === 'month' ? 'tháng' : 'năm' }}</span></p>
            <ul class="mb-5 flex-1 space-y-1.5 text-sm">
              <li v-for="k in limitKeys" :key="k" class="flex gap-2"><AppIcon name="check" :size="16" class="shrink-0 text-success-600" />{{ fmtLimit(p.limits[k]) }} {{ LIMIT_LABELS[k]!.toLowerCase() }}</li>
              <li class="flex gap-2"><AppIcon name="coins" :size="16" class="shrink-0 text-warning-600" />{{ p.monthlyCredits.toLocaleString('vi-VN') }} credit / tháng</li>
            </ul>
            <AppButton :variant="p.code === plan.code && interval === sub.interval && sub.status !== 'trialing' ? 'secondary' : 'primary'" :disabled="p.code === plan.code && interval === sub.interval && sub.status !== 'trialing'" block @click="choose(p)">
              {{ p.code === plan.code && interval === sub.interval && sub.status !== 'trialing' ? 'Gói hiện tại' : rank(p.code) > rank(plan.code) || sub.status === 'trialing' ? 'Nâng cấp' : rank(p.code) < rank(plan.code) ? 'Hạ gói' : 'Đổi chu kỳ' }}
            </AppButton>
          </div>
        </div>
      </AppCard>

      <div class="grid gap-6 lg:grid-cols-3">
        <AppCard class="lg:col-span-2" title="Hóa đơn" :padded="false">
          <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
            <SearchInput v-model="table.q.value" placeholder="Tìm số hóa đơn…" />
            <FilterSelect v-model="table.filters.status" label="Trạng thái" :options="options('invoice')" />
          </Toolbar>
          <DataTable :table="table" :columns="[{ key: 'number', label: 'Số' }, { key: 'issued', label: 'Ngày', sortable: true }, { key: 'description', label: 'Nội dung', hideBelow: 'md' }, { key: 'total', label: 'Tổng', sortable: true, align: 'right' }, { key: 'status', label: 'Trạng thái' }, { key: 'dl', label: '', align: 'right' }]" empty-title="Chưa có hóa đơn" empty-description="Hóa đơn xuất hiện sau lần thanh toán đầu tiên.">
            <template #cell-number="{ row }"><span class="font-mono text-xs">{{ row.number }}</span></template>
            <template #cell-issued="{ row }"><span class="whitespace-nowrap">{{ fmtDate(row.issuedAt) }}</span></template>
            <template #cell-description="{ row }"><span class="text-ink-muted">{{ row.description }}</span></template>
            <template #cell-total="{ row }"><span class="tabular-nums">{{ fmtVnd(row.totalMinor) }}</span></template>
            <template #cell-status="{ row }"><StatusBadge group="invoice" :value="row.status" /></template>
            <template #cell-dl="{ row }"><AppButton size="sm" variant="ghost" title="Tải hóa đơn" @click="downloadInvoice(row)"><AppIcon name="download" :size="16" /></AppButton></template>
          </DataTable>
        </AppCard>
        <AppCard title="Thông tin xuất hóa đơn">
          <form class="space-y-3" novalidate @submit.prevent="saveProfile">
            <AppInput v-model="profile.values.company" label="Tên công ty / cá nhân" :error="profile.errors.company" />
            <AppInput v-model="profile.values.taxCode" label="Mã số thuế" :error="profile.errors.taxCode" />
            <AppInput v-model="profile.values.email" type="email" label="Email nhận hóa đơn" :error="profile.errors.email" />
            <AppInput v-model="profile.values.address" label="Địa chỉ" :error="profile.errors.address" />
            <AppButton type="submit" :loading="profile.submitting.value">Lưu</AppButton>
          </form>
        </AppCard>
      </div>
    </div>

    <DemoCheckoutModal :open="checkoutOpen" :title="`Nâng cấp lên ${target?.name}`" :description="`Gói ${target?.name} – ${interval === 'month' ? 'thanh toán theo tháng' : 'thanh toán theo năm'}`" :amount-minor="target ? Math.round(price(target) * 1.1) : 0" :saved-card="sub.paymentMethod" @close="checkoutOpen = false" @paid="onPaid" />
    <ConfirmDialog :open="downgradeOpen" :title="`Chuyển sang gói ${target?.name}?`" :message="`Hạn mức sẽ giảm theo gói ${target?.name}. Trong bản thật, thay đổi áp dụng từ kỳ tiếp theo (${fmtDate(sub.currentPeriodEnd)}).`" confirm-label="Xác nhận hạ gói" @close="downgradeOpen = false" @confirm="confirmDowngrade" />
    <ConfirmDialog :open="cancelOpen" :title="sub.cancelAtPeriodEnd ? 'Tiếp tục gia hạn?' : 'Hủy gia hạn tự động?'" :message="sub.cancelAtPeriodEnd ? 'Gói sẽ tự động gia hạn khi hết kỳ.' : `Bạn vẫn dùng được đến ${fmtDate(sub.currentPeriodEnd)}. Sau đó workspace chuyển sang chế độ chỉ đọc.`" :confirm-label="sub.cancelAtPeriodEnd ? 'Tiếp tục gia hạn' : 'Hủy gia hạn'" :danger="!sub.cancelAtPeriodEnd" @close="cancelOpen = false" @confirm="toggleCancel" />
  </div>
</template>
