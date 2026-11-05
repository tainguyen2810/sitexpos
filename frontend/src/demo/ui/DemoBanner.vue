<script setup lang="ts">
import { ref } from 'vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import DemoResetDialog from './DemoResetDialog.vue'

const resetOpen = ref(false)
</script>

<template>
  <div class="flex flex-wrap items-center gap-x-3 gap-y-1 bg-amber-100 px-4 py-2 text-xs text-amber-900 sm:px-6" role="note" data-testid="demo-banner">
    <span class="inline-flex items-center gap-1 rounded bg-amber-600 px-1.5 py-0.5 font-bold uppercase tracking-wide text-white"><AppIcon name="info" :size="12" /> Demo</span>
    <span class="flex-1">Chế độ demo (chỉ môi trường development): toàn bộ dữ liệu là <strong>dữ liệu mẫu</strong>, không gọi API AI hay cổng thanh toán thật.</span>
    <button type="button" class="inline-flex items-center gap-1 font-semibold underline-offset-2 hover:underline" @click="resetOpen = true"><AppIcon name="refresh" :size="12" /> Reset dữ liệu demo</button>
    <DemoResetDialog :open="resetOpen" @close="resetOpen = false" />
  </div>
</template>
