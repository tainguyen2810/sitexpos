<script setup lang="ts">
import { watch } from 'vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import { useToast } from '@/shared/composables/useToast'
import DTextarea from '../../ui/DTextarea.vue'
import { db, demoMutation, newId } from '../../store'
import { rules, useForm } from '../../composables/useForm'
import { toDateInput } from '../../format'
import type { DemoCampaign } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const props = defineProps<{ open: boolean; campaign?: DemoCampaign | null }>()
const emit = defineEmits<{ close: []; saved: [id: string] }>()
const toast = useToast()
const { ws, sites } = useWorkspace()

const today = toDateInput(new Date().toISOString())
const form = useForm(
  () => ({ name: '', siteId: '', goal: '', audience: '', startDate: today, endDate: toDateInput(new Date(Date.now() + 90 * 86_400_000).toISOString()), targetArticles: '20', postsPerWeek: '3' }),
  {
    name: [rules.required(), rules.minLength(4), rules.maxLength(80)],
    siteId: [rules.required('Chọn website cho chiến dịch.')],
    goal: [rules.required(), rules.minLength(10)],
    startDate: [rules.required()],
    endDate: [rules.required(), (v, all) => (String(v) > String(all.startDate) ? true : 'Ngày kết thúc phải sau ngày bắt đầu.')],
    targetArticles: [rules.required(), rules.min(1), rules.max(500)],
    postsPerWeek: [rules.required(), rules.min(1), rules.max(21)],
  },
)
watch(
  () => props.open,
  (o) => {
    if (!o) return
    const c = props.campaign
    form.reset(
      c
        ? { name: c.name, siteId: c.siteId, goal: c.goal, audience: c.audience, startDate: toDateInput(c.startDate), endDate: toDateInput(c.endDate), targetArticles: String(c.targetArticles), postsPerWeek: String(c.postsPerWeek) }
        : { siteId: sites.value.find((s) => s.status === 'connected')?.id ?? '' },
    )
  },
)

async function save() {
  await form
    .submit(async () => {
      const v = form.values
      const id = await demoMutation(() => {
        const data = {
          name: v.name.trim(),
          siteId: v.siteId,
          goal: v.goal.trim(),
          audience: v.audience.trim(),
          startDate: new Date(v.startDate).toISOString(),
          endDate: new Date(v.endDate).toISOString(),
          targetArticles: Number(v.targetArticles),
          postsPerWeek: Number(v.postsPerWeek),
        }
        const existing = props.campaign ? db.campaigns.find((c) => c.id === props.campaign?.id) : undefined
        if (existing) {
          Object.assign(existing, data)
          return existing.id
        }
        const c: DemoCampaign = { id: newId('cmp'), workspaceId: ws.value.id, status: 'draft', createdAt: new Date().toISOString(), ...data }
        db.campaigns.push(c)
        return c.id
      })
      toast.success(props.campaign ? 'Đã cập nhật chiến dịch.' : 'Đã tạo chiến dịch ở trạng thái Nháp.')
      emit('saved', id)
      emit('close')
    })
    .catch((e: Error) => toast.error(e.message))
}
</script>

<template>
  <AppModal :open="open" :title="campaign ? 'Sửa chiến dịch' : 'Tạo chiến dịch SEO'" width="lg" @close="emit('close')">
    <form id="campaign-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
      <AppInput v-model="form.values.name" label="Tên chiến dịch" class="sm:col-span-2" :error="form.errors.name" placeholder="Ví dụ: Nội thất căn hộ nhỏ Q1" />
      <AppSelect v-model="form.values.siteId" label="Website" :error="form.errors.siteId" :options="[{ value: '', label: '— Chọn website —' }, ...sites.filter((s) => s.status !== 'revoked').map((s) => ({ value: s.id, label: s.name }))]" />
      <AppInput v-model="form.values.audience" label="Đối tượng mục tiêu" placeholder="Ví dụ: Gia đình trẻ" />
      <DTextarea v-model="form.values.goal" label="Mục tiêu" class="sm:col-span-2" :rows="2" :maxlength="200" :error="form.errors.goal" placeholder="Ví dụ: Tăng traffic tự nhiên cho nhóm sản phẩm…" />
      <AppInput v-model="form.values.startDate" type="date" label="Bắt đầu" :error="form.errors.startDate" />
      <AppInput v-model="form.values.endDate" type="date" label="Kết thúc" :error="form.errors.endDate" />
      <AppInput v-model="form.values.targetArticles" type="number" label="Số bài mục tiêu" :error="form.errors.targetArticles" />
      <AppInput v-model="form.values.postsPerWeek" type="number" label="Bài / tuần" :error="form.errors.postsPerWeek" />
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="emit('close')">Hủy</AppButton>
      <AppButton type="submit" form="campaign-form" :loading="form.submitting.value">{{ campaign ? 'Lưu thay đổi' : 'Tạo chiến dịch' }}</AppButton>
    </template>
  </AppModal>
</template>
