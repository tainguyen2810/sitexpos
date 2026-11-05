<script setup lang="ts">
import EmptyState from '@/shared/ui/EmptyState.vue'
</script>

<template>
  <div class="grid min-h-screen place-items-center bg-surface-muted p-6">
    <EmptyState icon="alert" title="404" :description="$t('errors.notFound')">
      <RouterLink to="/" class="text-sm font-medium text-brand-600 hover:underline">{{ $t('common.back') }}</RouterLink>
    </EmptyState>
  </div>
</template>
