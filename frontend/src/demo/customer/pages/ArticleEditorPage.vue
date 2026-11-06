<script setup lang="ts">
import { computed, reactive, ref, shallowRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import DTextarea from '../../ui/DTextarea.vue'
import JobProgress from '../../ui/JobProgress.vue'
import DemoImage from '../../ui/DemoImage.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import { db, demoMutation, newId, simulateJob } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { rules, useForm } from '../../composables/useForm'
import { fmtDateTime, fmtRelative, toDateTimeInput } from '../../format'
import type { DemoArticle, DemoClaim } from '../../data/types'
import { demoDraftSections } from '../actions'
import { useWorkspace } from '../useWorkspace'

const route = useRoute()
const toast = useToast()
const { ws, base, mine, siteName, campaignName, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const article = computed(() => mine(db.articles).find((a) => a.id === route.params.articleId))
const site = computed(() => db.sites.find((s) => s.id === article.value?.siteId))
const keyword = computed(() => db.keywords.find((k) => k.id === article.value?.keywordId))
const report = computed(() => [...db.factChecks].reverse().find((f) => f.articleId === article.value?.id))
const featured = computed(() => db.media.find((m) => m.id === article.value?.featuredMediaId))
const locked = computed(() => ['publishing', 'scheduled'].includes(article.value?.status ?? ''))

// Local editable draft
const draft = reactive({ title: '', slug: '', metaDescription: '', sections: [] as { heading: string; body: string }[] })
function loadDraft(a: DemoArticle | undefined) {
  if (!a) return
  draft.title = a.title
  draft.slug = a.slug
  draft.metaDescription = a.metaDescription
  draft.sections = a.sections.map((s) => ({ ...s }))
}
watch(article, (a) => loadDraft(a), { immediate: true })
const dirty = computed(() => {
  const a = article.value
  if (!a) return false
  return draft.title !== a.title || draft.slug !== a.slug || draft.metaDescription !== a.metaDescription || JSON.stringify(draft.sections) !== JSON.stringify(a.sections)
})
const wordCount = computed(() => draft.sections.reduce((s, x) => s + `${x.heading} ${x.body}`.split(/\s+/).filter(Boolean).length, 0) * 12)
const errors = reactive<{ title?: string; slug?: string; meta?: string }>({})

// SEO checklist (a checklist, not a ranking guarantee)
const checks = computed(() => {
  const kw = keyword.value?.keyword.toLowerCase()
  const body = draft.sections.map((s) => s.body).join(' ').toLowerCase()
  return [
    { ok: !!kw && draft.title.toLowerCase().includes(kw.split(' ')[0]!), label: 'Từ khóa chính xuất hiện trong tiêu đề' },
    { ok: draft.title.length >= 30 && draft.title.length <= 65, label: `Độ dài tiêu đề 30–65 ký tự (${draft.title.length})` },
    { ok: draft.metaDescription.length >= 120 && draft.metaDescription.length <= 160, label: `Meta description 120–160 ký tự (${draft.metaDescription.length})` },
    { ok: /^[a-z0-9-]+$/.test(draft.slug) && draft.slug.length <= 75, label: 'Slug ngắn gọn, không dấu' },
    { ok: draft.sections.length >= 3, label: `Có ít nhất 3 mục H2 (${draft.sections.length})` },
    { ok: !!kw && body.includes(kw.split(' ')[0]!), label: 'Từ khóa xuất hiện trong nội dung' },
    { ok: !!featured.value, label: 'Có ảnh đại diện với alt text' },
    { ok: report.value?.result === 'passed' || article.value?.verificationStatus === 'overridden', label: 'Đã qua kiểm chứng nguồn' },
  ]
})
const seoScore = computed(() => Math.round((checks.value.filter((c) => c.ok).length / checks.value.length) * 100))

function validate(): boolean {
  errors.title = draft.title.trim().length < 10 ? 'Tiêu đề tối thiểu 10 ký tự.' : draft.title.length > 120 ? 'Tiêu đề tối đa 120 ký tự.' : undefined
  errors.slug = /^[a-z0-9-]+$/.test(draft.slug) ? undefined : 'Slug chỉ gồm chữ thường không dấu, số và dấu gạch ngang.'
  errors.meta = draft.metaDescription.length > 160 ? 'Meta description tối đa 160 ký tự.' : undefined
  return !errors.title && !errors.slug && !errors.meta
}
const saving = ref(false)
async function save(note = 'Biên tập viên chỉnh sửa') {
  const a = article.value
  if (!a || !validate()) return
  saving.value = true
  try {
    await demoMutation(() => {
      a.title = draft.title.trim()
      a.slug = draft.slug
      a.metaDescription = draft.metaDescription
      a.sections = draft.sections.map((s) => ({ ...s }))
      a.wordCount = wordCount.value
      a.seoScore = seoScore.value
      a.updatedAt = new Date().toISOString()
      a.versions.push({ no: a.versions.length + 1, authorType: 'user', authorName: ws.value.ownerName, note, createdAt: a.updatedAt, wordCount: a.wordCount, snapshot: { title: a.title, metaDescription: a.metaDescription, sections: a.sections.map((s) => ({ ...s })) } })
      // Content changed after verification → must be re-verified
      if (a.verificationStatus === 'passed' && a.status !== 'published') {
        a.verificationStatus = 'pending'
        if (['awaiting_approval', 'approved'].includes(a.status)) a.status = 'ready_for_review'
      }
    })
    toast.success(`Đã lưu phiên bản v${a.versions.length}.`)
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    saving.value = false
  }
}
function addSection() {
  draft.sections.push({ heading: 'Mục mới', body: '' })
}
function removeSection(i: number) {
  draft.sections.splice(i, 1)
}
function moveSection(i: number, d: number) {
  const [s] = draft.sections.splice(i, 1)
  draft.sections.splice(i + d, 0, s!)
}
const rewriting = ref<number | null>(null)
async function rewrite(i: number) {
  rewriting.value = i
  try {
    await demoMutation(() => {
      const s = draft.sections[i]!
      s.body = `${s.body.split('.')[0] ?? s.body}. Phiên bản viết lại bởi AI (mô phỏng): nội dung được diễn đạt rõ ràng hơn, câu ngắn hơn và giữ nguyên ý chính. Hãy kiểm tra lại trước khi lưu.`
    }, 900)
    toast.success('AI (mô phỏng) đã viết lại đoạn. Nhớ lưu phiên bản.')
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    rewriting.value = null
  }
}

// AI draft for brief articles
const job = shallowRef<ReturnType<typeof simulateJob> | null>(null)
function aiDraft() {
  const a = article.value
  if (!a) return
  job.value = simulateJob(['Lập dàn ý…', 'Tải nguồn tham khảo…', 'Viết bản nháp…', 'Tối ưu SEO…'], () => {
    a.sections = demoDraftSections(a.title, keyword.value, ws.value.settings.toneOfVoice)
    a.metaDescription = `${a.title} – hướng dẫn chi tiết và dễ hiểu (bản nháp demo).`.slice(0, 158)
    a.wordCount = 1500
    a.status = 'ready_for_review'
    a.updatedAt = new Date().toISOString()
    a.versions.push({ no: a.versions.length + 1, authorType: 'ai', authorName: 'AI Writer (mô phỏng)', note: 'Bản nháp đầu tiên', createdAt: a.updatedAt, wordCount: 1500 })
    loadDraft(a)
    job.value = null
    toast.success('Đã có bản nháp. Bước tiếp theo: chạy kiểm chứng.')
  })
}

// Fact-check (simulated): any percentage figure without a source is flagged.
const verifyJob = shallowRef<ReturnType<typeof simulateJob> | null>(null)
function runFactCheck() {
  const a = article.value
  if (!a) return
  if (dirty.value) {
    toast.error('Hãy lưu thay đổi trước khi chạy kiểm chứng.')
    return
  }
  a.status = 'verifying'
  verifyJob.value = simulateJob(['Trích xuất nhận định (claims)…', 'Đối chiếu với nguồn đã tải…', 'Kiểm tra trích dẫn nguyên văn…', 'Tổng hợp báo cáo…'], () => {
    const text = a.sections.map((s) => s.body).join(' ')
    const claims: DemoClaim[] = a.sections.slice(0, 4).map((s, i) => {
      const hasNumber = /\d+\s?%/.test(s.body)
      return {
        id: newId('clm'),
        text: s.body.split('.')[0]!.slice(0, 140),
        severity: hasNumber ? 'high' : i === 1 ? 'medium' : 'low',
        status: hasNumber ? 'unsupported' : 'supported',
        evidence: hasNumber ? [] : [{ sourceTitle: `Nguồn mẫu #${i + 1}: Tài liệu tham khảo (demo)`, url: `https://nguon-mau.example/tai-lieu/${i + 11}`, quote: `Trích dẫn mẫu: "${s.body.slice(0, 60)}…"`, quoteFound: true, stance: 'supports' as const }],
      }
    })
    const failed = claims.some((c) => c.status === 'unsupported' && c.severity === 'high')
    db.factChecks.push({
      id: newId('fc'),
      workspaceId: a.workspaceId,
      articleId: a.id,
      versionNo: a.versions.length,
      result: failed ? 'failed' : 'passed',
      score: Math.round((claims.filter((c) => c.status === 'supported').length / Math.max(1, claims.length)) * 100),
      claims,
      sourcesCount: 4,
      overriddenBy: null,
      overrideReason: null,
      createdAt: new Date().toISOString(),
    })
    a.verificationStatus = failed ? 'failed' : 'passed'
    a.status = failed ? 'needs_revision' : 'ready_for_review'
    a.updatedAt = new Date().toISOString()
    verifyJob.value = null
    if (failed) toast.error(`Kiểm chứng không đạt: có số liệu chưa có nguồn${text.includes('%') ? ' (ký hiệu %)' : ''}.`)
    else toast.success('Kiểm chứng đạt. Bài có thể gửi duyệt.')
  })
}

// Workflow transitions
async function transition(to: DemoArticle['status'], message: string, extra?: (a: DemoArticle) => void) {
  const a = article.value
  if (!a) return
  try {
    await demoMutation(() => {
      a.status = to
      a.updatedAt = new Date().toISOString()
      extra?.(a)
    })
    toast.success(message)
  } catch (e) {
    toast.error((e as Error).message)
  }
}
function submitForReview() {
  const auto = site.value?.approvalMode === 'auto'
  void transition(auto ? 'approved' : 'awaiting_approval', auto ? 'Website dùng chế độ duyệt tự động: bài đã được duyệt.' : 'Đã gửi duyệt.')
}
const changesOpen = ref(false)
const changes = useForm(() => ({ comment: '' }), { comment: [rules.required('Nhập góp ý cho người viết.'), rules.minLength(10)] })
async function requestChanges() {
  await changes
    .submit(async () => {
      await transition('needs_revision', 'Đã yêu cầu chỉnh sửa.')
      changesOpen.value = false
      changes.reset()
    })
    .catch((e: Error) => toast.error(e.message))
}

const scheduleOpen = ref(false)
const schedule = useForm(() => ({ at: toDateTimeInput(new Date(Date.now() + 86_400_000).toISOString()) }), {
  at: [rules.required(), (v) => (new Date(String(v)).getTime() > Date.now() + 5 * 60_000 ? true : 'Thời điểm đăng phải sau hiện tại ít nhất 5 phút.')],
})
function upsertJob(a: DemoArticle, runAt: string, status: 'queued' | 'running', action: 'create' | 'update' = 'create') {
  db.publishJobs.unshift({ id: newId('pub'), workspaceId: a.workspaceId, siteId: a.siteId, articleId: a.id, action, wpStatus: status === 'queued' ? 'future' : 'publish', runAt, status, attempts: status === 'running' ? 1 : 0, lastError: null, wpPostId: null, createdAt: new Date().toISOString() })
  const cal = db.calendar.find((c) => c.articleId === a.id)
  if (cal) {
    cal.plannedDate = runAt
    cal.status = 'in_progress'
  } else db.calendar.push({ id: newId('cal'), workspaceId: a.workspaceId, siteId: a.siteId, campaignId: a.campaignId, ideaId: a.ideaId, articleId: a.id, title: a.title, plannedDate: runAt, status: 'in_progress' })
}
async function saveSchedule() {
  await schedule
    .submit(async () => {
      const at = new Date(schedule.values.at).toISOString()
      await transition('scheduled', `Đã lên lịch đăng lúc ${fmtDateTime(at)}.`, (a) => {
        a.scheduledAt = at
        upsertJob(a, at, 'queued')
      })
      scheduleOpen.value = false
    })
    .catch((e: Error) => toast.error(e.message))
}
async function publishNow() {
  const a = article.value
  if (!a || !site.value) return
  if (site.value.status !== 'connected' && site.value.status !== 'degraded') {
    toast.error('Website chưa kết nối, không thể đăng.')
    return
  }
  await transition('publishing', 'Đã đưa vào hàng đợi đăng bài.', (x) => upsertJob(x, new Date().toISOString(), 'running', x.wpUrl ? 'update' : 'create'))
  setTimeout(() => {
    const job = db.publishJobs.find((j) => j.articleId === a.id && j.status === 'running')
    if (job) {
      job.status = 'succeeded'
      job.wpPostId = 2000 + Math.floor(Math.random() * 999)
    }
    a.status = 'published'
    a.publishedAt = new Date().toISOString()
    a.wpUrl = `${site.value!.url}/${a.slug}/`
    const cal = db.calendar.find((c) => c.articleId === a.id)
    if (cal) cal.status = 'done'
    if (site.value) site.value.managedPosts += 1
    toast.success('Đã đăng bài lên WordPress (mô phỏng).')
  }, 2500)
}
async function unschedule() {
  await transition('approved', 'Đã hủy lịch đăng.', (a) => {
    a.scheduledAt = null
    db.publishJobs.filter((j) => j.articleId === a.id && j.status === 'queued').forEach((j) => (j.status = 'canceled'))
  })
}

// Featured image picker
const pickOpen = ref(false)
const pickable = computed(() => mine(db.media).filter((m) => m.moderation === 'approved'))
async function pick(id: string) {
  const a = article.value
  if (!a) return
  await demoMutation(() => {
    const prev = db.media.find((m) => m.id === a.featuredMediaId)
    if (prev) prev.articleIds = prev.articleIds.filter((x) => x !== a.id)
    a.featuredMediaId = id
    const m = db.media.find((x) => x.id === id)
    if (m && !m.articleIds.includes(a.id)) m.articleIds.push(a.id)
  }).catch((e: Error) => toast.error(e.message))
  pickOpen.value = false
  toast.success('Đã đặt ảnh đại diện.')
}

// Versions
const restoreNo = ref<number | null>(null)
async function restore() {
  const a = article.value
  if (!a || restoreNo.value === null) return
  const no = restoreNo.value
  restoreNo.value = null
  const snap = a.versions.find((v) => v.no === no)?.snapshot
  if (snap) {
    draft.title = snap.title
    draft.metaDescription = snap.metaDescription
    draft.sections = snap.sections.map((s) => ({ ...s }))
  }
  await save(`Khôi phục nội dung từ v${no}`)
}
const previewOpen = ref(false)
</script>

<template>
  <div>
    <LoadingState v-if="loading" :rows="6" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <AppCard v-else-if="!article">
      <EmptyState title="Không tìm thấy bài viết" icon="file"><RouterLink :to="`${base}/articles`" class="text-sm font-medium text-brand-700 hover:underline">Về danh sách bài viết</RouterLink></EmptyState>
    </AppCard>
    <template v-else>
      <PageHeader :title="article.title" :crumbs="crumbs({ label: 'AI Article Writer', to: `${base}/articles` }, { label: article.title.length > 40 ? `${article.title.slice(0, 40)}…` : article.title })">
        <template #actions>
          <StatusBadge group="article" :value="article.status" />
          <StatusBadge group="verification" :value="article.verificationStatus" />
          <span v-if="dirty" class="text-xs font-medium text-warning-600">● Chưa lưu</span>
        </template>
      </PageHeader>

      <!-- Workflow bar -->
      <div class="mb-6 flex flex-wrap items-center gap-2 rounded-xl border border-line bg-surface p-3 shadow-sm">
        <AppButton variant="secondary" size="sm" @click="previewOpen = true"><AppIcon name="eye" :size="16" /> Xem trước</AppButton>
        <AppButton variant="secondary" size="sm" :disabled="!dirty || locked" :loading="saving" @click="save()">Lưu phiên bản</AppButton>
        <span class="mx-1 hidden h-6 w-px bg-line sm:block" />
        <template v-if="article.status === 'brief'">
          <AppButton size="sm" :disabled="!!job" @click="aiDraft"><AppIcon name="sparkles" :size="16" /> AI viết bản nháp</AppButton>
        </template>
        <template v-else-if="['drafting', 'ready_for_review', 'needs_revision', 'verifying'].includes(article.status)">
          <AppButton size="sm" variant="secondary" :disabled="!!verifyJob || article.status === 'verifying'" @click="runFactCheck"><AppIcon name="shieldCheck" :size="16" /> Chạy kiểm chứng</AppButton>
          <AppButton size="sm" :disabled="article.verificationStatus !== 'passed' && article.verificationStatus !== 'overridden'" :title="article.verificationStatus !== 'passed' ? 'Bài phải qua kiểm chứng trước khi gửi duyệt' : undefined" @click="submitForReview">Gửi duyệt</AppButton>
        </template>
        <template v-else-if="article.status === 'awaiting_approval'">
          <AppButton size="sm" variant="secondary" @click="changesOpen = true">Yêu cầu sửa</AppButton>
          <AppButton size="sm" @click="transition('approved', 'Đã duyệt bài viết.')"><AppIcon name="check" :size="16" /> Duyệt bài</AppButton>
        </template>
        <template v-else-if="article.status === 'approved'">
          <AppButton size="sm" variant="secondary" @click="scheduleOpen = true"><AppIcon name="calendar" :size="16" /> Lên lịch</AppButton>
          <AppButton size="sm" @click="publishNow"><AppIcon name="send" :size="16" /> Đăng ngay</AppButton>
        </template>
        <template v-else-if="article.status === 'scheduled'">
          <span class="text-sm text-ink-muted">Đăng lúc {{ fmtDateTime(article.scheduledAt) }}</span>
          <AppButton size="sm" variant="secondary" @click="unschedule">Hủy lịch</AppButton>
          <AppButton size="sm" @click="publishNow">Đăng ngay</AppButton>
        </template>
        <template v-else-if="article.status === 'publishing'"><span class="flex items-center gap-2 text-sm text-brand-700"><AppIcon name="refresh" :size="16" class="animate-spin" /> Đang đăng lên WordPress…</span></template>
        <template v-else-if="article.status === 'published'">
          <a v-if="article.wpUrl" :href="article.wpUrl" target="_blank" rel="noopener noreferrer"><AppButton size="sm" variant="secondary"><AppIcon name="external" :size="16" /> Xem trên website</AppButton></a>
          <AppButton size="sm" :disabled="dirty" @click="publishNow">Cập nhật lên WordPress</AppButton>
        </template>
        <template v-else-if="article.status === 'publish_failed'">
          <span class="text-sm text-danger-600">Đăng thất bại.</span>
          <AppButton size="sm" @click="publishNow"><AppIcon name="refresh" :size="16" /> Thử đăng lại</AppButton>
        </template>
      </div>

      <JobProgress v-if="job" class="mb-6" :progress="job.progress.value" :step="job.step.value" :running="job.running.value" :failed="job.failed.value" />
      <JobProgress v-if="verifyJob" class="mb-6" :progress="verifyJob.progress.value" :step="verifyJob.step.value" :running="verifyJob.running.value" :failed="verifyJob.failed.value" title="Kiểm chứng" />

      <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
          <AppCard title="Nội dung">
            <fieldset :disabled="locked" class="space-y-4">
              <AppInput v-model="draft.title" label="Tiêu đề (H1)" :error="errors.title" />
              <div class="grid gap-4 sm:grid-cols-2">
                <AppInput v-model="draft.slug" label="Slug" :error="errors.slug" />
                <div class="text-sm">
                  <p class="mb-1.5 font-medium">Từ khóa chính</p>
                  <p class="rounded-lg border border-line bg-surface-muted px-3 py-2 text-ink-muted">{{ keyword?.keyword ?? 'Chưa gắn từ khóa' }}</p>
                </div>
              </div>
              <DTextarea v-model="draft.metaDescription" label="Meta description" :rows="2" :maxlength="160" :error="errors.meta" hint="Nên dài 120–160 ký tự." />
            </fieldset>
            <p v-if="locked" class="mt-3 rounded-lg bg-warning-50 p-3 text-sm text-amber-900">Bài đang được lên lịch / đăng – hủy lịch để chỉnh sửa.</p>
          </AppCard>

          <AppCard title="Các mục (H2)" :subtitle="`Khoảng ${wordCount.toLocaleString('vi-VN')} từ`">
            <template #actions><AppButton size="sm" variant="secondary" :disabled="locked" @click="addSection"><AppIcon name="plus" :size="14" /> Thêm mục</AppButton></template>
            <EmptyState v-if="!draft.sections.length" title="Chưa có nội dung" description="Dùng “AI viết bản nháp” hoặc thêm mục thủ công." icon="pen" />
            <div v-for="(s, i) in draft.sections" :key="i" class="mb-4 rounded-lg border border-line p-3 last:mb-0">
              <div class="mb-2 flex items-center gap-2">
                <input v-model="s.heading" :disabled="locked" class="flex-1 rounded-md border border-transparent px-2 py-1 text-sm font-semibold hover:border-line focus:border-brand-500 focus:outline-none" :aria-label="`Tiêu đề mục ${i + 1}`" />
                <button type="button" class="rounded p-1 text-ink-subtle hover:bg-surface-muted disabled:opacity-30" :disabled="i === 0 || locked" aria-label="Lên" @click="moveSection(i, -1)"><AppIcon name="chevronUp" :size="14" /></button>
                <button type="button" class="rounded p-1 text-ink-subtle hover:bg-surface-muted disabled:opacity-30" :disabled="i === draft.sections.length - 1 || locked" aria-label="Xuống" @click="moveSection(i, 1)"><AppIcon name="chevronDown" :size="14" /></button>
                <button type="button" class="rounded p-1 text-ink-subtle hover:bg-danger-50 hover:text-danger-600" :disabled="locked" aria-label="Xóa mục" @click="removeSection(i)"><AppIcon name="trash" :size="14" /></button>
              </div>
              <textarea v-model="s.body" :disabled="locked" rows="4" class="block w-full rounded-lg border border-line px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100" :aria-label="`Nội dung mục ${i + 1}`" />
              <div class="mt-2 flex justify-end">
                <AppButton size="sm" variant="ghost" :loading="rewriting === i" :disabled="locked" @click="rewrite(i)"><AppIcon name="sparkles" :size="14" /> AI viết lại</AppButton>
              </div>
            </div>
          </AppCard>
        </div>

        <div class="space-y-6">
          <AppCard title="SEO checklist" :subtitle="`Điểm: ${seoScore}/100 – checklist, không phải cam kết thứ hạng`">
            <ul class="space-y-2 text-sm">
              <li v-for="c in checks" :key="c.label" class="flex items-start gap-2">
                <span class="mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full" :class="c.ok ? 'bg-success-600 text-white' : 'bg-slate-200 text-ink-subtle'"><AppIcon :name="c.ok ? 'check' : 'x'" :size="10" /></span>
                <span :class="c.ok ? 'text-ink' : 'text-ink-muted'">{{ c.label }}</span>
              </li>
            </ul>
          </AppCard>

          <AppCard title="Kiểm chứng nguồn">
            <template v-if="report">
              <div class="flex items-center justify-between text-sm">
                <span>Báo cáo v{{ report.versionNo }} · {{ fmtRelative(report.createdAt) }}</span>
                <StatusBadge group="verification" :value="report.result === 'passed' ? 'passed' : article.verificationStatus === 'overridden' ? 'overridden' : 'failed'" />
              </div>
              <p class="mt-2 text-sm text-ink-muted">{{ report.claims.filter((c) => c.status === 'supported').length }}/{{ report.claims.length }} nhận định có nguồn xác nhận.</p>
              <RouterLink :to="`${base}/fact-checks/${report.id}`" class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">Xem báo cáo <AppIcon name="chevronRight" :size="14" /></RouterLink>
            </template>
            <p v-else class="text-sm text-ink-muted">Chưa có báo cáo. Bài không thể gửi duyệt khi chưa qua kiểm chứng.</p>
          </AppCard>

          <AppCard title="Ảnh đại diện">
            <template v-if="featured">
              <DemoImage :hue="featured.art.hue" :motif="featured.art.motif" :ai="featured.isAiGenerated" :title="featured.altText" />
              <p class="mt-2 text-xs text-ink-muted">{{ featured.altText }} · {{ featured.license }}</p>
            </template>
            <p v-else class="text-sm text-ink-muted">Chưa chọn ảnh.</p>
            <AppButton class="mt-3" size="sm" variant="secondary" :disabled="locked" @click="pickOpen = true"><AppIcon name="image" :size="14" /> {{ featured ? 'Đổi ảnh' : 'Chọn ảnh' }}</AppButton>
          </AppCard>

          <AppCard title="Thông tin">
            <dl class="space-y-2 text-sm">
              <div class="flex justify-between gap-2"><dt class="text-ink-muted">Website</dt><dd class="text-right">{{ siteName(article.siteId) }}</dd></div>
              <div class="flex justify-between gap-2"><dt class="text-ink-muted">Chiến dịch</dt><dd class="text-right">{{ campaignName(article.campaignId) }}</dd></div>
              <div class="flex justify-between gap-2"><dt class="text-ink-muted">Tác giả</dt><dd>{{ article.authorName }}</dd></div>
              <div class="flex justify-between gap-2"><dt class="text-ink-muted">Tạo lúc</dt><dd>{{ fmtDateTime(article.createdAt) }}</dd></div>
              <div v-if="article.publishedAt" class="flex justify-between gap-2"><dt class="text-ink-muted">Đăng lúc</dt><dd>{{ fmtDateTime(article.publishedAt) }}</dd></div>
            </dl>
          </AppCard>

          <AppCard title="Lịch sử phiên bản" :padded="false">
            <p v-if="!article.versions.length" class="px-5 py-4 text-sm text-ink-muted">Chưa có phiên bản.</p>
            <ol v-else class="max-h-72 divide-y divide-line overflow-y-auto">
              <li v-for="v in [...article.versions].reverse()" :key="v.no" class="flex items-start gap-3 px-5 py-3 text-sm">
                <span class="mt-0.5 rounded bg-surface-muted px-1.5 text-xs font-semibold">v{{ v.no }}</span>
                <div class="min-w-0 flex-1">
                  <p>{{ v.note }}</p>
                  <p class="text-xs text-ink-muted">{{ v.authorType === 'ai' ? '🤖' : '👤' }} {{ v.authorName }} · {{ fmtRelative(v.createdAt) }}</p>
                </div>
                <button v-if="v.no !== article.versions.length" type="button" class="text-xs text-brand-700 hover:underline" :disabled="locked" @click="restoreNo = v.no">Khôi phục</button>
              </li>
            </ol>
          </AppCard>
        </div>
      </div>

      <AppModal :open="previewOpen" title="Xem trước bài viết" width="xl" @close="previewOpen = false">
        <article class="mx-auto max-w-2xl">
          <p class="mb-2 text-xs text-ink-subtle">{{ site?.url }}/{{ draft.slug }}/</p>
          <h1 class="mb-4 text-2xl font-bold">{{ draft.title }}</h1>
          <DemoImage v-if="featured" class="mb-4" :hue="featured.art.hue" :motif="featured.art.motif" :ai="featured.isAiGenerated" :title="featured.altText" />
          <section v-for="(s, i) in draft.sections" :key="i" class="mb-4">
            <h2 class="mb-1 text-lg font-semibold">{{ s.heading }}</h2>
            <p class="text-sm leading-relaxed text-ink-muted">{{ s.body }}</p>
          </section>
          <p class="mt-6 rounded-lg bg-warning-50 p-3 text-xs text-amber-900">Nội dung mẫu (demo) – không phải bài viết thật.</p>
        </article>
      </AppModal>

      <AppModal :open="changesOpen" title="Yêu cầu chỉnh sửa" @close="changesOpen = false">
        <form id="changes" @submit.prevent="requestChanges">
          <DTextarea v-model="changes.values.comment" label="Góp ý cho người viết" :rows="4" :error="changes.errors.comment" :maxlength="500" />
        </form>
        <template #footer>
          <AppButton variant="secondary" @click="changesOpen = false">Hủy</AppButton>
          <AppButton type="submit" form="changes" :loading="changes.submitting.value">Gửi yêu cầu</AppButton>
        </template>
      </AppModal>

      <AppModal :open="scheduleOpen" title="Lên lịch đăng bài" @close="scheduleOpen = false">
        <form id="schedule" class="space-y-3" @submit.prevent="saveSchedule">
          <AppInput v-model="schedule.values.at" type="datetime-local" label="Thời điểm đăng (giờ Việt Nam)" :error="schedule.errors.at" />
          <p class="text-xs text-ink-muted">Website: {{ site?.name }} · trạng thái WP: future</p>
        </form>
        <template #footer>
          <AppButton variant="secondary" @click="scheduleOpen = false">Hủy</AppButton>
          <AppButton type="submit" form="schedule" :loading="schedule.submitting.value">Lên lịch</AppButton>
        </template>
      </AppModal>

      <AppModal :open="pickOpen" title="Chọn ảnh đại diện" width="xl" @close="pickOpen = false">
        <p v-if="!pickable.length" class="text-sm text-ink-muted">Chưa có ảnh đã duyệt. <RouterLink :to="`${base}/media`" class="text-brand-700 hover:underline">Mở Media Studio</RouterLink></p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <button v-for="m in pickable" :key="m.id" type="button" class="rounded-lg border-2 p-1 text-left" :class="m.id === article.featuredMediaId ? 'border-brand-600' : 'border-transparent hover:border-line'" @click="pick(m.id)">
            <DemoImage :hue="m.art.hue" :motif="m.art.motif" :ai="m.isAiGenerated" :title="m.altText" />
            <p class="mt-1 truncate text-xs">{{ m.title }}</p>
          </button>
        </div>
      </AppModal>

      <ConfirmDialog :open="restoreNo !== null" title="Khôi phục phiên bản?" :message="`Nội dung của v${restoreNo} sẽ được lưu thành một phiên bản mới (phiên bản không bao giờ bị ghi đè). Lịch sử cũ được giữ nguyên.`" confirm-label="Khôi phục" @close="restoreNo = null" @confirm="restore" />
    </template>
  </div>
</template>
