<script setup lang="ts">
import { computed } from 'vue'
import { formatTokens, formatUsd, type WorkflowRun } from '@/shared/api/ai'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import WorkflowStatusBadge from './WorkflowStatusBadge.vue'
import { aiErrorMessage } from './aiErrors'

const props = defineProps<{ run: WorkflowRun }>()
const out = computed(() => props.run.output ?? null)
const json = computed(() => (out.value?.json ? JSON.stringify(out.value.json, null, 2) : null))
const usage = computed(() => out.value?.usage ?? {})
</script>

<template>
  <div class="space-y-5 text-sm">
    <div class="flex flex-wrap items-center gap-2">
      <WorkflowStatusBadge :status="run.status" />
      <AppBadge v-if="run.cancel_requested && run.status !== 'canceled'" tone="warning">{{ $t('ai.cancelRequested') }}</AppBadge>
      <span class="text-ink-muted">{{ $t('ai.attempts') }}: {{ run.attempts }}/{{ run.max_attempts }} · {{ $t('ai.cycle') }} {{ run.cycle }} · {{ $t('ai.queue') }}: {{ run.queue }}</span>
    </div>

    <p v-if="run.status === 'retry_scheduled' && run.next_attempt_at" class="text-warning-600">{{ $t('ai.nextAttempt', { time: formatDateTime(run.next_attempt_at) }) }}</p>
    <p v-if="run.error" class="rounded-lg bg-danger-50 px-3 py-2 text-danger-600" role="alert" data-testid="run-error">
      <span class="font-mono text-xs">{{ run.error.code }}</span> — {{ aiErrorMessage(run.error) }}
    </p>

    <div v-if="run.input.prompt">
      <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">{{ $t('ai.prompt') }}</p>
      <p class="mt-1 whitespace-pre-wrap break-words">{{ run.input.prompt }}</p>
    </div>

    <div v-if="out && (out.text || json)">
      <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">{{ $t('ai.result') }}</p>
      <pre v-if="json" class="mt-1 max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-surface-muted p-3 text-xs" data-testid="run-json">{{ json }}</pre>
      <p v-else class="mt-1 whitespace-pre-wrap break-words" data-testid="run-text">{{ out.text }}</p>
    </div>

    <dl v-if="out && out.model" class="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-4" data-testid="run-usage">
      <div><dt class="text-xs text-ink-muted">{{ $t('ai.model') }}</dt><dd class="font-medium">{{ out.model_key ?? out.model }}</dd></div>
      <div><dt class="text-xs text-ink-muted">{{ $t('ai.cost') }}</dt><dd class="font-medium">{{ formatUsd(out.cost_micro_usd) }}</dd></div>
      <div><dt class="text-xs text-ink-muted">{{ $t('ai.tokensIn') }}</dt><dd>{{ formatTokens(usage.input_tokens) }}</dd></div>
      <div><dt class="text-xs text-ink-muted">{{ $t('ai.tokensOut') }}</dt><dd>{{ formatTokens(usage.output_tokens) }}</dd></div>
      <div v-if="usage.cached_input_tokens"><dt class="text-xs text-ink-muted">{{ $t('ai.cachedTokens') }}</dt><dd>{{ formatTokens(usage.cached_input_tokens) }}</dd></div>
      <div v-if="usage.searches"><dt class="text-xs text-ink-muted">{{ $t('ai.searches') }}</dt><dd>{{ usage.searches }}</dd></div>
    </dl>
    <div v-if="out && (out.used_fallback || out.downgraded)" class="flex flex-wrap gap-2">
      <AppBadge v-if="out.used_fallback" tone="warning">{{ $t('ai.usedFallback') }}</AppBadge>
      <AppBadge v-if="out.downgraded" tone="warning">{{ $t('ai.downgraded') }}</AppBadge>
    </div>

    <div v-if="run.steps?.length">
      <ol class="flex flex-wrap gap-2">
        <li v-for="s in run.steps" :key="s.key" class="rounded-lg border border-line px-3 py-1.5">
          <span class="font-medium">{{ $te(`ai.steps.${s.key}`) ? $t(`ai.steps.${s.key}`) : s.key }}</span>
          <span class="ml-2 text-xs text-ink-muted">{{ $t(`ai.stepStatuses.${s.status}`) }}</span>
        </li>
      </ol>
    </div>

    <div v-if="run.events?.length">
      <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">{{ $t('ai.timeline') }}</p>
      <ul class="mt-2 space-y-1">
        <li v-for="(e, i) in run.events" :key="i" class="flex gap-3 text-xs">
          <span class="w-36 shrink-0 text-ink-muted">{{ formatDateTime(e.at) }}</span>
          <span>{{ $te(`ai.events.${e.event}`) ? $t(`ai.events.${e.event}`) : e.event }}<span v-if="e.details?.error_code" class="ml-1 font-mono text-ink-muted">({{ e.details.error_code }})</span></span>
        </li>
      </ul>
    </div>
  </div>
</template>
