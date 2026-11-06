<script setup lang="ts">
import { onBeforeUnmount, ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { analyticsApi, type CopilotQuery } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDate, formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AnalyticsNav from './AnalyticsNav.vue'
import { useAnalytics } from './useAnalytics'

/**
 * SEO Copilot: answers only from this workspace's stored data, keeps facts,
 * inferences and recommendations apart, and never executes anything. The
 * rules answer is immediate; AI wording (if enabled) arrives via the queue.
 */
const { t } = useI18n()
const { workspaceId, siteId, toastError } = useAnalytics()
const history = useAsync(() => analyticsApi.copilotHistory(workspaceId.value))
const question = ref('')
const asking = ref(false)
const current = shallowRef<CopilotQuery | null>(null)
let timer: ReturnType<typeof setTimeout> | null = null
onBeforeUnmount(() => timer && clearTimeout(timer))

const examples = ['why_drop', 'what_first', 'refresh_which', 'opportunities'] as const

async function ask(q = question.value) {
  if (q.trim().length < 3) return
  asking.value = true
  try {
    current.value = (await analyticsApi.ask(workspaceId.value, q.trim(), siteId.value || null)).data
    question.value = ''
    void history.run()
    if (current.value.mode === 'ai_pending') schedule(current.value.id, 0)
  } catch (e) {
    toastError(e)
  } finally {
    asking.value = false
  }
}
function schedule(id: string, n: number) {
  if (n > 10) return
  timer = setTimeout(async () => {
    try {
      const q = (await analyticsApi.copilotQuery(workspaceId.value, id)).data
      if (current.value?.id === id) current.value = q
      if (q.mode === 'ai_pending') schedule(id, n + 1)
    } catch {
      /* the rules answer is already shown */
    }
  }, 2500)
}
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.copilot.subtitle')" />
    <AnalyticsNav active="copilot" all-sites />
    <div class="grid gap-6 lg:grid-cols-3">
      <div class="space-y-4 lg:col-span-2">
        <AppCard>
          <form class="flex flex-col gap-3" @submit.prevent="ask()">
            <label for="copilot-q" class="text-sm font-medium">{{ t('an.copilot.ask') }}</label>
            <textarea id="copilot-q" v-model="question" rows="3" maxlength="1000" class="w-full rounded-lg border border-line bg-surface p-2 text-sm" :placeholder="t('an.copilot.placeholder')" data-testid="copilot-input" />
            <div class="flex flex-wrap items-center gap-2">
              <button v-for="e in examples" :key="e" type="button" class="rounded-full border border-line px-3 py-1 text-xs hover:bg-surface-muted" @click="ask(t(`an.copilot.examples.${e}`))">
                {{ t(`an.copilot.examples.${e}`) }}
              </button>
              <AppButton type="submit" class="ml-auto" :loading="asking" :disabled="question.trim().length < 3">{{ t('an.copilot.send') }}</AppButton>
            </div>
          </form>
        </AppCard>
        <AppCard v-if="current" :title="current.question" data-testid="copilot-answer">
          <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
            <AppBadge :tone="current.mode === 'ai' ? 'brand' : 'neutral'">{{ t(`an.copilot.mode.${current.mode}`) }}</AppBadge>
            <span class="text-ink-muted">{{ t('an.copilot.freshness') }}: {{ current.answer.data_freshness ? formatDate(current.answer.data_freshness) : t('an.common.noData') }}</span>
          </div>
          <p v-if="current.answer.summary" class="mb-4 text-sm">{{ current.answer.summary }}</p>
          <section v-for="k in ['facts', 'inferences', 'recommendations'] as const" :key="k" class="mb-4">
            <h3 class="mb-1 text-sm font-semibold">{{ t(`an.copilot.sections.${k}`) }}</h3>
            <p class="mb-1 text-xs text-ink-muted">{{ t(`an.copilot.hints.${k}`) }}</p>
            <ul v-if="current.answer[k].length" class="list-disc space-y-1 pl-5 text-sm">
              <li v-for="(line, i) in current.answer[k]" :key="i">{{ line }}</li>
            </ul>
            <p v-else class="text-sm text-ink-muted">{{ t('an.common.noData') }}</p>
          </section>
          <p class="text-xs text-ink-muted">{{ current.answer.disclaimer }}</p>
        </AppCard>
      </div>
      <AppCard :title="t('an.copilot.history')" :padded="false">
        <LoadingState v-if="history.loading.value && !history.data.value" />
        <ErrorState v-else-if="history.error.value" :error="history.error.value" @retry="history.run" />
        <EmptyState v-else-if="!history.data.value?.data.length" icon="sparkles" :title="t('an.copilot.noHistory')" />
        <ul v-else class="divide-y divide-line">
          <li v-for="q in history.data.value.data" :key="q.id">
            <button type="button" class="w-full px-4 py-2 text-left text-sm hover:bg-surface-muted" @click="current = q">
              <span class="line-clamp-2">{{ q.question }}</span>
              <span class="text-xs text-ink-muted">{{ formatDateTime(q.created_at) }}</span>
            </button>
          </li>
        </ul>
      </AppCard>
    </div>
  </div>
</template>
