<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import AppCard from '@/shared/ui/AppCard.vue'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatCard from '../../ui/StatCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import { db } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { fmtDateTime } from '../../format'
import type { DemoFactCheck } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const router = useRouter()
const { base, mine, siteName, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const reports = computed(() => mine(db.factChecks))
const art = (r: DemoFactCheck) => db.articles.find((a) => a.id === r.articleId)
const count = (r: DemoFactCheck, s: string) => r.claims.filter((c) => c.status === s).length
const outcome = (r: DemoFactCheck) => (r.overriddenBy ? 'overridden' : r.result)
const table = useTable(() => reports.value, {
  search: (r) => art(r)?.title ?? '',
  filters: {
    initial: { result: '', attention: '' },
    match: { result: (r, v) => outcome(r) === v, attention: (r, v) => (v === 'human' ? count(r, 'needs_human') > 0 : count(r, 'unsupported') + count(r, 'contradicted') > 0) },
  },
  sorts: { date: (r) => r.createdAt, score: (r) => r.score, claims: (r) => r.claims.length },
  defaultSort: { key: 'date', dir: 'desc' },
})
const columns = [
  { key: 'article', label: 'Bài viết' },
  { key: 'result', label: 'Kết quả' },
  { key: 'score', label: 'Tỷ lệ có nguồn', sortable: true },
  { key: 'claims', label: 'Nhận định', sortable: true, hideBelow: 'md' as const },
  { key: 'date', label: 'Thời gian', sortable: true, hideBelow: 'lg' as const },
]
const passRate = computed(() => (reports.value.length ? Math.round((reports.value.filter((r) => r.result === 'passed').length / reports.value.length) * 100) : 0))
</script>

<template>
  <div>
    <PageHeader title="Fact-Check Report" description="Mỗi nhận định quan trọng phải được đối chiếu với nguồn đã tải về. Bài không đạt sẽ không được lên lịch đăng." :crumbs="crumbs({ label: 'Fact-Check Report' })" />
    <div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard label="Báo cáo" :value="reports.length" icon="shieldCheck" />
      <StatCard label="Tỷ lệ đạt" :value="`${passRate}%`" icon="check" />
      <StatCard label="Cần người kiểm tra" :value="reports.reduce((s, r) => s + count(r, 'needs_human'), 0)" icon="user" hint="nhận định" />
      <StatCard label="Không có nguồn / mâu thuẫn" :value="reports.reduce((s, r) => s + count(r, 'unsupported') + count(r, 'contradicted'), 0)" icon="alert" hint="nhận định" />
    </div>
    <AppCard :padded="false">
      <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
        <SearchInput v-model="table.q.value" placeholder="Tìm theo tiêu đề bài…" />
        <FilterSelect v-model="table.filters.result" label="Kết quả" :options="[{ value: '', label: 'Tất cả' }, { value: 'passed', label: 'Đạt' }, { value: 'failed', label: 'Không đạt' }, { value: 'overridden', label: 'Duyệt thủ công' }]" />
        <FilterSelect v-model="table.filters.attention" label="Cần xử lý" :options="[{ value: '', label: 'Tất cả' }, { value: 'human', label: 'Cần người kiểm tra' }, { value: 'missing', label: 'Thiếu nguồn / mâu thuẫn' }]" />
      </Toolbar>
      <DataTable :table="table" :columns="columns" :loading="loading" :error="error" clickable empty-title="Chưa có báo cáo kiểm chứng" empty-description="Báo cáo được tạo khi bạn chạy kiểm chứng trong AI Article Writer." @retry="reload" @row-click="(r) => router.push(`${base}/fact-checks/${r.id}`)">
        <template #cell-article="{ row }">
          <p class="line-clamp-1 font-medium">{{ art(row)?.title ?? '(bài đã xóa)' }}</p>
          <p class="text-xs text-ink-muted">{{ siteName(art(row)?.siteId ?? null) }} · phiên bản v{{ row.versionNo }}</p>
        </template>
        <template #cell-result="{ row }">
          <AppBadge :tone="row.overriddenBy ? 'warning' : row.result === 'passed' ? 'success' : 'danger'">{{ row.overriddenBy ? 'Duyệt thủ công' : row.result === 'passed' ? 'Đạt' : 'Không đạt' }}</AppBadge>
        </template>
        <template #cell-score="{ row }">
          <div class="flex items-center gap-2">
            <span class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full" :class="row.score >= 80 ? 'bg-success-600' : row.score >= 50 ? 'bg-warning-600' : 'bg-danger-600'" :style="{ width: `${row.score}%` }" /></span>
            <span class="tabular-nums">{{ row.score }}%</span>
          </div>
        </template>
        <template #cell-claims="{ row }">
          <span class="text-xs text-ink-muted"><span class="text-success-600">{{ count(row, 'supported') }} ✓</span> · <span class="text-warning-600">{{ count(row, 'needs_human') }} ?</span> · <span class="text-danger-600">{{ count(row, 'unsupported') + count(row, 'contradicted') }} ✗</span></span>
        </template>
        <template #cell-date="{ row }"><span class="whitespace-nowrap text-ink-muted">{{ fmtDateTime(row.createdAt) }}</span></template>
      </DataTable>
    </AppCard>
  </div>
</template>
