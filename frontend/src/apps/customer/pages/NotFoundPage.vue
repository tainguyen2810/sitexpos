<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import EmptyState from '@/shared/ui/EmptyState.vue'

const route = useRoute()
const suspended = computed(() => route.query.reason === 'workspace_suspended')
</script>

<template>
  <div class="grid min-h-screen place-items-center bg-surface-muted p-6">
    <EmptyState icon="alert" :title="suspended ? $t('statuses.suspended') : '404'" :description="suspended ? $t('errors.forbidden') : $t('errors.notFound')">
      <RouterLink to="/" class="text-sm font-medium text-brand-600 hover:underline">{{ $t('common.back') }}</RouterLink>
    </EmptyState>
  </div>
</template>
