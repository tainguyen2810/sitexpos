<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import Drawer from '../../ui/Drawer.vue'
import DataTable from '../../ui/DataTable.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import { db, demoMutation } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { options } from '../../labels'
import { fmtDateTime, toDateInput, toDateTimeInput } from '../../format'
import type { DemoCalendarItem } from '../../data/types'
import { planIdea } from '../actions'
import { useWorkspace } from '../useWorkspace'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { base, mine, sites, siteName, campaignName, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const view = ref<'month' | 'list'>('month')
const cursor = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1))
const filters = ref({ site: '', campaign: typeof route.query.campaign === 'string' ? route.query.campaign : '', status: '' })
const items = computed(() =>
  mine(db.calendar).filter(
    (c) => (!filters.value.site || c.siteId === filters.value.site) && (!filters.value.campaign || c.campaignId === filters.value.campaign) && (!filters.value.status || c.status === filters.value.status),
  ),
)
const monthLabel = computed(() => new Intl.DateTimeFormat('vi-VN', { month: 'long', year: 'numeric' }).format(cursor.value))
const days = computed(() => {
  const first = new Date(cursor.value)
  const offset = (first.getDay() + 6) % 7 // Monday first
  const start = new Date(first)
  start.setDate(first.getDate() - offset)
  return Array.from({ length: 42 }, (_, i) => {
    const d = new Date(start)
    d.setDate(start.getDate() + i)
    const key = toDateInput(d.toISOString())
    return { date: d, key, inMonth: d.getMonth() === cursor.value.getMonth(), isToday: key === toDateInput(new Date().toISOString()), items: items.value.filter((c) => toDateInput(c.plannedDate) === key) }
  })
})
const monthCount = computed(() => days.value.filter((d) => d.inMonth).reduce((s, d) => s + d.items.length, 0))
function shift(n: number) {
  cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + n, 1)
}
function today() {
  cursor.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1)
}
const chip = (c: DemoCalendarItem) =>
  ({ planned: 'bg-slate-100 text-ink border-slate-200', in_progress: 'bg-brand-50 text-brand-700 border-brand-100', done: 'bg-success-50 text-success-600 border-emerald-100', skipped: 'bg-surface-muted text-ink-subtle line-through border-line' })[c.status]

// Drag & drop reschedule
const dragId = ref<string | null>(null)
async function dropOn(key: string) {
  const item = db.calendar.find((c) => c.id === dragId.value)
  dragId.value = null
  if (!item) return
  if (item.status === 'done') {
    toast.error('Bài đã đăng, không thể dời lịch.')
    return
  }
  const old = new Date(item.plannedDate)
  const next = new Date(`${key}T${String(old.getHours()).padStart(2, '0')}:${String(old.getMinutes()).padStart(2, '0')}`)
  try {
    await demoMutation(() => syncDate(item, next.toISOString()))
    toast.success(`Đã dời “${item.title.slice(0, 40)}…” sang ${next.toLocaleDateString('vi-VN')}.`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}
function syncDate(item: DemoCalendarItem, iso: string) {
  item.plannedDate = iso
  const art = db.articles.find((a) => a.id === item.articleId)
  if (art && art.status === 'scheduled') {
    art.scheduledAt = iso
    const job = db.publishJobs.find((j) => j.articleId === art.id && j.status === 'queued')
    if (job) job.runAt = iso
  }
}

// Drawer
const selected = ref<DemoCalendarItem | null>(null)
const resched = useForm(() => ({ at: '' }), { at: [rules.required(), (v) => (new Date(String(v)).getTime() > Date.now() ? true : 'Chọn thời điểm trong tương lai.')] })
function openItem(c: DemoCalendarItem) {
  selected.value = c
  resched.reset({ at: toDateTimeInput(c.plannedDate) })
}
async function saveResched() {
  const c = selected.value
  if (!c) return
  await resched
    .submit(async () => {
      await demoMutation(() => syncDate(c, new Date(resched.values.at).toISOString()))
      toast.success('Đã cập nhật lịch.')
      selected.value = null
    })
    .catch((e: Error) => toast.error(e.message))
}
async function skip(c: DemoCalendarItem) {
  try {
    await demoMutation(() => (c.status = c.status === 'skipped' ? 'planned' : 'skipped'))
    toast.success(c.status === 'skipped' ? 'Đã bỏ qua mục này.' : 'Đã khôi phục mục lịch.')
    selected.value = null
  } catch (e) {
    toast.error((e as Error).message)
  }
}

// Add from shortlisted ideas
const addOpen = ref(false)
const addForm = useForm(() => ({ ideaId: '', at: '' }), { ideaId: [rules.required('Chọn ý tưởng.')], at: [rules.required()] })
const candidates = computed(() => mine(db.ideas).filter((i) => i.status === 'shortlisted' || i.status === 'new'))
function openAdd(key?: string) {
  addForm.reset({ at: key ?? toDateInput(new Date(Date.now() + 2 * 86_400_000).toISOString()) })
  addOpen.value = true
}
async function saveAdd() {
  await addForm
    .submit(async () => {
      const idea = db.ideas.find((i) => i.id === addForm.values.ideaId)
      if (!idea) return
      const d = new Date(`${addForm.values.at}T09:00`)
      await demoMutation(() => planIdea(idea, d.toISOString()))
      cursor.value = new Date(d.getFullYear(), d.getMonth(), 1)
      toast.success('Đã thêm vào lịch nội dung.')
      addOpen.value = false
    })
    .catch((e: Error) => toast.error(e.message))
}

const listTable = useTable(() => items.value, {
  search: (c) => c.title,
  sorts: { date: (c) => c.plannedDate, title: (c) => c.title },
  defaultSort: { key: 'date', dir: 'asc' },
  pageSize: 15,
})
const weekdays = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN']
</script>

<template>
  <div>
    <PageHeader title="Lịch nội dung" description="Lên kế hoạch và theo dõi lịch đăng bài. Kéo-thả bài để đổi ngày." :crumbs="crumbs({ label: 'Lịch nội dung' })">
      <template #actions>
        <div class="flex rounded-lg border border-line bg-surface p-0.5 text-sm" role="group" aria-label="Chế độ xem">
          <button type="button" class="rounded-md px-3 py-1.5" :class="view === 'month' ? 'bg-brand-600 text-white' : 'text-ink-muted'" @click="view = 'month'">Tháng</button>
          <button type="button" class="rounded-md px-3 py-1.5" :class="view === 'list' ? 'bg-brand-600 text-white' : 'text-ink-muted'" @click="view = 'list'">Danh sách</button>
        </div>
        <AppButton :disabled="!candidates.length" @click="openAdd()"><AppIcon name="plus" :size="16" /> Thêm vào lịch</AppButton>
      </template>
    </PageHeader>

    <AppCard :padded="false">
      <div class="flex flex-col gap-3 border-b border-line px-4 py-3 lg:flex-row lg:items-center">
        <div v-if="view === 'month'" class="flex items-center gap-2">
          <AppButton size="sm" variant="secondary" aria-label="Tháng trước" @click="shift(-1)"><AppIcon name="chevronLeft" :size="16" /></AppButton>
          <AppButton size="sm" variant="secondary" @click="today">Hôm nay</AppButton>
          <AppButton size="sm" variant="secondary" aria-label="Tháng sau" @click="shift(1)"><AppIcon name="chevronRight" :size="16" /></AppButton>
          <h2 class="ml-2 text-base font-semibold capitalize">{{ monthLabel }}</h2>
          <span class="text-sm text-ink-muted">· {{ monthCount }} mục</span>
        </div>
        <div class="flex flex-1 flex-wrap items-center gap-2 lg:justify-end">
          <FilterSelect v-model="filters.site" label="Website" :options="[{ value: '', label: 'Tất cả' }, ...sites.map((s) => ({ value: s.id, label: s.name }))]" />
          <FilterSelect v-model="filters.campaign" label="Chiến dịch" :options="[{ value: '', label: 'Tất cả' }, ...mine(db.campaigns).map((c) => ({ value: c.id, label: c.name }))]" />
          <FilterSelect v-model="filters.status" label="Trạng thái" :options="options('calendar')" />
        </div>
      </div>

      <LoadingState v-if="loading" :rows="6" />
      <DemoErrorState v-else-if="error" :error="error" @retry="reload" />
      <div v-else-if="view === 'month'" class="overflow-x-auto">
        <div class="grid min-w-[760px] grid-cols-7 border-b border-line bg-surface-muted/60 text-center text-xs font-semibold text-ink-muted">
          <div v-for="w in weekdays" :key="w" class="py-2">{{ w }}</div>
        </div>
        <div class="grid min-w-[760px] grid-cols-7">
          <div
            v-for="d in days"
            :key="d.key"
            class="group min-h-28 border-b border-r border-line p-1.5 text-xs"
            :class="[d.inMonth ? 'bg-surface' : 'bg-surface-muted/50 text-ink-subtle', dragId ? 'hover:bg-brand-50' : '']"
            @dragover.prevent
            @drop="dropOn(d.key)"
          >
            <div class="mb-1 flex items-center justify-between">
              <span class="grid h-6 w-6 place-items-center rounded-full" :class="d.isToday ? 'bg-brand-600 font-semibold text-white' : ''">{{ d.date.getDate() }}</span>
              <button type="button" class="rounded p-0.5 text-ink-subtle opacity-0 hover:bg-surface-muted group-hover:opacity-100 focus:opacity-100" :aria-label="`Thêm vào ngày ${d.date.getDate()}`" @click="openAdd(d.key)"><AppIcon name="plus" :size="14" /></button>
            </div>
            <button
              v-for="c in d.items.slice(0, 3)"
              :key="c.id"
              type="button"
              draggable="true"
              class="mb-1 block w-full truncate rounded border px-1.5 py-0.5 text-left text-[11px]"
              :class="chip(c)"
              :title="c.title"
              @dragstart="dragId = c.id"
              @dragend="dragId = null"
              @click="openItem(c)"
            >
              {{ new Date(c.plannedDate).getHours() }}h · {{ c.title }}
            </button>
            <button v-if="d.items.length > 3" type="button" class="text-[11px] font-medium text-brand-700 hover:underline" @click="view = 'list'">+{{ d.items.length - 3 }} mục</button>
          </div>
        </div>
      </div>
      <DataTable v-else :table="listTable" :columns="[{ key: 'date', label: 'Ngày đăng', sortable: true }, { key: 'title', label: 'Tiêu đề', sortable: true }, { key: 'site', label: 'Website', hideBelow: 'md' }, { key: 'status', label: 'Trạng thái' }]" clickable empty-title="Không có mục nào" @row-click="openItem">
        <template #cell-date="{ row }"><span class="whitespace-nowrap">{{ fmtDateTime(row.plannedDate) }}</span></template>
        <template #cell-title="{ row }"><span class="font-medium">{{ row.title }}</span></template>
        <template #cell-site="{ row }"><span class="text-ink-muted">{{ siteName(row.siteId) }}</span></template>
        <template #cell-status="{ row }"><StatusBadge group="calendar" :value="row.status" /></template>
      </DataTable>
      <div class="flex flex-wrap gap-3 border-t border-line px-4 py-2 text-xs text-ink-muted">
        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-slate-300" /> Kế hoạch</span>
        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-brand-500" /> Đang thực hiện</span>
        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-success-600" /> Đã đăng</span>
      </div>
    </AppCard>

    <Drawer :open="!!selected" :title="selected?.title ?? ''" :subtitle="selected ? siteName(selected.siteId) : ''" @close="selected = null">
      <template v-if="selected">
        <dl class="mb-4 space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-ink-muted">Trạng thái</dt><dd><StatusBadge group="calendar" :value="selected.status" /></dd></div>
          <div class="flex justify-between"><dt class="text-ink-muted">Ngày dự kiến</dt><dd>{{ fmtDateTime(selected.plannedDate) }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-muted">Chiến dịch</dt><dd>{{ campaignName(selected.campaignId) }}</dd></div>
        </dl>
        <form v-if="selected.status !== 'done'" class="space-y-3 rounded-lg border border-line p-3" @submit.prevent="saveResched">
          <AppInput v-model="resched.values.at" type="datetime-local" label="Dời lịch" :error="resched.errors.at" />
          <AppButton type="submit" size="sm" :loading="resched.submitting.value">Lưu lịch mới</AppButton>
        </form>
        <p v-else class="rounded-lg bg-success-50 p-3 text-sm text-success-600">Bài đã được đăng.</p>
      </template>
      <template #footer>
        <AppButton v-if="selected && selected.status !== 'done'" variant="ghost" @click="skip(selected)">{{ selected.status === 'skipped' ? 'Khôi phục' : 'Bỏ qua' }}</AppButton>
        <AppButton v-if="selected?.articleId" @click="router.push(`${base}/articles/${selected.articleId}`)">Mở bài viết</AppButton>
        <AppButton v-else-if="selected?.ideaId" variant="secondary" @click="router.push(`${base}/topics`)">Xem ý tưởng</AppButton>
      </template>
    </Drawer>

    <AppModal :open="addOpen" title="Thêm ý tưởng vào lịch" @close="addOpen = false">
      <form id="cal-add" class="space-y-4" @submit.prevent="saveAdd">
        <AppSelect v-model="addForm.values.ideaId" label="Ý tưởng (mới / đã chọn lọc)" :error="addForm.errors.ideaId" :options="[{ value: '', label: '— Chọn ý tưởng —' }, ...candidates.map((i) => ({ value: i.id, label: i.title }))]" />
        <AppInput v-model="addForm.values.at" type="date" label="Ngày đăng dự kiến" :error="addForm.errors.at" />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="addOpen = false">Hủy</AppButton>
        <AppButton type="submit" form="cal-add" :loading="addForm.submitting.value">Thêm</AppButton>
      </template>
    </AppModal>
  </div>
</template>
