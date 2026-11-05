<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { useRoute } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import JobProgress from '../../ui/JobProgress.vue'
import DonutChart from '../../ui/charts/DonutChart.vue'
import { db, demoMutation, newId, simulateJob } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { label, options } from '../../labels'
import { fmtDateTime } from '../../format'
import type { DemoSeoIssue } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const route = useRoute()
const toast = useToast()
const { ws, sites, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const auditable = computed(() => sites.value.filter((s) => s.status !== 'pending_pairing' && s.status !== 'revoked'))
const siteId = ref(typeof route.query.site === 'string' ? route.query.site : (auditable.value[0]?.id ?? ''))
const audit = computed(() => db.seoAudits.find((a) => a.siteId === siteId.value))
const issues = computed(() => audit.value?.issues ?? [])
const table = useTable(() => issues.value, {
  search: (i) => `${i.title} ${i.pageUrl}`,
  filters: { initial: { severity: '', category: '', status: 'open' }, match: { severity: (i, v) => i.severity === v, category: (i, v) => i.category === v, status: (i, v) => i.status === v } },
  sorts: { severity: (i) => ({ critical: 0, warning: 1, notice: 2 })[i.severity], title: (i) => i.title },
  defaultSort: { key: 'severity', dir: 'asc' },
})
const columns = [
  { key: 'title', label: 'Vấn đề', sortable: true },
  { key: 'severity', label: 'Mức độ', sortable: true },
  { key: 'category', label: 'Nhóm', hideBelow: 'md' as const },
  { key: 'status', label: 'Trạng thái', hideBelow: 'sm' as const },
  { key: 'actions', label: '', align: 'right' as const },
]
const categories = [
  ['technical', 'Kỹ thuật'],
  ['content', 'Nội dung'],
  ['performance', 'Hiệu năng'],
  ['links', 'Liên kết'],
  ['schema', 'Schema'],
] as const
const byCategory = computed(() => categories.map(([k, l]) => ({ name: l, value: issues.value.filter((i) => i.category === k && i.status === 'open').length })).filter((x) => x.value))
const scoreColor = computed(() => ((audit.value?.score ?? 0) >= 80 ? '#1baf7a' : (audit.value?.score ?? 0) >= 60 ? '#eda100' : '#e34948'))

function rescore() {
  const a = audit.value
  if (!a) return
  const penalty = a.issues.filter((i) => i.status === 'open').reduce((s, i) => s + (i.severity === 'critical' ? 9 : i.severity === 'warning' ? 4 : 1), 0)
  a.score = Math.max(35, 100 - penalty)
}
async function setStatus(i: DemoSeoIssue, status: DemoSeoIssue['status']) {
  try {
    await demoMutation(() => {
      i.status = status
      rescore()
    })
    toast.success(status === 'fixed' ? 'Đã đánh dấu đã sửa.' : status === 'ignored' ? 'Đã bỏ qua vấn đề.' : 'Đã mở lại vấn đề.')
  } catch (e) {
    toast.error((e as Error).message)
  }
}
const detail = ref<DemoSeoIssue | null>(null)

const job = shallowRef<ReturnType<typeof simulateJob> | null>(null)
function rescan() {
  const site = auditable.value.find((s) => s.id === siteId.value)
  if (!site) return
  if (site.status === 'disconnected') {
    toast.error('Website mất kết nối – không thể quét. Kiểm tra plugin trước.')
    return
  }
  job.value = simulateJob(['Đọc sitemap…', 'Thu thập trang (crawl qua SsrfGuard)…', 'Phân tích kỹ thuật & nội dung…', 'Tính điểm…'], () => {
    let a = audit.value
    if (!a) {
      db.seoAudits.push({ id: newId('aud'), workspaceId: ws.value.id, siteId: site.id, score: 100, pagesCrawled: 0, createdAt: '', status: 'completed', issues: [] })
      a = db.seoAudits[db.seoAudits.length - 1]!
    }
    // Simulate: some open notices got fixed, one new issue found
    a.issues.filter((i) => i.status === 'open' && i.severity === 'notice').slice(0, 1).forEach((i) => (i.status = 'fixed'))
    a.issues.unshift({ id: newId('iss'), siteId: site.id, category: 'content', severity: 'warning', title: 'Bài viết mới thiếu liên kết nội bộ', pageUrl: `${site.url}/blog/`, recommendation: 'Thêm 2–3 liên kết tới bài cùng cụm chủ đề.', status: 'open' })
    a.createdAt = new Date().toISOString()
    a.pagesCrawled = site.postsCount + 18
    a.status = 'completed'
    rescore()
    job.value = null
    toast.success(`Quét xong ${site.name}: điểm ${a.score}/100.`)
  })
}
</script>

<template>
  <div>
    <PageHeader title="SEO Audit" description="Quét kỹ thuật và nội dung website để tìm vấn đề SEO. Điểm số là checklist tham khảo, không bảo đảm thứ hạng." :crumbs="crumbs({ label: 'SEO Audit' })">
      <template #actions>
        <label class="text-sm">
          <span class="sr-only">Website</span>
          <select v-model="siteId" class="rounded-lg border border-line bg-surface py-2 pl-3 pr-8 text-sm">
            <option v-for="s in auditable" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <AppButton :disabled="!siteId || !!job" @click="rescan"><AppIcon name="refresh" :size="16" /> Quét lại</AppButton>
      </template>
    </PageHeader>

    <AppCard v-if="!auditable.length">
      <EmptyState title="Chưa có website để quét" description="Kết nối website WordPress để chạy SEO Audit." icon="activity" />
    </AppCard>
    <template v-else>
      <JobProgress v-if="job" class="mb-4" :progress="job.progress.value" :step="job.step.value" :running="job.running.value" :failed="job.failed.value" title="Quét SEO" />
      <div class="mb-6 grid gap-4 lg:grid-cols-3">
        <AppCard title="Điểm SEO">
          <div v-if="audit" class="flex items-center gap-5">
            <svg viewBox="0 0 36 36" class="h-28 w-28 -rotate-90" role="img" :aria-label="`Điểm ${audit.score}/100`">
              <circle cx="18" cy="18" r="15.9" fill="none" stroke="#f1f5f9" stroke-width="3" />
              <circle cx="18" cy="18" r="15.9" fill="none" :stroke="scoreColor" stroke-width="3" stroke-linecap="round" :stroke-dasharray="`${audit.score} 100`" />
            </svg>
            <div>
              <p class="text-3xl font-semibold tabular-nums">{{ audit.score }}<span class="text-base text-ink-muted">/100</span></p>
              <p class="text-sm text-ink-muted">{{ audit.pagesCrawled }} trang đã quét</p>
              <p class="text-xs text-ink-subtle">Lần quét: {{ fmtDateTime(audit.createdAt) }}</p>
              <StatusBadge class="mt-1" group="audit" :value="audit.status" />
            </div>
          </div>
          <p v-else class="text-sm text-ink-muted">Chưa quét website này. Bấm “Quét lại”.</p>
        </AppCard>
        <AppCard title="Vấn đề đang mở theo nhóm">
          <DonutChart v-if="byCategory.length" :segments="byCategory" center-label="vấn đề" :size="120" />
          <p v-else class="text-sm text-success-600">Không còn vấn đề nào đang mở 🎉</p>
        </AppCard>
        <AppCard title="Mức độ">
          <ul class="space-y-3 text-sm">
            <li v-for="sev in ['critical', 'warning', 'notice'] as const" :key="sev" class="flex items-center gap-3">
              <StatusBadge group="severity" :value="sev" />
              <span class="ml-auto font-semibold tabular-nums">{{ issues.filter((i) => i.severity === sev && i.status === 'open').length }}</span>
              <button type="button" class="text-xs text-brand-700 hover:underline" @click="table.filters.severity = sev; table.filters.status = 'open'">Lọc</button>
            </li>
          </ul>
        </AppCard>
      </div>

      <AppCard :padded="false">
        <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
          <SearchInput v-model="table.q.value" placeholder="Tìm vấn đề, URL…" />
          <FilterSelect v-model="table.filters.severity" label="Mức độ" :options="[{ value: '', label: 'Tất cả' }, { value: 'critical', label: 'Nghiêm trọng' }, { value: 'warning', label: 'Cảnh báo' }, { value: 'notice', label: 'Lưu ý' }]" />
          <FilterSelect v-model="table.filters.category" label="Nhóm" :options="[{ value: '', label: 'Tất cả' }, ...categories.map(([v, l]) => ({ value: v, label: l }))]" />
          <FilterSelect v-model="table.filters.status" label="Trạng thái" :options="options('issueStatus')" />
        </Toolbar>
        <DataTable :table="table" :columns="columns" :loading="loading" :error="error" clickable empty-title="Không có vấn đề" @retry="reload" @row-click="(i) => (detail = i)">
          <template #cell-title="{ row }">
            <p class="font-medium">{{ row.title }}</p>
            <p class="truncate text-xs text-ink-muted">{{ row.pageUrl }}</p>
          </template>
          <template #cell-severity="{ row }"><StatusBadge group="severity" :value="row.severity" /></template>
          <template #cell-category="{ row }"><span class="text-ink-muted">{{ categories.find(([k]) => k === row.category)?.[1] }}</span></template>
          <template #cell-status="{ row }"><StatusBadge group="issueStatus" :value="row.status" /></template>
          <template #cell-actions="{ row }">
            <div class="flex justify-end gap-1" @click.stop>
              <template v-if="row.status === 'open'">
                <AppButton size="sm" variant="secondary" @click="setStatus(row, 'fixed')">Đã sửa</AppButton>
                <AppButton size="sm" variant="ghost" @click="setStatus(row, 'ignored')">Bỏ qua</AppButton>
              </template>
              <AppButton v-else size="sm" variant="ghost" @click="setStatus(row, 'open')">Mở lại</AppButton>
            </div>
          </template>
        </DataTable>
      </AppCard>
    </template>

    <AppModal :open="!!detail" :title="detail?.title ?? ''" @close="detail = null">
      <template v-if="detail">
        <div class="mb-3 flex gap-2"><StatusBadge group="severity" :value="detail.severity" /><StatusBadge group="issueStatus" :value="detail.status" /></div>
        <p class="mb-1 text-sm font-medium">Trang bị ảnh hưởng</p>
        <a :href="detail.pageUrl" target="_blank" rel="noopener noreferrer" class="mb-4 block truncate text-sm text-brand-700 hover:underline">{{ detail.pageUrl }}</a>
        <p class="mb-1 text-sm font-medium">Khuyến nghị</p>
        <p class="text-sm text-ink-muted">{{ detail.recommendation }}</p>
        <p class="mt-4 text-xs text-ink-subtle">Nhóm: {{ categories.find(([k]) => k === detail?.category)?.[1] }} · {{ label('severity', detail.severity) }}</p>
      </template>
      <template #footer>
        <AppButton variant="secondary" @click="detail = null">Đóng</AppButton>
        <AppButton v-if="detail?.status === 'open'" @click="setStatus(detail, 'fixed'); detail = null">Đánh dấu đã sửa</AppButton>
      </template>
    </AppModal>
  </div>
</template>
