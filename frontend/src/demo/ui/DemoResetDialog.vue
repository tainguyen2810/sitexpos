<script setup lang="ts">
import { ref } from 'vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import { useToast } from '@/shared/composables/useToast'
import { resetDemo, sleep } from '../store'

defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: [] }>()
const toast = useToast()
const loading = ref(false)

async function confirm() {
  loading.value = true
  await sleep(600)
  resetDemo()
  loading.value = false
  emit('close')
  toast.success('Đã khôi phục bộ dữ liệu demo ban đầu.')
}
</script>

<template>
  <ConfirmDialog :open="open" title="Reset dữ liệu demo?" message="Mọi thay đổi bạn đã thực hiện trong chế độ demo (website, bài viết, cài đặt…) sẽ bị xóa và bộ dữ liệu mẫu ban đầu được khôi phục. Chỉ ảnh hưởng trình duyệt này." confirm-label="Reset demo" danger :loading="loading" @close="emit('close')" @confirm="confirm" />
</template>
