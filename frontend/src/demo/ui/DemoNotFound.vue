<script setup lang="ts">
import EmptyState from '@/shared/ui/EmptyState.vue'
</script>

<template>
  <div class="grid min-h-screen place-items-center bg-surface-muted p-6">
    <div class="rounded-xl border border-line bg-surface p-6 shadow-sm">
      <EmptyState title="Không tìm thấy trang" description="Trang hoặc dữ liệu demo này không tồn tại (có thể bạn vừa reset dữ liệu demo)." icon="alert">
        <RouterLink to="/" class="text-sm font-medium text-brand-700 hover:underline">Về trang chính</RouterLink>
      </EmptyState>
    </div>
  </div>
</template>
