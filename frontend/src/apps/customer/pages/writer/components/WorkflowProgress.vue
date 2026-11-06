<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { isActiveWorkflow, type Workflow } from '@/shared/api/writer'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'

defineProps<{ workflow: Workflow; compact?: boolean }>()
const { t } = useI18n()
const tone = (s: string) => (s === 'succeeded' ? 'success' : s === 'failed' ? 'danger' : s === 'running' ? 'brand' : 'neutral') as 'success' | 'danger' | 'brand' | 'neutral'
</script>

<template>
  <div class="space-y-3" data-testid="workflow-progress">
    <div class="flex flex-wrap items-center gap-2 text-sm">
      <span class="font-medium">{{ t(`writer.kind.${workflow.kind}`) }}</span>
      <AppBadge :tone="tone(workflow.status)">{{ t(`writer.runStatus.${workflow.status}`) }}</AppBadge>
      <span v-if="isActiveWorkflow(workflow) && workflow.current_step_label" class="flex items-center gap-1 text-ink-muted">
        <AppIcon name="refresh" :size="14" class="animate-spin" /> {{ workflow.current_step_label }}
      </span>
      <span class="ml-auto text-xs text-ink-muted">{{ formatDateTime(workflow.started_at ?? workflow.created_at) }}</span>
    </div>
    <ProgressBar :value="workflow.progress" :max="100" :tone="workflow.status === 'failed' ? 'danger' : 'brand'" />
    <p v-if="workflow.error" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">
      {{ workflow.error.message || workflow.error.code }}
    </p>
    <ol v-if="!compact" class="grid gap-1 text-sm sm:grid-cols-2">
      <li v-for="s in workflow.steps" :key="s.key" class="flex items-center gap-2">
        <AppIcon
:name="s.status === 'succeeded' ? 'check' : s.status === 'failed' ? 'alert' : s.status === 'running' ? 'refresh' : 'clock'" :size="14"
          :class="s.status === 'succeeded' ? 'text-success-600' : s.status === 'failed' ? 'text-danger-600' : 'text-ink-muted'" />
        <span :class="s.status === 'pending' ? 'text-ink-muted' : ''">{{ s.label }}</span>
        <span v-if="s.attempts > 1" class="text-xs text-ink-muted">({{ t('writer.article.attempts', { n: s.attempts }) }})</span>
        <span v-if="s.error" class="truncate text-xs text-danger-600" :title="s.error.message ?? ''">{{ s.error.code }}</span>
      </li>
    </ol>
  </div>
</template>
