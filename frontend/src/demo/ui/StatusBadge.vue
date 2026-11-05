<script setup lang="ts">
import AppBadge from '@/shared/ui/AppBadge.vue'
import { label, tone, type LabelGroup } from '../labels'

defineProps<{ group: LabelGroup; value: string | null | undefined }>()
</script>

<template>
  <AppBadge :tone="tone(group, value)" class="whitespace-nowrap">{{ label(group, value) }}</AppBadge>
</template>
