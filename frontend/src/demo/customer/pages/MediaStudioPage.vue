<script setup lang="ts">
import { computed, ref, shallowRef, watch } from 'vue'
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
import Pagination from '../../ui/Pagination.vue'
import Drawer from '../../ui/Drawer.vue'
import DTextarea from '../../ui/DTextarea.vue'
import DemoImage from '../../ui/DemoImage.vue'
import JobProgress from '../../ui/JobProgress.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import { db, demoMutation, newId, simulateJob } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { options } from '../../labels'
import { fmtBytes, fmtDate } from '../../format'
import type { DemoMedia } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const toast = useToast()
const { ws, base, mine, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const media = computed(() => mine(db.media))
const table = useTable(() => media.value, {
  search: (m) => `${m.title} ${m.altText}`,
  filters: { initial: { kind: '', moderation: '', usage: '' }, match: { kind: (m, v) => m.kind === v, moderation: (m, v) => m.moderation === v, usage: (m, v) => (v === 'used' ? m.articleIds.length > 0 : m.articleIds.length === 0) } },
  sorts: { date: (m) => m.createdAt, title: (m) => m.title, size: (m) => m.bytes },
  defaultSort: { key: 'date', dir: 'desc' },
  pageSize: 12,
})
const aiCount = computed(() => media.value.filter((m) => m.isAiGenerated).length)
const plan = computed(() => db.plans.find((p) => p.code === ws.value.planCode)!)
const aiUsed = computed(() => db.usage.find((u) => u.workspaceId === ws.value.id && u.metric === 'images_ai')?.used ?? 0)

// Detail / edit
const selected = ref<DemoMedia | null>(null)
const editForm = useForm(() => ({ altText: '', caption: '', representsRealProduct: false }), { altText: [rules.required('Alt text giúp SEO và người khiếm thị.'), rules.minLength(8), rules.maxLength(125)] })
watch(selected, (m) => m && editForm.reset({ altText: m.altText, caption: m.caption, representsRealProduct: m.representsRealProduct }))
async function saveMeta() {
  const m = selected.value
  if (!m) return
  await editForm
    .submit(async () => {
      await demoMutation(() => {
        m.altText = editForm.values.altText
        m.caption = editForm.values.caption
        // AI images can never represent a real product (rule enforced server-side too).
        m.representsRealProduct = m.isAiGenerated ? false : editForm.values.representsRealProduct
      })
      toast.success('Đã lưu thông tin ảnh.')
    })
    .catch((e: Error) => toast.error(e.message))
}
async function moderate(m: DemoMedia, status: DemoMedia['moderation']) {
  try {
    await demoMutation(() => (m.moderation = status))
    toast.success(status === 'approved' ? 'Đã duyệt ảnh.' : 'Đã từ chối ảnh.')
  } catch (e) {
    toast.error((e as Error).message)
  }
}
const deleting = ref<DemoMedia | null>(null)
async function confirmDelete() {
  const m = deleting.value
  if (!m) return
  try {
    await demoMutation(() => {
      db.articles.forEach((a) => a.featuredMediaId === m.id && (a.featuredMediaId = null))
      db.media.splice(db.media.indexOf(m), 1)
    })
    toast.success('Đã xóa ảnh khỏi thư viện.')
    selected.value = null
  } catch (e) {
    toast.error((e as Error).message)
  }
  deleting.value = null
}

// Upload (file stays in the browser; only metadata is stored)
const uploadOpen = ref(false)
const file = ref<File | null>(null)
const uploadForm = useForm(() => ({ altText: '', license: 'owned', real: true }), { altText: [rules.required(), rules.minLength(8)] })
function onFile(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0] ?? null
  if (f && !['image/jpeg', 'image/png', 'image/webp'].includes(f.type)) {
    toast.error('Chỉ hỗ trợ JPG, PNG, WebP.')
    return
  }
  if (f && f.size > 8 * 1024 * 1024) {
    toast.error('Ảnh tối đa 8 MB.')
    return
  }
  file.value = f
  if (f && !uploadForm.values.altText) uploadForm.values.altText = f.name.replace(/\.[^.]+$/, '').replace(/[-_]/g, ' ')
}
async function saveUpload() {
  if (!file.value) {
    toast.error('Chọn một tệp ảnh.')
    return
  }
  await uploadForm
    .submit(async () => {
      const f = file.value!
      await demoMutation(() =>
        db.media.unshift({
          id: newId('med'),
          workspaceId: ws.value.id,
          title: f.name,
          kind: 'customer_upload',
          isAiGenerated: false,
          representsRealProduct: uploadForm.values.real,
          width: 1600,
          height: 1000,
          bytes: f.size,
          altText: uploadForm.values.altText,
          caption: '',
          sourceProvider: 'Khách hàng tải lên (demo – tệp không rời trình duyệt)',
          sourceUrl: null,
          license: uploadForm.values.license === 'owned' ? 'Thuộc sở hữu khách hàng' : 'Được cấp phép sử dụng (khách hàng xác nhận)',
          attribution: ws.value.name,
          moderation: 'approved',
          art: { hue: Math.floor(Math.random() * 360), motif: 3 },
          articleIds: [],
          createdAt: new Date().toISOString(),
        }),
      )
      toast.success('Đã thêm ảnh vào thư viện (demo: chỉ lưu thông tin, xem trước bằng ảnh minh họa).')
      uploadOpen.value = false
      file.value = null
    })
    .catch((e: Error) => toast.error(e.message))
}

// AI image (simulated, always labelled)
const genOpen = ref(false)
const job = shallowRef<ReturnType<typeof simulateJob> | null>(null)
const genForm = useForm(() => ({ prompt: '', style: 'illustration', ratio: '16:10' }), { prompt: [rules.required(), rules.minLength(10), rules.maxLength(400)] })
function openGen() {
  job.value = null
  genForm.reset()
  genOpen.value = true
}
function runGen() {
  if (!genForm.validate()) return
  if (plan.value.limits.images_ai !== -1 && aiUsed.value >= plan.value.limits.images_ai) {
    toast.error('Đã hết hạn mức ảnh AI tháng này. Nâng cấp gói hoặc mua thêm credit.')
    return
  }
  const prompt = genForm.values.prompt
  job.value = simulateJob(['Kiểm duyệt prompt…', 'Tạo ảnh (mô phỏng)…', 'Gắn nhãn “Ảnh AI” và lưu…'], () => {
    db.media.unshift({
      id: newId('med'),
      workspaceId: ws.value.id,
      title: prompt.slice(0, 50),
      kind: 'ai_image',
      isAiGenerated: true,
      representsRealProduct: false,
      width: 1600,
      height: 1000,
      bytes: 260 * 1024,
      altText: prompt.slice(0, 120),
      caption: 'Ảnh minh họa tạo bằng AI (mô phỏng), không phải ảnh thật.',
      sourceProvider: 'AI image (mô phỏng, không gọi API thật)',
      sourceUrl: null,
      license: 'Ảnh AI – chỉ dùng minh họa, không đại diện sản phẩm thật',
      attribution: 'Gắn nhãn: Ảnh do AI tạo',
      moderation: 'pending',
      art: { hue: Math.floor(Math.random() * 360), motif: Math.floor(Math.random() * 5) },
      articleIds: [],
      createdAt: new Date().toISOString(),
    })
    const u = db.usage.find((x) => x.workspaceId === ws.value.id && x.metric === 'images_ai')
    if (u) u.used += 1
    toast.success('Đã tạo ảnh AI (mô phỏng). Ảnh chờ duyệt trước khi dùng.')
  })
}
</script>

<template>
  <div>
    <PageHeader title="Media Studio" description="Thư viện ảnh cho bài viết: ảnh thư viện có giấy phép, ảnh khách hàng tải lên và ảnh AI (luôn gắn nhãn, không dùng làm ảnh sản phẩm thật)." :crumbs="crumbs({ label: 'Media Studio' })">
      <template #actions>
        <AppButton variant="secondary" @click="uploadOpen = true"><AppIcon name="upload" :size="16" /> Tải ảnh lên</AppButton>
        <AppButton @click="openGen"><AppIcon name="sparkles" :size="16" /> Tạo ảnh AI</AppButton>
      </template>
    </PageHeader>

    <div class="mb-4 flex flex-wrap gap-4 text-sm text-ink-muted">
      <span><strong class="text-ink">{{ media.length }}</strong> ảnh</span>
      <span><strong class="text-ink">{{ aiCount }}</strong> ảnh AI</span>
      <span>Hạn mức ảnh AI tháng này: <strong class="text-ink">{{ aiUsed }}/{{ plan.limits.images_ai === -1 ? '∞' : plan.limits.images_ai }}</strong></span>
    </div>

    <AppCard :padded="false">
      <Toolbar :count="table.total.value" :filtered="table.hasActiveFilters.value" @clear="table.clearFilters()">
        <SearchInput v-model="table.q.value" placeholder="Tìm theo tên, alt text…" />
        <FilterSelect v-model="table.filters.kind" label="Loại" :options="options('mediaKind')" />
        <FilterSelect v-model="table.filters.moderation" label="Duyệt" :options="options('moderation')" />
        <FilterSelect v-model="table.filters.usage" label="Sử dụng" :options="[{ value: '', label: 'Tất cả' }, { value: 'used', label: 'Đang dùng' }, { value: 'unused', label: 'Chưa dùng' }]" />
        <template #right>
          <select v-model="table.sortKey.value" class="rounded-lg border border-line bg-surface py-2 pl-3 pr-8 text-sm" aria-label="Sắp xếp">
            <option value="date">Mới nhất</option>
            <option value="title">Tên</option>
            <option value="size">Dung lượng</option>
          </select>
        </template>
      </Toolbar>
      <LoadingState v-if="loading" :rows="4" />
      <DemoErrorState v-else-if="error" :error="error" @retry="reload" />
      <EmptyState v-else-if="!table.total.value" :title="table.hasActiveFilters.value ? 'Không có ảnh phù hợp' : 'Thư viện trống'" description="Tải ảnh lên hoặc tạo ảnh minh họa bằng AI." icon="image">
        <AppButton v-if="table.hasActiveFilters.value" size="sm" variant="secondary" @click="table.clearFilters()">Xóa bộ lọc</AppButton>
      </EmptyState>
      <template v-else>
        <div class="grid grid-cols-2 gap-4 p-4 sm:grid-cols-3 lg:grid-cols-4 sm:p-5">
          <button v-for="m in table.rows.value" :key="m.id" type="button" class="group rounded-xl border border-line bg-surface p-2 text-left transition hover:border-brand-200 hover:shadow" @click="selected = m">
            <DemoImage :hue="m.art.hue" :motif="m.art.motif" :ai="m.isAiGenerated" :title="m.altText" />
            <div class="mt-2 flex items-start gap-2 px-1">
              <p class="min-w-0 flex-1 truncate text-sm font-medium">{{ m.title }}</p>
              <StatusBadge v-if="m.moderation !== 'approved'" group="moderation" :value="m.moderation" />
            </div>
            <p class="px-1 text-xs text-ink-muted"><StatusBadge group="mediaKind" :value="m.kind" /> · {{ m.articleIds.length }} bài</p>
          </button>
        </div>
        <Pagination v-model:page="table.page.value" v-model:page-size="table.pageSize.value" :total="table.total.value" :page-count="table.pageCount.value" :sizes="[12, 24, 48]" />
      </template>
    </AppCard>

    <Drawer :open="!!selected" :title="selected?.title ?? ''" :subtitle="selected ? `${selected.width}×${selected.height} · ${fmtBytes(selected.bytes)}` : ''" @close="selected = null">
      <template v-if="selected">
        <DemoImage :hue="selected.art.hue" :motif="selected.art.motif" :ai="selected.isAiGenerated" :title="selected.altText" class="mb-4" />
        <div v-if="selected.isAiGenerated" class="mb-4 rounded-lg bg-warning-50 p-3 text-xs text-amber-900">Ảnh do AI tạo – luôn hiển thị nhãn “Ảnh AI”, không được dùng làm ảnh sản phẩm thật.</div>
        <dl class="mb-4 space-y-2 text-sm">
          <div class="flex justify-between gap-2"><dt class="text-ink-muted">Loại</dt><dd><StatusBadge group="mediaKind" :value="selected.kind" /></dd></div>
          <div class="flex justify-between gap-2"><dt class="text-ink-muted">Nguồn</dt><dd class="text-right">{{ selected.sourceProvider }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-ink-muted">Giấy phép</dt><dd class="text-right">{{ selected.license }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-ink-muted">Ghi công</dt><dd class="text-right">{{ selected.attribution }}</dd></div>
          <div v-if="selected.sourceUrl" class="flex justify-between gap-2"><dt class="text-ink-muted">URL nguồn</dt><dd class="truncate text-right text-brand-700">{{ selected.sourceUrl }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-ink-muted">Ngày thêm</dt><dd>{{ fmtDate(selected.createdAt) }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-ink-muted">Duyệt</dt><dd><StatusBadge group="moderation" :value="selected.moderation" /></dd></div>
        </dl>
        <form id="media-meta" class="space-y-3 border-t border-line pt-4" @submit.prevent="saveMeta">
          <AppInput v-model="editForm.values.altText" label="Alt text" :error="editForm.errors.altText" />
          <DTextarea v-model="editForm.values.caption" label="Chú thích" :rows="2" :maxlength="200" />
          <label class="flex items-start gap-2 text-sm" :class="selected.isAiGenerated ? 'opacity-60' : ''">
            <input v-model="editForm.values.representsRealProduct" type="checkbox" class="mt-0.5" :disabled="selected.isAiGenerated" />
            <span>Ảnh chụp sản phẩm thật<span v-if="selected.isAiGenerated" class="block text-xs text-ink-muted">Không áp dụng cho ảnh AI.</span></span>
          </label>
        </form>
        <div v-if="selected.articleIds.length" class="mt-4 border-t border-line pt-4">
          <p class="mb-2 text-sm font-medium">Đang dùng trong</p>
          <ul class="space-y-1 text-sm">
            <li v-for="id in selected.articleIds" :key="id"><RouterLink :to="`${base}/articles/${id}`" class="text-brand-700 hover:underline">{{ db.articles.find((a) => a.id === id)?.title ?? id }}</RouterLink></li>
          </ul>
        </div>
      </template>
      <template #footer>
        <AppButton v-if="selected" variant="ghost" @click="deleting = selected"><AppIcon name="trash" :size="16" /> Xóa</AppButton>
        <AppButton v-if="selected && selected.moderation !== 'approved'" variant="secondary" @click="moderate(selected, 'approved')">Duyệt ảnh</AppButton>
        <AppButton v-if="selected && selected.moderation === 'pending'" variant="secondary" @click="moderate(selected, 'rejected')">Từ chối</AppButton>
        <AppButton type="submit" form="media-meta" :loading="editForm.submitting.value">Lưu</AppButton>
      </template>
    </Drawer>

    <AppModal :open="uploadOpen" title="Tải ảnh lên" @close="uploadOpen = false">
      <form id="upload" class="space-y-4" @submit.prevent="saveUpload">
        <label class="flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed border-line p-6 text-center text-sm text-ink-muted hover:border-brand-200">
          <AppIcon name="upload" :size="24" />
          <span>{{ file ? `${file.name} (${fmtBytes(file.size)})` : 'Chọn ảnh JPG, PNG, WebP (tối đa 8 MB)' }}</span>
          <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="onFile" />
        </label>
        <AppInput v-model="uploadForm.values.altText" label="Alt text" :error="uploadForm.errors.altText" />
        <AppSelect v-model="uploadForm.values.license" label="Quyền sử dụng" :options="[{ value: 'owned', label: 'Tôi sở hữu ảnh này' }, { value: 'licensed', label: 'Tôi có giấy phép sử dụng' }]" />
        <label class="flex items-center gap-2 text-sm"><input v-model="uploadForm.values.real" type="checkbox" /> Ảnh chụp sản phẩm / dịch vụ thật</label>
        <p class="text-xs text-ink-muted">Chế độ demo: tệp không được tải lên máy chủ; chỉ thông tin ảnh được lưu trong trình duyệt.</p>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="uploadOpen = false">Hủy</AppButton>
        <AppButton type="submit" form="upload" :loading="uploadForm.submitting.value">Tải lên</AppButton>
      </template>
    </AppModal>

    <AppModal :open="genOpen" title="Tạo ảnh minh họa bằng AI" @close="genOpen = false">
      <form v-if="!job" id="gen" class="space-y-4" @submit.prevent="runGen">
        <DTextarea v-model="genForm.values.prompt" label="Mô tả ảnh (prompt)" :rows="3" :maxlength="400" :error="genForm.errors.prompt" placeholder="Ví dụ: phòng khách nhỏ tông be, ánh sáng tự nhiên, phong cách tối giản" />
        <div class="grid gap-4 sm:grid-cols-2">
          <AppSelect v-model="genForm.values.style" label="Phong cách" :options="[{ value: 'illustration', label: 'Minh họa' }, { value: 'flat', label: 'Phẳng (flat)' }, { value: 'photo_like', label: 'Giống ảnh chụp' }]" />
          <AppSelect v-model="genForm.values.ratio" label="Tỷ lệ" :options="[{ value: '16:10', label: '16:10 (ảnh bìa)' }, { value: '1:1', label: '1:1' }, { value: '4:5', label: '4:5' }]" />
        </div>
        <p class="rounded-lg bg-surface-muted p-3 text-xs text-ink-muted">Ảnh AI luôn có nhãn “Ảnh AI”, cần duyệt trước khi dùng và không bao giờ được đánh dấu là ảnh sản phẩm thật. Demo không gọi API tạo ảnh thật.</p>
      </form>
      <JobProgress v-else :progress="job.progress.value" :step="job.step.value" :running="job.running.value" :failed="job.failed.value" />
      <template #footer>
        <AppButton variant="secondary" @click="genOpen = false">{{ job && !job.running.value ? 'Đóng' : 'Hủy' }}</AppButton>
        <AppButton v-if="!job" type="submit" form="gen"><AppIcon name="sparkles" :size="16" /> Tạo ảnh</AppButton>
        <AppButton v-else-if="job.failed.value" @click="job = null">Thử lại</AppButton>
      </template>
    </AppModal>

    <ConfirmDialog :open="!!deleting" title="Xóa ảnh?" :message="deleting?.articleIds.length ? `Ảnh đang được dùng trong ${deleting.articleIds.length} bài viết; các bài sẽ mất ảnh đại diện.` : 'Ảnh sẽ bị xóa khỏi thư viện.'" confirm-label="Xóa" danger @close="deleting = null" @confirm="confirmDelete" />
  </div>
</template>
