<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import ProgressBar from '../../ui/ProgressBar.vue'
import Toggle from '../../ui/Toggle.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import { db, demoMutation, newId } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { options } from '../../labels'
import { fmtRelative } from '../../format'
import type { DemoQueueJob } from '../../data/types'
import { adminCrumbs, staffAudit, wsName } from '../useAdmin'

const toast = useToast()
const { loading, error, reload } = useDemoQuery()
const QUEUES: { key: DemoQueueJob['queue']; label: string; workers: number }[] = [
  { key: 'ai', label: 'AI', workers: 4 },
  { key: 'crawl', label: 'Crawl & nghiên cứu', workers: 3 },
  { key: 'media', label: 'Media', workers: 2 },
  { key: 'publishing', label: 'Xuất bản', workers: 2 },
  { key: 'default', label: 'Mặc định', workers: 2 },
]
const paused = reactive<Record<string, boolean>>({})
const stats = computed(() =>
  QUEUES.map((q) => {
    const jobs = db.queueJobs.filter((j) => j.queue === q.key)
    return { ...q, queued: jobs.filter((j) => j.status === 'queued').length, running: jobs.filter((j) => j.status === 'running').length, failed: jobs.filter((j) => j.status === 'failed').length }
  }),
)

const table = useTable(() => db.queueJobs, {
  search: (j) => `${j.type} ${j.id} ${wsName(j.workspaceId)}`,
  filters: { initial: { queue: '', status: '' }, match: { queue: (j, v) => j.queue === v, status: (j, v) => j.status === v } },
  sorts: { created: (j) => j.createdAt, progress: (j) => j.progress, attempts: (j) => j.attempts },
  defaultSort: { key: 'created', dir: 'desc' },
})
const queueOptions = [{ value: '', label: 'Tất cả' }, ...QUEUES.map((q) => ({ value: q.key, label: q.label }))]

async function togglePause(q: (typeof QUEUES)[number]) {
  try {
    await demoMutation(() => staffAudit(paused[q.key] ? 'platform.queue.resumed' : 'platform.queue.paused', q.key))
    paused[q.key] = !paused[q.key]
    toast.success(paused[q.key] ? `Đã tạm dừng hàng đợi ${q.label}. Job đang chạy vẫn chạy nốt.` : `Đã tiếp tục hàng đợi ${q.label}.`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}
async function cancel(j: DemoQueueJob) {
  try {
    await demoMutation(() => {
      db.queueJobs.splice(db.queueJobs.indexOf(j), 1)
      staffAudit('platform.job.canceled', `${j.type} ${j.id}`, j.workspaceId)
    })
    toast.success(`Đã hủy job ${j.type}.`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}
async function retry(j: DemoQueueJob) {
  try {
    await demoMutation(() => {
      j.status = 'queued'
      j.progress = 0
      staffAudit('platform.job.retried', `${j.type} ${j.id}`, j.workspaceId)
    })
    toast.success(`Đã đưa ${j.type} vào lại hàng đợi.`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}
async function dispatchTest() {
  try {
    await demoMutation(() => db.queueJobs.unshift({ id: newId('job'), queue: 'default', type: 'HealthCheckPing', workspaceId: null, status: 'queued', progress: 0, attempts: 0, startedAt: null, createdAt: new Date().toISOString() }))
    toast.success('Đã gửi job kiểm tra vào hàng đợi mặc định.')
  } catch (e) {
    toast.error((e as Error).message)
  }
}

// Live simulation: workers pick queued jobs and advance running ones.
const live = ref(true)
let timer: ReturnType<typeof setInterval> | undefined
function tick() {
  for (const q of QUEUES) {
    if (paused[q.key]) continue
    const running = db.queueJobs.filter((j) => j.queue === q.key && j.status === 'running')
    running.forEach((j) => {
      j.progress = Math.min(100, j.progress + 8 + Math.round(Math.random() * 20))
      if (j.progress >= 100) j.status = Math.random() < 0.06 ? 'failed' : 'succeeded'
    })
    const free = q.workers - running.filter((j) => j.status === 'running').length
    if (free <= 0) continue
    db.queueJobs
      .filter((j) => j.queue === q.key && j.status === 'queued')
      .slice(-free)
      .forEach((j) => {
        j.status = 'running'
        j.attempts += 1
        j.startedAt = new Date().toISOString()
      })
  }
}
watch(
  live,
  (on) => {
    clearInterval(timer)
    if (on) timer = setInterval(tick, 2000)
  },
  { immediate: true },
)
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <div>
    <PageHeader title="Queue Monitor" description="Hàng đợi Redis/Horizon (mô phỏng): độ sâu, worker và job gần đây." :crumbs="adminCrumbs({ label: 'Queue Monitor' })">
      <template #actions>
        <AppButton variant="secondary" @click="dispatchTest"><AppIcon name="play" :size="16" /> Gửi job kiểm tra</AppButton>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" :rows="6" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <div v-else class="space-y-6">
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <div v-for="q in stats" :key="q.key" class="rounded-xl border bg-surface p-4 shadow-sm" :class="paused[q.key] ? 'border-amber-300' : 'border-line'">
          <div class="flex items-center justify-between gap-2">
            <p class="font-medium">{{ q.label }}</p>
            <button type="button" class="rounded p-1 text-ink-muted hover:bg-surface-muted" :title="paused[q.key] ? 'Tiếp tục' : 'Tạm dừng'" :aria-label="paused[q.key] ? `Tiếp tục ${q.label}` : `Tạm dừng ${q.label}`" @click="togglePause(q)"><AppIcon :name="paused[q.key] ? 'play' : 'pause'" :size="16" /></button>
          </div>
          <p class="mt-1 text-xs text-ink-muted">{{ paused[q.key] ? 'Đã tạm dừng' : `${q.workers} worker` }}</p>
          <dl class="mt-3 grid grid-cols-3 gap-1 text-center">
            <div><dt class="text-[11px] text-ink-muted">Chờ</dt><dd class="font-semibold tabular-nums">{{ q.queued }}</dd></div>
            <div><dt class="text-[11px] text-ink-muted">Chạy</dt><dd class="font-semibold tabular-nums text-brand-700">{{ q.running }}</dd></div>
            <div><dt class="text-[11px] text-ink-muted">Lỗi</dt><dd class="font-semibold tabular-nums" :class="q.failed ? 'text-danger-600' : ''">{{ q.failed }}</dd></div>
          </dl>
        </div>
      </div>

      <AppCard :padded="false">
        <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
          <SearchInput v-model="table.q.value" placeholder="Loại job, mã, khách hàng…" />
          <FilterSelect v-model="table.filters.queue" label="Hàng đợi" :options="queueOptions" />
          <FilterSelect v-model="table.filters.status" label="Trạng thái" :options="options('job').filter((o) => o.value !== 'canceled')" />
          <div class="ml-auto"><Toggle v-model="live" label="Cập nhật trực tiếp" /></div>
        </Toolbar>
        <DataTable :table="table" :columns="[{ key: 'type', label: 'Job' }, { key: 'queue', label: 'Hàng đợi', hideBelow: 'sm' }, { key: 'ws', label: 'Khách hàng', hideBelow: 'lg' }, { key: 'progress', label: 'Tiến độ', sortable: true, hideBelow: 'md' }, { key: 'attempts', label: 'Lần thử', sortable: true, align: 'right', hideBelow: 'md' }, { key: 'created', label: 'Tạo', sortable: true, hideBelow: 'sm' }, { key: 'status', label: 'Trạng thái' }, { key: 'actions', label: '', align: 'right' }]" empty-title="Không có job phù hợp">
          <template #cell-type="{ row }"><p class="font-mono text-xs">{{ row.type }}</p><p class="text-[11px] text-ink-subtle">{{ row.id }}</p></template>
          <template #cell-queue="{ row }"><span class="text-ink-muted">{{ row.queue }}</span></template>
          <template #cell-ws="{ row }"><span class="text-ink-muted">{{ wsName(row.workspaceId) }}</span></template>
          <template #cell-progress="{ row }"><div class="w-28"><ProgressBar :value="row.progress" :tone="row.status === 'failed' ? 'danger' : row.status === 'succeeded' ? 'success' : 'brand'" :label="`Tiến độ ${row.type}`" /></div></template>
          <template #cell-attempts="{ row }"><span class="tabular-nums">{{ row.attempts }}</span></template>
          <template #cell-created="{ row }"><span class="whitespace-nowrap text-ink-muted">{{ fmtRelative(row.createdAt) }}</span></template>
          <template #cell-status="{ row }"><StatusBadge group="job" :value="row.status" /></template>
          <template #cell-actions="{ row }">
            <AppButton v-if="row.status === 'queued'" size="sm" variant="ghost" @click="cancel(row)">Hủy</AppButton>
            <AppButton v-else-if="row.status === 'failed'" size="sm" variant="ghost" @click="retry(row)">Thử lại</AppButton>
          </template>
        </DataTable>
      </AppCard>
    </div>
  </div>
</template>
