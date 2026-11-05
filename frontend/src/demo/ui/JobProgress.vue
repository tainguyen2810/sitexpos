<script setup lang="ts">
import AppIcon from '@/shared/ui/AppIcon.vue'
import ProgressBar from './ProgressBar.vue'

defineProps<{ progress: number; step: string; running: boolean; failed?: string | null; title?: string }>()
</script>

<template>
  <div class="rounded-lg border p-4" :class="failed ? 'border-red-200 bg-danger-50' : 'border-brand-100 bg-brand-50/60'" role="status" aria-live="polite">
    <div class="mb-2 flex items-center gap-2 text-sm font-medium" :class="failed ? 'text-danger-600' : 'text-brand-700'">
      <AppIcon :name="failed ? 'alert' : running ? 'sparkles' : 'check'" :size="16" :class="running ? 'animate-pulse' : ''" />
      <span>{{ failed ?? (running ? step : 'Hoàn tất') }}</span>
      <span class="ml-auto tabular-nums">{{ progress }}%</span>
    </div>
    <ProgressBar :value="progress" :tone="failed ? 'danger' : 'brand'" :label="title ?? 'Tiến độ tác vụ'" />
    <p class="mt-2 text-xs text-ink-muted">Tác vụ mô phỏng trong chế độ demo — không gọi API AI thật, không trừ credit thật.</p>
  </div>
</template>
