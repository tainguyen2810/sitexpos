<script setup lang="ts">
import { computed } from 'vue'
import { systemApi, healthTone, type HealthCheck } from '@/shared/api/system'
import { useAsync } from '@/shared/composables/useAsync'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import SystemNav from './SystemNav.vue'
import { detailText, formatTime, useSystem } from './useSystem'

const { t } = useSystem()
const report = useAsync(() => systemApi.health())
const order = { critical: 0, unknown: 1, warning: 2, ok: 3 } as const
const sorted = computed<HealthCheck[]>(() => [...(report.data.value?.checks ?? [])].sort((a, b) => order[a.status] - order[b.status]))
const counts = computed(() => {
  const c = { ok: 0, warning: 0, critical: 0, unknown: 0 }
  for (const x of report.data.value?.checks ?? []) c[x.status]++
  return c
})
</script>

<template>
  <div>
    <PageHeader :title="t('sys.title')" :description="t('sys.health.subtitle')" />
    <SystemNav active="health" />
    <LoadingState v-if="report.loading.value && !report.data.value" />
    <ErrorState v-else-if="report.error.value" :error="report.error.value" @retry="report.run" />
    <div v-else-if="report.data.value" class="space-y-6">
      <AppCard>
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <p class="text-sm text-ink-muted">{{ t('sys.health.overall') }}</p>
            <p class="mt-1 flex items-center gap-2 text-xl font-semibold">
              <AppBadge :tone="healthTone(report.data.value.status)" data-testid="health-overall">{{ t(`sys.healthStatus.${report.data.value.status}`) }}</AppBadge>
            </p>
            <p class="mt-1 text-xs text-ink-muted">{{ t('sys.health.generatedAt', { time: formatTime(report.data.value.generated_at) }) }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2 text-sm">
            <AppBadge tone="danger">{{ t('sys.healthStatus.critical') }}: {{ counts.critical }}</AppBadge>
            <AppBadge tone="warning">{{ t('sys.healthStatus.warning') }}: {{ counts.warning + counts.unknown }}</AppBadge>
            <AppBadge tone="success">{{ t('sys.healthStatus.ok') }}: {{ counts.ok }}</AppBadge>
            <AppButton size="sm" variant="secondary" :loading="report.loading.value" data-testid="health-refresh" @click="report.run">{{ t('sys.common.refresh') }}</AppButton>
          </div>
        </div>
      </AppCard>

      <div class="grid gap-4 md:grid-cols-2">
        <AppCard v-for="c in sorted" :key="c.key" :data-testid="`health-${c.key}`">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium">{{ t(`sys.health.checks.${c.key}`) }}</p>
              <p class="mt-1 text-sm text-ink-muted">{{ c.summary }}</p>
            </div>
            <AppBadge :tone="healthTone(c.status)">{{ t(`sys.healthStatus.${c.status}`) }}</AppBadge>
          </div>
          <p v-if="c.fix" class="mt-3 rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-600">
            <span class="font-medium">{{ t('sys.common.howToFix') }}:</span> {{ c.fix }}
          </p>
          <details v-if="Object.keys(c.details).length" class="mt-3 text-sm">
            <summary class="cursor-pointer text-ink-muted">{{ t('sys.common.details') }}</summary>
            <dl class="mt-2 grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-x-3 gap-y-1 text-xs">
              <template v-for="(v, k) in c.details" :key="k">
                <dt class="truncate font-mono text-ink-muted">{{ k }}</dt>
                <dd class="break-all font-mono">{{ detailText(v) }}</dd>
              </template>
            </dl>
          </details>
        </AppCard>
      </div>
    </div>
  </div>
</template>
