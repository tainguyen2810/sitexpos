<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { authApi } from '@/shared/api/auth'
import { ApiError } from '@/shared/api/client'
import type { SessionInfo } from '@/shared/api/types'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useAsync } from '@/shared/composables/useAsync'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
import { setLocale } from '@/shared/i18n'
import { TIMEZONES, formatDateTime } from '@/shared/utils/format'
import TwoFactorSettings from '@/shared/auth/TwoFactorSettings.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'

const { t } = useI18n()
const toast = useToast()
const session = useSessionStore()
const user = computed(() => session.user!)

// ---- profile ----
const profile = ref({ name: user.value.name, locale: user.value.locale, timezone: user.value.timezone })
const profileForm = useApiForm()
const tzOptions = computed(() => (TIMEZONES.includes(profile.value.timezone) ? TIMEZONES : [profile.value.timezone, ...TIMEZONES]).map((z) => ({ value: z, label: z })))
const localeOptions = [
  { value: 'vi', label: 'Tiếng Việt' },
  { value: 'en', label: 'English' },
]
async function saveProfile() {
  const res = await profileForm.submit(() => authApi.updateProfile(profile.value))
  if (!res) return
  session.user = res
  setLocale(res.locale)
  toast.success(t('common.saved'))
}

// ---- password ----
const pwd = ref({ current_password: '', password: '', password_confirmation: '' })
const pwdForm = useApiForm()
async function changePassword() {
  const ok = await pwdForm.submit(async () => {
    await authApi.updatePassword(pwd.value)
    return true
  })
  if (!ok) return
  pwd.value = { current_password: '', password: '', password_confirmation: '' }
  toast.success(t('account.passwordChanged'))
  await sessions.run()
}

// ---- sessions ----
const sessions = useAsync(() => authApi.sessions())
async function revoke(s: SessionInfo) {
  try {
    await authApi.revokeSession(s.id)
    await sessions.run()
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  }
}
const othersOpen = ref(false)
const othersPassword = ref('')
const othersForm = useApiForm()
async function revokeOthers() {
  const ok = await othersForm.submit(() => authApi.revokeOtherSessions(othersPassword.value))
  if (!ok) return
  othersOpen.value = false
  othersPassword.value = ''
  await sessions.run()
}

function device(ua: string | null): string {
  if (!ua) return t('account.unknownDevice')
  const browser = /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : null
  const os = /Windows/.test(ua) ? 'Windows' : /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Mac OS/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : null
  return [browser, os].filter(Boolean).join(' · ') || t('account.unknownDevice')
}
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ $t('account.title') }}</h1>

    <AppCard :title="$t('account.profile')">
      <form class="grid max-w-xl gap-4" novalidate @submit.prevent="saveProfile">
        <p v-if="profileForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ profileForm.formError.value }}</p>
        <AppInput :model-value="user.email" :label="$t('auth.email')" disabled />
        <AppInput v-model="profile.name" :label="$t('auth.name')" :error="profileForm.errors.name" />
        <AppSelect v-model="profile.locale" :label="$t('account.language')" :options="localeOptions" :error="profileForm.errors.locale" />
        <AppSelect v-model="profile.timezone" :label="$t('account.timezone')" :options="tzOptions" :error="profileForm.errors.timezone" />
        <div><AppButton type="submit" :loading="profileForm.submitting.value">{{ $t('common.save') }}</AppButton></div>
      </form>
    </AppCard>

    <AppCard :title="$t('account.changePassword')">
      <form class="grid max-w-xl gap-4" novalidate @submit.prevent="changePassword">
        <p v-if="pwdForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ pwdForm.formError.value }}</p>
        <AppInput v-model="pwd.current_password" :label="$t('account.currentPassword')" type="password" autocomplete="current-password" :error="pwdForm.errors.current_password" />
        <AppInput v-model="pwd.password" :label="$t('auth.newPassword')" type="password" autocomplete="new-password" :hint="$t('auth.passwordHint')" :error="pwdForm.errors.password" />
        <AppInput v-model="pwd.password_confirmation" :label="$t('auth.passwordConfirmation')" type="password" autocomplete="new-password" />
        <div><AppButton type="submit" :loading="pwdForm.submitting.value">{{ $t('account.changePassword') }}</AppButton></div>
      </form>
    </AppCard>

    <TwoFactorSettings />

    <AppCard :title="$t('account.sessions')" :subtitle="$t('account.sessionsHelp')" :padded="false">
      <template #actions>
        <AppButton v-if="(sessions.data.value?.data.length ?? 0) > 1" size="sm" variant="secondary" @click="othersOpen = true">{{ $t('account.revokeOthers') }}</AppButton>
      </template>
      <LoadingState v-if="sessions.loading.value" class="p-5" />
      <ErrorState v-else-if="sessions.error.value" :error="sessions.error.value" class="p-5" @retry="sessions.run" />
      <p v-else-if="sessions.data.value && !sessions.data.value.meta.supported" class="p-5 text-sm text-ink-muted">{{ $t('account.sessionsUnsupported') }}</p>
      <ul v-else-if="sessions.data.value" class="divide-y divide-line">
        <li v-for="s in sessions.data.value.data" :key="s.id" class="flex flex-wrap items-center gap-3 px-5 py-4">
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium">{{ device(s.user_agent) }} <AppBadge v-if="s.is_current" tone="success">{{ $t('account.thisDevice') }}</AppBadge></p>
            <p class="text-xs text-ink-muted">{{ s.ip_address ?? '—' }} · {{ $t('account.lastActive', { time: formatDateTime(s.last_active_at) }) }}</p>
          </div>
          <AppButton v-if="!s.is_current" size="sm" variant="ghost" @click="revoke(s)">{{ $t('account.revoke') }}</AppButton>
        </li>
      </ul>
    </AppCard>

    <AppModal :open="othersOpen" :title="$t('account.revokeOthers')" width="sm" @close="othersOpen = false">
      <form id="others-form" class="space-y-4" @submit.prevent="revokeOthers">
        <p v-if="othersForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ othersForm.formError.value }}</p>
        <AppInput v-model="othersPassword" :label="$t('common.password')" type="password" autocomplete="current-password" :error="othersForm.errors.password" />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="othersOpen = false">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="others-form" :loading="othersForm.submitting.value">{{ $t('common.confirm') }}</AppButton>
      </template>
    </AppModal>
  </div>
</template>
