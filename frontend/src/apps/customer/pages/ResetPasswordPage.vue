<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { authApi } from '@/shared/api/auth'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useToast } from '@/shared/composables/useToast'
import AuthLayout from '@/shared/layouts/AuthLayout.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const toast = useToast()
const { errors, formError, submitting, submit } = useApiForm()

const token = typeof route.query.token === 'string' ? route.query.token : ''
const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const password = ref('')
const passwordConfirmation = ref('')

async function onSubmit() {
  const ok = await submit(async () => {
    await authApi.resetPassword({ token, email: email.value, password: password.value, password_confirmation: passwordConfirmation.value })
    return true
  })
  if (!ok) return
  toast.success(t('auth.resetDone'))
  await router.replace({ name: 'login' })
}
</script>

<template>
  <AuthLayout :title="$t('auth.resetTitle')">
    <form class="space-y-4" novalidate @submit.prevent="onSubmit">
      <p v-if="formError" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ formError }}</p>
      <AppInput v-model="email" :label="$t('auth.email')" type="email" autocomplete="email" required :error="errors.email ?? errors.token" />
      <AppInput v-model="password" :label="$t('auth.newPassword')" type="password" autocomplete="new-password" required :hint="$t('auth.passwordHint')" :error="errors.password" />
      <AppInput v-model="passwordConfirmation" :label="$t('auth.passwordConfirmation')" type="password" autocomplete="new-password" required />
      <AppButton type="submit" block :loading="submitting">{{ $t('auth.resetSubmit') }}</AppButton>
    </form>
  </AuthLayout>
</template>
