<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { researchApi, runTone, formatUsd, type Campaign } from '@/shared/api/research'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import StatCard from '@/shared/ui/StatCard.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'

const props = defineProps<{ workspaceId: string; campaign: Campaign }>()
const emit = defineEmits<{ navigate: [tab: 'ideas' | 'keywords' | 'clusters' | 'calendar' | 'runs' | 'settings'] }>()
const { t } = useI18n()
const overview = useAsync(() => researchApi.overview(props.workspaceId, props.campaign.id))

const ideaStatuses = ['suggested', 'approved', 'briefed', 'rejected'] as const
const intents = ['informational', 'commercial', 'transactional', 'navigational'] as const
const totalIntents = computed(() => Object.values(overview.data.value?.keywords_by_intent ?? {}).reduce((a, b) => a + Number(b), 0))
</script>

<template>
  <LoadingState v-if="overview.loading.value" :rows="4" />
  <AppCard v-else-if="overview.error.value"><ErrorState :error="overview.error.value" @retry="overview.run" /></AppCard>
  <div v-else-if="overview.data.value" class="space-y-6">
    <div class=" ">
      <button type="button" class="text-left" @click="emit('navigate', 'keywords')"><StatCard :label="t('research.overview.keywords')" :value="overview.data.value.campaign.counts?.keywords ?? 0" icon="key" /></button>
      <button type="button" class="text-left" @click="emit('navigate', 'ideas')"><StatCard :label="t('research.overview.ideas')" :value="overview.data.value.campaign.counts?.ideas ?? 0" icon="sparkles" /></button>
      <button type="button" class="text-left" @click="emit('navigate', 'clusters')"><StatCard :label="t('research.overview.gaps')" :value="overview.data.value.open_gaps" icon="layers" /></button>
      <button type="button" class="text-left" @click="emit('navigate', 'calendar')"><StatCard :label="t('research.overview.upcoming')" :value="overview.data.value.upcoming" icon="calendar" /></button>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <AppCard :title="t('research.overview.lastRun')" class="lg:col-span-2">
        <p v-if="!overview.data.value.last_run" class="text-sm text-ink-muted">{{ t('research.run.emptyBody') }}</p>
        <template v-else>
          <div class="flex flex-wrap items-center gap-2 text-sm">
            <AppBadge :tone="runTone(overview.data.value.last_run.status)">{{ t(`research.run.status.${overview.data.value.last_run.status}`) }}</AppBadge>
            <span class="text-ink-muted">{{ formatDateTime(overview.data.value.last_run.created_at) }} · {{ t(`research.run.trigger.${overview.data.value.last_run.trigger}`) }}</span>
            <button type="button" class="ml-auto text-sm font-medium text-brand-700 hover:underline" @click="emit('navigate', 'runs')">{{ t('common.open') }}</button>
          </div>
          <dl class=" ">
            <div v-for="(v, k) in overview.data.value.last_run.stats" :key="k" class="rounded-lg bg-surface-muted p-3">
              <dt class="text-xs text-ink-muted">{{ t(`research.run.stat.${k}`) }}</dt>
              <dd class="text-lg font-semibold tabular-nums">{{ v }}</dd>
            </div>
          </dl>
        </template>
      </AppCard>
      <AppCard :title="t('research.overview.schedule')">
        <p v-if="!overview.data.value.schedule?.is_enabled" class="text-sm text-ink-muted">{{ t('research.overview.scheduleOff') }}</p>
        <p v-else class="text-sm">
          {{ t(`research.settings.freq.${overview.data.value.schedule.frequency}`) }} ·
          {{ t('research.overview.nextRun', { time: formatDateTime(overview.data.value.schedule.next_run_at) }) }}
        </p>
        <div class="mt-4 space-y-2 text-sm">
          <p class="text-ink-muted">{{ t('research.campaigns.usage', { used: overview.data.value.usage.runs.used, limit: overview.data.value.usage.runs.limit }) }}</p>
          <ProgressBar tone="auto" :value="overview.data.value.usage.runs.used" :max="overview.data.value.usage.runs.limit ?? 0" />
          <p class="text-ink-muted">{{ t('research.overview.aiCost') }}: {{ formatUsd(overview.data.value.usage.ai_cost_micro_usd.used) }}</p>
        </div>
        <button type="button" class="mt-3 text-sm font-medium text-brand-700 hover:underline" @click="emit('navigate', 'settings')">{{ t('research.tabs.settings') }}</button>
      </AppCard>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <AppCard :title="t('research.overview.ideasByStatus')">
        <ul class="space-y-2 text-sm">
          <li v-for="s in ideaStatuses" :key="s" class="flex items-center justify-between">
            <span>{{ t(`research.ideas.status.${s}`) }}</span>
            <span class="font-semibold tabular-nums">{{ overview.data.value.ideas_by_status[s] ?? 0 }}</span>
          </li>
        </ul>
      </AppCard>
      <AppCard :title="t('research.overview.intents')">
        <ul class="space-y-3 text-sm">
          <li v-for="i in intents" :key="i">
            <div class="mb-1 flex justify-between"><span>{{ t(`research.intent.${i}`) }}</span><span class="tabular-nums text-ink-muted">{{ overview.data.value.keywords_by_intent[i] ?? 0 }}</span></div>
            <ProgressBar :value="Number(overview.data.value.keywords_by_intent[i] ?? 0)" :max="totalIntents" />
          </li>
        </ul>
      </AppCard>
    </div>
  </div>
</template>
