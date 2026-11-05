<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { authApi } from '@/shared/api/auth'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useSessionStore } from '@/shared/stores/session'
import { currentLocale } from '@/shared/i18n'
import AuthLayout from '@/shared/layouts/AuthLayout.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const { errors, formError, submitting, submit } = useApiForm()

const form = ref({ name: '', email: typeof route.query.email === 'string' ? route.query.email : '', password: '', password_confirmation: '' })

async function onSubmit() {
  const ok = await submit(async () => {
    await authApi.register({ ...form.value, locale: currentLocale() })
    return true
  })
  if (!ok) return
  await session.fetchMe()
  const r = route.query.redirect
  await router.replace({ name: 'verify-email', query: typeof r === 'string' && r.startsWith('/') && !r.startsWith('//') ? { redirect: r } : {} })
}
</script>

<template>
  <AuthLayout :title="$t('auth.registerTitle')" :subtitle="$t('auth.registerSubtitle')">
    <form class="space-y-4" novalidate @submit.prevent="onSubmit">
      <p v-if="formError" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ formError }}</p>
      <AppInput v-model="form.name" :label="$t('auth.name')" autocomplete="name" required :error="errors.name" />
      <AppInput v-model="form.email" :label="$t('auth.email')" type="email" autocomplete="email" required :error="errors.email" />
      <AppInput v-model="form.password" :label="$t('auth.password')" type="password" autocomplete="new-password" required :hint="$t('auth.passwordHint')" :error="errors.password" />
      <AppInput v-model="form.password_confirmation" :label="$t('auth.passwordConfirmation')" type="password" autocomplete="new-password" required :error="errors.password_confirmation" />
      <AppButton type="submit" block :loading="submitting">{{ $t('auth.register') }}</AppButton>
    </form>
    <template #footer>
      {{ $t('auth.haveAccount') }}
      <RouterLink :to="{ name: 'login', query: route.query }" class="font-medium text-brand-600 hover:underline">{{ $t('auth.login') }}</RouterLink>
    </template>
  </AuthLayout>
</template>
