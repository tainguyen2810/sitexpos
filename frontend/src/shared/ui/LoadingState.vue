<script setup lang="ts">
defineProps<{ rows?: number }>()
</script>

<template>
  <div class="space-y-3 p-5" role="status" aria-live="polite" data-testid="loading-state">
    <span class="sr-only">{{ $t('common.loading') }}</span>
    <div v-for="i in rows ?? 3" :key="i" class="flex animate-pulse items-center gap-3">
      <div class="h-9 w-9 rounded-full bg-slate-200" />
      <div class="flex-1 space-y-2">
        <div class="h-3 w-1/3 rounded bg-slate-200" />
        <div class="h-3 w-1/2 rounded bg-slate-100" />
      </div>
    </div>
  </div>
</template>
