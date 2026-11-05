<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { workspaceApi } from '@/shared/api/workspaces'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useSessionStore } from '@/shared/stores/session'
import { useToast } from '@/shared/composables/useToast'
import AuthLayout from '@/shared/layouts/AuthLayout.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'

const props = defineProps<{ token: string }>()
const router = useRouter()
const session = useSessionStore()
const toast = useToast()
const { t } = useI18n()

const { data: invitation, loading, error } = useAsync(async () => {
  await session.ensureLoaded()
  return workspaceApi.previewInvitation(props.token)
})

const accepting = ref(false)
const acceptError = ref<string | null>(null)
const here = computed(() => `/invitations/${props.token}`)

async function accept() {
  accepting.value = true
  acceptError.value = null
  try {
    const ws = await workspaceApi.acceptInvitation(props.token)
    await session.fetchMe()
    toast.success(t('invitation.accepted'))
    await router.replace({ name: 'dashboard', params: { workspaceId: ws.id } })
  } catch (e) {
    if (e instanceof ApiError && e.code === 'email_not_verified') {
      await router.push({ name: 'verify-email', query: { redirect: here.value } })
      return
    }
    acceptError.value = e instanceof ApiError && e.message ? e.message : t('errors.generic')
  } finally {
    accepting.value = false
  }
}
</script>

<template>
  <AuthLayout :title="$t('invitation.title')">
    <LoadingState v-if="loading" :rows="2" />
    <p v-else-if="error || !invitation" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ $t('invitation.invalid') }}</p>
    <div v-else class="space-y-4">
      <p class="text-sm text-ink">
        {{ $t('invitation.body', { inviter: invitation.inviter_name ?? $t('audit.system'), workspace: invitation.workspace_name, role: $t(`roles.${invitation.role}`) }) }}
      </p>
      <p class="text-sm text-ink-muted">{{ $t('invitation.forEmail', { email: invitation.email }) }}</p>
      <p v-if="invitation.status !== 'pending'" class="rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-600">
        {{ $t('invitation.notPending', { status: $t(`statuses.${invitation.status}`).toLowerCase() }) }}
      </p>
      <template v-else-if="session.isAuthenticated">
        <p v-if="acceptError" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ acceptError }}</p>
        <AppButton block :loading="accepting" @click="accept">{{ $t('invitation.accept') }}</AppButton>
      </template>
      <div v-else class="grid gap-2 sm:grid-cols-2">
        <RouterLink :to="{ name: 'login', query: { redirect: here } }" class="rounded-lg bg-brand-600 px-4 py-2 text-center text-sm font-medium text-white hover:bg-brand-700">{{ $t('invitation.loginToAccept') }}</RouterLink>
        <RouterLink :to="{ name: 'register', query: { redirect: here, email: invitation.email } }" class="rounded-lg border border-line px-4 py-2 text-center text-sm font-medium hover:bg-surface-muted">{{ $t('invitation.registerToAccept') }}</RouterLink>
      </div>
    </div>
  </AuthLayout>
</template>
