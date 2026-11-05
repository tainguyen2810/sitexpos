<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { workspaceApi } from '@/shared/api/workspaces'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useSessionStore } from '@/shared/stores/session'
import { currentLocale } from '@/shared/i18n'
import AuthLayout from '@/shared/layouts/AuthLayout.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import { useWorkspaceStore } from '../stores/workspace'

const router = useRouter()
const session = useSessionStore()
const workspaces = useWorkspaceStore()
const { errors, formError, submitting, submit } = useApiForm()
const name = ref('')

async function onSubmit() {
  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Asia/Ho_Chi_Minh'
  const ws = await submit(() => workspaceApi.create({ name: name.value, timezone, locale: currentLocale() }))
  if (!ws) return
  workspaces.set(ws)
  await session.fetchMe()
  await router.replace({ name: 'dashboard', params: { workspaceId: ws.id } })
}
</script>

<template>
  <AuthLayout :title="$t('onboarding.title')" :subtitle="$t('onboarding.subtitle')">
    <form class="space-y-4" novalidate @submit.prevent="onSubmit">
      <p v-if="formError" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ formError }}</p>
      <AppInput v-model="name" :label="$t('onboarding.name')" :placeholder="$t('onboarding.namePlaceholder')" required :error="errors.name" />
      <AppButton type="submit" block :loading="submitting">{{ $t('onboarding.create') }}</AppButton>
    </form>
    <template v-if="session.user && session.user.workspaces.length > 0" #footer>
      <RouterLink to="/" class="font-medium text-brand-600 hover:underline">{{ $t('common.back') }}</RouterLink>
    </template>
  </AuthLayout>
</template>
