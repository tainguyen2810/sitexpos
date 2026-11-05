<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi } from '@/shared/api/admin'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import ReasonDialog from '../components/ReasonDialog.vue'

const props = defineProps<{ id: string }>()
const { t } = useI18n()
const toast = useToast()
const session = useSessionStore()
const canSuspend = computed(() => (session.user?.platform_permissions ?? []).includes('platform.users.suspend') && props.id !== session.user?.id)
const canViewTenants = computed(() => (session.user?.platform_permissions ?? []).includes('platform.tenants.view'))
const { data: user, loading, error, run } = useAsync(() => adminApi.user(props.id))

const action = ref<'suspend' | 'unsuspend' | null>(null)
const busy = ref(false)
const actionError = ref<string | null>(null)

async function confirm(reason: string) {
  if (!user.value || !action.value) return
  busy.value = true
  actionError.value = null
  try {
    await (action.value === 'suspend' ? adminApi.suspendUser : adminApi.unsuspendUser)(user.value.id, reason)
    action.value = null
    toast.success(t('common.saved'))
    await run()
  } catch (e) {
    actionError.value = e instanceof ApiError && e.message ? e.message : t('errors.generic')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <RouterLink :to="{ name: 'users' }" class="text-sm text-brand-600 hover:underline">← {{ $t('admin.users') }}</RouterLink>
    <LoadingState v-if="loading" :rows="3" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <template v-else-if="user">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-bold">{{ user.name }}</h1>
          <p class="mt-1 text-sm text-ink-muted">{{ user.email }}</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <AppBadge :tone="user.status === 'active' ? 'success' : 'warning'">{{ $t(`statuses.${user.status}`) }}</AppBadge>
            <AppBadge v-if="user.platform_role" tone="brand">{{ $t(`roles.${user.platform_role}`) }}</AppBadge>
            <AppBadge :tone="user.email_verified ? 'success' : 'neutral'">{{ $t('auth.email') }}: {{ user.email_verified ? $t('common.yes') : $t('common.no') }}</AppBadge>
            <AppBadge :tone="user.two_factor_enabled ? 'success' : 'neutral'">{{ $t('admin.twoFactor') }}: {{ user.two_factor_enabled ? $t('common.yes') : $t('common.no') }}</AppBadge>
          </div>
          <p class="mt-2 text-xs text-ink-subtle">{{ $t('admin.lastLogin') }}: {{ user.last_login_at ? formatDateTime(user.last_login_at) : $t('admin.never') }}</p>
        </div>
        <div v-if="canSuspend">
          <AppButton v-if="user.status === 'active'" variant="danger" @click="action = 'suspend'">{{ $t('admin.suspend') }}</AppButton>
          <AppButton v-else @click="action = 'unsuspend'">{{ $t('admin.unsuspend') }}</AppButton>
        </div>
      </div>
      <AppCard :title="$t('admin.workspacesOfUser')" :padded="false">
        <EmptyState v-if="!user.workspaces?.length" icon="building" :title="$t('errors.notFound')" />
        <ul v-else class="divide-y divide-line">
          <li v-for="w in user.workspaces" :key="w.id" class="flex items-center gap-3 px-5 py-3">
            <RouterLink v-if="canViewTenants" :to="{ name: 'workspace', params: { id: w.id } }" class="flex-1 text-sm font-medium text-brand-600 hover:underline">{{ w.name }}</RouterLink>
            <span v-else class="flex-1 text-sm font-medium">{{ w.name }}</span>
            <AppBadge>{{ $t(`roles.${w.role}`) }}</AppBadge>
          </li>
        </ul>
      </AppCard>
    </template>
    <ReasonDialog
      :open="action !== null"
      :title="action === 'suspend' ? $t('admin.suspendTitle', { name: user?.name ?? '' }) : $t('admin.unsuspendTitle', { name: user?.name ?? '' })"
      :confirm-label="action === 'suspend' ? $t('admin.suspend') : $t('admin.unsuspend')"
      :danger="action === 'suspend'"
      :loading="busy"
      :error="actionError"
      @close="action = null"
      @confirm="confirm"
    />
  </div>
</template>
