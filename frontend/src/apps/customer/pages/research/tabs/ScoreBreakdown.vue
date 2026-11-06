<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ScoreComponent } from '@/shared/api/research'

/** Transparent opportunity score: every component, with "no data" ones shown but not counted. */
defineProps<{ components: ScoreComponent[]; confidence?: number | null; penalty?: number }>()
const { t, te } = useI18n()
const label = (key: string) => (te(`research.score.${key}`) ? t(`research.score.${key}`) : key)
</script>

<template>
  <div>
    <p class="mb-2 text-xs text-ink-muted">{{ t('research.internalEstimateHint') }}</p>
    <ul class="space-y-1.5 text-sm">
      <li v-for="c in components" :key="c.key" class="flex items-center gap-2" :class="c.available ? '' : 'text-ink-subtle'" :title="c.note">
        <span class="w-40 shrink-0 truncate">{{ label(c.key) }}</span>
        <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-muted">
          <span v-if="c.available && c.points !== null" class="block h-full rounded-full bg-brand-500" :style="{ width: `${c.max ? Math.round((c.points / c.max) * 100) : 0}%` }" />
        </span>
        <span class="w-24 shrink-0 text-right text-xs tabular-nums">{{ c.available && c.points !== null ? `${c.points}/${c.max}` : t('research.noData') }}</span>
      </li>
    </ul>
    <p v-if="penalty" class="mt-2 text-xs text-warning-600">{{ t('research.score.penalty', { n: penalty }) }}</p>
    <p v-if="confidence !== null && confidence !== undefined" class="mt-1 text-xs text-ink-muted">×{{ confidence }} · {{ t('research.score.confidence') }}</p>
  </div>
</template>
