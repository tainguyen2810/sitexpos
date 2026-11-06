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
import Tabs from '../../ui/Tabs.vue'
import JobProgress from '../../ui/JobProgress.vue'
import { db, newId, simulateJob } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { options } from '../../labels'
import { fmtNumber, fmtRelative } from '../../format'
import { slugify } from '../../data/seed'
import { createArticleFromIdea, demoDraftSections } from '../actions'
import { useWorkspace } from '../useWorkspace'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { ws, base, mine, sites, siteName, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const GROUPS: Record<string, string[]> = {
  all: [],
  writing: ['brief', 'drafting', 'verifying', 'needs_revision'],
  review: ['ready_for_review', 'awaiting_approval', 'approved'],
  scheduled: ['scheduled', 'publishing'],
  published: ['published'],
  failed: ['publish_failed'],
}
const initialStatus = typeof route.query.status === 'string' ? route.query.status : ''
const tab = ref(Object.keys(GROUPS).find((g) => GROUPS[g]!.includes(initialStatus)) ?? 'all')
const articles = computed(() => mine(db.articles))
const table = useTable(() => articles.value.filter((a) => tab.value === 'all' || GROUPS[tab.value]!.includes(a.status)), {
  search: (a) => `${a.title} ${a.slug}`,
  filters: { initial: { site: '', verification: '', status: '' }, match: { site: (a, v) => a.siteId === v, verification: (a, v) => a.verificationStatus === v, status: (a, v) => a.status === v } },
  sorts: { title: (a) => a.title, updated: (a) => a.updatedAt, seo: (a) => a.seoScore, words: (a) => a.wordCount },
  defaultSort: { key: 'updated', dir: 'desc' },
})
if (typeof route.query.q === 'string') table.q.value = route.query.q
const tabs = computed(() =>
  [
    ['all', 'Tất cả'],
    ['writing', 'Đang viết'],
    ['review', 'Chờ duyệt'],
    ['scheduled', 'Đã lên lịch'],
    ['published', 'Đã đăng'],
    ['failed', 'Lỗi đăng'],
  ].map(([key, l]) => ({ key: key!, label: l!, count: articles.value.filter((a) => key === 'all' || GROUPS[key!]!.includes(a.status)).length })),
)
const columns = [
  { key: 'title', label: 'Bài viết', sortable: true },
  { key: 'status', label: 'Trạng thái' },
  { key: 'verification', label: 'Kiểm chứng', hideBelow: 'md' as const },
  { key: 'seo', label: 'SEO', sortable: true, align: 'center' as const, hideBelow: 'sm' as const },
  { key: 'words', label: 'Số từ', sortable: true, align: 'right' as const, hideBelow: 'lg' as const },
  { key: 'updated', label: 'Cập nhật', sortable: true, hideBelow: 'lg' as const },
]

// New article (simulated AI writer)
const newOpen = ref(route.query.new === '1')
const job = shallowRef<ReturnType<typeof simulateJob> | null>(null)
const ideaOptions = computed(() => mine(db.ideas).filter((i) => ['new', 'shortlisted', 'planned'].includes(i.status)))
const form = useForm(() => ({ mode: 'idea', ideaId: '', title: '', keyword: '', siteId: sites.value.find((s) => s.status === 'connected')?.id ?? '', length: '1500', tone: ws.value.settings.toneOfVoice }), {
  ideaId: [(v, all) => (all.mode !== 'idea' || v ? true : 'Chọn một ý tưởng.')],
  title: [(v, all) => (all.mode !== 'custom' || String(v).trim().length >= 10 ? true : 'Tiêu đề tối thiểu 10 ký tự.')],
  siteId: [rules.required('Chọn website.')],
  length: [rules.required(), rules.min(600), rules.max(4000)],
})
function openNew() {
  job.value = null
  form.reset()
  newOpen.value = true
}
function start() {
  if (!form.validate()) return
  const v = { ...form.values }
  job.value = simulateJob(['Lập dàn ý (brief)…', 'Tìm & tải nguồn tham khảo…', 'Viết bản nháp…', 'Chèn trích dẫn từ nguồn đã tải…', 'Tính điểm SEO checklist…'], () => {
    const idea = v.mode === 'idea' ? db.ideas.find((i) => i.id === v.ideaId) : undefined
    const art = idea
      ? createArticleFromIdea(idea, ws.value.ownerName)
      : (() => {
          const now = new Date().toISOString()
          db.articles.unshift({
            id: newId('art'),
            workspaceId: ws.value.id,
            siteId: v.siteId,
            campaignId: null,
            ideaId: null,
            keywordId: null,
            clusterId: null,
            title: v.title.trim(),
            slug: slugify(v.title).slice(0, 70),
            metaDescription: '',
            status: 'brief',
            verificationStatus: 'pending',
            seoScore: 0,
            wordCount: 0,
            sections: [],
            versions: [],
            featuredMediaId: null,
            authorName: ws.value.ownerName,
            scheduledAt: null,
            publishedAt: null,
            wpUrl: null,
            createdAt: now,
            updatedAt: now,
          })
          return db.articles[0]!
        })()
    const kw = db.keywords.find((k) => k.id === art.keywordId)
    art.sections = demoDraftSections(art.title, kw, v.tone || 'trung lập')
    art.metaDescription = `${art.title} – hướng dẫn chi tiết, dễ hiểu (bản nháp demo).`.slice(0, 155)
    art.wordCount = Number(v.length)
    art.seoScore = 72
    art.status = 'ready_for_review'
    art.versions.push({ no: 1, authorType: 'ai', authorName: 'AI Writer (mô phỏng)', note: 'Bản nháp đầu tiên', createdAt: new Date().toISOString(), wordCount: art.wordCount })
    art.updatedAt = new Date().toISOString()
    const credit = 25
    ws.value.credits -= credit
    db.credits.push({ id: newId('cr'), workspaceId: ws.value.id, delta: -credit, balanceAfter: ws.value.credits, reason: 'consume', note: 'Viết bài AI (mô phỏng)', createdAt: new Date().toISOString() })
    toast.success('Đã tạo bản nháp (mô phỏng). Bài cần chạy kiểm chứng trước khi duyệt.')
    newOpen.value = false
    void router.push(`${base.value}/articles/${art.id}`)
  }, 650)
}
</script>

<template>
  <div>
    <PageHeader title="AI Article Writer" description="Tạo bài viết từ ý tưởng, chỉnh sửa, kiểm chứng và gửi duyệt. Mọi lần sửa đều lưu thành phiên bản mới." :crumbs="crumbs({ label: 'AI Article Writer' })">
      <template #actions>
        <AppButton :disabled="!sites.length" @click="openNew"><AppIcon name="sparkles" :size="16" /> Viết bài mới</AppButton>
      </template>
    </PageHeader>

    <AppCard :padded="false">
      <div class="px-4 pt-2 sm:px-5"><Tabs v-model="tab" :tabs="tabs" /></div>
      <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
        <SearchInput v-model="table.q.value" placeholder="Tìm tiêu đề, slug…" />
        <FilterSelect v-model="table.filters.site" label="Website" :options="[{ value: '', label: 'Tất cả' }, ...sites.map((s) => ({ value: s.id, label: s.name }))]" />
        <FilterSelect v-model="table.filters.verification" label="Kiểm chứng" :options="options('verification')" />
        <FilterSelect v-model="table.filters.status" label="Trạng thái" :options="options('article')" />
      </Toolbar>
      <DataTable :table="table" :columns="columns" :loading="loading" :error="error" clickable empty-title="Chưa có bài viết" empty-description="Bắt đầu từ một ý tưởng trong AI Topic Discovery hoặc tạo bài mới." @retry="reload" @row-click="(a) => router.push(`${base}/articles/${a.id}`)">
        <template #cell-title="{ row }">
          <p class="line-clamp-1 font-medium text-ink">{{ row.title }}</p>
          <p class="text-xs text-ink-muted">{{ siteName(row.siteId) }} · {{ row.authorName }} · v{{ row.versions.length || 0 }}</p>
        </template>
        <template #cell-status="{ row }"><StatusBadge group="article" :value="row.status" /></template>
        <template #cell-verification="{ row }"><StatusBadge group="verification" :value="row.verificationStatus" /></template>
        <template #cell-seo="{ row }">
          <span v-if="row.seoScore" class="inline-grid h-8 w-8 place-items-center rounded-full text-xs font-semibold" :class="row.seoScore >= 80 ? 'bg-success-50 text-success-600' : row.seoScore >= 65 ? 'bg-warning-50 text-warning-600' : 'bg-danger-50 text-danger-600'">{{ row.seoScore }}</span>
          <span v-else class="text-ink-subtle">—</span>
        </template>
        <template #cell-words="{ row }"><span class="tabular-nums text-ink-muted">{{ row.wordCount ? fmtNumber(row.wordCount) : '—' }}</span></template>
        <template #cell-updated="{ row }"><span class="whitespace-nowrap text-ink-muted">{{ fmtRelative(row.updatedAt) }}</span></template>
        <template #empty-action><AppButton size="sm" :disabled="!sites.length" @click="openNew">Viết bài mới</AppButton></template>
      </DataTable>
    </AppCard>

    <AppModal :open="newOpen" title="Viết bài mới với AI" width="lg" @close="newOpen = false">
      <form v-if="!job" id="new-article" class="space-y-4" novalidate @submit.prevent="start">
        <div class="flex gap-2 rounded-lg bg-surface-muted p-1 text-sm" role="radiogroup" aria-label="Nguồn bài viết">
          <button type="button" role="radio" :aria-checked="form.values.mode === 'idea'" class="flex-1 rounded-md px-3 py-1.5" :class="form.values.mode === 'idea' ? 'bg-surface font-medium shadow-sm' : 'text-ink-muted'" @click="form.values.mode = 'idea'">Từ ý tưởng có sẵn</button>
          <button type="button" role="radio" :aria-checked="form.values.mode === 'custom'" class="flex-1 rounded-md px-3 py-1.5" :class="form.values.mode === 'custom' ? 'bg-surface font-medium shadow-sm' : 'text-ink-muted'" @click="form.values.mode = 'custom'">Tự nhập tiêu đề</button>
        </div>
        <AppSelect v-if="form.values.mode === 'idea'" v-model="form.values.ideaId" label="Ý tưởng" :error="form.errors.ideaId" :options="[{ value: '', label: ideaOptions.length ? '— Chọn ý tưởng —' : 'Chưa có ý tưởng khả dụng' }, ...ideaOptions.map((i) => ({ value: i.id, label: `${i.title} (điểm ${i.score})` }))]" />
        <template v-else>
          <AppInput v-model="form.values.title" label="Tiêu đề" :error="form.errors.title" placeholder="Ví dụ: Cách chọn sofa cho phòng khách nhỏ" />
          <AppSelect v-model="form.values.siteId" label="Website" :error="form.errors.siteId" :options="[{ value: '', label: '— Chọn —' }, ...sites.map((s) => ({ value: s.id, label: s.name }))]" />
        </template>
        <div class="grid gap-4 sm:grid-cols-2">
          <AppInput v-model="form.values.length" type="number" label="Độ dài mục tiêu (từ)" :error="form.errors.length" />
          <AppInput v-model="form.values.tone" label="Giọng văn" />
        </div>
        <p class="rounded-lg bg-surface-muted p-3 text-xs text-ink-muted">Chi phí ước tính: 25 credit (mô phỏng). Bản nháp phải qua kiểm chứng nguồn trước khi có thể duyệt và đăng.</p>
      </form>
      <JobProgress v-else :progress="job.progress.value" :step="job.step.value" :running="job.running.value" :failed="job.failed.value" title="Viết bài" />
      <template #footer>
        <AppButton variant="secondary" @click="newOpen = false">{{ job && !job.running.value ? 'Đóng' : 'Hủy' }}</AppButton>
        <AppButton v-if="!job" type="submit" form="new-article"><AppIcon name="sparkles" :size="16" /> Bắt đầu viết</AppButton>
        <AppButton v-else-if="job.failed.value" @click="job = null">Thử lại</AppButton>
      </template>
    </AppModal>
  </div>
</template>
