<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import Tabs from '../../ui/Tabs.vue'
import Toggle from '../../ui/Toggle.vue'
import DTextarea from '../../ui/DTextarea.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import { db, demoMutation, newId } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { rules, useForm } from '../../composables/useForm'
import { slugify } from '../../data/seed'
import { downloadFile } from '../../download'
import { useWorkspace } from '../useWorkspace'

const toast = useToast()
const router = useRouter()
const { ws, mine, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()
const tab = ref('general')
const tabs = [
  { key: 'general', label: 'Chung' },
  { key: 'content', label: 'Nội dung & AI' },
  { key: 'notify', label: 'Thông báo' },
  { key: 'danger', label: 'Vùng nguy hiểm' },
]
function audit(action: string, subject: string) {
  db.auditLogs.unshift({ id: newId('log'), workspaceId: ws.value.id, actorName: ws.value.ownerName, actorType: 'user', action, subject, ip: '203.0.113.10', createdAt: new Date().toISOString() })
}

// General
const general = useForm(() => ({ name: ws.value.name, slug: ws.value.slug, timezone: ws.value.timezone, language: ws.value.settings.defaultLanguage, phone: ws.value.phone }), {
  name: [rules.required(), rules.minLength(3), rules.maxLength(80)],
  slug: [
    rules.required(),
    (v) => (/^[a-z0-9]+(-[a-z0-9]+)*$/.test(String(v)) ? true : 'Chỉ chữ thường không dấu, số và dấu gạch ngang.'),
    (v) => (db.workspaces.some((w) => w.id !== ws.value.id && w.slug === v) ? 'Slug đã được dùng.' : true),
  ],
  phone: [(v) => (!v || /^(\+84|0)\d{9,10}$/.test(String(v).replace(/[\s.]/g, '')) ? true : 'Số điện thoại không hợp lệ.')],
})
async function saveGeneral() {
  await general
    .submit(async () => {
      await demoMutation(() => {
        ws.value.name = general.values.name.trim()
        ws.value.slug = general.values.slug
        ws.value.timezone = general.values.timezone
        ws.value.phone = general.values.phone
        ws.value.settings.defaultLanguage = general.values.language
        audit('workspace.updated', 'Cài đặt chung')
      })
      toast.success('Đã lưu cài đặt chung.')
    })
    .catch((e: Error) => toast.error(e.message))
}

// Content & AI
const content = useForm(
  () => ({
    tone: ws.value.settings.toneOfVoice,
    banned: ws.value.settings.bannedTerms.join('\n'),
    approvalMode: ws.value.settings.approvalMode,
    autoInternalLinks: ws.value.settings.autoInternalLinks,
  }),
  {
    tone: [rules.required(), rules.minLength(10), rules.maxLength(400)],
    banned: [(v) => (String(v).split('\n').filter((x) => x.trim()).length <= 50 ? true : 'Tối đa 50 cụm từ.')],
  },
)
const bannedList = computed(() => [...new Set(content.values.banned.split('\n').map((x) => x.trim()).filter(Boolean))])
async function saveContent() {
  await content
    .submit(async () => {
      await demoMutation(() => {
        const s = ws.value.settings
        s.toneOfVoice = content.values.tone.trim()
        s.bannedTerms = bannedList.value
        s.approvalMode = content.values.approvalMode as 'manual' | 'auto'
        s.autoInternalLinks = content.values.autoInternalLinks
        audit('workspace.content_settings_updated', `Duyệt: ${s.approvalMode}`)
      })
      toast.success('Đã lưu cài đặt nội dung. Áp dụng cho các bài viết tạo sau.')
    })
    .catch((e: Error) => toast.error(e.message))
}

// Notifications
const notify = ref({ email: ws.value.settings.notifyEmail, weekly: ws.value.settings.notifyWeekly, publishFailed: true, factCheckFailed: true, quota: true })
const savingNotify = ref(false)
async function saveNotify() {
  savingNotify.value = true
  try {
    await demoMutation(() => {
      ws.value.settings.notifyEmail = notify.value.email
      ws.value.settings.notifyWeekly = notify.value.weekly
    })
    toast.success('Đã lưu tùy chọn thông báo (demo không gửi email).')
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    savingNotify.value = false
  }
}

// Reload forms when switching workspace in the topbar.
watch(
  () => ws.value.id,
  () => {
    general.reset()
    content.reset()
    notify.value = { email: ws.value.settings.notifyEmail, weekly: ws.value.settings.notifyWeekly, publishFailed: true, factCheckFailed: true, quota: true }
  },
)

// Danger zone
function exportData() {
  const id = ws.value.id
  const pick = <T extends { workspaceId: string }>(list: T[]) => list.filter((x) => x.workspaceId === id)
  const data = {
    _note: 'DỮ LIỆU DEMO – SiteX Post (không phải dữ liệu thật)',
    exportedAt: new Date().toISOString(),
    workspace: ws.value,
    sites: pick(db.sites),
    campaigns: pick(db.campaigns),
    keywords: pick(db.keywords),
    ideas: pick(db.ideas),
    articles: pick(db.articles),
    factChecks: pick(db.factChecks),
    members: pick(db.members),
  }
  downloadFile(`sitex-${ws.value.slug}-demo-export.json`, JSON.stringify(data, null, 2), 'application/json')
  audit('workspace.exported', 'Xuất dữ liệu JSON')
  toast.success('Đã xuất dữ liệu workspace (JSON).')
}
const deleteOpen = ref(false)
const confirmName = ref('')
const deleting = ref(false)
const canDelete = computed(() => confirmName.value.trim() === ws.value.name)
async function deleteWorkspace() {
  if (!canDelete.value) return
  if (db.workspaces.length <= 1) return toast.error('Demo cần giữ ít nhất một workspace. Dùng “Reset demo” để khôi phục dữ liệu.')
  deleting.value = true
  try {
    const id = ws.value.id
    const name = ws.value.name
    await demoMutation(() => {
      const keep = <T extends { workspaceId: string | null }>(list: T[]) => list.filter((x) => x.workspaceId !== id)
      db.workspaces = db.workspaces.filter((w) => w.id !== id)
      db.members = keep(db.members)
      db.sites = keep(db.sites)
      db.campaigns = keep(db.campaigns)
      db.keywords = keep(db.keywords)
      db.clusters = keep(db.clusters)
      db.ideas = keep(db.ideas)
      db.articles = keep(db.articles)
      db.factChecks = keep(db.factChecks)
      db.media = keep(db.media)
      db.seoAudits = keep(db.seoAudits)
      db.publishJobs = keep(db.publishJobs)
      db.calendar = keep(db.calendar)
      db.subscriptions = keep(db.subscriptions)
      db.usage = keep(db.usage)
      db.credits = keep(db.credits)
      db.auditLogs.unshift({ id: newId('log'), workspaceId: null, actorName: 'Hệ thống', actorType: 'system', action: 'workspace.deleted', subject: name, ip: '203.0.113.10', createdAt: new Date().toISOString() })
    }, 600)
    deleteOpen.value = false
    toast.success(`Đã xóa workspace “${name}”. Bấm “Reset demo” để khôi phục.`)
    await router.push(`/w/${db.workspaces[0]!.id}/dashboard`)
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    deleting.value = false
  }
}
const contentCount = computed(() => mine(db.articles).length)
</script>

<template>
  <div>
    <PageHeader title="Cài đặt workspace" description="Thông tin workspace, quy tắc nội dung cho AI và thông báo." :crumbs="crumbs({ label: 'Cài đặt' })" />

    <LoadingState v-if="loading" :rows="4" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <div v-else class="space-y-6">
      <Tabs v-model="tab" :tabs="tabs" />

      <AppCard v-if="tab === 'general'" title="Thông tin chung">
        <form class="grid max-w-2xl gap-4 sm:grid-cols-2" novalidate @submit.prevent="saveGeneral">
          <AppInput v-model="general.values.name" label="Tên workspace" class="sm:col-span-2" :error="general.errors.name" />
          <div class="sm:col-span-2">
            <AppInput v-model="general.values.slug" label="Slug" :error="general.errors.slug" hint="Dùng trong đường dẫn nội bộ." />
            <button type="button" class="mt-1 text-xs text-brand-700 hover:underline" @click="general.values.slug = slugify(general.values.name.replace('(Demo)', ''))">Tạo từ tên</button>
          </div>
          <AppSelect v-model="general.values.timezone" label="Múi giờ" :options="[{ value: 'Asia/Ho_Chi_Minh', label: 'GMT+7 – Hồ Chí Minh' }, { value: 'Asia/Bangkok', label: 'GMT+7 – Bangkok' }, { value: 'Asia/Singapore', label: 'GMT+8 – Singapore' }]" />
          <AppSelect v-model="general.values.language" label="Ngôn ngữ nội dung mặc định" :options="[{ value: 'vi', label: 'Tiếng Việt' }, { value: 'en', label: 'English' }]" />
          <AppInput v-model="general.values.phone" label="Số điện thoại liên hệ" :error="general.errors.phone" />
          <div class="sm:col-span-2"><AppButton type="submit" :loading="general.submitting.value">Lưu thay đổi</AppButton></div>
        </form>
      </AppCard>

      <AppCard v-else-if="tab === 'content'" title="Quy tắc nội dung & AI" subtitle="Áp dụng cho mọi tác vụ AI (mô phỏng trong demo)">
        <form class="max-w-2xl space-y-5" novalidate @submit.prevent="saveContent">
          <DTextarea v-model="content.values.tone" label="Giọng văn thương hiệu" :rows="3" :maxlength="400" :error="content.errors.tone" hint="Ví dụ: thân thiện, chuyên môn, xưng “chúng tôi”, tránh từ lóng." />
          <div>
            <DTextarea v-model="content.values.banned" label="Từ/cụm từ cấm" :rows="4" :error="content.errors.banned" hint="Mỗi dòng một cụm từ. Bài viết chứa cụm từ cấm sẽ bị đánh dấu khi kiểm tra SEO." />
            <div v-if="bannedList.length" class="mt-2 flex flex-wrap gap-1.5">
              <span v-for="t in bannedList" :key="t" class="rounded-full bg-danger-50 px-2 py-0.5 text-xs text-red-700">{{ t }}</span>
            </div>
          </div>
          <fieldset class="space-y-2">
            <legend class="mb-1 text-sm font-medium">Chế độ duyệt bài</legend>
            <label class="flex items-start gap-2 text-sm"><input v-model="content.values.approvalMode" type="radio" value="manual" class="mt-1" /><span><span class="font-medium">Duyệt thủ công</span><span class="block text-xs text-ink-muted">Mọi bài cần người duyệt trước khi lên lịch đăng.</span></span></label>
            <label class="flex items-start gap-2 text-sm"><input v-model="content.values.approvalMode" type="radio" value="auto" class="mt-1" /><span><span class="font-medium">Tự động lên lịch khi đạt kiểm chứng</span><span class="block text-xs text-ink-muted">Chỉ bài đã qua Fact-Check mới được lên lịch. Bài chưa kiểm chứng không bao giờ tự đăng.</span></span></label>
          </fieldset>
          <div class="divide-y divide-line rounded-lg border border-line px-4">
            <Toggle :model-value="true" disabled label="Bắt buộc kiểm chứng trước khi đăng" description="Luôn bật – quy tắc an toàn nội dung của SiteX Post." />
            <Toggle v-model="content.values.autoInternalLinks" label="Gợi ý liên kết nội bộ" description="Đề xuất liên kết tới bài cùng cụm chủ đề khi viết." />
          </div>
          <AppButton type="submit" :loading="content.submitting.value">Lưu quy tắc</AppButton>
        </form>
      </AppCard>

      <AppCard v-else-if="tab === 'notify'" title="Thông báo">
        <div class="max-w-2xl divide-y divide-line">
          <Toggle v-model="notify.email" label="Nhận thông báo qua email" description="Gửi tới email chủ sở hữu." />
          <Toggle v-model="notify.weekly" label="Báo cáo tuần" description="Tóm tắt bài đã đăng, traffic và credit mỗi thứ Hai." />
          <Toggle v-model="notify.publishFailed" label="Đăng bài thất bại" description="Báo ngay khi WordPress từ chối hoặc mất kết nối." />
          <Toggle v-model="notify.factCheckFailed" label="Kiểm chứng không đạt" />
          <Toggle v-model="notify.quota" label="Sắp hết hạn mức / credit" description="Khi dùng quá 80% hạn mức của gói." />
        </div>
        <AppButton class="mt-4" :loading="savingNotify" @click="saveNotify">Lưu tùy chọn</AppButton>
      </AppCard>

      <div v-else class="space-y-4">
        <AppCard title="Xuất dữ liệu">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-ink-muted">Tải toàn bộ website, chiến dịch, từ khóa, ý tưởng và {{ contentCount }} bài viết dưới dạng JSON.</p>
            <AppButton variant="secondary" @click="exportData"><AppIcon name="download" :size="16" /> Xuất JSON</AppButton>
          </div>
        </AppCard>
        <div class="rounded-xl border border-red-200 bg-surface p-5">
          <h2 class="font-semibold text-red-700">Xóa workspace</h2>
          <p class="mt-1 text-sm text-ink-muted">Xóa vĩnh viễn workspace, ngắt mọi website và hủy gói cước. Bài đã đăng trên WordPress không bị xóa. Trong demo có thể khôi phục bằng “Reset demo”.</p>
          <AppButton class="mt-4" variant="danger" @click="(confirmName = ''), (deleteOpen = true)"><AppIcon name="trash" :size="16" /> Xóa workspace</AppButton>
        </div>
      </div>
    </div>

    <AppModal :open="deleteOpen" title="Xóa workspace?" width="sm" @close="deleteOpen = false">
      <p class="text-sm text-ink-muted">Thao tác này không thể hoàn tác trong bản thật. Nhập <strong class="text-ink">{{ ws.name }}</strong> để xác nhận.</p>
      <AppInput v-model="confirmName" class="mt-3" label="Tên workspace" autocomplete="off" />
      <template #footer>
        <AppButton variant="secondary" @click="deleteOpen = false">Hủy</AppButton>
        <AppButton variant="danger" :disabled="!canDelete" :loading="deleting" @click="deleteWorkspace">Xóa vĩnh viễn</AppButton>
      </template>
    </AppModal>
  </div>
</template>
