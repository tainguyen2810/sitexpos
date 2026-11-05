<script setup lang="ts">
import { computed } from 'vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'

const props = defineProps<{ error: unknown }>()
const emit = defineEmits<{ retry: [] }>()
const message = computed(() => (props.error instanceof Error ? props.error.message : 'Đã xảy ra lỗi. Vui lòng thử lại.'))
</script>

<template>
  <div class="flex flex-col items-center px-6 py-10 text-center" role="alert" data-testid="error-state">
    <div class="mb-3 rounded-full bg-danger-50 p-3 text-danger-600"><AppIcon name="alert" :size="24" /></div>
    <p class="text-sm font-medium text-ink">Không tải được dữ liệu</p>
    <p class="mt-1 max-w-md text-sm text-ink-muted">{{ message }}</p>
    <AppButton class="mt-4" variant="secondary" size="sm" @click="emit('retry')"><AppIcon name="refresh" :size="16" /> Thử lại</AppButton>
  </div>
</template>
