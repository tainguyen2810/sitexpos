<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useSessionStore } from '@/shared/stores/session'
import AuthLayout from '@/shared/layouts/AuthLayout.vue'
import AppButton from '@/shared/ui/AppButton.vue'

const router = useRouter()
const session = useSessionStore()
async function logout() {
  await session.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <AuthLayout :title="$t('admin.title')">
    <p class="text-sm text-ink-muted">{{ $t('admin.notStaff') }}</p>
    <AppButton class="mt-4" block variant="secondary" @click="logout">{{ $t('auth.logout') }}</AppButton>
  </AuthLayout>
</template>
