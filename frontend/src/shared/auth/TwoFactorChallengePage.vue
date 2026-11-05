<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { authApi } from '@/shared/api/auth'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useSessionStore } from '@/shared/stores/session'
import AuthLayout from '@/shared/layouts/AuthLayout.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'

const props = defineProps<{ home: string }>()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const { errors, formError, submitting, submit } = useApiForm()

const useRecovery = ref(false)
const code = ref('')

async function onSubmit() {
  const payload = useRecovery.value ? { recovery_code: code.value.trim() } : { code: code.value.replace(/\s/g, '') }
  const ok = await submit(async () => {
    await authApi.twoFactorChallenge(payload)
    return true
  })
  if (!ok) return
  await session.fetchMe()
  const r = route.query.redirect
  await router.replace(typeof r === 'string' && r.startsWith('/') && !r.startsWith('//') ? r : props.home)
}
</script>

<template>
  <AuthLayout :title="$t('auth.twoFactorTitle')" :subtitle="$t('auth.twoFactorBody')">
    <form class="space-y-4" @submit.prevent="onSubmit">
      <p v-if="formError" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ formError }}</p>
      <AppInput
        v-model="code"
        :label="useRecovery ? $t('auth.recoveryCode') : $t('auth.twoFactorCode')"
        :autocomplete="useRecovery ? 'off' : 'one-time-code'"
        :error="errors.code ?? errors.recovery_code"
        required
      />
      <AppButton type="submit" block :loading="submitting">{{ $t('auth.verify') }}</AppButton>
      <button type="button" class="w-full text-center text-sm font-medium text-brand-600 hover:underline" @click="useRecovery = !useRecovery; code = ''">
        {{ useRecovery ? $t('auth.useCode') : $t('auth.useRecovery') }}
      </button>
    </form>
  </AuthLayout>
</template>
