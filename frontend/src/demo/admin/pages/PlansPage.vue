<script setup lang="ts">
import { ref } from 'vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import Toggle from '../../ui/Toggle.vue'
import DTextarea from '../../ui/DTextarea.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import { db, demoMutation } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { LIMIT_LABELS } from '../../labels'
import { fmtNumber, fmtVnd } from '../../format'
import type { DemoPlan, LimitKey } from '../../data/types'
import { adminCrumbs, staffAudit } from '../useAdmin'

const toast = useToast()
const { loading, error, reload } = useDemoQuery()
const limitKeys = Object.keys(LIMIT_LABELS) as LimitKey[]
const subscribers = (code: string) => db.subscriptions.filter((s) => s.planCode === code && s.status !== 'canceled').length

const table = useTable(() => db.plans.map((p) => ({ ...p, id: p.code })), {
  search: (p) => `${p.name} ${p.code} ${p.description}`,
  filters: { initial: { visibility: '', active: '' }, match: { visibility: (p, v) => (v === 'public' ? p.isPublic : !p.isPublic), active: (p, v) => (v === 'yes' ? p.isActive : !p.isActive) } },
  sorts: { name: (p) => p.name, price: (p) => p.priceMonthMinor, subs: (p) => subscribers(p.code) },
  defaultSort: { key: 'price', dir: 'asc' },
})

const editOpen = ref(false)
const editingCode = ref<string | null>(null)
const intRule = (v: unknown) => (/^-?\d+$/.test(String(v)) && Number(v) >= -1 ? true : 'Số nguyên ≥ 0, hoặc -1 = không giới hạn.')
const blank = () => ({
  code: '',
  name: '',
  description: '',
  priceMonth: '0',
  priceYear: '0',
  trialDays: '14',
  monthlyCredits: '500',
  isPublic: true,
  isActive: true,
  highlight: false,
  ...Object.fromEntries(limitKeys.map((k) => [`limit_${k}`, '0'])),
}) as Record<string, string | boolean>
const form = useForm(blank, {
  code: [rules.required(), (v) => (/^[a-z][a-z0-9_]{2,30}$/.test(String(v)) ? true : 'Chữ thường, số, gạch dưới; 3–31 ký tự.'), (v) => (!editingCode.value && db.plans.some((p) => p.code === v) ? 'Mã gói đã tồn tại.' : true)],
  name: [rules.required(), rules.maxLength(40)],
  description: [rules.maxLength(160)],
  priceMonth: [(v) => (/^\d+$/.test(String(v)) ? true : 'Nhập số tiền (VND).')],
  priceYear: [(v) => (/^\d+$/.test(String(v)) ? true : 'Nhập số tiền (VND).'), (v, all) => (Number(v) <= Number(all.priceMonth) * 12 ? true : 'Giá năm không nên cao hơn 12 × giá tháng.')],
  trialDays: [rules.min(0), rules.max(60)],
  monthlyCredits: [rules.min(0), rules.max(100000)],
  ...Object.fromEntries(limitKeys.map((k) => [`limit_${k}`, [intRule]])),
})
function openCreate() {
  editingCode.value = null
  form.reset()
  editOpen.value = true
}
function openEdit(p: DemoPlan) {
  editingCode.value = p.code
  form.reset({
    code: p.code,
    name: p.name,
    description: p.description,
    priceMonth: String(p.priceMonthMinor),
    priceYear: String(p.priceYearMinor),
    trialDays: String(p.trialDays),
    monthlyCredits: String(p.monthlyCredits),
    isPublic: p.isPublic,
    isActive: p.isActive,
    highlight: p.highlight,
    ...Object.fromEntries(limitKeys.map((k) => [`limit_${k}`, String(p.limits[k])])),
  })
  editOpen.value = true
}
async function save() {
  await form
    .submit(async () => {
      const v = form.values
      const data: DemoPlan = {
        code: String(v.code),
        name: String(v.name).trim(),
        description: String(v.description).trim(),
        priceMonthMinor: Number(v.priceMonth),
        priceYearMinor: Number(v.priceYear),
        currency: 'VND',
        isPublic: Boolean(v.isPublic),
        isActive: Boolean(v.isActive),
        trialDays: Number(v.trialDays),
        monthlyCredits: Number(v.monthlyCredits),
        highlight: Boolean(v.highlight),
        limits: Object.fromEntries(limitKeys.map((k) => [k, Number(v[`limit_${k}`])])) as Record<LimitKey, number>,
      }
      await demoMutation(() => {
        if (data.highlight) db.plans.forEach((p) => (p.highlight = false))
        const existing = db.plans.find((p) => p.code === editingCode.value)
        if (existing) Object.assign(existing, data)
        else db.plans.push(data)
        staffAudit(existing ? 'platform.plan.updated' : 'platform.plan.created', data.name)
      })
      toast.success(editingCode.value ? `Đã cập nhật gói ${data.name}. Khách hàng hiện tại giữ giá cũ đến kỳ sau.` : `Đã tạo gói ${data.name}.`)
      editOpen.value = false
    })
    .catch((e: Error) => toast.error(e.message))
}
async function toggleActive(p: DemoPlan) {
  if (p.isActive && db.systemSettings.defaultPlan === p.code) return toast.error('Đây là gói mặc định khi đăng ký. Đổi gói mặc định trong Cài đặt hệ thống trước.')
  try {
    await demoMutation(() => {
      const real = db.plans.find((x) => x.code === p.code)!
      real.isActive = !real.isActive
      staffAudit(real.isActive ? 'platform.plan.activated' : 'platform.plan.deactivated', real.name)
    })
    toast.success(p.isActive ? `Đã ngừng bán gói ${p.name}. Khách hàng hiện tại không bị ảnh hưởng.` : `Đã mở bán lại gói ${p.name}.`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}
const fmtLimit = (n: number) => (n === -1 ? '∞' : fmtNumber(n))
</script>

<template>
  <div>
    <PageHeader title="Quản lý gói cước" description="Giá, hạn mức và credit của từng gói. Giá trong demo là giá minh họa." :crumbs="adminCrumbs({ label: 'Gói cước' })">
      <template #actions><AppButton @click="openCreate"><AppIcon name="plus" :size="16" /> Tạo gói</AppButton></template>
    </PageHeader>

    <LoadingState v-if="loading" :rows="4" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <AppCard v-else :padded="false">
      <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
        <SearchInput v-model="table.q.value" placeholder="Tìm gói…" />
        <FilterSelect v-model="table.filters.visibility" label="Hiển thị" :options="[{ value: '', label: 'Tất cả' }, { value: 'public', label: 'Công khai' }, { value: 'private', label: 'Riêng' }]" />
        <FilterSelect v-model="table.filters.active" label="Bán" :options="[{ value: '', label: 'Tất cả' }, { value: 'yes', label: 'Đang bán' }, { value: 'no', label: 'Ngừng bán' }]" />
      </Toolbar>
      <DataTable :table="table" :columns="[{ key: 'name', label: 'Gói', sortable: true }, { key: 'price', label: 'Giá tháng / năm', sortable: true, align: 'right' }, { key: 'limits', label: 'Hạn mức chính', hideBelow: 'lg' }, { key: 'credits', label: 'Credit/tháng', align: 'right', hideBelow: 'md' }, { key: 'subs', label: 'Khách hàng', sortable: true, align: 'right' }, { key: 'actions', label: '', align: 'right' }]" empty-title="Không có gói phù hợp">
        <template #cell-name="{ row }">
          <p class="flex flex-wrap items-center gap-1.5 font-medium">{{ row.name }} <AppBadge v-if="row.highlight" tone="brand">Nổi bật</AppBadge><AppBadge v-if="!row.isPublic" tone="neutral">Riêng</AppBadge><AppBadge v-if="!row.isActive" tone="danger">Ngừng bán</AppBadge></p>
          <p class="text-xs text-ink-muted"><code>{{ row.code }}</code> · dùng thử {{ row.trialDays }} ngày</p>
        </template>
        <template #cell-price="{ row }"><span class="whitespace-nowrap tabular-nums">{{ row.priceMonthMinor ? fmtVnd(row.priceMonthMinor) : 'Liên hệ' }}</span><br /><span class="text-xs text-ink-muted tabular-nums">{{ row.priceYearMinor ? fmtVnd(row.priceYearMinor) : '' }}</span></template>
        <template #cell-limits="{ row }"><span class="text-xs text-ink-muted">{{ fmtLimit(row.limits.sites) }} website · {{ fmtLimit(row.limits.seats) }} thành viên · {{ fmtLimit(row.limits.articles) }} bài/tháng</span></template>
        <template #cell-credits="{ row }"><span class="tabular-nums">{{ fmtNumber(row.monthlyCredits) }}</span></template>
        <template #cell-subs="{ row }"><span class="tabular-nums">{{ subscribers(row.code) }}</span></template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-1">
            <AppButton size="sm" variant="ghost" title="Sửa" @click="openEdit(db.plans.find((p) => p.code === row.code)!)"><AppIcon name="edit" :size="16" /></AppButton>
            <AppButton size="sm" variant="ghost" @click="toggleActive(row)">{{ row.isActive ? 'Ngừng bán' : 'Mở bán' }}</AppButton>
          </div>
        </template>
      </DataTable>
    </AppCard>

    <AppModal :open="editOpen" :title="editingCode ? `Sửa gói ${form.values.name}` : 'Tạo gói mới'" width="lg" @close="editOpen = false">
      <form id="plan" class="space-y-5" novalidate @submit.prevent="save">
        <div class="grid gap-4 sm:grid-cols-2">
          <AppInput v-model="(form.values.code as string)" label="Mã gói" :disabled="!!editingCode" :error="form.errors.code" hint="Không đổi được sau khi tạo." />
          <AppInput v-model="(form.values.name as string)" label="Tên hiển thị" :error="form.errors.name" />
          <DTextarea v-model="(form.values.description as string)" label="Mô tả ngắn" :rows="2" class="sm:col-span-2" :error="form.errors.description" />
          <AppInput v-model="(form.values.priceMonth as string)" label="Giá tháng (VND, trước VAT)" :error="form.errors.priceMonth" />
          <AppInput v-model="(form.values.priceYear as string)" label="Giá năm (VND, trước VAT)" :error="form.errors.priceYear" />
          <AppInput v-model="(form.values.trialDays as string)" label="Số ngày dùng thử" :error="form.errors.trialDays" />
          <AppInput v-model="(form.values.monthlyCredits as string)" label="Credit mỗi tháng" :error="form.errors.monthlyCredits" />
        </div>
        <fieldset>
          <legend class="mb-2 text-sm font-medium">Hạn mức <span class="font-normal text-ink-muted">(-1 = không giới hạn)</span></legend>
          <div class="grid gap-3 sm:grid-cols-3">
            <AppInput v-for="k in limitKeys" :key="k" v-model="(form.values[`limit_${k}`] as string)" :label="LIMIT_LABELS[k]" :error="form.errors[`limit_${k}`]" />
          </div>
        </fieldset>
        <div class="divide-y divide-line rounded-lg border border-line px-4">
          <Toggle v-model="(form.values.isPublic as boolean)" label="Công khai trên trang giá" description="Tắt cho gói hợp đồng riêng." />
          <Toggle v-model="(form.values.isActive as boolean)" label="Đang bán" />
          <Toggle v-model="(form.values.highlight as boolean)" label="Đánh dấu “Phổ biến”" description="Chỉ một gói được nổi bật." />
        </div>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="editOpen = false">Hủy</AppButton>
        <AppButton type="submit" form="plan" :loading="form.submitting.value">{{ editingCode ? 'Lưu thay đổi' : 'Tạo gói' }}</AppButton>
      </template>
    </AppModal>
  </div>
</template>
