<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { systemApi } from '@/shared/api/system'
import { useAsync } from '@/shared/composables/useAsync'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import SystemNav from './SystemNav.vue'
import { formatTime, useSystem } from './useSystem'

const { t, toast, toastError, fieldErrors } = useSystem()
const { te } = useI18n()
const form = reactive({ emails: '', cooldown: '', token: '', chat: '' })
const tokenSet = ref(false)
const view = useAsync(async () => {
  const r = await systemApi.alerts()
  form.emails = r.settings.emails.join(', ')
  form.cooldown = r.settings.cooldown_minutes?.toString() ?? ''
  form.chat = r.settings.telegram_chat_id ?? ''
  tokenSet.value = r.settings.telegram_bot_token_configured
  return r
})
const busy = ref(false)
const errors = ref<Record<string, string>>({})
async function save(clearTelegram = false) {
  busy.value = true
  errors.value = {}
  try {
    const s = await systemApi.updateAlerts({
      emails: form.emails.split(',').map((x) => x.trim()).filter(Boolean),
      cooldown_minutes: form.cooldown.trim() === '' ? null : Number(form.cooldown),
      ...(clearTelegram ? { clear_telegram: true } : { telegram_chat_id: form.chat.trim() || null, ...(form.token ? { telegram_bot_token: form.token.trim() } : {}) }),
    })
    form.token = ''
    tokenSet.value = s.telegram_bot_token_configured
    if (clearTelegram) form.chat = ''
    toast.success(t('sys.common.saved'))
  } catch (e) {
    errors.value = fieldErrors(e)
    toastError(e)
  } finally {
    busy.value = false
  }
}
async function test() {
  try {
    await systemApi.testAlert()
    toast.success(t('sys.common.queued'))
  } catch (e) {
    toastError(e)
  }
}
const sevTone = (s: string) => (s === 'critical' ? 'danger' : s === 'warning' ? 'warning' : s === 'ok' || s === 'recovered' ? 'success' : 'neutral')
const emailError = () => errors.value.emails ?? Object.entries(errors.value).find(([k]) => k.startsWith('emails.'))?.[1]
</script>

<template>
  <div>
    <PageHeader :title="t('sys.nav.alerts')" :description="t('sys.alerts.subtitle')" />
    <SystemNav active="alerts" />
    <LoadingState v-if="view.loading.value && !view.data.value" />
    <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.run" />
    <div v-else-if="view.data.value" class="space-y-6">
      <AppCard>
        <form class="grid grid-cols-1 gap-4 md:grid-cols-2" @submit.prevent="save()">
          <AppInput v-model="form.emails" :label="t('sys.alerts.emails')" :error="emailError()" class="md:col-span-2" data-testid="alert-emails" />
          <AppInput v-model="form.cooldown" type="number" :label="t('sys.alerts.cooldown')" :error="errors.cooldown_minutes" />
          <div />
          <AppInput v-model="form.token" type="password" autocomplete="new-password" :label="t('sys.alerts.telegramToken')" :hint="tokenSet ? t('sys.common.secretKept') : undefined" :error="errors.telegram_bot_token" />
          <AppInput v-model="form.chat" :label="t('sys.alerts.telegramChat')" :error="errors.telegram_chat_id" />
          <div class="md:col-span-2 flex flex-wrap justify-end gap-2">
            <AppButton v-if="tokenSet" variant="ghost" :loading="busy" @click="save(true)">{{ t('sys.alerts.clearTelegram') }}</AppButton>
            <AppButton variant="secondary" data-testid="alert-test" @click="test">{{ t('sys.alerts.test') }}</AppButton>
            <AppButton type="submit" :loading="busy" data-testid="save-alerts">{{ t('common.save') }}</AppButton>
          </div>
        </form>
      </AppCard>

      <AppCard :title="t('sys.alerts.history')">
        <template #actions><AppButton size="sm" variant="secondary" :loading="view.loading.value" @click="view.run">{{ t('sys.common.refresh') }}</AppButton></template>
        <EmptyState v-if="!view.data.value.history.length" :title="t('sys.alerts.empty')" icon="alert" />
        <ul v-else class="divide-y divide-line text-sm">
          <li v-for="a in view.data.value.history" :key="a.id" class="py-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="flex items-center gap-2"><AppBadge :tone="sevTone(a.severity)">{{ te(`sys.alerts.severity.${a.severity}`) ? t(`sys.alerts.severity.${a.severity}`) : a.severity }}</AppBadge><span class="font-mono text-xs">{{ a.check_key }}</span></span>
              <span class="text-xs text-ink-muted">{{ formatTime(a.created_at) }}</span>
            </div>
            <p class="mt-1">{{ a.summary }}</p>
            <p v-if="Object.keys(a.channels).length" class="mt-1 text-xs text-ink-muted">{{ t('sys.alerts.channel') }}: {{ Object.entries(a.channels).map(([k, v]) => `${k}: ${v}`).join(' · ') }}</p>
          </li>
        </ul>
      </AppCard>
    </div>
  </div>
</template>
