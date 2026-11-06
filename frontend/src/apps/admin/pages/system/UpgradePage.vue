<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError } from '@/shared/api/client'
import { systemApi } from '@/shared/api/system'
import { useAsync } from '@/shared/composables/useAsync'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import ReauthModal from './ReauthModal.vue'
import SystemNav from './SystemNav.vue'
import { formatTime, useSystem } from './useSystem'

const { t, toast, message } = useSystem()
const status = useAsync(() => systemApi.upgrade())
const s = computed(() => status.data.value)
const runTone = (x: string) => (x === 'succeeded' ? 'success' : x === 'failed' ? 'danger' : 'brand')

const confirm = ref(false)
const backupFirst = ref(true)
const busy = ref(false)
const error = ref<string | undefined>()
async function start(password: string) {
  busy.value = true
  error.value = undefined
  try {
    await systemApi.startUpgrade(password, backupFirst.value)
    confirm.value = false
    toast.success(t('sys.upgrade.started'))
    await status.run()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422 && e.fieldError('password')) error.value = e.fieldError('password')
    else {
      confirm.value = false
      toast.error(message(e))
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('sys.nav.upgrade')" :description="t('sys.upgrade.subtitle')" />
    <SystemNav active="upgrade" />
    <LoadingState v-if="status.loading.value && !s" />
    <ErrorState v-else-if="status.error.value" :error="status.error.value" @retry="status.run" />
    <div v-else-if="s" class="space-y-6">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <AppCard>
          <p class="text-sm text-ink-muted">{{ t('sys.upgrade.current') }}</p>
          <p class="mt-1 font-mono text-xl font-semibold" data-testid="upgrade-current">{{ s.current_version ?? t('sys.upgrade.notRecorded') }}</p>
        </AppCard>
        <AppCard>
          <p class="text-sm text-ink-muted">{{ t('sys.upgrade.target') }}</p>
          <p class="mt-1 font-mono text-xl font-semibold">{{ s.target_version }}</p>
        </AppCard>
        <AppCard>
          <p class="text-sm text-ink-muted">{{ t('sys.upgrade.lastBackup') }}</p>
          <p class="mt-1 text-sm font-medium">{{ formatTime(s.backup.last_success_at) }}</p>
          <div class="mt-1 flex gap-2">
            <AppBadge :tone="s.backup.recent ? 'success' : 'warning'">{{ t('sys.upgrade.recent') }}: {{ s.backup.recent ? t('sys.common.yes') : t('sys.common.no') }}</AppBadge>
            <AppBadge :tone="s.backup.verified ? 'success' : 'neutral'">{{ t('sys.upgrade.verified') }}: {{ s.backup.verified ? t('sys.common.yes') : t('sys.common.no') }}</AppBadge>
          </div>
        </AppCard>
      </div>

      <AppCard v-if="!s.upgrade_needed"><p class="text-sm text-success-600" data-testid="upgrade-uptodate">{{ t('sys.upgrade.upToDate') }}</p></AppCard>

      <AppCard v-else :title="t('sys.upgrade.pending')">
        <p v-if="!s.migrations.pending.length" class="text-sm text-ink-muted">{{ t('sys.upgrade.noPending') }}</p>
        <ul v-else class="divide-y divide-line text-sm">
          <li v-for="m in s.migrations.pending" :key="m.name" class="flex flex-wrap items-center justify-between gap-2 py-2">
            <span class="break-all font-mono text-xs">{{ m.name }}</span>
            <AppBadge v-if="m.destructive" tone="danger">{{ t('sys.upgrade.destructive') }}</AppBadge>
          </li>
        </ul>
        <p v-if="s.migrations.destructive.length" class="mt-3 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600">{{ t('sys.upgrade.destructiveBlock') }}</p>
        <div v-if="s.requirements.failed.length" class="mt-3 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600">
          <p class="font-medium">{{ t('sys.upgrade.requirements') }}</p>
          <ul class="ml-5 list-disc"><li v-for="r in s.requirements.failed" :key="r.key"><span class="font-mono">{{ r.key }}</span>: {{ r.value }}<span v-if="r.fix"> — {{ r.fix }}</span></li></ul>
        </div>
        <div v-if="s.blockers.length" class="mt-3 flex flex-wrap gap-2">
          <AppBadge v-for="b in s.blockers" :key="b" tone="danger">{{ t(`sys.upgrade.blockers.${b}`) }}</AppBadge>
        </div>
        <div class="mt-4 flex justify-end">
          <AppButton :disabled="!s.can_upgrade" data-testid="upgrade-start" @click="confirm = true">{{ t('sys.upgrade.start') }}</AppButton>
        </div>
      </AppCard>

      <AppCard v-if="s.changelog.length" :title="t('sys.upgrade.changelog')">
        <div v-for="c in s.changelog" :key="c.version" class="mb-4 last:mb-0">
          <p class="font-medium"><span class="font-mono">{{ c.version }}</span> <span v-if="c.date" class="text-sm text-ink-muted">· {{ c.date }}</span></p>
          <ul class="ml-5 list-disc text-sm text-ink-muted"><li v-for="(n, i) in c.notes" :key="i">{{ n }}</li></ul>
        </div>
      </AppCard>

      <AppCard :title="t('sys.upgrade.sshTitle')">
        <ol class="ml-5 list-decimal space-y-1 text-sm">
          <li v-for="(step, i) in ($tm('sys.upgrade.ssh') as string[])" :key="i">{{ $rt(step) }}</li>
        </ol>
      </AppCard>

      <AppCard :title="t('sys.upgrade.runs')">
        <EmptyState v-if="!s.runs.length" :title="t('sys.upgrade.noRuns')" icon="layers" />
        <ul v-else class="divide-y divide-line text-sm">
          <li v-for="r in s.runs" :key="r.id" class="py-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="font-mono">{{ r.from_version ?? '—' }} → {{ r.to_version }}</span>
              <span class="flex items-center gap-2"><span class="text-xs text-ink-muted">{{ formatTime(r.created_at) }}</span><AppBadge :tone="runTone(r.status)">{{ t(`sys.upgrade.runStatus.${r.status}`) }}</AppBadge></span>
            </div>
            <p v-if="r.steps.length" class="mt-1 text-xs text-ink-muted">{{ r.steps.map((x) => `${x.step}: ${x.status}`).join(' · ') }}</p>
            <p v-if="r.error" class="mt-1 break-all text-xs text-danger-600">{{ r.error }}</p>
          </li>
        </ul>
      </AppCard>
    </div>

    <ReauthModal :open="confirm" :title="t('sys.upgrade.confirmTitle')" :body="t('sys.upgrade.confirmBody')" :loading="busy" :error="error" @close="confirm = false" @submit="start">
      <Toggle v-model="backupFirst" :label="t('sys.upgrade.backupFirst')" data-testid="backup-first" />
    </ReauthModal>
  </div>
</template>
