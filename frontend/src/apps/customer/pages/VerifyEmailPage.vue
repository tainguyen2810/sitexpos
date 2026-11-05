<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { authApi } from '@/shared/api/auth'
import { ApiError } from '@/shared/api/client'
import { useSessionStore } from '@/shared/stores/session'
import { useToast } from '@/shared/composables/useToast'
import AuthLayout from '@/shared/layouts/AuthLayout.vue'
import AppButton from '@/shared/ui/AppButton.vue'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const toast = useToast()
const { t } = useI18n()

const state = ref<'notice' | 'verifying' | 'failed'>('notice')
const resending = ref(false)

function next() {
  const r = route.query.redirect
  return typeof r === 'string' && r.startsWith('/') && !r.startsWith('//') ? r : '/'
}

onMounted(async () => {
  const link = route.query.link
  if (typeof link === 'string') {
    state.value = 'verifying'
    try {
      await authApi.verifyEmail(link)
      await session.fetchMe()
      toast.success(t('auth.verified'))
      await router.replace(next())
    } catch {
      state.value = 'failed'
    }
    return
  }
  if (session.user?.email_verified) await router.replace(next())
})

async function resend() {
  resending.value = true
  try {
    await authApi.resendVerification()
    toast.success(t('auth.verifyResent'))
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  } finally {
    resending.value = false
  }
}

async function logout() {
  await session.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <AuthLayout :title="$t('auth.verifyTitle')">
    <p v-if="state === 'verifying'" class="text-sm text-ink-muted" role="status">{{ $t('auth.verifying') }}</p>
    <div v-else class="space-y-4">
      <p v-if="state === 'failed'" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ $t('auth.verifyFailed') }}</p>
      <p class="text-sm text-ink-muted">{{ $t('auth.verifyBody', { email: session.user?.email ?? '' }) }}</p>
      <AppButton block :loading="resending" @click="resend">{{ $t('auth.verifyResend') }}</AppButton>
      <AppButton block variant="ghost" @click="logout">{{ $t('auth.logout') }}</AppButton>
    </div>
  </AuthLayout>
</template>
