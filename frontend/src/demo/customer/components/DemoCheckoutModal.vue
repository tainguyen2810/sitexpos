<script setup lang="ts">
import { watch } from 'vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import { useToast } from '@/shared/composables/useToast'
import { demoMutation, sleep } from '../../store'
import { useForm } from '../../composables/useForm'
import { fmtVnd } from '../../format'

// Simulated checkout: validates the form like a real one, but never contacts a payment gateway.
const props = defineProps<{ open: boolean; title: string; description: string; amountMinor: number; savedCard?: { brand: string; last4: string } | null }>()
const emit = defineEmits<{ close: []; paid: [method: string] }>()
const toast = useToast()

const luhn = (num: string) => {
  const digits = num.replace(/\D/g, '')
  let sum = 0
  for (let i = 0; i < digits.length; i++) {
    let d = Number(digits[digits.length - 1 - i])
    if (i % 2) {
      d *= 2
      if (d > 9) d -= 9
    }
    sum += d
  }
  return digits.length >= 13 && sum % 10 === 0
}
const form = useForm(() => ({ method: props.savedCard ? 'saved' : 'card', name: '', card: '', exp: '', cvc: '', agree: false }), {
  name: [(v, all) => (all.method !== 'card' || String(v).trim().length >= 3 ? true : 'Nhập tên chủ thẻ.')],
  card: [(v, all) => (all.method !== 'card' || luhn(String(v)) ? true : 'Số thẻ không hợp lệ. Dùng thẻ thử 4242 4242 4242 4242.')],
  exp: [
    (v, all) => {
      if (all.method !== 'card') return true
      const m = /^(\d{2})\/(\d{2})$/.exec(String(v))
      if (!m) return 'Định dạng MM/YY.'
      const exp = new Date(2000 + Number(m[2]), Number(m[1]), 0)
      return Number(m[1]) >= 1 && Number(m[1]) <= 12 && exp.getTime() > Date.now() ? true : 'Thẻ đã hết hạn.'
    },
  ],
  cvc: [(v, all) => (all.method !== 'card' || /^\d{3,4}$/.test(String(v)) ? true : 'CVC gồm 3–4 chữ số.')],
  agree: [(v) => (v ? true : 'Bạn cần đồng ý điều khoản.')],
})
watch(
  () => props.open,
  (o) => o && form.reset({ method: props.savedCard ? 'saved' : 'card' }),
)
async function pay() {
  await form
    .submit(async () => {
      await sleep(800)
      await demoMutation(() => undefined)
      const method = form.values.method === 'saved' && props.savedCard ? `${props.savedCard.brand} •••• ${props.savedCard.last4}` : form.values.method === 'bank' ? 'Chuyển khoản (mô phỏng)' : `Thẻ •••• ${form.values.card.replace(/\D/g, '').slice(-4)}`
      emit('paid', method)
      emit('close')
    })
    .catch((e: Error) => toast.error(e.message))
}
</script>

<template>
  <AppModal :open="open" :title="title" @close="emit('close')">
    <form id="checkout" class="space-y-4" novalidate @submit.prevent="pay">
      <div class="rounded-lg bg-surface-muted p-3 text-sm">
        <p>{{ description }}</p>
        <p class="mt-1 text-lg font-semibold">{{ fmtVnd(amountMinor) }} <span class="text-xs font-normal text-ink-muted">(đã gồm VAT 10%)</span></p>
      </div>
      <div class="rounded-lg border border-amber-200 bg-warning-50 p-3 text-xs text-amber-900"><AppIcon name="info" :size="12" class="inline" /> Thanh toán DEMO: không kết nối cổng thanh toán, không trừ tiền thật. Không nhập thẻ thật.</div>
      <fieldset class="space-y-2 text-sm">
        <legend class="mb-1 font-medium">Phương thức</legend>
        <label v-if="savedCard" class="flex items-center gap-2"><input v-model="form.values.method" type="radio" value="saved" /> {{ savedCard.brand }} •••• {{ savedCard.last4 }} (đã lưu)</label>
        <label class="flex items-center gap-2"><input v-model="form.values.method" type="radio" value="card" /> Thẻ mới (thẻ thử)</label>
        <label class="flex items-center gap-2"><input v-model="form.values.method" type="radio" value="bank" /> Chuyển khoản / QR (mô phỏng)</label>
      </fieldset>
      <div v-if="form.values.method === 'card'" class="grid gap-3 sm:grid-cols-2">
        <AppInput v-model="form.values.name" label="Tên chủ thẻ" class="sm:col-span-2" :error="form.errors.name" autocomplete="off" />
        <AppInput v-model="form.values.card" label="Số thẻ" placeholder="4242 4242 4242 4242" class="sm:col-span-2" :error="form.errors.card" autocomplete="off" />
        <AppInput v-model="form.values.exp" label="Hết hạn" placeholder="MM/YY" :error="form.errors.exp" autocomplete="off" />
        <AppInput v-model="form.values.cvc" label="CVC" placeholder="123" :error="form.errors.cvc" autocomplete="off" />
      </div>
      <div v-else-if="form.values.method === 'bank'" class="rounded-lg border border-dashed border-line p-4 text-center text-sm text-ink-muted">Mã QR chuyển khoản sẽ hiển thị ở đây trong bản thật. Demo coi như đã nhận tiền.</div>
      <label class="flex items-start gap-2 text-sm"><input v-model="form.values.agree" type="checkbox" class="mt-0.5" /> Tôi đồng ý với điều khoản dịch vụ (demo).</label>
      <p v-if="form.errors.agree" class="text-xs text-danger-600">{{ form.errors.agree }}</p>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="emit('close')">Hủy</AppButton>
      <AppButton type="submit" form="checkout" :loading="form.submitting.value">Thanh toán demo {{ fmtVnd(amountMinor) }}</AppButton>
    </template>
  </AppModal>
</template>
