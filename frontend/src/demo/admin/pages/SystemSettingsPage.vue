<script setup lang="ts">
import { computed, ref } from 'vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import Tabs from '../../ui/Tabs.vue'
import Toggle from '../../ui/Toggle.vue'
import DTextarea from '../../ui/DTextarea.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import { db, demoMutation } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { rules, useForm } from '../../composables/useForm'
import { fmtUsd } from '../../format'
import { adminCrumbs, staffAudit } from '../useAdmin'

const toast = useToast()
const { loading, error, reload } = useDemoQuery()
// Read through a computed so the page follows “Reset demo” (which replaces the object).
const settings = computed(() => db.systemSettings)
const tab = ref('general')
const tabs = [
  { key: 'general', label: 'Chung' },
  { key: 'ai', label: 'Ngân sách AI & job' },
  { key: 'flags', label: 'Tính năng' },
  { key: 'maintenance', label: 'Bảo trì' },
]
const planOptions = computed(() => db.plans.filter((p) => p.isActive).map((p) => ({ value: p.code, label: p.name })))

const general = useForm(() => ({ registrationOpen: settings.value.registrationOpen, defaultPlan: settings.value.defaultPlan, trialDays: String(settings.value.trialDays), supportEmail: settings.value.supportEmail, announcement: settings.value.announcement }), {
  defaultPlan: [rules.required()],
  trialDays: [(v) => (/^\d+$/.test(String(v)) ? true : 'Số nguyên.'), rules.max(60)],
  supportEmail: [rules.required(), rules.email()],
  announcement: [rules.maxLength(200)],
})
async function saveGeneral() {
  await general
    .submit(async () => {
      await demoMutation(() => {
        settings.value.registrationOpen = general.values.registrationOpen
        settings.value.defaultPlan = general.values.defaultPlan
        settings.value.trialDays = Number(general.values.trialDays)
        settings.value.supportEmail = general.values.supportEmail.trim()
        settings.value.announcement = general.values.announcement.trim()
        staffAudit('platform.settings.updated', 'Cài đặt chung')
      })
      toast.success('Đã lưu cài đặt chung.')
    })
    .catch((e: Error) => toast.error(e.message))
}

const ai = useForm(() => ({ budget: String(settings.value.globalAiBudgetUsd), threshold: String(settings.value.alertThresholdPct), action: settings.value.actionOnExceed, concurrency: String(settings.value.maxConcurrentJobs) }), {
  budget: [(v) => (/^\d+(\.\d+)?$/.test(String(v)) && Number(v) > 0 ? true : 'Nhập số USD > 0.'), rules.max(1_000_000)],
  threshold: [(v) => (/^\d+$/.test(String(v)) ? true : 'Số nguyên.'), rules.min(10), rules.max(100)],
  concurrency: [(v) => (/^\d+$/.test(String(v)) ? true : 'Số nguyên.'), rules.min(1), rules.max(200)],
})
const monthCost = computed(() => db.aiUsage.reduce((sum, u) => sum + u.costMicroUsd, 0) / 1e6)
async function saveAi() {
  await ai
    .submit(async () => {
      await demoMutation(() => {
        settings.value.globalAiBudgetUsd = Number(ai.values.budget)
        settings.value.alertThresholdPct = Number(ai.values.threshold)
        settings.value.actionOnExceed = ai.values.action as typeof settings.value.actionOnExceed
        settings.value.maxConcurrentJobs = Number(ai.values.concurrency)
        staffAudit('platform.ai_budget.updated', `${fmtUsd(settings.value.globalAiBudgetUsd)} · ${settings.value.alertThresholdPct}% · ${settings.value.actionOnExceed}`)
      })
      toast.success('Đã lưu ngân sách AI.')
    })
    .catch((e: Error) => toast.error(e.message))
}

const FLAGS: Record<string, [string, string]> = {
  gsc_integration: ['Tích hợp Google Search Console', 'Cho phép khách hàng kết nối GSC để xem analytics.'],
  ai_images: ['Tạo ảnh AI', 'Ảnh AI luôn được gắn nhãn và không dùng làm ảnh sản phẩm thật.'],
  auto_publish: ['Tự động lên lịch đăng', 'Chỉ áp dụng cho bài đã qua kiểm chứng. Bài chưa kiểm chứng không bao giờ tự đăng.'],
  internal_linking: ['Gợi ý liên kết nội bộ', 'Đề xuất liên kết giữa các bài trong cùng cụm chủ đề.'],
  refresh_suggestions: ['Gợi ý làm mới bài cũ', 'Phân hệ giai đoạn sau – đang thử nghiệm.'],
}
const flagBusy = ref<string | null>(null)
async function toggleFlag(key: string, value: boolean) {
  flagBusy.value = key
  try {
    await demoMutation(() => {
      settings.value.featureFlags[key] = value
      staffAudit('platform.feature_flag.changed', `${key} = ${value}`)
    })
    toast.success(`${FLAGS[key]?.[0] ?? key}: ${value ? 'đã bật' : 'đã tắt'}.`)
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    flagBusy.value = null
  }
}

const maintOpen = ref(false)
const maintBusy = ref(false)
async function toggleMaintenance() {
  maintBusy.value = true
  try {
    await demoMutation(() => {
      settings.value.maintenanceMode = !settings.value.maintenanceMode
      staffAudit(settings.value.maintenanceMode ? 'platform.maintenance.enabled' : 'platform.maintenance.disabled', 'Chế độ bảo trì')
    })
    toast.success(settings.value.maintenanceMode ? 'Đã bật chế độ bảo trì (mô phỏng).' : 'Đã tắt chế độ bảo trì.')
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    maintBusy.value = false
    maintOpen.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader title="Cài đặt hệ thống" description="Cấu hình toàn nền tảng. Mọi thay đổi được ghi audit log." :crumbs="adminCrumbs({ label: 'Cài đặt hệ thống' })" />

    <LoadingState v-if="loading" :rows="4" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <div v-else class="space-y-6">
      <Tabs v-model="tab" :tabs="tabs" />

      <AppCard v-if="tab === 'general'" title="Đăng ký & hỗ trợ">
        <form class="grid max-w-2xl gap-4 sm:grid-cols-2" novalidate @submit.prevent="saveGeneral">
          <div class="sm:col-span-2 rounded-lg border border-line px-4"><Toggle v-model="general.values.registrationOpen" label="Cho phép đăng ký mới" description="Tắt khi chỉ nhận khách hàng theo lời mời." /></div>
          <AppSelect v-model="general.values.defaultPlan" label="Gói mặc định khi đăng ký" :options="planOptions" :error="general.errors.defaultPlan" />
          <AppInput v-model="general.values.trialDays" label="Số ngày dùng thử" :error="general.errors.trialDays" />
          <AppInput v-model="general.values.supportEmail" type="email" label="Email hỗ trợ" class="sm:col-span-2" :error="general.errors.supportEmail" />
          <DTextarea v-model="general.values.announcement" label="Thông báo hiển thị cho khách hàng" :rows="2" :maxlength="200" class="sm:col-span-2" :error="general.errors.announcement" hint="Để trống nếu không có thông báo." />
          <div class="sm:col-span-2"><AppButton type="submit" :loading="general.submitting.value">Lưu</AppButton></div>
        </form>
      </AppCard>

      <AppCard v-else-if="tab === 'ai'" title="Ngân sách AI toàn hệ thống" :subtitle="`Chi phí 30 ngày qua: ${fmtUsd(monthCost)} (giả lập)`">
        <form class="grid max-w-2xl gap-4 sm:grid-cols-2" novalidate @submit.prevent="saveAi">
          <AppInput v-model="ai.values.budget" label="Ngân sách tháng (USD)" :error="ai.errors.budget" />
          <AppInput v-model="ai.values.threshold" label="Ngưỡng cảnh báo (%)" :error="ai.errors.threshold" />
          <AppSelect v-model="ai.values.action" label="Khi vượt ngân sách" :options="[{ value: 'alert', label: 'Chỉ cảnh báo' }, { value: 'pause_batch', label: 'Tạm dừng job hàng loạt' }, { value: 'pause_all', label: 'Tạm dừng mọi job AI' }]" />
          <AppInput v-model="ai.values.concurrency" label="Số job AI chạy đồng thời tối đa" :error="ai.errors.concurrency" />
          <div class="sm:col-span-2"><AppButton type="submit" :loading="ai.submitting.value">Lưu</AppButton></div>
        </form>
      </AppCard>

      <AppCard v-else-if="tab === 'flags'" title="Cờ tính năng">
        <div class="max-w-2xl divide-y divide-line">
          <Toggle v-for="(v, key) in settings.featureFlags" :key="key" :model-value="v" :label="FLAGS[key]?.[0] ?? key" :description="FLAGS[key]?.[1]" :disabled="flagBusy === key" @update:model-value="(val: boolean) => toggleFlag(String(key), val)" />
        </div>
      </AppCard>

      <AppCard v-else title="Chế độ bảo trì">
        <p class="max-w-2xl text-sm text-ink-muted">Khi bật, khách hàng thấy trang bảo trì, mọi job mới bị giữ lại trong hàng đợi. Nhân viên vẫn truy cập trang quản trị bình thường.</p>
        <p class="mt-3 text-sm">Trạng thái: <strong :class="settings.maintenanceMode ? 'text-danger-600' : 'text-success-600'">{{ settings.maintenanceMode ? 'Đang bảo trì' : 'Hoạt động bình thường' }}</strong></p>
        <AppButton class="mt-4" :variant="settings.maintenanceMode ? 'primary' : 'danger'" @click="maintOpen = true">{{ settings.maintenanceMode ? 'Tắt bảo trì' : 'Bật bảo trì' }}</AppButton>
      </AppCard>
    </div>

    <ConfirmDialog :open="maintOpen" :title="settings.maintenanceMode ? 'Tắt chế độ bảo trì?' : 'Bật chế độ bảo trì?'" :message="settings.maintenanceMode ? 'Khách hàng truy cập lại được ngay, job được xử lý tiếp.' : 'Toàn bộ khách hàng sẽ không dùng được ứng dụng cho đến khi tắt.'" :confirm-label="settings.maintenanceMode ? 'Tắt bảo trì' : 'Bật bảo trì'" :danger="!settings.maintenanceMode" :loading="maintBusy" @close="maintOpen = false" @confirm="toggleMaintenance" />
  </div>
</template>
