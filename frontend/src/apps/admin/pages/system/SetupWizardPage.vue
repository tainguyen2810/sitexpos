<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { systemApi, stepTone, type SetupStep } from '@/shared/api/system'
import { useAsync } from '@/shared/composables/useAsync'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import ProgressBar from '@/shared/ui/ProgressBar.vue'
import SetupStepPanel from './SetupStepPanel.vue'
import SystemNav from './SystemNav.vue'
import { formatTime, useSystem } from './useSystem'

const { t } = useSystem()
const route = useRoute()
const router = useRouter()
const overview = useAsync(() => systemApi.setup())
const steps = computed<SetupStep[]>(() => overview.data.value?.steps ?? [])
const activeKey = ref<string>(typeof route.query.step === 'string' ? route.query.step : '')

// Open the first unfinished step by default.
watch(steps, (list) => {
  if (!list.length || list.some((s) => s.key === activeKey.value)) return
  activeKey.value = (list.find((s) => s.status !== 'COMPLETE') ?? list[0]!).key
})
const active = computed(() => steps.value.find((s) => s.key === activeKey.value) ?? null)
function select(key: string) {
  activeKey.value = key
  void router.replace({ query: { step: key } })
}
</script>

<template>
  <div>
    <PageHeader :title="t('sys.nav.setup')" :description="t('sys.setup.subtitle')" />
    <SystemNav active="setup" />
    <LoadingState v-if="overview.loading.value && !overview.data.value" />
    <ErrorState v-else-if="overview.error.value" :error="overview.error.value" @retry="overview.run" />
    <div v-else-if="overview.data.value" class="space-y-6">
      <AppCard>
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div class="min-w-[14rem] flex-1">
            <ProgressBar :value="overview.data.value.progress_percent" :max="100" :label="t('sys.setup.progress')" />
          </div>
          <div class="flex items-center gap-2">
            <AppBadge :tone="overview.data.value.readiness === 'READY' ? 'success' : overview.data.value.readiness === 'NOT_READY' ? 'danger' : 'warning'" data-testid="setup-readiness">
              {{ t(`sys.readiness.${overview.data.value.readiness}`) }}
            </AppBadge>
            <AppButton size="sm" variant="secondary" :loading="overview.loading.value" @click="overview.run">{{ t('sys.common.refresh') }}</AppButton>
          </div>
        </div>
        <p v-if="overview.data.value.completed_at" class="mt-3 text-sm text-success-600">{{ t('sys.setup.completedAt', { time: formatTime(overview.data.value.completed_at) }) }}</p>
      </AppCard>

      <div class="grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
        <nav :aria-label="t('sys.nav.setup')">
          <ol class="space-y-1">
            <li v-for="(s, i) in steps" :key="s.key">
              <button
                type="button"
                class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm"
                :class="activeKey === s.key ? 'bg-brand-50 text-brand-700' : 'hover:bg-surface-muted'"
                :aria-current="activeKey === s.key ? 'step' : undefined"
                :data-testid="`setup-step-${s.key}`"
                @click="select(s.key)"
              >
                <span class="min-w-0">
                  <span class="text-ink-muted">{{ i + 1 }}.</span> {{ t(`sys.setup.steps.${s.key}`) }}
                  <span v-if="!s.required" class="block text-xs text-ink-muted">{{ t('sys.setup.optional') }}</span>
                </span>
                <AppBadge :tone="stepTone(s.status)">{{ t(`sys.stepStatus.${s.status}`) }}</AppBadge>
              </button>
            </li>
          </ol>
        </nav>
        <SetupStepPanel v-if="active" :key="active.key" :step="active" @changed="overview.run" />
      </div>
    </div>
  </div>
</template>
