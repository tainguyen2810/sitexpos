<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { num, pct, signedPct, type RankedRow } from '@/shared/api/analytics'
import { useWorkspaceStore } from '../../stores/workspace'

/** Query / page performance with comparison to the previous period. */
defineProps<{ rows: RankedRow[]; label: string; link?: boolean }>()
const { t } = useI18n()
const ws = useWorkspaceStore()
</script>

<template>
  <div class="overflow-x-auto">
    <table class="min-w-full text-sm" data-testid="ranked-table">
      <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
        <tr>
          <th class="px-4 py-2">{{ label }}</th>
          <th class="px-4 py-2 text-right">{{ t('an.metrics.clicks') }}</th>
          <th class="px-4 py-2 text-right">{{ t('an.metrics.clicks_change') }}</th>
          <th class="px-4 py-2 text-right">{{ t('an.metrics.impressions') }}</th>
          <th class="px-4 py-2 text-right">{{ t('an.metrics.ctr') }}</th>
          <th class="px-4 py-2 text-right">{{ t('an.metrics.position') }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-line">
        <tr v-for="r in rows" :key="r.key">
          <td class="max-w-md truncate px-4 py-2" :title="r.label">
            <RouterLink v-if="link && r.article_id" :to="{ name: 'analytics-article', params: { workspaceId: ws.current!.id, articleId: r.article_id } }" class="text-brand-700 hover:underline">
              {{ r.label }}
            </RouterLink>
            <span v-else>{{ r.label }}</span>
          </td>
          <td class="px-4 py-2 text-right tabular-nums">{{ num(r.clicks) }}</td>
          <td class="px-4 py-2 text-right tabular-nums" :class="(r.clicks_change ?? 0) < 0 ? 'text-danger-600' : (r.clicks_change ?? 0) > 0 ? 'text-success-600' : ''">
            {{ signedPct(r.clicks_change) }}
          </td>
          <td class="px-4 py-2 text-right tabular-nums">{{ num(r.impressions) }}</td>
          <td class="px-4 py-2 text-right tabular-nums">{{ pct(r.ctr) }}</td>
          <td class="px-4 py-2 text-right tabular-nums">
            {{ num(r.position, 1) }}
            <span v-if="r.position_change" class="ml-1 text-xs" :class="r.position_change > 0 ? 'text-success-600' : 'text-danger-600'">
              {{ r.position_change > 0 ? '▲' : '▼' }}{{ num(Math.abs(r.position_change), 1) }}
            </span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
