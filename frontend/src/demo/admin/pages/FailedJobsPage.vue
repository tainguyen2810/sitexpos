<script setup lang="ts">
import { computed, ref } from 'vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import { db, demoMutation, newId } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { fmtDateTime, fmtRelative } from '../../format'
import type { DemoFailedJob, DemoQueueJob } from '../../data/types'
import { adminCrumbs, staffAudit, wsName } from '../useAdmin'

const toast = useToast()
const { loading, error, reload } = useDemoQuery()
const shortClass = (c: string) => c.split('\\').slice(-1)[0] ?? c

const table = useTable(() => db.failedJobs, {
  search: (j) => `${j.jobClass} ${j.exception} ${wsName(j.workspaceId)}`,
  filters: { initial: { queue: '', job: '' }, match: { queue: (j, v) => j.queue === v, job: (j, v) => shortClass(j.jobClass) === v } },
  sorts: { failed: (j) => j.failedAt, job: (j) => shortClass(j.jobClass) },
  defaultSort: { key: 'failed', dir: 'desc' },
})
const queueOptions = computed(() => [{ value: '', label: 'Tất cả' }, ...[...new Set(db.failedJobs.map((j) => j.queue))].map((q) => ({ value: q, label: q }))])
const jobOptions = computed(() => [{ value: '', label: 'Tất cả' }, ...[...new Set(db.failedJobs.map((j) => shortClass(j.jobClass)))].map((c) => ({ value: c, label: c }))])
const selected = ref<string[]>([])
const viewing = ref<DemoFailedJob | null>(null)

function requeue(j: DemoFailedJob) {
  db.queueJobs.unshift({ id: newId('job'), queue: (['default', 'ai', 'media', 'publishing', 'crawl'].includes(j.queue) ? j.queue : 'default') as DemoQueueJob['queue'], type: shortClass(j.jobClass), workspaceId: j.workspaceId, status: 'queued', progress: 0, attempts: 0, startedAt: null, createdAt: new Date().toISOString() })
}
async function retry(ids: string[]) {
  const jobs = db.failedJobs.filter((j) => ids.includes(j.id))
  if (!jobs.length) return
  try {
    await demoMutation(() => {
      jobs.forEach(requeue)
      db.failedJobs = db.failedJobs.filter((j) => !ids.includes(j.id))
      staffAudit('platform.failed_jobs.retried', `${jobs.length} job`)
    })
    selected.value = selected.value.filter((id) => !ids.includes(id))
    viewing.value = null
    toast.success(`Đã đưa ${jobs.length} job vào lại hàng đợi. Xem tiến độ ở Queue Monitor.`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}
const deleting = ref<string[] | null>(null)
async function remove() {
  const ids = deleting.value ?? []
  try {
    await demoMutation(() => {
      db.failedJobs = db.failedJobs.filter((j) => !ids.includes(j.id))
      staffAudit('platform.failed_jobs.deleted', `${ids.length} job`)
    })
    selected.value = selected.value.filter((id) => !ids.includes(id))
    viewing.value = null
    toast.success(`Đã xóa ${ids.length} job lỗi.`)
  } catch (e) {
    toast.error((e as Error).message)
  }
  deleting.value = null
}
const retryAllOpen = ref(false)
async function copyTrace(j: DemoFailedJob) {
  try {
    await navigator.clipboard.writeText(`${j.exception}\n${j.trace}`)
    toast.success('Đã sao chép stack trace.')
  } catch {
    toast.error('Trình duyệt không cho phép sao chép.')
  }
}
</script>

<template>
  <div>
    <PageHeader title="Failed Jobs" description="Job đã thất bại sau khi hết số lần thử. Thử lại sau khi đã xử lý nguyên nhân." :crumbs="adminCrumbs({ label: 'Failed Jobs' })">
      <template #actions>
        <AppButton variant="secondary" :disabled="!db.failedJobs.length" @click="retryAllOpen = true"><AppIcon name="refresh" :size="16" /> Thử lại tất cả</AppButton>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" :rows="6" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <AppCard v-else :padded="false">
      <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
        <SearchInput v-model="table.q.value" placeholder="Class, lỗi, khách hàng…" />
        <FilterSelect v-model="table.filters.queue" label="Hàng đợi" :options="queueOptions" />
        <FilterSelect v-model="table.filters.job" label="Job" :options="jobOptions" />
        <div v-if="selected.length" class="ml-auto flex items-center gap-2 text-sm">
          <span class="text-ink-muted">Đã chọn {{ selected.length }}</span>
          <AppButton size="sm" variant="secondary" @click="retry(selected)">Thử lại</AppButton>
          <AppButton size="sm" variant="danger" @click="deleting = [...selected]">Xóa</AppButton>
        </div>
      </Toolbar>
      <DataTable v-model:selected="selected" :table="table" selectable clickable :columns="[{ key: 'job', label: 'Job', sortable: true }, { key: 'exception', label: 'Lỗi', hideBelow: 'md' }, { key: 'ws', label: 'Khách hàng', hideBelow: 'lg' }, { key: 'failed', label: 'Thất bại', sortable: true }, { key: 'actions', label: '', align: 'right' }]" empty-title="Không có job lỗi" empty-description="Mọi job đều chạy ổn. 🎉" @row-click="(j) => (viewing = j)">
        <template #cell-job="{ row }"><p class="font-mono text-xs">{{ shortClass(row.jobClass) }}</p><p class="text-[11px] text-ink-subtle">{{ row.queue }} · {{ row.attempts }} lần thử</p></template>
        <template #cell-exception="{ row }"><p class="line-clamp-2 max-w-md text-xs text-danger-600">{{ row.exception }}</p></template>
        <template #cell-ws="{ row }"><span class="text-ink-muted">{{ wsName(row.workspaceId) }}</span></template>
        <template #cell-failed="{ row }"><span class="whitespace-nowrap text-ink-muted" :title="fmtDateTime(row.failedAt)">{{ fmtRelative(row.failedAt) }}</span></template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-1" @click.stop>
            <AppButton size="sm" variant="ghost" title="Thử lại" @click="retry([row.id])"><AppIcon name="refresh" :size="16" /></AppButton>
            <AppButton size="sm" variant="ghost" title="Xóa" @click="deleting = [row.id]"><AppIcon name="trash" :size="16" class="text-danger-600" /></AppButton>
          </div>
        </template>
      </DataTable>
    </AppCard>

    <AppModal :open="!!viewing" :title="viewing ? shortClass(viewing.jobClass) : ''" width="lg" @close="viewing = null">
      <div v-if="viewing" class="space-y-4 text-sm">
        <dl class="grid gap-3 sm:grid-cols-3">
          <div><dt class="text-xs text-ink-muted">Hàng đợi</dt><dd>{{ viewing.queue }}</dd></div>
          <div><dt class="text-xs text-ink-muted">Khách hàng</dt><dd>{{ wsName(viewing.workspaceId) }}</dd></div>
          <div><dt class="text-xs text-ink-muted">Thất bại lúc</dt><dd>{{ fmtDateTime(viewing.failedAt) }}</dd></div>
        </dl>
        <div><p class="mb-1 text-xs text-ink-muted">Class</p><code class="break-all text-xs">{{ viewing.jobClass }}</code></div>
        <div class="rounded-lg bg-danger-50 p-3 text-danger-600">{{ viewing.exception }}</div>
        <pre class="max-h-64 overflow-auto rounded-lg bg-slate-900 p-3 text-xs leading-relaxed text-slate-100">{{ viewing.trace }}</pre>
      </div>
      <template #footer>
        <AppButton variant="secondary" @click="copyTrace(viewing!)"><AppIcon name="copy" :size="16" /> Sao chép</AppButton>
        <AppButton variant="danger" @click="deleting = [viewing!.id]">Xóa</AppButton>
        <AppButton @click="retry([viewing!.id])">Thử lại</AppButton>
      </template>
    </AppModal>

    <ConfirmDialog :open="!!deleting" :title="`Xóa ${deleting?.length ?? 0} job lỗi?`" message="Job bị xóa sẽ không thể thử lại. Payload vẫn được giữ trong log 30 ngày ở bản thật." confirm-label="Xóa" danger @close="deleting = null" @confirm="remove" />
    <ConfirmDialog :open="retryAllOpen" :title="`Thử lại ${db.failedJobs.length} job?`" message="Chỉ nên thử lại sau khi đã khắc phục nguyên nhân (ví dụ nhà cung cấp AI đã ổn định). Job có thể tiêu tốn credit AI." confirm-label="Thử lại tất cả" @close="retryAllOpen = false" @confirm="(retryAllOpen = false), retry(db.failedJobs.map((j) => j.id))" />
  </div>
</template>
