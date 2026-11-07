<script setup lang="ts">
import { reactive, ref } from 'vue'
import { ApiError } from '@/shared/api/client'
import { formatBytes, systemApi, type BackupItem } from '@/shared/api/system'
import { useAsync } from '@/shared/composables/useAsync'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import ReauthModal from './ReauthModal.vue'
import SystemNav from './SystemNav.vue'
import { formatTime, useSystem } from './useSystem'

const { t, toast, toastError, message, fieldErrors } = useSystem()
const schedule = reactive({ schedule_enabled: false, schedule_time: '02:00' })
const view = useAsync(async () => {
  const r = await systemApi.backups()
  Object.assign(schedule, r.schedule)
  return r
})
const busy = ref(false)
const errors = ref<Record<string, string>>({})
async function saveSchedule() {
  busy.value = true
  errors.value = {}
  try {
    Object.assign(schedule, await systemApi.updateBackup({ ...schedule }))
    toast.success(t('sys.common.saved'))
  } catch (e) {
    errors.value = fieldErrors(e)
    toastError(e)
  } finally {
    busy.value = false
  }
}

const confirm = ref(false)
const pwError = ref<string | undefined>()
async function runNow(password: string) {
  busy.value = true
  pwError.value = undefined
  try {
    await systemApi.startBackup(password)
    confirm.value = false
    toast.success(t('sys.backups.started'))
    await view.run()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422 && e.fieldError('password')) pwError.value = e.fieldError('password')
    else {
      confirm.value = false
      toast.error(message(e))
    }
  } finally {
    busy.value = false
  }
}
async function verify(b: BackupItem) {
  try {
    await systemApi.verifyBackup(b.id)
    toast.success(t('sys.common.queued'))
  } catch (e) {
    toastError(e)
  }
}
const tone = (s: string) => (s === 'succeeded' ? 'success' : s === 'failed' ? 'danger' : 'brand')
const verifyTone = (s: string | null) => (s === 'verified' ? 'success' : s === 'failed' ? 'danger' : 'neutral')
</script>

<template>
  <div>
    <PageHeader :title="t('sys.nav.backups')" :description="t('sys.backups.subtitle')" />
    <SystemNav active="backups" />
    <LoadingState v-if="view.loading.value && !view.data.value" />
    <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.run" />
    <div v-else-if="view.data.value" class="space-y-6">
      <p v-if="!view.data.value.configured" class="rounded-lg bg-danger-50 px-4 py-2 text-sm text-danger-600" data-testid="backup-not-configured">{{ t('sys.backups.notConfigured') }}</p>

      <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
        <AppCard :title="t('sys.backups.schedule')">
          <form class="space-y-3" @submit.prevent="saveSchedule">
            <Toggle v-model="schedule.schedule_enabled" :label="t('sys.backups.enabled')" />
            <AppInput v-model="schedule.schedule_time" :label="t('sys.backups.time')" :error="errors.schedule_time" placeholder="02:00" />
            <div class="flex justify-end"><AppButton type="submit" :loading="busy" data-testid="save-schedule">{{ t('common.save') }}</AppButton></div>
          </form>
        </AppCard>
        <AppCard :title="t('sys.backups.offsite')">
          <p class="text-sm">
            <span v-if="view.data.value.offsite_disk" class="font-mono">{{ view.data.value.offsite_disk }}</span>
            <span v-else class="text-warning-600">{{ t('sys.backups.offsiteNone') }}</span>
          </p>
          <p class="mt-2 text-sm text-ink-muted">{{ t('sys.backups.retention', { days: view.data.value.retention_days }) }}</p>
          <p class="mt-2 text-sm text-ink-muted">{{ t('sys.backups.restoreHint') }}</p>
          <div class="mt-4 flex justify-end"><AppButton :disabled="!view.data.value.configured" data-testid="backup-run" @click="confirm = true">{{ t('sys.backups.runNow') }}</AppButton></div>
        </AppCard>
      </div>

      <AppCard :padded="false">
        <div class="flex items-center justify-end p-3"><AppButton size="sm" variant="secondary" :loading="view.loading.value" @click="view.run">{{ t('sys.common.refresh') }}</AppButton></div>
        <EmptyState v-if="!view.data.value.items.length" :title="t('sys.backups.empty')" icon="server" />
        <div v-else class="overflow-x-auto">
          <table class="data-table w-full text-sm">
            <thead class="border-y border-line text-left text-xs text-ink-muted">
              <tr><th class="px-4 py-2">{{ t('sys.backups.file') }}</th><th class="px-4">{{ t('sys.backups.trigger') }}</th><th class="px-4">{{ t('sys.backups.size') }}</th><th class="px-4">{{ t('sys.backups.status') }}</th><th class="px-4" /></tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="b in view.data.value.items" :key="b.id" :data-testid="`backup-${b.id}`">
                <td class="px-4 py-3">
                  <p class="break-all font-mono text-xs">{{ b.file_name ?? '—' }}</p>
                  <p class="text-xs text-ink-muted">{{ formatTime(b.finished_at ?? b.started_at) }} · {{ b.contents.join(', ') }}</p>
                  <p v-if="b.error" class="break-all text-xs text-danger-600">{{ b.error }}</p>
                </td>
                <td class="px-4">{{ t(`sys.backups.triggers.${b.trigger}`) }}</td>
                <td class="whitespace-nowrap px-4">{{ formatBytes(b.size_bytes) }}</td>
                <td class="px-4">
                  <div class="flex flex-wrap gap-1">
                    <AppBadge :tone="tone(b.status)">{{ t(`sys.backups.statuses.${b.status}`) }}</AppBadge>
                    <AppBadge v-if="b.status === 'succeeded'" :tone="verifyTone(b.verify_status)">{{ t(`sys.backups.verifyStatus.${b.verify_status ?? 'pending'}`) }}</AppBadge>
                  </div>
                </td>
                <td class="px-4 text-right"><AppButton v-if="b.status === 'succeeded'" size="sm" variant="ghost" @click="verify(b)">{{ t('sys.backups.verify') }}</AppButton></td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>
    </div>

    <ReauthModal :open="confirm" :title="t('sys.backups.runTitle')" :body="t('sys.backups.runBody')" :loading="busy" :error="pwError" @close="confirm = false" @submit="runNow" />
  </div>
</template>
