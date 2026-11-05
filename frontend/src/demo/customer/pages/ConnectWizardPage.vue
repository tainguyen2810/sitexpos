<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import { db, demoMutation, newId, sleep } from '../../store'
import { rules, useForm } from '../../composables/useForm'
import { downloadFile } from '../../download'
import type { DemoSite } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { ws, base, sites, crumbs } = useWorkspace()

const steps = ['Thông tin website', 'Cài plugin', 'Ghép nối', 'Cấu hình', 'Hoàn tất']
const resume = sites.value.find((s) => s.id === route.query.site && s.status === 'pending_pairing')
const step = ref(resume ? 2 : 0)
const site = ref<DemoSite | null>(resume ?? null)
const plan = computed(() => db.plans.find((p) => p.code === ws.value.planCode)!)
const atLimit = computed(() => !resume && plan.value.limits.sites !== -1 && sites.value.filter((s) => s.status !== 'revoked').length >= plan.value.limits.sites)

const info = useForm(() => ({ name: '', url: 'https://', language: 'vi' }), {
  name: [rules.required(), rules.maxLength(80)],
  url: [
    rules.required(),
    rules.url(),
    (v) => {
      try {
        const host = new URL(String(v)).hostname
        if (['localhost', '127.0.0.1'].includes(host) || /^(10|192\.168|172\.(1[6-9]|2\d|3[01]))\./.test(host)) return 'Không chấp nhận địa chỉ nội bộ (chống SSRF).'
        if (sites.value.some((s) => new URL(s.url).hostname === host && s.status !== 'revoked')) return 'Website này đã có trong workspace.'
      } catch {
        return true
      }
      return true
    },
  ],
})

async function submitInfo() {
  await info
    .submit(async () => {
      await demoMutation(() => {
        const u = new URL(String(info.values.url))
        const created: DemoSite = {
          id: newId('site'),
          workspaceId: ws.value.id,
          name: String(info.values.name).trim(),
          url: `https://${u.hostname}`,
          status: 'pending_pairing',
          language: String(info.values.language),
          wpVersion: '—',
          pluginVersion: '—',
          phpVersion: '—',
          approvalMode: ws.value.settings.approvalMode,
          postsCount: 0,
          managedPosts: 0,
          categories: [],
          lastSeenAt: null,
          connectedAt: null,
          createdAt: new Date().toISOString(),
          latencyMs: 0,
          gscConnected: false,
          health: [],
        }
        db.sites.push(created)
        site.value = db.sites[db.sites.length - 1]!
      })
      step.value = 1
    })
    .catch((e: Error) => toast.error(e.message))
}

function downloadPlugin() {
  downloadFile(
    'sitex-post-plugin-DEMO.txt',
    'Đây là tệp DEMO thay cho gói plugin sitex-post.zip.\nTrong bản thật, bạn tải plugin, vào WordPress > Plugins > Add New > Upload, kích hoạt, rồi mở Settings > SiteX Post để nhập mã ghép nối.\n',
  )
  toast.success('Đã tải tệp hướng dẫn plugin (bản demo).')
}

// Pairing: 15-minute single-use code, plugin calls back (simulated).
const code = ref('')
const expiresAt = ref(0)
const now = ref(Date.now())
const timer = setInterval(() => (now.value = Date.now()), 1000)
onBeforeUnmount(() => clearInterval(timer))
const remaining = computed(() => Math.max(0, Math.round((expiresAt.value - now.value) / 1000)))
const remainingText = computed(() =>
  remaining.value ? `Mã hết hạn sau ${Math.floor(remaining.value / 60)}:${String(remaining.value % 60).padStart(2, '0')} · chỉ dùng một lần` : 'Mã đã hết hạn.',
)
function generateCode() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
  code.value = Array.from({ length: 8 }, () => chars[Math.floor(Math.random() * chars.length)]).join('').replace(/^(.{4})/, '$1-')
  expiresAt.value = Date.now() + 15 * 60_000
}
function goPairing() {
  if (!code.value || !remaining.value) generateCode()
  step.value = 2
}
async function copyCode() {
  try {
    await navigator.clipboard.writeText(code.value)
    toast.success('Đã sao chép mã ghép nối.')
  } catch {
    toast.error('Trình duyệt không cho phép sao chép. Hãy chọn và sao chép thủ công.')
  }
}
const verifying = ref(false)
async function simulatePluginConfirm() {
  const s = site.value
  if (!s) return
  if (!remaining.value) {
    toast.error('Mã đã hết hạn. Hãy tạo mã mới.')
    return
  }
  verifying.value = true
  try {
    await sleep(1200)
    await demoMutation(() => {
      s.status = 'connected'
      s.wpVersion = '6.8.3'
      s.pluginVersion = '1.0.0'
      s.phpVersion = '8.3'
      s.connectedAt = new Date().toISOString()
      s.lastSeenAt = new Date().toISOString()
      s.categories = ['Tin tức', 'Cẩm nang', 'Hỏi đáp']
      s.postsCount = 12
      s.latencyMs = 320
      s.health = [{ at: new Date().toISOString(), ok: true, latencyMs: 320 }]
    })
    toast.success('Plugin đã xác nhận mã ghép nối. Website đã kết nối!')
    step.value = 3
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    verifying.value = false
  }
}

const cfgForm = useForm(() => ({ approvalMode: ws.value.settings.approvalMode, category: 'Cẩm nang', author: '' }), { author: [rules.required('Chọn tài khoản WordPress dùng để đăng bài.')] })
async function submitConfig() {
  await cfgForm
    .submit(async () => {
      await demoMutation(() => {
        if (site.value) site.value.approvalMode = cfgForm.values.approvalMode as 'manual' | 'auto'
      })
      step.value = 4
    })
    .catch((e: Error) => toast.error(e.message))
}
if (resume) generateCode()
</script>

<template>
  <div>
    <PageHeader title="Kết nối website WordPress" description="Kết nối an toàn bằng plugin SiteX Post và mã ghép nối dùng một lần (không cần chia sẻ mật khẩu WordPress)." :crumbs="crumbs({ label: 'Website', to: `${base}/sites` }, { label: 'Kết nối website' })" />

    <ol class="mb-6 grid grid-cols-5 gap-2" aria-label="Các bước">
      <li v-for="(s, i) in steps" :key="s" class="flex flex-col gap-2">
        <div class="h-1.5 rounded-full" :class="i <= step ? 'bg-brand-600' : 'bg-slate-200'" />
        <span class="hidden text-xs sm:block" :class="i === step ? 'font-semibold text-brand-700' : i < step ? 'text-ink' : 'text-ink-subtle'">{{ i + 1 }}. {{ s }}</span>
      </li>
    </ol>
    <p class="mb-4 text-sm font-medium sm:hidden">Bước {{ step + 1 }}/5: {{ steps[step] }}</p>

    <AppCard class="mx-auto max-w-2xl">
      <div v-if="atLimit && step === 0" class="text-center">
        <p class="text-sm text-ink-muted">Gói {{ plan.name }} đã đạt giới hạn {{ plan.limits.sites }} website.</p>
        <RouterLink :to="`${base}/billing`"><AppButton class="mt-4">Nâng cấp gói</AppButton></RouterLink>
      </div>

      <template v-else>
      <form v-if="step === 0" class="space-y-4" novalidate @submit.prevent="submitInfo">
        <h2 class="text-base font-semibold">Thông tin website</h2>
        <AppInput v-model="info.values.name" label="Tên website" placeholder="Ví dụ: Blog Nội Thất Mộc An" :error="info.errors.name" @focusout="info.validateField('name')" />
        <AppInput v-model="info.values.url" label="Địa chỉ website" placeholder="https://tenmien.vn" hint="Chỉ tên miền gốc, hỗ trợ HTTPS. Demo: dùng tên miền .example" :error="info.errors.url" @focusout="info.validateField('url')" />
        <AppSelect v-model="info.values.language" label="Ngôn ngữ nội dung" :options="[{ value: 'vi', label: 'Tiếng Việt' }, { value: 'en', label: 'English' }]" />
        <div class="flex justify-end gap-2 pt-2">
          <AppButton variant="secondary" @click="router.push(`${base}/sites`)">Hủy</AppButton>
          <AppButton type="submit" :loading="info.submitting.value">Tiếp tục</AppButton>
        </div>
      </form>

      <div v-if="step === 1" class="space-y-4">
        <h2 class="text-base font-semibold">Cài plugin SiteX Post</h2>
        <ol class="list-decimal space-y-2 pl-5 text-sm text-ink-muted">
          <li>Tải plugin và vào <strong>WordPress → Plugins → Add New → Upload Plugin</strong>.</li>
          <li>Kích hoạt plugin <strong>SiteX Post</strong> (yêu cầu WordPress ≥ 6.4, PHP ≥ 7.4).</li>
          <li>Mở <strong>Settings → SiteX Post</strong>, sẵn sàng nhập mã ghép nối ở bước sau.</li>
        </ol>
        <AppButton variant="secondary" @click="downloadPlugin"><AppIcon name="download" :size="16" /> Tải plugin (tệp demo)</AppButton>
        <div class="flex justify-between gap-2 pt-2">
          <AppButton variant="ghost" @click="step = 0">Quay lại</AppButton>
          <AppButton @click="goPairing">Tôi đã cài plugin</AppButton>
        </div>
      </div>

      <div v-if="step === 2" class="space-y-4 text-center">
        <h2 class="text-base font-semibold">Nhập mã ghép nối vào plugin</h2>
        <p class="text-sm text-ink-muted">Website: <strong>{{ site?.url }}</strong></p>
        <div class="mx-auto inline-flex items-center gap-3 rounded-xl border-2 border-dashed border-brand-200 bg-brand-50 px-6 py-4">
          <span class="font-mono text-3xl font-bold tracking-widest text-brand-700" data-testid="pairing-code">{{ code }}</span>
          <button type="button" class="rounded p-1.5 text-brand-700 hover:bg-brand-100" aria-label="Sao chép mã" @click="copyCode"><AppIcon name="copy" :size="18" /></button>
        </div>
        <p class="text-sm" :class="remaining < 60 ? 'text-danger-600' : 'text-ink-muted'">
          {{ remainingText }}
        </p>
        <div class="flex flex-wrap justify-center gap-2">
          <AppButton variant="secondary" @click="generateCode"><AppIcon name="refresh" :size="16" /> Tạo mã mới</AppButton>
          <AppButton :loading="verifying" :disabled="!remaining" @click="simulatePluginConfirm"><AppIcon name="zap" :size="16" /> Mô phỏng plugin xác nhận</AppButton>
        </div>
        <p class="text-xs text-ink-subtle">Chế độ demo: không có plugin thật gọi về, nút trên mô phỏng bước plugin gửi mã + khóa công khai.</p>
        <div class="flex justify-start pt-2"><AppButton variant="ghost" @click="step = 1">Quay lại</AppButton></div>
      </div>

      <form v-if="step === 3" class="space-y-4" @submit.prevent="submitConfig">
        <h2 class="text-base font-semibold">Cấu hình đăng bài</h2>
        <AppSelect v-model="cfgForm.values.approvalMode" label="Chế độ duyệt" :options="[{ value: 'manual', label: 'Thủ công – duyệt từng bài' }, { value: 'auto', label: 'Tự động – đăng khi đã qua kiểm chứng' }]" />
        <AppSelect v-model="cfgForm.values.category" label="Chuyên mục mặc định" :options="(site?.categories ?? []).map((c) => ({ value: c, label: c }))" />
        <AppSelect v-model="cfgForm.values.author" label="Tài khoản tác giả WordPress" :error="cfgForm.errors.author" :options="[{ value: '', label: '— Chọn tài khoản —' }, { value: 'sitex-bot', label: 'sitex-bot (Editor)' }, { value: 'admin', label: 'admin (Administrator)' }]" />
        <div class="flex justify-end pt-2"><AppButton type="submit" :loading="cfgForm.submitting.value">Hoàn tất</AppButton></div>
      </form>

      <div v-if="step === 4" class="py-6 text-center">
        <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-full bg-success-50 text-success-600"><AppIcon name="check" :size="28" /></div>
        <h2 class="text-lg font-semibold">Đã kết nối {{ site?.name }}</h2>
        <p class="mt-1 text-sm text-ink-muted">Bạn có thể tạo chiến dịch SEO hoặc chạy SEO Audit cho website này.</p>
        <div class="mt-6 flex flex-wrap justify-center gap-2">
          <AppButton variant="secondary" @click="router.push(`${base}/sites/${site?.id}`)">Xem website</AppButton>
          <AppButton @click="router.push(`${base}/campaigns?new=1`)">Tạo chiến dịch</AppButton>
        </div>
      </div>
      </template>
    </AppCard>
  </div>
</template>
