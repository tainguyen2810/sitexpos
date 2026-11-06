<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import Tabs from '../../ui/Tabs.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import LineChart from '../../ui/charts/LineChart.vue'
import { db, demoMutation } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { rules, useForm } from '../../composables/useForm'
import { fmtDate, fmtDateTime, fmtRelative } from '../../format'
import { useWorkspace } from '../useWorkspace'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { base, mine, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const site = computed(() => mine(db.sites).find((s) => s.id === route.params.siteId))
const tab = ref('overview')
const articles = computed(() => mine(db.articles).filter((a) => a.siteId === site.value?.id))
const audit = computed(() => db.seoAudits.find((a) => a.siteId === site.value?.id))
const uptime = computed(() => {
  const h = site.value?.health ?? []
  return h.length ? Math.round((h.filter((x) => x.ok).length / h.length) * 100) : 0
})

const form = useForm(
  () => ({ name: '', approvalMode: 'manual', defaultCategory: '', defaultStatus: 'draft', author: 'SiteX Bot' }),
  { name: [rules.required()], author: [rules.required('Cần chọn tài khoản WordPress dùng để đăng bài.')] },
)
watch(
  site,
  (s) => {
    if (s) form.reset({ name: s.name, approvalMode: s.approvalMode, defaultCategory: s.categories[0] ?? '', defaultStatus: 'draft', author: 'SiteX Bot' })
  },
  { immediate: true },
)
async function save() {
  const s = site.value
  if (!s) return
  await form
    .submit(async () => {
      await demoMutation(() => {
        s.name = String(form.values.name)
        s.approvalMode = form.values.approvalMode as 'manual' | 'auto'
      })
      toast.success('Đã lưu cấu hình đăng bài.')
    })
    .catch((e: Error) => toast.error(e.message))
}

const rotating = ref(false)
async function rotateKey() {
  rotating.value = true
  try {
    await demoMutation(() => undefined, 800)
    toast.success('Đã xoay khóa Ed25519 (mô phỏng). Plugin sẽ nhận khóa mới ở lần đồng bộ tiếp theo.')
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    rotating.value = false
  }
}
const syncing = ref(false)
async function syncTaxonomies() {
  const s = site.value
  if (!s) return
  syncing.value = true
  try {
    await demoMutation(() => {
      if (!s.categories.includes('Kiến thức')) s.categories.push('Kiến thức')
      s.lastSeenAt = new Date().toISOString()
    }, 700)
    toast.success('Đã đồng bộ chuyên mục và thẻ từ WordPress (mô phỏng).')
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    syncing.value = false
  }
}
const revokeOpen = ref(false)
async function revoke() {
  const s = site.value
  if (!s) return
  await demoMutation(() => {
    s.status = 'revoked'
    s.gscConnected = false
  }).catch((e: Error) => toast.error(e.message))
  revokeOpen.value = false
  toast.success('Đã thu hồi kết nối.')
  void router.push(`${base.value}/sites`)
}
</script>

<template>
  <div>
    <LoadingState v-if="loading" :rows="5" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <AppCard v-else-if="!site">
      <EmptyState title="Không tìm thấy website" description="Website không tồn tại trong workspace này." icon="globe">
        <RouterLink :to="`${base}/sites`" class="text-sm font-medium text-brand-700 hover:underline">Về danh sách website</RouterLink>
      </EmptyState>
    </AppCard>
    <template v-else>
      <PageHeader :title="site.name" :description="site.url" :crumbs="crumbs({ label: 'Website', to: `${base}/sites` }, { label: site.name })">
        <template #actions>
          <StatusBadge group="site" :value="site.status" />
          <a :href="site.url" target="_blank" rel="noopener noreferrer"><AppButton variant="secondary"><AppIcon name="external" :size="16" /> Mở website</AppButton></a>
          <AppButton variant="secondary" :loading="syncing" :disabled="site.status === 'revoked'" @click="syncTaxonomies"><AppIcon name="refresh" :size="16" /> Đồng bộ</AppButton>
        </template>
      </PageHeader>

      <Tabs v-model="tab" :tabs="[{ key: 'overview', label: 'Tổng quan' }, { key: 'articles', label: 'Bài viết SiteX', count: articles.length }, { key: 'settings', label: 'Cấu hình đăng bài' }, { key: 'security', label: 'Kết nối & bảo mật' }]" class="mb-6" />

      <div v-if="tab === 'overview'" class="grid gap-6 lg:grid-cols-3">
        <AppCard class="lg:col-span-2" title="Độ trễ phản hồi 14 ngày" subtitle="Kiểm tra sức khỏe tự động mỗi ngày (mô phỏng)">
          <LineChart :labels="site.health.map((h) => fmtDate(h.at).slice(0, 5))" :series="[{ name: 'Độ trễ (ms)', values: site.health.map((h) => h.latencyMs), color: '#1baf7a' }]" :height="180" :format="(v) => `${Math.round(v)} ms`" />
          <div class="mt-4 flex flex-wrap gap-1">
            <span v-for="(h, i) in site.health" :key="i" class="h-6 flex-1 rounded" :class="h.ok ? 'bg-success-600/80' : 'bg-danger-600/80'" :title="`${fmtDateTime(h.at)}: ${h.ok ? `${h.latencyMs} ms` : h.error}`" />
          </div>
          <p class="mt-2 text-xs text-ink-muted">Tỷ lệ phản hồi thành công: <strong>{{ uptime }}%</strong></p>
        </AppCard>
        <AppCard title="Thông tin">
          <dl class="space-y-2.5 text-sm">
            <div class="flex justify-between gap-2"><dt class="text-ink-muted">WordPress</dt><dd>{{ site.wpVersion }}</dd></div>
            <div class="flex justify-between gap-2"><dt class="text-ink-muted">Plugin SiteX Post</dt><dd>{{ site.pluginVersion }} <span v-if="site.pluginVersion === '0.9.2'" class="text-xs text-warning-600">(có bản mới 1.0.0)</span></dd></div>
            <div class="flex justify-between gap-2"><dt class="text-ink-muted">PHP</dt><dd>{{ site.phpVersion }}</dd></div>
            <div class="flex justify-between gap-2"><dt class="text-ink-muted">Ngôn ngữ</dt><dd>Tiếng Việt</dd></div>
            <div class="flex justify-between gap-2"><dt class="text-ink-muted">Tổng bài / do SiteX</dt><dd>{{ site.postsCount }} / {{ site.managedPosts }}</dd></div>
            <div class="flex justify-between gap-2"><dt class="text-ink-muted">Kết nối từ</dt><dd>{{ fmtDate(site.connectedAt) }}</dd></div>
            <div class="flex justify-between gap-2"><dt class="text-ink-muted">Phản hồi gần nhất</dt><dd>{{ fmtRelative(site.lastSeenAt) }}</dd></div>
            <div class="flex justify-between gap-2"><dt class="text-ink-muted">Search Console</dt><dd><RouterLink :to="`${base}/analytics`" class="text-brand-700 hover:underline">{{ site.gscConnected ? 'Đã kết nối' : 'Chưa kết nối' }}</RouterLink></dd></div>
            <div class="flex justify-between gap-2"><dt class="text-ink-muted">Điểm SEO Audit</dt><dd><RouterLink :to="`${base}/seo-audit?site=${site.id}`" class="text-brand-700 hover:underline">{{ audit ? `${audit.score}/100` : 'Chưa quét' }}</RouterLink></dd></div>
          </dl>
          <div class="mt-4 border-t border-line pt-3">
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-ink-muted">Chuyên mục đã đồng bộ</p>
            <div class="flex flex-wrap gap-1.5"><span v-for="c in site.categories" :key="c" class="rounded-full bg-surface-muted px-2 py-0.5 text-xs ring-1 ring-line">{{ c }}</span></div>
          </div>
        </AppCard>
      </div>

      <AppCard v-else-if="tab === 'articles'" :padded="false">
        <EmptyState v-if="!articles.length" title="Chưa có bài viết cho website này" icon="file" />
        <ul v-else class="divide-y divide-line">
          <li v-for="a in articles" :key="a.id">
            <RouterLink :to="`${base}/articles/${a.id}`" class="flex items-center gap-3 px-5 py-3 hover:bg-surface-muted/60">
              <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ a.title }}</span>
              <span class="hidden text-xs text-ink-muted sm:block">{{ fmtDate(a.publishedAt ?? a.updatedAt) }}</span>
              <StatusBadge group="article" :value="a.status" />
            </RouterLink>
          </li>
        </ul>
      </AppCard>

      <AppCard v-else-if="tab === 'settings'" title="Cấu hình đăng bài" subtitle="Áp dụng mặc định cho mọi bài SiteX đăng lên website này.">
        <form class="grid max-w-2xl gap-4 sm:grid-cols-2" @submit.prevent="save">
          <AppInput v-model="form.values.name" label="Tên website" :error="form.errors.name" class="sm:col-span-2" />
          <AppSelect v-model="form.values.approvalMode" label="Chế độ duyệt" :options="[{ value: 'manual', label: 'Thủ công' }, { value: 'auto', label: 'Tự động (khi đã kiểm chứng)' }]" />
          <AppSelect v-model="form.values.defaultStatus" label="Trạng thái bài trên WordPress" :options="[{ value: 'draft', label: 'Bản nháp (draft)' }, { value: 'publish', label: 'Đăng ngay (publish)' }, { value: 'future', label: 'Hẹn giờ (future)' }]" />
          <AppSelect v-model="form.values.defaultCategory" label="Chuyên mục mặc định" :options="site.categories.map((c) => ({ value: c, label: c }))" />
          <AppSelect v-model="form.values.author" label="Tài khoản tác giả WP" :error="form.errors.author" :options="[{ value: '', label: '— Chọn —' }, { value: 'SiteX Bot', label: 'SiteX Bot (editor)' }, { value: 'admin', label: 'admin (administrator)' }]" />
          <div class="sm:col-span-2"><AppButton type="submit" :loading="form.submitting.value" :disabled="site.status === 'revoked'">Lưu cấu hình</AppButton></div>
        </form>
      </AppCard>

      <div v-else class="grid gap-6 lg:grid-cols-2">
        <AppCard title="Khóa ký Ed25519" subtitle="Mọi request giữa SiteX Cloud và plugin đều được ký và chống replay.">
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between"><dt class="text-ink-muted">Key ID</dt><dd class="font-mono text-xs">{{ site.id.replace('site_', 'key_') }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-muted">Trạng thái</dt><dd><StatusBadge group="site" :value="site.status" /></dd></div>
          </dl>
          <AppButton class="mt-4" variant="secondary" :loading="rotating" :disabled="site.status === 'revoked' || site.status === 'pending_pairing'" @click="rotateKey"><AppIcon name="refresh" :size="16" /> Xoay khóa</AppButton>
        </AppCard>
        <AppCard title="Vùng nguy hiểm">
          <p class="text-sm text-ink-muted">Thu hồi kết nối sẽ vô hiệu hóa khóa ký. Bài đã đăng trên website không bị xóa.</p>
          <AppButton class="mt-4" variant="danger" :disabled="site.status === 'revoked'" @click="revokeOpen = true">Thu hồi kết nối</AppButton>
        </AppCard>
      </div>

      <ConfirmDialog :open="revokeOpen" title="Thu hồi kết nối?" :message="`Ngừng đăng bài lên ${site.name}. Bạn có thể ghép nối lại sau.`" confirm-label="Thu hồi" danger @close="revokeOpen = false" @confirm="revoke" />
    </template>
  </div>
</template>
