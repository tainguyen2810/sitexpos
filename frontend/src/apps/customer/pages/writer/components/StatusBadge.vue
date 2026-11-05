<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { statusTone, type ArticleStatus } from '@/shared/api/writer'
import AppBadge from '@/shared/ui/AppBadge.vue'

defineProps<{ status: ArticleStatus }>()
const { t } = useI18n()
</script>

<template>
  <AppBadge :tone="statusTone(status)" :data-status="status">{{ t(`writer.status.${status}`) }}</AppBadge>
</template>
