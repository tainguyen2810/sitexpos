<script setup lang="ts">
import { computed } from 'vue'
import type { WorkflowStatus } from '@/shared/api/ai'
import AppBadge from '@/shared/ui/AppBadge.vue'

const props = defineProps<{ status: WorkflowStatus }>()
const tone = computed(() => ({
  pending: 'neutral',
  running: 'brand',
  retry_scheduled: 'warning',
  succeeded: 'success',
  failed: 'danger',
  canceled: 'neutral',
}[props.status] as 'neutral' | 'brand' | 'warning' | 'success' | 'danger'))
</script>

<template>
  <AppBadge :tone="tone" :data-status="status">{{ $t(`ai.statuses.${status}`) }}</AppBadge>
</template>
