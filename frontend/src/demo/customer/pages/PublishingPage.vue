<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import StatCard from '../../ui/StatCard.vue'
import Tabs from '../../ui/Tabs.vue'
import { db, demoMutation } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { fmtDateTime, fmtRelative, toDateTimeInput } from '../../format'
import type { DemoPublishJob } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const router = useRouter()
const toast = useToast()
const { base, mine, sites, siteName, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const jobs = computed(() => mine(db.publishJobs))
const art = (j: DemoPublishJob) => db.articles.find((a) => a.id === j.articleId)
const tab = ref('active')
const tabMatch: Record<string, (j: DemoPublishJob) => boolean> = {
  active: (j) => j.status === 'queued' || j.status === 'running',
  failed: (j) => j.status === 'failed',
  done: (j) => j.status === 'succeeded' || j.status === 'canceled',
  all: () => true,
}
const table = useTable(() => jobs.value.filter(tabMatch[tab.value]!), {
  search: (j) => art(j)?.title ?? '',
  filters: { initial: { site: '', action: '' }, match: { site: (j, v) => j.siteId === v, action: (j, v) => j.action === v } },
  sorts: { runAt: (j) => j.runAt, attempts: (j) => j.attempts },
  defaultSort: { key: 'runAt', dir: 'asc' },
})
const columns = [
  { key: 'article', label: 'Bài viết' },
  { key: 'runAt', label: 'Thời điểm', sortable: true },
  { key: 'action', label: 'Hành động', hideBelow: 'md' as const },
  { key: 'status', label: 'Trạng thái' },
  { key: 'attempts', label: 'Lần thử', sortable: true, align: 'center' as const, hideBelow: 'lg' as const },
  { key: 'actions', label: '', align: 'right' as const },
]
const tabs = computed(() => [
  { key: 'active', label: 'Đang chờ / chạy', count: jobs.value.filter(tabMatch.active!).length },
  { key: 'failed', label: 'Thất bại', count: jobs.value.filter(tabMatch.failed!).length },
  { key: 'done', label: 'Hoàn tất', count: jobs.value.filter(tabMatch.done!).length },
  { key: 'all', label: 'Tất cả' },
])
const selected = ref<string[]>([])

function finish(j: DemoPublishJob) {
  const a = art(j)
  const site = db.sites.find((s) => s.id === j.siteId)
  const ok = site?.status === 'connected' || site?.status === 'degraded'
  j.status = ok ? 'succeeded' : 'failed'
  j.attempts += 1
  j.lastError = ok ? null : 'Website không phản hồi (mô phỏng: plugin mất kết nối)'
  if (a) {
    a.status = ok ? 'published' : 'publish_failed'
    if (ok) {
      j.wpPostId = 3000 + Math.floor(Math.random() * 999)
      a.publishedAt = new Date().toISOString()
      a.wpUrl = `${site!.url}/${a.slug}/`
      const cal = db.calendar.find((c) => c.articleId === a.id)
      if (cal) cal.status = 'done'
    }
  }
  return ok
}
async function runNow(list: DemoPublishJob[]) {
  const runnable = list.filter((j) => j.status === 'queued' || j.status === 'failed')
  if (!runnable.length) return
  try {
    await demoMutation(() =>
      runnable.forEach((j) => {
        j.status = 'running'
        j.runAt = new Date().toISOString()
        const a = art(j)
        if (a) a.status = 'publishing'
      }),
    )
    toast.success(`Đang đăng ${runnable.length} bài…`)
    selected.value = []
    setTimeout(() => {
      const results = runnable.map(finish)
      const ok = results.filter(Boolean).length
      if (ok) toast.success(`Đã đăng ${ok} bài lên WordPress (mô phỏng).`)
      if (ok < results.length) toast.error(`${results.length - ok} bài đăng thất bại – website mất kết nối.`)
    }, 2000)
  } catch (e) {
    toast.error((e as Error).message)
  }
}
const canceling = ref<DemoPublishJob | null>(null)
async function cancel() {
  const j = canceling.value
  if (!j) return
  try {
    await demoMutation(() => {
      j.status = 'canceled'
      const a = art(j)
      if (a && a.status === 'scheduled') {
        a.status = 'approved'
        a.scheduledAt = null
      }
    })
    toast.success('Đã hủy lịch đăng. Bài quay về trạng thái “Đã duyệt”.')
  } catch (e) {
    toast.error((e as Error).message)
  }
  canceling.value = null
}
const rescheduling = ref<DemoPublishJob | null>(null)
const form = useForm(() => ({ at: '' }), { at: [rules.required(), (v) => (new Date(String(v)).getTime() > Date.now() ? true : 'Chọn thời điểm trong tương lai.')] })
function openResched(j: DemoPublishJob) {
  rescheduling.value = j
  form.reset({ at: toDateTimeInput(j.runAt) })
}
async function saveResched() {
  const j = rescheduling.value
  if (!j) return
  await form
    .submit(async () => {
      const at = new Date(form.values.at).toISOString()
      await demoMutation(() => {
        j.runAt = at
        const a = art(j)
        if (a) a.scheduledAt = at
        const cal = db.calendar.find((c) => c.articleId === j.articleId)
        if (cal) cal.plannedDate = at
      })
      toast.success(`Đã dời lịch sang ${fmtDateTime(at)}.`)
      rescheduling.value = null
    })
    .catch((e: Error) => toast.error(e.message))
}
const errorView = ref<DemoPublishJob | null>(null)
</script>

<template>
  <div>
    <PageHeader title="Hàng đợi xuất bản" description="Theo dõi các tác vụ đăng bài lên WordPress. Chỉ bài đã qua kiểm chứng và được duyệt mới vào hàng đợi." :crumbs="crumbs({ label: 'Hàng đợi xuất bản' })" />

    <div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard label="Đang chờ" :value="jobs.filter((j) => j.status === 'queued').length" icon="clock" />
      <StatCard label="Đang chạy" :value="jobs.filter((j) => j.status === 'running').length" icon="refresh" />
      <StatCard label="Thất bại" :value="jobs.filter((j) => j.status === 'failed').length" icon="alert" />
      <StatCard label="Đã đăng" :value="jobs.filter((j) => j.status === 'succeeded').length" icon="check" />
    </div>

    <AppCard :padded="false">
      <div class="px-4 pt-2 sm:px-5"><Tabs v-model="tab" :tabs="tabs" /></div>
      <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
        <SearchInput v-model="table.q.value" placeholder="Tìm bài viết…" />
        <FilterSelect v-model="table.filters.site" label="Website" :options="[{ value: '', label: 'Tất cả' }, ...sites.map((s) => ({ value: s.id, label: s.name }))]" />
        <FilterSelect v-model="table.filters.action" label="Hành động" :options="[{ value: '', label: 'Tất cả' }, { value: 'create', label: 'Đăng mới' }, { value: 'update', label: 'Cập nhật' }]" />
      </Toolbar>
      <div v-if="selected.length" class="flex flex-wrap items-center gap-2 border-b border-line bg-brand-50/60 px-5 py-2 text-sm">
        <span class="font-medium">Đã chọn {{ selected.length }}</span>
        <AppButton size="sm" @click="runNow(jobs.filter((j) => selected.includes(j.id)))"><AppIcon name="play" :size="14" /> Chạy / thử lại ngay</AppButton>
        <button type="button" class="ml-auto text-brand-700 hover:underline" @click="selected = []">Bỏ chọn</button>
      </div>
      <DataTable v-model:selected="selected" :table="table" :columns="columns" :loading="loading" :error="error" selectable empty-title="Hàng đợi trống" empty-description="Bài đã duyệt và lên lịch sẽ xuất hiện ở đây." @retry="reload">
        <template #cell-article="{ row }">
          <button type="button" class="line-clamp-1 text-left font-medium hover:text-brand-700" @click="router.push(`${base}/articles/${row.articleId}`)">{{ art(row)?.title ?? '—' }}</button>
          <p class="text-xs text-ink-muted">{{ siteName(row.siteId) }}<span v-if="row.wpPostId"> · WP #{{ row.wpPostId }}</span></p>
        </template>
        <template #cell-runAt="{ row }"><span class="whitespace-nowrap">{{ fmtDateTime(row.runAt) }}</span><p class="text-xs text-ink-muted">{{ fmtRelative(row.runAt) }}</p></template>
        <template #cell-action="{ row }"><span class="text-ink-muted">{{ row.action === 'create' ? 'Đăng mới' : row.action === 'update' ? 'Cập nhật' : 'Gỡ bài' }} · {{ row.wpStatus }}</span></template>
        <template #cell-status="{ row }">
          <StatusBadge group="job" :value="row.status" />
          <button v-if="row.lastError" type="button" class="ml-1 text-xs text-danger-600 hover:underline" @click="errorView = row">Lỗi</button>
        </template>
        <template #cell-attempts="{ row }"><span class="tabular-nums">{{ row.attempts }}/3</span></template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-1">
            <AppButton v-if="row.status === 'failed'" size="sm" @click="runNow([row])"><AppIcon name="refresh" :size="14" /> Thử lại</AppButton>
            <template v-if="row.status === 'queued'">
              <AppButton size="sm" variant="ghost" title="Đăng ngay" @click="runNow([row])"><AppIcon name="play" :size="16" /></AppButton>
              <AppButton size="sm" variant="ghost" title="Dời lịch" @click="openResched(row)"><AppIcon name="calendar" :size="16" /></AppButton>
              <AppButton size="sm" variant="ghost" title="Hủy" @click="canceling = row"><AppIcon name="x" :size="16" /></AppButton>
            </template>
            <a v-if="row.status === 'succeeded' && art(row)?.wpUrl" :href="art(row)?.wpUrl ?? '#'" target="_blank" rel="noopener noreferrer" class="text-xs font-medium text-brand-700 hover:underline">Xem bài</a>
          </div>
        </template>
      </DataTable>
    </AppCard>

    <AppModal :open="!!rescheduling" title="Dời lịch đăng" @close="rescheduling = null">
      <form id="resched" @submit.prevent="saveResched"><AppInput v-model="form.values.at" type="datetime-local" label="Thời điểm mới" :error="form.errors.at" /></form>
      <template #footer>
        <AppButton variant="secondary" @click="rescheduling = null">Hủy</AppButton>
        <AppButton type="submit" form="resched" :loading="form.submitting.value">Lưu</AppButton>
      </template>
    </AppModal>
    <AppModal :open="!!errorView" title="Chi tiết lỗi đăng bài" @close="errorView = null">
      <p class="mb-2 text-sm font-medium">{{ errorView ? art(errorView)?.title : '' }}</p>
      <pre class="whitespace-pre-wrap rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{{ errorView?.lastError }}</pre>
      <p class="mt-3 text-sm text-ink-muted">Gợi ý: kiểm tra plugin SiteX Post trên website còn kích hoạt và kết nối ổn định, sau đó bấm “Thử lại”.</p>
      <template #footer>
        <AppButton variant="secondary" @click="router.push(`${base}/sites/${errorView?.siteId}`)">Kiểm tra website</AppButton>
        <AppButton @click="errorView && runNow([errorView]); errorView = null">Thử lại</AppButton>
      </template>
    </AppModal>
    <ConfirmDialog :open="!!canceling" title="Hủy lịch đăng?" message="Bài sẽ quay về trạng thái Đã duyệt và có thể lên lịch lại." confirm-label="Hủy lịch" danger @close="canceling = null" @confirm="cancel" />
  </div>
</template>
