<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useSessionStore } from '@/shared/stores/session'
import TwoFactorSettings from '@/shared/auth/TwoFactorSettings.vue'
import AppButton from '@/shared/ui/AppButton.vue'

const router = useRouter()
const session = useSessionStore()

// Stay on the page after enabling so the recovery codes can be saved first.
const done = ref(false)
async function onEnabled() {
  await session.fetchMe()
  done.value = session.user?.two_factor_enabled === true
}
async function logout() {
  await session.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen bg-surface-muted px-4 py-10">
    <div class="mx-auto max-w-2xl space-y-4">
      <div>
        <h1 class="text-2xl font-bold">{{ $t('admin.setup2faTitle') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ $t('admin.setup2faBody') }}</p>
      </div>
      <TwoFactorSettings @enabled="onEnabled" />
      <AppButton v-if="done" @click="router.replace({ name: 'dashboard' })">{{ $t('auth.continue') }}</AppButton>
      <AppButton variant="ghost" @click="logout">{{ $t('auth.logout') }}</AppButton>
    </div>
  </div>
</template>
