<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'
import AppButton from './AppButton.vue'
import AppIcon from './AppIcon.vue'

const props = defineProps<{ error: unknown }>()
const emit = defineEmits<{ retry: [] }>()
const { t } = useI18n()

const message = computed(() => {
  const e = props.error
  if (e instanceof ApiError) {
    if (e.status === 0) return t('errors.network')
    if (e.status === 404) return t('errors.notFound')
    if (e.status === 403 && !e.message) return t('errors.forbidden')
    return e.message || t('errors.generic')
  }
  return t('errors.generic')
})
const requestId = computed(() => (props.error instanceof ApiError ? props.error.requestId : null))
</script>

<template>
  <div class="flex flex-col items-center px-6 py-10 text-center" role="alert" data-testid="error-state">
    <div class="mb-3 rounded-full bg-danger-50 p-3 text-danger-600"><AppIcon name="alert" :size="24" /></div>
    <p class="text-sm font-medium text-ink">{{ message }}</p>
    <p v-if="requestId" class="mt-1 text-xs text-ink-subtle">{{ t('errors.requestId', { id: requestId }) }}</p>
    <AppButton class="mt-4" variant="secondary" size="sm" @click="emit('retry')">{{ t('common.retry') }}</AppButton>
  </div>
</template>
