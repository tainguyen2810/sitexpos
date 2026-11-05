<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { useRoute } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import Drawer from '../../ui/Drawer.vue'
import DTextarea from '../../ui/DTextarea.vue'
import JobProgress from '../../ui/JobProgress.vue'
import Sparkline from '../../ui/charts/Sparkline.vue'
import BarChart from '../../ui/charts/BarChart.vue'
import { db, demoMutation, newId, simulateJob } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { label, options } from '../../labels'
import { fmtNumber, fmtVnd } from '../../format'
import { downloadFile, toCsv } from '../../download'
import type { DemoKeyword, KeywordStatus } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const route = useRoute()
const toast = useToast()
const { ws, mine, sites, siteName, campaignName, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const keywords = computed(() => mine(db.keywords))
const campaigns = computed(() => mine(db.campaigns))
const clusters = computed(() => mine(db.clusters))
const table = useTable(() => keywords.value, {
  search: (k) => k.keyword,
  filters: {
    initial: { status: '', intent: '', origin: '', data: '', campaign: typeof route.query.campaign === 'string' ? route.query.campaign : '' },
    match: {
      status: (k, v) => k.status === v,
      intent: (k, v) => (v === 'none' ? k.intent === null : k.intent === v),
      origin: (k, v) => k.origin === v,
      data: (k, v) => (v === 'yes' ? k.metrics !== null : k.metrics === null),
      campaign: (k, v) => k.campaignId === v,
    },
  },
  sorts: { keyword: (k) => k.keyword, volume: (k) => k.metrics?.volume ?? null, kd: (k) => k.metrics?.difficulty ?? null, cpc: (k) => k.metrics?.cpcMinor ?? null, position: (k) => k.position },
  defaultSort: { key: 'volume', dir: 'desc' },
  pageSize: 20,
})
const columns = [
  { key: 'keyword', label: 'Từ khóa', sortable: true },
  { key: 'intent', label: 'Ý định', hideBelow: 'md' as const },
  { key: 'volume', label: 'Lượt tìm/tháng*', sortable: true, align: 'right' as const },
  { key: 'trend', label: 'Xu hướng', hideBelow: 'lg' as const },
  { key: 'kd', label: 'Độ khó*', sortable: true, hideBelow: 'sm' as const },
  { key: 'cpc', label: 'CPC*', sortable: true, align: 'right' as const, hideBelow: 'lg' as const },
  { key: 'position', label: 'Vị trí', sortable: true, align: 'right' as const, hideBelow: 'md' as const },
  { key: 'status', label: 'Trạng thái' },
]
const selected = ref<string[]>([])
const kdTone = (kd: number) => (kd >= 60 ? 'bg-danger-600' : kd >= 35 ? 'bg-warning-600' : 'bg-success-600')

async function bulkStatus(status: KeywordStatus) {
  const list = keywords.value.filter((k) => selected.value.includes(k.id))
  try {
    await demoMutation(() => list.forEach((k) => (k.status = status)))
    toast.success(`Đã cập nhật ${list.length} từ khóa → ${label('keywordStatus', status)}.`)
    selected.value = []
  } catch (e) {
    toast.error((e as Error).message)
  }
}
const assignCluster = ref('')
async function bulkCluster() {
  if (!assignCluster.value) return
  const list = keywords.value.filter((k) => selected.value.includes(k.id))
  try {
    await demoMutation(() => list.forEach((k) => (k.clusterId = assignCluster.value)))
    toast.success(`Đã gán ${list.length} từ khóa vào cụm chủ đề.`)
    selected.value = []
    assignCluster.value = ''
  } catch (e) {
    toast.error((e as Error).message)
  }
}
function exportCsv() {
  const rows = table.sorted.value.map((k) => ({
    keyword: k.keyword,
    intent: label('intent', k.intent),
    volume_simulated: k.metrics?.volume ?? 'Chưa có dữ liệu',
    difficulty_simulated: k.metrics?.difficulty ?? '',
    status: label('keywordStatus', k.status),
    site: siteName(k.siteId),
  }))
  downloadFile(`tu-khoa-demo-${ws.value.slug}.csv`, toCsv(rows), 'text/csv;charset=utf-8')
  toast.success(`Đã xuất ${rows.length} từ khóa (CSV, dữ liệu demo).`)
}

// Detail drawer
const detail = ref<DemoKeyword | null>(null)
const months = ['T12', 'T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9', 'T10', 'T11']

// Add manually
const addOpen = ref(false)
const addForm = useForm(() => ({ list: '', siteId: '', campaignId: '' }), {
  list: [rules.required('Nhập ít nhất một từ khóa.'), (v) => (String(v).split('\n').filter((x) => x.trim()).length <= 50 ? true : 'Tối đa 50 từ khóa mỗi lần.')],
  siteId: [rules.required('Chọn website.')],
})
function openAdd() {
  addForm.reset({ siteId: sites.value[0]?.id ?? '', campaignId: campaigns.value[0]?.id ?? '' })
  addOpen.value = true
}
async function saveAdd() {
  await addForm
    .submit(async () => {
      const lines = [...new Set(addForm.values.list.split('\n').map((x) => x.trim().toLowerCase()).filter(Boolean))]
      const existing = new Set(keywords.value.filter((k) => k.siteId === addForm.values.siteId).map((k) => k.keyword))
      const fresh = lines.filter((l) => !existing.has(l))
      await demoMutation(() =>
        fresh.forEach((kw) =>
          db.keywords.unshift({
            id: newId('kw'),
            workspaceId: ws.value.id,
            siteId: addForm.values.siteId,
            campaignId: addForm.values.campaignId || null,
            clusterId: null,
            keyword: kw,
            intent: null,
            status: 'accepted',
            origin: 'customer',
            metrics: null,
            position: null,
            createdAt: new Date().toISOString(),
          }),
        ),
      )
      toast.success(`Đã thêm ${fresh.length} từ khóa${lines.length - fresh.length ? `, bỏ qua ${lines.length - fresh.length} từ trùng` : ''}. Số liệu sẽ hiển thị khi có nguồn dữ liệu.`)
      addOpen.value = false
    })
    .catch((e: Error) => toast.error(e.message))
}

// AI expansion (simulated): suggestions only, never invented metrics
const expandOpen = ref(false)
const job = shallowRef<ReturnType<typeof simulateJob> | null>(null)
const expandForm = useForm(() => ({ seed: '', siteId: '' }), { seed: [rules.required(), rules.minLength(3)], siteId: [rules.required('Chọn website.')] })
function openExpand() {
  job.value = null
  expandForm.reset({ siteId: sites.value[0]?.id ?? '' })
  expandOpen.value = true
}
function runExpand() {
  if (!expandForm.validate()) return
  const { seed, siteId } = { ...expandForm.values }
  job.value = simulateJob(['Mở rộng biến thể từ khóa…', 'Phân loại ý định tìm kiếm…', 'Loại bỏ trùng lặp…'], () => {
    const mods = ['là gì', 'giá bao nhiêu', 'loại nào tốt', 'kinh nghiệm', 'cho người mới', 'ở đâu uy tín']
    mods.forEach((m, i) =>
      db.keywords.unshift({
        id: newId('kw'),
        workspaceId: ws.value.id,
        siteId,
        campaignId: null,
        clusterId: null,
        keyword: `${seed.trim().toLowerCase()} ${m}`,
        intent: i % 2 ? 'commercial' : 'informational',
        status: 'suggested',
        origin: 'ai_suggested',
        metrics: null,
        position: null,
        createdAt: new Date().toISOString(),
      }),
    )
    table.filters.status = 'suggested'
    toast.success('AI (mô phỏng) đã đề xuất 6 từ khóa. Lượt tìm kiếm: “Chưa có dữ liệu” cho tới khi tra cứu từ nhà cung cấp.')
  })
}

const intentDist = computed(() => {
  const keys = ['informational', 'commercial', 'transactional', 'navigational'] as const
  return { labels: keys.map((k) => label('intent', k)), values: keys.map((k) => keywords.value.filter((x) => x.intent === k).length) }
})
</script>

<template>
  <div>
    <PageHeader title="Keyword Explorer" description="Nghiên cứu, phân loại và chọn từ khóa mục tiêu. *Số liệu lượt tìm, độ khó, CPC trong demo là giả lập (nhà cung cấp: demo)." :crumbs="crumbs({ label: 'Keyword Explorer' })">
      <template #actions>
        <AppButton variant="secondary" @click="exportCsv"><AppIcon name="download" :size="16" /> Xuất CSV</AppButton>
        <AppButton variant="secondary" :disabled="!sites.length" @click="openAdd"><AppIcon name="plus" :size="16" /> Thêm từ khóa</AppButton>
        <AppButton :disabled="!sites.length" @click="openExpand"><AppIcon name="sparkles" :size="16" /> Mở rộng bằng AI</AppButton>
      </template>
    </PageHeader>

    <div class="mb-4 grid gap-4 lg:grid-cols-3">
      <AppCard title="Phân bổ ý định tìm kiếm" class="lg:col-span-2">
        <BarChart :labels="intentDist.labels" :series="[{ name: 'Từ khóa', values: intentDist.values }]" :height="140" />
      </AppCard>
      <AppCard title="Tóm tắt">
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-ink-muted">Tổng từ khóa</dt><dd class="font-semibold">{{ keywords.length }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-muted">Có số liệu (giả lập)</dt><dd class="font-semibold">{{ keywords.filter((k) => k.metrics).length }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-muted">Chưa có dữ liệu</dt><dd class="font-semibold">{{ keywords.filter((k) => !k.metrics).length }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-muted">Đang xếp hạng top 10</dt><dd class="font-semibold">{{ keywords.filter((k) => k.position && k.position <= 10).length }}</dd></div>
        </dl>
      </AppCard>
    </div>

    <AppCard :padded="false">
      <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
        <SearchInput v-model="table.q.value" placeholder="Tìm từ khóa…" />
        <FilterSelect v-model="table.filters.status" label="Trạng thái" :options="options('keywordStatus')" />
        <FilterSelect v-model="table.filters.intent" label="Ý định" :options="[...options('intent'), { value: 'none', label: 'Chưa phân loại' }]" />
        <FilterSelect v-model="table.filters.origin" label="Nguồn" :options="options('origin')" />
        <FilterSelect v-model="table.filters.data" label="Số liệu" :options="[{ value: '', label: 'Tất cả' }, { value: 'yes', label: 'Có số liệu' }, { value: 'no', label: 'Chưa có dữ liệu' }]" />
      </Toolbar>
      <div v-if="selected.length" class="flex flex-wrap items-center gap-2 border-b border-line bg-brand-50/60 px-5 py-2 text-sm">
        <span class="font-medium">Đã chọn {{ selected.length }}</span>
        <AppButton size="sm" variant="secondary" @click="bulkStatus('accepted')">Chọn dùng</AppButton>
        <AppButton size="sm" variant="secondary" @click="bulkStatus('rejected')">Loại bỏ</AppButton>
        <select v-model="assignCluster" class="rounded-lg border border-line bg-surface py-1.5 pl-2 pr-7 text-sm" aria-label="Gán vào cụm">
          <option value="">Gán vào cụm…</option>
          <option v-for="c in clusters" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <AppButton size="sm" :disabled="!assignCluster" @click="bulkCluster">Gán</AppButton>
        <button type="button" class="ml-auto text-brand-700 hover:underline" @click="selected = []">Bỏ chọn</button>
      </div>
      <DataTable v-model:selected="selected" :table="table" :columns="columns" :loading="loading" :error="error" selectable clickable empty-title="Chưa có từ khóa" empty-description="Thêm thủ công hoặc dùng AI để mở rộng từ khóa." @retry="reload" @row-click="(k) => (detail = k)">
        <template #cell-keyword="{ row }">
          <p class="font-medium text-ink">{{ row.keyword }}</p>
          <p class="text-xs text-ink-muted">{{ label('origin', row.origin) }}<span v-if="row.campaignId"> · {{ campaignName(row.campaignId) }}</span></p>
        </template>
        <template #cell-intent="{ row }"><StatusBadge v-if="row.intent" group="intent" :value="row.intent" /><span v-else class="text-xs text-ink-subtle">Chưa phân loại</span></template>
        <template #cell-volume="{ row }"><span v-if="row.metrics" class="tabular-nums">{{ fmtNumber(row.metrics.volume) }}</span><span v-else class="text-xs text-ink-subtle">Chưa có dữ liệu</span></template>
        <template #cell-trend="{ row }"><Sparkline v-if="row.metrics" :values="row.metrics.trend" /><span v-else class="text-xs text-ink-subtle">—</span></template>
        <template #cell-kd="{ row }">
          <div v-if="row.metrics" class="flex items-center gap-2">
            <span class="w-6 text-right tabular-nums">{{ row.metrics.difficulty }}</span>
            <span class="h-1.5 w-14 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full" :class="kdTone(row.metrics.difficulty)" :style="{ width: `${row.metrics.difficulty}%` }" /></span>
          </div>
          <span v-else class="text-xs text-ink-subtle">—</span>
        </template>
        <template #cell-cpc="{ row }"><span class="tabular-nums text-ink-muted">{{ row.metrics ? fmtVnd(row.metrics.cpcMinor) : '—' }}</span></template>
        <template #cell-position="{ row }"><span class="tabular-nums" :class="row.position && row.position <= 10 ? 'font-semibold text-success-600' : 'text-ink-muted'">{{ row.position ?? '—' }}</span></template>
        <template #cell-status="{ row }"><StatusBadge group="keywordStatus" :value="row.status" /></template>
      </DataTable>
    </AppCard>

    <Drawer :open="!!detail" :title="detail?.keyword ?? ''" :subtitle="detail ? siteName(detail.siteId) : ''" @close="detail = null">
      <template v-if="detail">
        <div class="mb-4 grid grid-cols-3 gap-3 text-center">
          <div class="rounded-lg bg-surface-muted p-3"><p class="text-xs text-ink-muted">Lượt tìm*</p><p class="font-semibold">{{ detail.metrics ? fmtNumber(detail.metrics.volume) : '—' }}</p></div>
          <div class="rounded-lg bg-surface-muted p-3"><p class="text-xs text-ink-muted">Độ khó*</p><p class="font-semibold">{{ detail.metrics?.difficulty ?? '—' }}</p></div>
          <div class="rounded-lg bg-surface-muted p-3"><p class="text-xs text-ink-muted">Vị trí</p><p class="font-semibold">{{ detail.position ?? '—' }}</p></div>
        </div>
        <template v-if="detail.metrics">
          <p class="mb-2 text-sm font-medium">Xu hướng 12 tháng (giả lập)</p>
          <BarChart :labels="months" :series="[{ name: 'Lượt tìm', values: detail.metrics.trend }]" :height="150" />
        </template>
        <div v-else class="rounded-lg border border-dashed border-line p-4 text-sm text-ink-muted">Chưa có dữ liệu lượt tìm kiếm cho từ khóa này. SiteX không tự suy đoán số liệu – cần tra cứu từ nhà cung cấp dữ liệu (chưa cấu hình trong demo).</div>
        <dl class="mt-4 space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-ink-muted">Ý định</dt><dd>{{ label('intent', detail.intent) }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-muted">Cụm chủ đề</dt><dd>{{ db.clusters.find((c) => c.id === detail?.clusterId)?.name ?? '—' }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-muted">Ý tưởng liên quan</dt><dd>{{ db.ideas.filter((i) => i.keywordId === detail?.id).length }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-muted">Bài viết dùng từ khóa</dt><dd>{{ db.articles.filter((a) => a.keywordId === detail?.id).length }}</dd></div>
        </dl>
        <AppSelect v-model="detail.status" class="mt-4" label="Trạng thái" :options="options('keywordStatus').slice(1)" />
      </template>
    </Drawer>

    <AppModal :open="addOpen" title="Thêm từ khóa thủ công" @close="addOpen = false">
      <form id="kw-add" class="space-y-4" @submit.prevent="saveAdd">
        <DTextarea v-model="addForm.values.list" label="Danh sách từ khóa" hint="Mỗi dòng một từ khóa, tối đa 50." :rows="6" :error="addForm.errors.list" />
        <AppSelect v-model="addForm.values.siteId" label="Website" :error="addForm.errors.siteId" :options="sites.map((s) => ({ value: s.id, label: s.name }))" />
        <AppSelect v-model="addForm.values.campaignId" label="Chiến dịch" :options="[{ value: '', label: 'Không gắn' }, ...campaigns.map((c) => ({ value: c.id, label: c.name }))]" />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="addOpen = false">Hủy</AppButton>
        <AppButton type="submit" form="kw-add" :loading="addForm.submitting.value">Thêm</AppButton>
      </template>
    </AppModal>

    <AppModal :open="expandOpen" title="Mở rộng từ khóa bằng AI" @close="expandOpen = false">
      <form v-if="!job" id="kw-expand" class="space-y-4" @submit.prevent="runExpand">
        <AppInput v-model="expandForm.values.seed" label="Từ khóa gốc" placeholder="Ví dụ: bàn ăn gỗ" :error="expandForm.errors.seed" />
        <AppSelect v-model="expandForm.values.siteId" label="Website" :error="expandForm.errors.siteId" :options="sites.map((s) => ({ value: s.id, label: s.name }))" />
        <p class="rounded-lg bg-surface-muted p-3 text-xs text-ink-muted">AI chỉ đề xuất từ khóa, không tạo số liệu lượt tìm kiếm. Số liệu chỉ hiển thị khi có dữ liệu thật từ nhà cung cấp.</p>
      </form>
      <JobProgress v-else :progress="job.progress.value" :step="job.step.value" :running="job.running.value" :failed="job.failed.value" />
      <template #footer>
        <AppButton variant="secondary" @click="expandOpen = false">{{ job && !job.running.value ? 'Đóng' : 'Hủy' }}</AppButton>
        <AppButton v-if="!job" type="submit" form="kw-expand"><AppIcon name="sparkles" :size="16" /> Mở rộng</AppButton>
        <AppButton v-else-if="job.failed.value" @click="job = null">Thử lại</AppButton>
      </template>
    </AppModal>
  </div>
</template>
