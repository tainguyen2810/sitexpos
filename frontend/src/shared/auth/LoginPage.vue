<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { authApi } from '@/shared/api/auth'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useSessionStore } from '@/shared/stores/session'
import AuthLayout from '@/shared/layouts/AuthLayout.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'

const props = defineProps<{ subtitle?: string; allowRegister?: boolean; home: string }>()

const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const { errors, formError, submitting, submit } = useApiForm()

const email = ref('')
const password = ref('')
const remember = ref(false)

function safeRedirect(): string {
  const r = route.query.redirect
  // Only internal paths: prevents open redirects.
  return typeof r === 'string' && r.startsWith('/') && !r.startsWith('//') ? r : props.home
}

async function onSubmit() {
  const res = await submit(() => authApi.login(email.value, password.value, remember.value))
  if (!res) return
  if (res.two_factor) {
    await router.push({ name: 'two-factor', query: { redirect: safeRedirect() } })
    return
  }
  await session.fetchMe()
  await router.replace(safeRedirect())
}
</script>

<template>
  <AuthLayout :title="$t('auth.loginTitle')" :subtitle="subtitle ?? $t('auth.loginSubtitle')">
    <form class="space-y-4" novalidate @submit.prevent="onSubmit">
      <p v-if="formError" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ formError }}</p>
      <AppInput v-model="email" :label="$t('auth.email')" type="email" autocomplete="email" required :error="errors.email" />
      <AppInput v-model="password" :label="$t('auth.password')" type="password" autocomplete="current-password" required :error="errors.password" />
      <div class="flex items-center justify-between text-sm">
        <label class="flex items-center gap-2 text-ink-muted"><input v-model="remember" type="checkbox" class="rounded border-line" /> {{ $t('auth.remember') }}</label>
        <RouterLink v-if="allowRegister" :to="{ name: 'forgot-password' }" class="font-medium text-brand-600 hover:underline">{{ $t('auth.forgot') }}</RouterLink>
      </div>
      <AppButton type="submit" block :loading="submitting">{{ submitting ? $t('auth.loggingIn') : $t('auth.login') }}</AppButton>
    </form>
    <template v-if="allowRegister" #footer>
      {{ $t('auth.noAccount') }}
      <RouterLink :to="{ name: 'register', query: route.query }" class="font-medium text-brand-600 hover:underline">{{ $t('auth.register') }}</RouterLink>
    </template>
  </AuthLayout>
</template>
