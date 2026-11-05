<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
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
import JobProgress from '../../ui/JobProgress.vue'
import { db, demoMutation, newId, simulateJob } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { label, options } from '../../labels'
import { fmtRelative, toDateInput } from '../../format'
import type { DemoIdea } from '../../data/types'
import { createArticleFromIdea, IDEA_PATTERNS, planIdea } from '../actions'
import { useWorkspace } from '../useWorkspace'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { ws, base, mine, sites, campaignName, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const ideas = computed(() => mine(db.ideas))
const campaigns = computed(() => mine(db.campaigns))
const keywordText = (id: string | null) => db.keywords.find((k) => k.id === id)?.keyword ?? '—'
const table = useTable(() => ideas.value, {
  search: (i) => `${i.title} ${keywordText(i.keywordId)}`,
  filters: {
    initial: { status: '', intent: '', format: '', campaign: typeof route.query.campaign === 'string' ? route.query.campaign : '' },
    match: { status: (i, v) => i.status === v, intent: (i, v) => i.intent === v, format: (i, v) => i.format === v, campaign: (i, v) => i.campaignId === v },
  },
  sorts: { score: (i) => i.score, title: (i) => i.title, created: (i) => i.createdAt },
  defaultSort: { key: 'score', dir: 'desc' },
})
const columns = [
  { key: 'title', label: 'Ý tưởng', sortable: true },
  { key: 'score', label: 'Điểm', sortable: true, align: 'center' as const },
  { key: 'intent', label: 'Ý định', hideBelow: 'md' as const },
  { key: 'format', label: 'Dạng bài', hideBelow: 'lg' as const },
  { key: 'status', label: 'Trạng thái' },
  { key: 'created', label: 'Tạo', sortable: true, hideBelow: 'lg' as const },
  { key: 'actions', label: '', align: 'right' as const },
]
const selected = ref<string[]>([])
const selectedIdeas = computed(() => ideas.value.filter((i) => selected.value.includes(i.id)))
const counts = computed(() => ({
  total: ideas.value.length,
  fresh: ideas.value.filter((i) => i.status === 'new').length,
  shortlisted: ideas.value.filter((i) => i.status === 'shortlisted').length,
  converted: ideas.value.filter((i) => i.status === 'converted').length,
}))

async function bulk(status: DemoIdea['status']) {
  const list = selectedIdeas.value.filter((i) => i.status !== 'converted')
  try {
    await demoMutation(() => list.forEach((i) => (i.status = status)))
    toast.success(`Đã chuyển ${list.length} ý tưởng sang “${label('idea', status)}”.`)
    selected.value = []
  } catch (e) {
    toast.error((e as Error).message)
  }
}
async function setOne(i: DemoIdea, status: DemoIdea['status']) {
  try {
    await demoMutation(() => (i.status = status))
    toast.success(`“${i.title.slice(0, 40)}…” → ${label('idea', status)}`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}
async function toArticle(i: DemoIdea) {
  try {
    const art = await demoMutation(() => createArticleFromIdea(i, ws.value.ownerName))
    toast.success('Đã tạo bài viết ở bước Dàn ý.')
    void router.push(`${base.value}/articles/${art.id}`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}

// Plan to calendar
const planning = ref<DemoIdea[]>([])
const planForm = useForm(() => ({ date: toDateInput(new Date(Date.now() + 7 * 86_400_000).toISOString()), every: '2' }), {
  date: [rules.required(), (v) => (new Date(String(v)).getTime() >= new Date(toDateInput(new Date().toISOString())).getTime() ? true : 'Không thể lên lịch trong quá khứ.')],
  every: [rules.required(), rules.min(1), rules.max(14)],
})
function openPlan(list: DemoIdea[]) {
  planning.value = list.filter((i) => i.status !== 'converted' && i.status !== 'rejected')
  if (!planning.value.length) {
    toast.error('Các ý tưởng đã chọn đã thành bài hoặc bị loại.')
    return
  }
  planForm.reset()
}
async function savePlan() {
  await planForm
    .submit(async () => {
      await demoMutation(() =>
        planning.value.forEach((i, idx) => {
          const d = new Date(planForm.values.date)
          d.setDate(d.getDate() + idx * Number(planForm.values.every))
          d.setHours(9, 0, 0, 0)
          planIdea(i, d.toISOString())
        }),
      )
      toast.success(`Đã thêm ${planning.value.length} ý tưởng vào lịch nội dung.`)
      planning.value = []
      selected.value = []
    })
    .catch((e: Error) => toast.error(e.message))
}

// Simulated AI discovery
const discoverOpen = ref(false)
const job = shallowRef<ReturnType<typeof simulateJob> | null>(null)
const discover = useForm(() => ({ seed: '', siteId: '', campaignId: '', count: '6' }), {
  seed: [rules.required('Nhập chủ đề hoặc từ khóa gốc.'), rules.minLength(3), rules.maxLength(60)],
  siteId: [rules.required('Chọn website.')],
  count: [rules.required(), rules.min(1), rules.max(20)],
})
function openDiscover() {
  job.value = null
  discover.reset({ siteId: sites.value.find((s) => s.status === 'connected')?.id ?? '', campaignId: campaigns.value[0]?.id ?? '' })
  discoverOpen.value = true
}
function runDiscover() {
  if (!discover.validate()) return
  const v = { ...discover.values }
  job.value = simulateJob(['Phân tích hồ sơ doanh nghiệp…', 'Tổng hợp chủ đề từ từ khóa đã chọn…', 'Đánh giá ý định tìm kiếm…', 'Chấm điểm & loại trùng lặp…'], () => {
    const n = Number(v.count)
    for (let i = 0; i < n; i++) {
      const p = IDEA_PATTERNS[i % IDEA_PATTERNS.length]!
      db.ideas.unshift({
        id: newId('idea'),
        workspaceId: ws.value.id,
        siteId: v.siteId,
        campaignId: v.campaignId || null,
        clusterId: null,
        keywordId: null,
        title: p.t(v.seed.trim()),
        angle: p.angle,
        intent: i % 3 === 0 ? 'commercial' : 'informational',
        format: p.format,
        score: 60 + ((i * 7) % 35),
        status: 'new',
        source: 'ai_simulated',
        createdAt: new Date().toISOString(),
      })
    }
    table.sortKey.value = 'created'
    table.sortDir.value = 'desc'
    toast.success(`AI (mô phỏng) đã đề xuất ${n} ý tưởng mới.`)
  })
}
</script>

<template>
  <div>
    <PageHeader title="AI Topic Discovery" description="Ý tưởng bài viết được đề xuất từ hồ sơ doanh nghiệp, từ khóa và cụm chủ đề. Chọn lọc, lên lịch hoặc chuyển thành bài viết." :crumbs="crumbs({ label: 'AI Topic Discovery' })">
      <template #actions>
        <AppButton :disabled="!sites.length" @click="openDiscover"><AppIcon name="sparkles" :size="16" /> Khám phá ý tưởng</AppButton>
      </template>
    </PageHeader>

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
      <button v-for="c in [{ k: '', l: 'Tất cả ý tưởng', v: counts.total }, { k: 'new', l: 'Mới', v: counts.fresh }, { k: 'shortlisted', l: 'Đã chọn lọc', v: counts.shortlisted }, { k: 'converted', l: 'Đã thành bài', v: counts.converted }]" :key="c.k" type="button" class="rounded-xl border bg-surface p-3 text-left shadow-sm" :class="table.filters.status === c.k ? 'border-brand-500 ring-2 ring-brand-100' : 'border-line hover:border-brand-200'" @click="table.filters.status = c.k">
        <p class="text-xs text-ink-muted">{{ c.l }}</p>
        <p class="text-xl font-semibold tabular-nums">{{ c.v }}</p>
      </button>
    </div>

    <AppCard :padded="false">
      <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
        <SearchInput v-model="table.q.value" placeholder="Tìm tiêu đề, từ khóa…" />
        <FilterSelect v-model="table.filters.intent" label="Ý định" :options="options('intent')" />
        <FilterSelect v-model="table.filters.format" label="Dạng bài" :options="options('format')" />
        <FilterSelect v-model="table.filters.campaign" label="Chiến dịch" :options="[{ value: '', label: 'Tất cả' }, ...campaigns.map((c) => ({ value: c.id, label: c.name }))]" />
      </Toolbar>
      <div v-if="selected.length" class="flex flex-wrap items-center gap-2 border-b border-line bg-brand-50/60 px-5 py-2 text-sm">
        <span class="font-medium">Đã chọn {{ selected.length }}</span>
        <AppButton size="sm" variant="secondary" @click="bulk('shortlisted')">Chọn lọc</AppButton>
        <AppButton size="sm" variant="secondary" @click="openPlan(selectedIdeas)"><AppIcon name="calendar" :size="14" /> Lên lịch</AppButton>
        <AppButton size="sm" variant="secondary" @click="bulk('rejected')">Loại bỏ</AppButton>
        <button type="button" class="ml-auto text-brand-700 hover:underline" @click="selected = []">Bỏ chọn</button>
      </div>
      <DataTable v-model:selected="selected" :table="table" :columns="columns" :loading="loading" :error="error" selectable empty-title="Chưa có ý tưởng" empty-description="Dùng “Khám phá ý tưởng” để AI (mô phỏng) đề xuất chủ đề." @retry="reload">
        <template #cell-title="{ row }">
          <p class="font-medium text-ink">{{ row.title }}</p>
          <p class="text-xs text-ink-muted">
            <span v-if="row.keywordId">🔑 {{ keywordText(row.keywordId) }} · </span>{{ row.angle }}
            <span v-if="row.campaignId"> · {{ campaignName(row.campaignId) }}</span>
            <span v-if="row.source === 'ai_simulated'" class="ml-1 rounded bg-brand-50 px-1 text-[10px] font-medium text-brand-700">AI mô phỏng</span>
          </p>
        </template>
        <template #cell-score="{ row }">
          <span class="inline-grid h-8 w-8 place-items-center rounded-full text-xs font-semibold" :class="row.score >= 80 ? 'bg-success-50 text-success-600' : row.score >= 60 ? 'bg-warning-50 text-warning-600' : 'bg-surface-muted text-ink-muted'">{{ row.score }}</span>
        </template>
        <template #cell-intent="{ row }"><StatusBadge group="intent" :value="row.intent" /></template>
        <template #cell-format="{ row }"><span class="text-ink-muted">{{ label('format', row.format) }}</span></template>
        <template #cell-status="{ row }"><StatusBadge group="idea" :value="row.status" /></template>
        <template #cell-created="{ row }"><span class="whitespace-nowrap text-ink-muted">{{ fmtRelative(row.createdAt) }}</span></template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-1">
            <template v-if="row.status !== 'converted'">
              <AppButton v-if="row.status === 'new'" size="sm" variant="ghost" title="Chọn lọc" @click="setOne(row, 'shortlisted')"><AppIcon name="star" :size="16" /></AppButton>
              <AppButton size="sm" variant="ghost" title="Lên lịch" @click="openPlan([row])"><AppIcon name="calendar" :size="16" /></AppButton>
              <AppButton size="sm" variant="secondary" @click="toArticle(row)">Viết bài</AppButton>
            </template>
            <RouterLink v-else :to="`${base}/articles?q=${encodeURIComponent(row.title.slice(0, 30))}`" class="text-xs font-medium text-brand-700 hover:underline">Xem bài</RouterLink>
          </div>
        </template>
      </DataTable>
    </AppCard>

    <AppModal :open="planning.length > 0" title="Thêm vào lịch nội dung" @close="planning = []">
      <form id="plan-form" class="space-y-4" @submit.prevent="savePlan">
        <p class="text-sm text-ink-muted">{{ planning.length }} ý tưởng sẽ được xếp lịch lần lượt.</p>
        <AppInput v-model="planForm.values.date" type="date" label="Ngày bắt đầu" :error="planForm.errors.date" />
        <AppInput v-model="planForm.values.every" type="number" label="Cách nhau (ngày)" :error="planForm.errors.every" />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="planning = []">Hủy</AppButton>
        <AppButton type="submit" form="plan-form" :loading="planForm.submitting.value">Lên lịch</AppButton>
      </template>
    </AppModal>

    <AppModal :open="discoverOpen" title="Khám phá ý tưởng bằng AI" width="lg" @close="discoverOpen = false">
      <form v-if="!job" id="discover-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="runDiscover">
        <AppInput v-model="discover.values.seed" label="Chủ đề / từ khóa gốc" placeholder="Ví dụ: sofa cho căn hộ nhỏ" class="sm:col-span-2" :error="discover.errors.seed" />
        <AppSelect v-model="discover.values.siteId" label="Website" :error="discover.errors.siteId" :options="[{ value: '', label: '— Chọn —' }, ...sites.map((s) => ({ value: s.id, label: s.name }))]" />
        <AppSelect v-model="discover.values.campaignId" label="Chiến dịch (tùy chọn)" :options="[{ value: '', label: 'Không gắn chiến dịch' }, ...campaigns.map((c) => ({ value: c.id, label: c.name }))]" />
        <AppInput v-model="discover.values.count" type="number" label="Số ý tưởng" :error="discover.errors.count" hint="Mỗi lượt khám phá dùng 1 lượt nghiên cứu (mô phỏng)." />
      </form>
      <JobProgress v-else :progress="job.progress.value" :step="job.step.value" :running="job.running.value" :failed="job.failed.value" title="Khám phá ý tưởng" />
      <template #footer>
        <AppButton variant="secondary" @click="discoverOpen = false">{{ job && !job.running.value ? 'Đóng' : 'Hủy' }}</AppButton>
        <AppButton v-if="!job" type="submit" form="discover-form"><AppIcon name="sparkles" :size="16" /> Bắt đầu</AppButton>
        <AppButton v-else-if="job.failed.value" @click="job = null">Thử lại</AppButton>
      </template>
    </AppModal>
  </div>
</template>
