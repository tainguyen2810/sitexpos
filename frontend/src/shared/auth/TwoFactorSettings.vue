<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { authApi } from '@/shared/api/auth'
import { ApiError } from '@/shared/api/client'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import SafeHtml from '@/shared/ui/SafeHtml.vue'

const emit = defineEmits<{ enabled: [] }>()
const { t } = useI18n()
const toast = useToast()
const session = useSessionStore()
const { errors, formError, submitting, submit } = useApiForm()

type Step = 'idle' | 'password' | 'qr' | 'codes'
const step = ref<Step>('idle')
const intent = ref<'enable' | 'disable' | 'codes'>('enable')
const password = ref('')
const code = ref('')
const qrSvg = ref('')
const secret = ref('')
const recovery = ref<string[]>([])

function start(which: 'enable' | 'disable' | 'codes') {
  intent.value = which
  password.value = ''
  step.value = 'password'
}

/** Fortify requires a fresh password confirmation for 2FA changes. */
async function withPasswordConfirmed<T>(fn: () => Promise<T>): Promise<T> {
  try {
    return await fn()
  } catch (e) {
    if (e instanceof ApiError && e.status === 423) {
      await authApi.confirmPassword(password.value)
      return fn()
    }
    throw e
  }
}

async function onPassword() {
  const ok = await submit(async () => {
    await authApi.confirmPassword(password.value)
    if (intent.value === 'enable') {
      await withPasswordConfirmed(() => authApi.enableTwoFactor())
      const [qr, key] = await Promise.all([authApi.twoFactorQr(), authApi.twoFactorSecret()])
      qrSvg.value = qr.svg
      secret.value = key.secretKey
      step.value = 'qr'
    } else if (intent.value === 'disable') {
      await withPasswordConfirmed(() => authApi.disableTwoFactor())
      await session.fetchMe()
      toast.success(t('account.twoFactorDisabled'))
      step.value = 'idle'
    } else {
      await withPasswordConfirmed(() => authApi.regenerateRecoveryCodes())
      recovery.value = await authApi.recoveryCodes()
      step.value = 'codes'
    }
    return true
  })
  if (!ok) return
}

async function onConfirmCode() {
  const ok = await submit(async () => {
    await authApi.confirmTwoFactor(code.value.replace(/\s/g, ''))
    recovery.value = await authApi.recoveryCodes()
    return true
  })
  if (!ok) return
  await session.fetchMe()
  toast.success(t('account.twoFactorEnabled'))
  step.value = 'codes'
  emit('enabled')
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="text-sm font-medium">{{ t('account.twoFactor') }}
          <AppBadge :tone="session.user?.two_factor_enabled ? 'success' : 'warning'" class="ml-2">
            {{ session.user?.two_factor_enabled ? t('account.twoFactorOn') : t('account.twoFactorOff') }}
          </AppBadge>
        </p>
        <p class="mt-1 text-sm text-ink-muted">{{ t('account.twoFactorHelp') }}</p>
      </div>
      <div v-if="step === 'idle'" class="flex gap-2">
        <template v-if="session.user?.two_factor_enabled">
          <AppButton variant="secondary" size="sm" @click="start('codes')">{{ t('account.regenerate') }}</AppButton>
          <AppButton variant="danger" size="sm" @click="start('disable')">{{ t('account.disable2fa') }}</AppButton>
        </template>
        <AppButton v-else size="sm" @click="start('enable')">{{ t('account.enable2fa') }}</AppButton>
      </div>
    </div>

    <p v-if="formError" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ formError }}</p>

    <form v-if="step === 'password'" class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="onPassword">
      <div class="flex-1"><AppInput v-model="password" type="password" autocomplete="current-password" :label="t('account.confirmPasswordFirst')" :error="errors.password" required /></div>
      <AppButton type="submit" :loading="submitting">{{ t('common.confirm') }}</AppButton>
      <AppButton variant="ghost" @click="step = 'idle'">{{ t('common.cancel') }}</AppButton>
    </form>

    <div v-if="step === 'qr'" class="space-y-4 rounded-lg border border-line p-4">
      <p class="text-sm text-ink-muted">{{ t('account.scanQr') }}</p>
      <SafeHtml :html="qrSvg" class="inline-block rounded bg-white p-2" />
      <p class="break-all font-mono text-xs text-ink-muted">{{ t('account.secretKey', { key: secret }) }}</p>
      <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="onConfirmCode">
        <div class="flex-1"><AppInput v-model="code" :label="t('auth.twoFactorCode')" autocomplete="one-time-code" :error="errors.code" required /></div>
        <AppButton type="submit" :loading="submitting">{{ t('auth.verify') }}</AppButton>
      </form>
    </div>

    <div v-if="step === 'codes'" class="space-y-3 rounded-lg border border-warning-600/30 bg-warning-50 p-4">
      <p class="text-sm font-medium">{{ t('account.recoveryCodes') }}</p>
      <p class="text-sm text-ink-muted">{{ t('account.recoveryHelp') }}</p>
      <ul class=" ">
        <li v-for="c in recovery" :key="c">{{ c }}</li>
      </ul>
      <AppButton size="sm" variant="secondary" @click="step = 'idle'">{{ t('common.close') }}</AppButton>
    </div>
  </div>
</template>
