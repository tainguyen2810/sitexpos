<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import ProgressBar from '../../ui/ProgressBar.vue'
import Pagination from '../../ui/Pagination.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import CampaignFormModal from '../components/CampaignFormModal.vue'
import { db, demoMutation } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { options } from '../../labels'
import { fmtDate } from '../../format'
import type { DemoCampaign } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { base, mine, sites, siteName, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const stats = (c: DemoCampaign) => {
  const arts = db.articles.filter((a) => a.campaignId === c.id)
  return {
    published: arts.filter((a) => a.status === 'published').length,
    inProgress: arts.filter((a) => a.status !== 'published').length,
    keywords: db.keywords.filter((k) => k.campaignId === c.id).length,
    ideas: db.ideas.filter((i) => i.campaignId === c.id).length,
  }
}
const table = useTable(() => mine(db.campaigns), {
  search: (c) => `${c.name} ${c.goal}`,
  filters: { initial: { status: '', site: '' }, match: { status: (c, v) => c.status === v, site: (c, v) => c.siteId === v } },
  sorts: { start: (c) => c.startDate, name: (c) => c.name, progress: (c) => stats(c).published / c.targetArticles },
  defaultSort: { key: 'start', dir: 'desc' },
  pageSize: 6,
})
const sortChoice = computed({
  get: () => `${table.sortKey.value}:${table.sortDir.value}`,
  set: (v: string) => {
    const [k, d] = v.split(':')
    table.sortKey.value = k!
    table.sortDir.value = d as 'asc' | 'desc'
  },
})

const formOpen = ref(route.query.new === '1')
const editing = ref<DemoCampaign | null>(null)
function openCreate() {
  editing.value = null
  formOpen.value = true
}
function openEdit(c: DemoCampaign) {
  editing.value = c
  formOpen.value = true
}
async function setStatus(c: DemoCampaign, status: DemoCampaign['status']) {
  try {
    await demoMutation(() => (c.status = status))
    toast.success(status === 'active' ? `Đã kích hoạt "${c.name}".` : status === 'paused' ? `Đã tạm dừng "${c.name}".` : `Đã cập nhật "${c.name}".`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}
const deleting = ref<DemoCampaign | null>(null)
async function confirmDelete() {
  const c = deleting.value
  if (!c) return
  try {
    await demoMutation(() => {
      db.campaigns.splice(db.campaigns.indexOf(c), 1)
      db.keywords.forEach((k) => k.campaignId === c.id && (k.campaignId = null))
      db.ideas.forEach((i) => i.campaignId === c.id && (i.campaignId = null))
      db.articles.forEach((a) => a.campaignId === c.id && (a.campaignId = null))
    })
    toast.success('Đã xóa chiến dịch. Từ khóa, ý tưởng và bài viết vẫn được giữ lại.')
  } catch (e) {
    toast.error((e as Error).message)
  }
  deleting.value = null
}
</script>

<template>
  <div>
    <PageHeader title="Chiến dịch SEO" description="Gom từ khóa, cụm chủ đề, lịch đăng và bài viết theo từng mục tiêu kinh doanh." :crumbs="crumbs({ label: 'Chiến dịch SEO' })">
      <template #actions>
        <AppButton :disabled="!sites.length" @click="openCreate"><AppIcon name="plus" :size="16" /> Tạo chiến dịch</AppButton>
      </template>
    </PageHeader>

    <AppCard :padded="false" class="mb-4">
      <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
        <SearchInput v-model="table.q.value" placeholder="Tìm chiến dịch…" />
        <FilterSelect v-model="table.filters.status" label="Trạng thái" :options="options('campaign')" />
        <FilterSelect v-model="table.filters.site" label="Website" :options="[{ value: '', label: 'Tất cả' }, ...sites.map((s) => ({ value: s.id, label: s.name }))]" />
        <template #right>
          <label class="flex items-center gap-1 text-sm">
            <span class="sr-only">Sắp xếp</span>
            <select v-model="sortChoice" class="rounded-lg border border-line bg-surface py-2 pl-3 pr-8 text-sm">
              <option value="start:desc">Mới nhất</option>
              <option value="start:asc">Cũ nhất</option>
              <option value="name:asc">Tên A–Z</option>
              <option value="progress:desc">Tiến độ cao nhất</option>
            </select>
          </label>
        </template>
      </Toolbar>
    </AppCard>

    <AppCard v-if="loading"><LoadingState :rows="4" /></AppCard>
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <AppCard v-else-if="!table.total.value">
      <EmptyState :title="table.hasActiveFilters.value ? 'Không có chiến dịch phù hợp' : 'Chưa có chiến dịch nào'" :description="sites.length ? 'Chiến dịch giúp bạn lập kế hoạch nội dung theo mục tiêu cụ thể.' : 'Hãy kết nối website trước khi tạo chiến dịch.'" icon="target">
        <AppButton v-if="table.hasActiveFilters.value" variant="secondary" size="sm" @click="table.clearFilters()">Xóa bộ lọc</AppButton>
        <AppButton v-else-if="sites.length" size="sm" @click="openCreate">Tạo chiến dịch đầu tiên</AppButton>
        <AppButton v-else size="sm" @click="router.push(`${base}/connect`)">Kết nối website</AppButton>
      </EmptyState>
    </AppCard>
    <template v-else>
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <article v-for="c in table.rows.value" :key="c.id" class="flex flex-col rounded-xl border border-line bg-surface p-5 shadow-sm">
          <div class="mb-3 flex items-start justify-between gap-2">
            <RouterLink :to="`${base}/campaigns/${c.id}`" class="font-semibold text-ink hover:text-brand-700">{{ c.name }}</RouterLink>
            <StatusBadge group="campaign" :value="c.status" />
          </div>
          <p class="mb-3 line-clamp-2 text-sm text-ink-muted">{{ c.goal }}</p>
          <p class="mb-3 flex items-center gap-1.5 text-xs text-ink-muted"><AppIcon name="globe" :size="14" />{{ siteName(c.siteId) }} · {{ fmtDate(c.startDate) }} – {{ fmtDate(c.endDate) }}</p>
          <div class="mb-1 flex justify-between text-xs"><span class="text-ink-muted">Đã đăng</span><span class="font-medium tabular-nums">{{ stats(c).published }}/{{ c.targetArticles }} bài</span></div>
          <ProgressBar :value="stats(c).published" :max="c.targetArticles" tone="success" label="Tiến độ chiến dịch" />
          <dl class="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
            <div class="rounded-lg bg-surface-muted py-2"><dt class="text-ink-muted">Từ khóa</dt><dd class="text-base font-semibold">{{ stats(c).keywords }}</dd></div>
            <div class="rounded-lg bg-surface-muted py-2"><dt class="text-ink-muted">Ý tưởng</dt><dd class="text-base font-semibold">{{ stats(c).ideas }}</dd></div>
            <div class="rounded-lg bg-surface-muted py-2"><dt class="text-ink-muted">Đang làm</dt><dd class="text-base font-semibold">{{ stats(c).inProgress }}</dd></div>
          </dl>
          <div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-3">
            <AppButton size="sm" variant="secondary" @click="router.push(`${base}/campaigns/${c.id}`)">Chi tiết</AppButton>
            <AppButton v-if="c.status === 'active'" size="sm" variant="ghost" @click="setStatus(c, 'paused')"><AppIcon name="pause" :size="14" /> Tạm dừng</AppButton>
            <AppButton v-else-if="c.status !== 'completed'" size="sm" variant="ghost" @click="setStatus(c, 'active')"><AppIcon name="play" :size="14" /> Kích hoạt</AppButton>
            <AppButton size="sm" variant="ghost" title="Sửa" @click="openEdit(c)"><AppIcon name="edit" :size="14" /></AppButton>
            <AppButton size="sm" variant="ghost" title="Xóa" class="ml-auto" @click="deleting = c"><AppIcon name="trash" :size="14" /></AppButton>
          </div>
        </article>
      </div>
      <AppCard :padded="false" class="mt-4">
        <Pagination v-model:page="table.page.value" v-model:page-size="table.pageSize.value" :total="table.total.value" :page-count="table.pageCount.value" :sizes="[6, 12, 24]" />
      </AppCard>
    </template>

    <CampaignFormModal :open="formOpen" :campaign="editing" @close="formOpen = false" @saved="(id) => !editing && router.push(`${base}/campaigns/${id}`)" />
    <ConfirmDialog :open="!!deleting" title="Xóa chiến dịch?" :message="`Xóa “${deleting?.name}”. Từ khóa, ý tưởng và bài viết liên quan không bị xóa.`" confirm-label="Xóa" danger @close="deleting = null" @confirm="confirmDelete" />
  </div>
</template>
