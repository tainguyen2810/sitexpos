<script setup lang="ts">
import { ref } from 'vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import Toggle from './Toggle.vue'
import DemoResetDialog from './DemoResetDialog.vue'
import { demoSettings, db } from '../store'
import { DEMO_ADMIN_URL, DEMO_CUSTOMER_URL } from '../index'
import { fmtDateTime } from '../format'

defineProps<{ app: 'customer' | 'admin' }>()
const open = ref(false)
const resetOpen = ref(false)
const latencies = [
  { v: 0, l: 'Tức thì' },
  { v: 450, l: 'Bình thường' },
  { v: 1500, l: 'Mạng chậm' },
]
</script>

<template>
  <div class="relative">
    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100" :aria-expanded="open" @click="open = !open">
      <AppIcon name="settings" :size="14" /> <span class="hidden sm:inline">Bảng demo</span>
    </button>
    <div v-if="open" class="absolute right-0 z-40 mt-2 w-80 rounded-xl border border-line bg-surface p-4 text-sm shadow-xl">
      <div class="mb-3 flex items-center justify-between">
        <p class="font-semibold">Điều khiển chế độ demo</p>
        <button type="button" class="rounded p-1 text-ink-muted hover:bg-surface-muted" aria-label="Đóng" @click="open = false"><AppIcon name="x" :size="16" /></button>
      </div>
      <p class="mb-1 text-xs font-medium uppercase tracking-wide text-ink-muted">Độ trễ API giả lập</p>
      <div class="mb-3 grid grid-cols-3 gap-1">
        <button v-for="o in latencies" :key="o.v" type="button" class="rounded-md border px-2 py-1 text-xs" :class="demoSettings.latency === o.v ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-line hover:bg-surface-muted'" @click="demoSettings.latency = o.v">{{ o.l }}</button>
      </div>
      <Toggle v-model="demoSettings.failRequests" label="Mô phỏng lỗi API" description="Mọi lần tải/lưu tiếp theo sẽ thất bại để xem trạng thái lỗi." />
      <div class="mt-3 space-y-2 border-t border-line pt-3">
        <a :href="app === 'customer' ? DEMO_ADMIN_URL : DEMO_CUSTOMER_URL" target="_blank" rel="noopener" class="flex items-center gap-2 text-brand-700 hover:underline">
          <AppIcon name="external" :size="14" /> Mở giao diện {{ app === 'customer' ? 'Super Admin' : 'khách hàng' }}
        </a>
        <p class="text-xs text-ink-muted">Bộ dữ liệu tạo lúc {{ fmtDateTime(db.generatedAt) }}. Dữ liệu lưu trong trình duyệt này.</p>
        <AppButton variant="danger" size="sm" block @click="resetOpen = true"><AppIcon name="refresh" :size="14" /> Reset dữ liệu demo</AppButton>
      </div>
    </div>
    <DemoResetDialog :open="resetOpen" @close="resetOpen = false" />
  </div>
</template>
