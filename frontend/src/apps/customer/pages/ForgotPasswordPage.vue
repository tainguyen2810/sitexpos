<script setup lang="ts">
import { ref } from 'vue'
import { authApi } from '@/shared/api/auth'
import { useApiForm } from '@/shared/composables/useApiForm'
import AuthLayout from '@/shared/layouts/AuthLayout.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'

const { errors, formError, submitting, submit } = useApiForm()
const email = ref('')
const sentMessage = ref<string | null>(null)

async function onSubmit() {
  const res = await submit(() => authApi.forgotPassword(email.value))
  // Generic message: the API never reveals whether the email exists.
  if (res) sentMessage.value = res.data.message
}
</script>

<template>
  <AuthLayout :title="$t('auth.forgotTitle')" :subtitle="$t('auth.forgotSubtitle')">
    <p v-if="sentMessage" class="rounded-lg bg-success-50 px-3 py-2 text-sm text-success-600" role="status">{{ sentMessage }}</p>
    <form v-else class="space-y-4" novalidate @submit.prevent="onSubmit">
      <p v-if="formError" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ formError }}</p>
      <AppInput v-model="email" :label="$t('auth.email')" type="email" autocomplete="email" required :error="errors.email" />
      <AppButton type="submit" block :loading="submitting">{{ $t('auth.sendLink') }}</AppButton>
    </form>
    <template #footer>
      <RouterLink :to="{ name: 'login' }" class="font-medium text-brand-600 hover:underline">{{ $t('common.back') }}</RouterLink>
    </template>
  </AuthLayout>
</template>
