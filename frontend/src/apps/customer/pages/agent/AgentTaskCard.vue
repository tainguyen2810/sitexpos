<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { decisionTone, taskTone, type AgentTask } from '@/shared/api/agent'
import { micro } from '@/shared/api/analytics'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'

/** One agent task with its policy decision, evidence and (when allowed) approve / reject / retry. */
defineProps<{ task: AgentTask; canAct: boolean; busy?: boolean }>()
const emit = defineEmits<{ approve: [note: string]; reject: [note: string]; retry: [] }>()
const { t } = useI18n()
const note = ref('')
const open = ref(false)
function reasonKey(r: string): string {
  return /^autonomy_level_\d_requires_approval$/.test(r) ? 'level_requires_approval' : r
}
</script>

<template>
  <div class="px-4 py-3 text-sm" :data-testid="`task-${task.id}`">
    <div class="flex flex-wrap items-center gap-2">
      <span class="text-xs text-ink-muted">#{{ task.sequence }}</span>
      <span class="font-medium">{{ t(`ag.tools.${task.tool}`) }}</span>
      <AppBadge :tone="taskTone(task.status)">{{ t(`ag.taskStatus.${task.status}`) }}</AppBadge>
      <AppBadge :tone="decisionTone(task.decision)">{{ t(`ag.decision.${task.decision}`) }}</AppBadge>
      <AppBadge v-if="task.is_demo">DEMO</AppBadge>
      <span class="ml-auto text-xs text-ink-muted">{{ micro(task.estimated_cost_micro_usd) }} · {{ formatDateTime(task.created_at) }}</span>
    </div>
    <p v-if="task.evidence?.reason" class="mt-1 text-xs">{{ task.evidence.reason }}</p>
    <ul v-if="task.decision_reasons.length" class="mt-1 flex flex-wrap gap-1">
      <li v-for="r in task.decision_reasons" :key="r" class="rounded bg-surface-muted px-1.5 py-0.5 text-[11px] text-ink-muted">{{ t(`ag.reasons.${reasonKey(r)}`, r) }}</li>
    </ul>
    <p v-if="task.error_code" class="mt-1 text-xs text-danger-600">{{ t(`an.errors.${task.error_code}`, task.error_message ?? task.error_code) }}</p>
    <button type="button" class="mt-1 text-xs text-brand-700 hover:underline" @click="open = !open">{{ open ? t('ag.task.hide') : t('ag.task.details') }}</button>
    <div v-if="open" class="mt-2 grid gap-2 md:grid-cols-2">
      <div><p class="text-[11px] uppercase text-ink-muted">{{ t('ag.task.input') }}</p><pre class="max-h-40 overflow-auto rounded bg-surface-muted p-2 text-[11px]">{{ JSON.stringify(task.input, null, 2) }}</pre></div>
      <div><p class="text-[11px] uppercase text-ink-muted">{{ t('ag.task.output') }}</p><pre class="max-h-40 overflow-auto rounded bg-surface-muted p-2 text-[11px]">{{ JSON.stringify(task.output ?? task.evidence, null, 2) }}</pre></div>
    </div>
    <div v-if="canAct && task.status === 'awaiting_approval'" class="mt-2 flex flex-wrap items-center gap-2">
      <input v-model="note" maxlength="500" :placeholder="t('an.common.note')" :aria-label="t('an.common.note')" class="min-w-0 flex-1 rounded-lg border border-line bg-surface px-2 py-1 text-xs" />
      <AppButton size="sm" :loading="busy" :data-testid="`approve-${task.id}`" @click="emit('approve', note)">{{ t('ag.task.approve') }}</AppButton>
      <AppButton size="sm" variant="secondary" :loading="busy" @click="emit('reject', note)">{{ t('ag.task.reject') }}</AppButton>
    </div>
    <AppButton v-if="canAct && ['failed', 'blocked'].includes(task.status)" size="sm" variant="ghost" class="mt-2" :loading="busy" @click="emit('retry')">{{ t('ag.task.retry') }}</AppButton>
  </div>
</template>
