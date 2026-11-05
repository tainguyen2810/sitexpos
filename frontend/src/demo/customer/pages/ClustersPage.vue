<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
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
import Drawer from '../../ui/Drawer.vue'
import DTextarea from '../../ui/DTextarea.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import { db, demoMutation, newId } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { options } from '../../labels'
import type { DemoCluster } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { ws, base, mine, siteName, campaignName, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const clusters = computed(() => mine(db.clusters))
const keywords = computed(() => mine(db.keywords))
const info = (c: DemoCluster) => {
  const kws = db.keywords.filter((k) => k.clusterId === c.id)
  const arts = db.articles.filter((a) => a.clusterId === c.id)
  return {
    pillar: db.keywords.find((k) => k.id === c.pillarKeywordId),
    kws,
    arts,
    published: arts.filter((a) => a.status === 'published').length,
    ideas: db.ideas.filter((i) => i.clusterId === c.id).length,
  }
}
const table = useTable(() => clusters.value, {
  search: (c) => `${c.name} ${c.description}`,
  filters: { initial: { status: '' }, match: { status: (c, v) => c.status === v } },
  sorts: { name: (c) => c.name, size: (c) => info(c).kws.length, coverage: (c) => (info(c).kws.length ? info(c).published / info(c).kws.length : 0) },
  defaultSort: { key: 'size', dir: 'desc' },
  pageSize: 6,
})

const open = ref<DemoCluster | null>(clusters.value.find((c) => c.id === route.query.open) ?? null)
const openInfo = computed(() => (open.value ? info(open.value) : null))
// Radial layout for the cluster map
const nodes = computed(() => {
  const list = openInfo.value?.kws.filter((k) => k.id !== open.value?.pillarKeywordId) ?? []
  return list.map((k, i) => {
    const a = (i / Math.max(1, list.length)) * Math.PI * 2 - Math.PI / 2
    const covered = db.articles.some((x) => x.keywordId === k.id && x.status === 'published')
    return { k, x: 160 + Math.cos(a) * 115, y: 130 + Math.sin(a) * 95, covered }
  })
})

const formOpen = ref(false)
const editing = ref<DemoCluster | null>(null)
const form = useForm(() => ({ name: '', pillarKeywordId: '', description: '', keywordIds: [] as string[] }), {
  name: [rules.required(), rules.minLength(3), rules.maxLength(80)],
  pillarKeywordId: [rules.required('Chọn từ khóa trụ cột (pillar).')],
  keywordIds: [(v) => ((v as string[]).length >= 2 ? true : 'Chọn ít nhất 2 từ khóa vệ tinh.')],
})
const kwFilter = ref('')
const pickable = computed(() => keywords.value.filter((k) => k.status !== 'rejected' && k.id !== form.values.pillarKeywordId && k.keyword.includes(kwFilter.value.toLowerCase())))
function openCreate() {
  editing.value = null
  form.reset()
  kwFilter.value = ''
  formOpen.value = true
}
function openEdit(c: DemoCluster) {
  editing.value = c
  form.reset({ name: c.name, pillarKeywordId: c.pillarKeywordId, description: c.description, keywordIds: db.keywords.filter((k) => k.clusterId === c.id && k.id !== c.pillarKeywordId).map((k) => k.id) })
  formOpen.value = true
}
async function save() {
  await form
    .submit(async () => {
      await demoMutation(() => {
        const v = form.values
        const pillar = db.keywords.find((k) => k.id === v.pillarKeywordId)!
        let c = editing.value
        if (c) {
          Object.assign(c, { name: v.name.trim(), pillarKeywordId: v.pillarKeywordId, description: v.description })
        } else {
          c = { id: newId('cl'), workspaceId: ws.value.id, siteId: pillar.siteId, campaignId: pillar.campaignId, name: v.name.trim(), pillarKeywordId: v.pillarKeywordId, description: v.description, status: 'active', createdAt: new Date().toISOString() }
          db.clusters.push(c)
        }
        const id = c.id
        db.keywords.forEach((k) => {
          if (k.clusterId === id && !v.keywordIds.includes(k.id) && k.id !== v.pillarKeywordId) k.clusterId = null
        })
        ;[v.pillarKeywordId, ...v.keywordIds].forEach((kid) => {
          const k = db.keywords.find((x) => x.id === kid)
          if (k) k.clusterId = id
        })
      })
      toast.success(editing.value ? 'Đã cập nhật cụm chủ đề.' : 'Đã tạo cụm chủ đề mới.')
      formOpen.value = false
    })
    .catch((e: Error) => toast.error(e.message))
}
function toggleKw(id: string) {
  const list = form.values.keywordIds
  form.values.keywordIds = list.includes(id) ? list.filter((x) => x !== id) : [...list, id]
}

const archiving = ref<DemoCluster | null>(null)
async function archive() {
  const c = archiving.value
  if (!c) return
  try {
    await demoMutation(() => (c.status = c.status === 'archived' ? 'active' : 'archived'))
    toast.success(c.status === 'archived' ? 'Đã lưu trữ cụm chủ đề.' : 'Đã khôi phục cụm chủ đề.')
  } catch (e) {
    toast.error((e as Error).message)
  }
  archiving.value = null
}
</script>

<template>
  <div>
    <PageHeader title="Topic Cluster" description="Tổ chức nội dung theo mô hình trụ cột – vệ tinh để tăng độ phủ chủ đề và liên kết nội bộ." :crumbs="crumbs({ label: 'Topic Cluster' })">
      <template #actions>
        <AppButton :disabled="!keywords.length" @click="openCreate"><AppIcon name="plus" :size="16" /> Tạo cụm chủ đề</AppButton>
      </template>
    </PageHeader>

    <AppCard :padded="false" class="mb-4">
      <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
        <SearchInput v-model="table.q.value" placeholder="Tìm cụm chủ đề…" />
        <FilterSelect v-model="table.filters.status" label="Trạng thái" :options="options('cluster')" />
        <template #right>
          <select v-model="table.sortKey.value" class="rounded-lg border border-line bg-surface py-2 pl-3 pr-8 text-sm" aria-label="Sắp xếp">
            <option value="size">Nhiều từ khóa nhất</option>
            <option value="coverage">Độ phủ cao nhất</option>
            <option value="name">Tên</option>
          </select>
        </template>
      </Toolbar>
    </AppCard>

    <AppCard v-if="loading"><LoadingState :rows="4" /></AppCard>
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <AppCard v-else-if="!table.total.value">
      <EmptyState :title="table.hasActiveFilters.value ? 'Không có cụm phù hợp' : 'Chưa có cụm chủ đề'" description="Nhóm các từ khóa liên quan quanh một từ khóa trụ cột." icon="layers">
        <AppButton v-if="keywords.length && !table.hasActiveFilters.value" size="sm" @click="openCreate">Tạo cụm đầu tiên</AppButton>
        <AppButton v-else-if="!keywords.length" size="sm" @click="router.push(`${base}/keywords`)">Thêm từ khóa trước</AppButton>
      </EmptyState>
    </AppCard>
    <template v-else>
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <article v-for="c in table.rows.value" :key="c.id" class="flex flex-col rounded-xl border border-line bg-surface p-5 shadow-sm" :class="c.status === 'archived' ? 'opacity-70' : ''">
          <div class="mb-2 flex items-start justify-between gap-2">
            <button type="button" class="text-left font-semibold hover:text-brand-700" @click="open = c">{{ c.name }}</button>
            <StatusBadge group="cluster" :value="c.status" />
          </div>
          <p class="mb-3 line-clamp-2 text-sm text-ink-muted">{{ c.description }}</p>
          <p class="mb-1 text-xs text-ink-muted">Trụ cột: <strong class="text-ink">{{ info(c).pillar?.keyword ?? '—' }}</strong></p>
          <p class="mb-3 text-xs text-ink-muted">{{ siteName(c.siteId) }}<span v-if="c.campaignId"> · {{ campaignName(c.campaignId) }}</span></p>
          <div class="mb-1 flex justify-between text-xs"><span class="text-ink-muted">Độ phủ (bài đã đăng / từ khóa)</span><span class="font-medium">{{ info(c).published }}/{{ info(c).kws.length }}</span></div>
          <ProgressBar :value="info(c).published" :max="Math.max(1, info(c).kws.length)" tone="success" label="Độ phủ cụm" />
          <div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-3">
            <AppButton size="sm" variant="secondary" @click="open = c"><AppIcon name="layers" :size="14" /> Sơ đồ</AppButton>
            <AppButton size="sm" variant="ghost" @click="openEdit(c)"><AppIcon name="edit" :size="14" /> Sửa</AppButton>
            <AppButton size="sm" variant="ghost" class="ml-auto" @click="archiving = c">{{ c.status === 'archived' ? 'Khôi phục' : 'Lưu trữ' }}</AppButton>
          </div>
        </article>
      </div>
      <AppCard :padded="false" class="mt-4">
        <Pagination v-model:page="table.page.value" v-model:page-size="table.pageSize.value" :total="table.total.value" :page-count="table.pageCount.value" :sizes="[6, 12]" />
      </AppCard>
    </template>

    <Drawer :open="!!open" :title="open?.name ?? ''" :subtitle="open ? siteName(open.siteId) : ''" @close="open = null">
      <template v-if="open && openInfo">
        <svg viewBox="0 0 320 260" class="mb-4 w-full rounded-lg bg-surface-muted" role="img" aria-label="Sơ đồ cụm chủ đề">
          <line v-for="n in nodes" :key="`l${n.k.id}`" x1="160" y1="130" :x2="n.x" :y2="n.y" stroke="#cbd5e1" />
          <g v-for="n in nodes" :key="n.k.id">
            <circle :cx="n.x" :cy="n.y" r="7" :fill="n.covered ? '#1baf7a' : '#ffffff'" stroke="#2a78d6" stroke-width="2" />
            <text :x="n.x" :y="n.y + 18" text-anchor="middle" font-size="8" fill="#475569">{{ n.k.keyword.length > 22 ? `${n.k.keyword.slice(0, 22)}…` : n.k.keyword }}</text>
          </g>
          <circle cx="160" cy="130" r="26" fill="#2a78d6" />
          <text x="160" y="133" text-anchor="middle" font-size="8" font-weight="600" fill="#fff">TRỤ CỘT</text>
        </svg>
        <p class="mb-4 flex flex-wrap gap-3 text-xs text-ink-muted">
          <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-[#1baf7a]" /> Đã có bài đăng</span>
          <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full border-2 border-[#2a78d6] bg-white" /> Chưa có bài</span>
        </p>
        <h3 class="mb-2 text-sm font-semibold">Trụ cột: {{ openInfo.pillar?.keyword }}</h3>
        <ul class="mb-4 divide-y divide-line rounded-lg border border-line">
          <li v-for="k in openInfo.kws" :key="k.id" class="flex items-center gap-2 px-3 py-2 text-sm">
            <span class="min-w-0 flex-1 truncate">{{ k.keyword }}</span>
            <StatusBadge group="keywordStatus" :value="k.status" />
          </li>
        </ul>
        <h3 class="mb-2 text-sm font-semibold">Bài viết trong cụm ({{ openInfo.arts.length }})</h3>
        <p v-if="!openInfo.arts.length" class="text-sm text-ink-muted">Chưa có bài viết.</p>
        <ul v-else class="space-y-1">
          <li v-for="a in openInfo.arts" :key="a.id"><RouterLink :to="`${base}/articles/${a.id}`" class="flex items-center gap-2 text-sm hover:text-brand-700"><span class="min-w-0 flex-1 truncate">{{ a.title }}</span><StatusBadge group="article" :value="a.status" /></RouterLink></li>
        </ul>
      </template>
      <template #footer>
        <AppButton variant="secondary" @click="router.push(`${base}/topics`)"><AppIcon name="sparkles" :size="16" /> Tìm ý tưởng cho cụm</AppButton>
        <AppButton v-if="open" @click="openEdit(open)">Sửa cụm</AppButton>
      </template>
    </Drawer>

    <AppModal :open="formOpen" :title="editing ? 'Sửa cụm chủ đề' : 'Tạo cụm chủ đề'" width="lg" @close="formOpen = false">
      <form id="cluster-form" class="space-y-4" novalidate @submit.prevent="save">
        <AppInput v-model="form.values.name" label="Tên cụm" :error="form.errors.name" placeholder="Ví dụ: Chủ đề sofa căn hộ" />
        <AppSelect v-model="form.values.pillarKeywordId" label="Từ khóa trụ cột" :error="form.errors.pillarKeywordId" :options="[{ value: '', label: '— Chọn —' }, ...keywords.filter((k) => k.status !== 'rejected').map((k) => ({ value: k.id, label: k.keyword }))]" />
        <DTextarea v-model="form.values.description" label="Mô tả" :rows="2" :maxlength="200" />
        <div>
          <p class="mb-1.5 text-sm font-medium">Từ khóa vệ tinh <span class="text-ink-muted">({{ form.values.keywordIds.length }} đã chọn)</span></p>
          <input v-model="kwFilter" type="search" placeholder="Lọc từ khóa…" class="mb-2 w-full rounded-lg border border-line px-3 py-1.5 text-sm" aria-label="Lọc từ khóa" />
          <div class="max-h-48 overflow-y-auto rounded-lg border border-line p-2">
            <label v-for="k in pickable" :key="k.id" class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-surface-muted">
              <input type="checkbox" :checked="form.values.keywordIds.includes(k.id)" @change="toggleKw(k.id)" />
              <span class="flex-1">{{ k.keyword }}</span>
              <span v-if="k.clusterId && k.clusterId !== editing?.id" class="text-[11px] text-ink-subtle">đang ở cụm khác</span>
            </label>
          </div>
          <p v-if="form.errors.keywordIds" class="mt-1 text-xs text-danger-600">{{ form.errors.keywordIds }}</p>
        </div>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="formOpen = false">Hủy</AppButton>
        <AppButton type="submit" form="cluster-form" :loading="form.submitting.value">{{ editing ? 'Lưu' : 'Tạo cụm' }}</AppButton>
      </template>
    </AppModal>

    <ConfirmDialog :open="!!archiving" :title="archiving?.status === 'archived' ? 'Khôi phục cụm?' : 'Lưu trữ cụm chủ đề?'" message="Từ khóa và bài viết trong cụm không bị xóa." :confirm-label="archiving?.status === 'archived' ? 'Khôi phục' : 'Lưu trữ'" @close="archiving = null" @confirm="archive" />
  </div>
</template>
