<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi } from '@/shared/api/admin'
import { ApiError } from '@/shared/api/client'
import { customerAppUrl } from '@/shared/config/runtime'
import { useAsync } from '@/shared/composables/useAsync'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
import { formatDate } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import ReasonDialog from '../components/ReasonDialog.vue'

const props = defineProps<{ id: string }>()
const { t } = useI18n()
const toast = useToast()
const session = useSessionStore()
const perms = computed(() => session.user?.platform_permissions ?? [])
const { data: ws, loading, error, run } = useAsync(() => adminApi.workspace(props.id))

type Action = 'suspend' | 'unsuspend' | 'impersonate'
const action = ref<Action | null>(null)
const busy = ref(false)
const actionError = ref<string | null>(null)

const dialog = computed(() => {
  const name = ws.value?.name ?? ''
  switch (action.value) {
    case 'suspend':
      return { title: t('admin.suspendTitle', { name }), label: t('admin.suspend'), danger: true, help: undefined }
    case 'unsuspend':
      return { title: t('admin.unsuspendTitle', { name }), label: t('admin.unsuspend'), danger: false, help: undefined }
    default:
      return { title: t('admin.impersonateTitle'), label: t('admin.impersonate'), danger: false, help: t('admin.impersonateHelp') }
  }
})

async function confirm(reason: string) {
  if (!ws.value || !action.value) return
  busy.value = true
  actionError.value = null
  try {
    if (action.value === 'impersonate') {
      const s = await adminApi.startImpersonation(ws.value.id, reason)
      toast.success(t('admin.impersonateStarted'))
      const base = customerAppUrl()
      window.open(`${base}/w/${encodeURIComponent(s.workspace_id)}/dashboard`, '_blank')
    } else {
      await (action.value === 'suspend' ? adminApi.suspendWorkspace : adminApi.unsuspendWorkspace)(ws.value.id, reason)
      toast.success(t('common.saved'))
      await run()
    }
    action.value = null
  } catch (e) {
    actionError.value = e instanceof ApiError && e.message ? e.message : t('errors.generic')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <RouterLink :to="{ name: 'workspaces' }" class="text-sm text-brand-600 hover:underline">← {{ $t('admin.workspaces') }}</RouterLink>
    <LoadingState v-if="loading" :rows="3" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <template v-else-if="ws">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-bold">{{ ws.name }}</h1>
          <p class="mt-1 font-mono text-xs text-ink-muted">{{ ws.id }} · {{ ws.slug }}</p>
          <div class="mt-2 flex gap-2">
            <AppBadge :tone="ws.status === 'active' ? 'success' : 'warning'">{{ $t(`statuses.${ws.status}`) }}</AppBadge>
            <AppBadge v-if="ws.is_demo">{{ $t('common.demo') }}</AppBadge>
          </div>
        </div>
        <div class="flex flex-wrap gap-2">
          <AppButton v-if="perms.includes('platform.impersonate') && ws.status === 'active'" variant="secondary" @click="action = 'impersonate'">{{ $t('admin.impersonate') }}</AppButton>
          <AppButton v-if="perms.includes('platform.tenants.suspend') && ws.status === 'active'" variant="danger" @click="action = 'suspend'">{{ $t('admin.suspend') }}</AppButton>
          <AppButton v-if="perms.includes('platform.tenants.suspend') && ws.status === 'suspended'" @click="action = 'unsuspend'">{{ $t('admin.unsuspend') }}</AppButton>
        </div>
      </div>
      <AppCard :title="$t('admin.membersOfWorkspace')" :padded="false">
        <ul class="divide-y divide-line">
          <li v-for="m in ws.members ?? []" :key="m.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
            <div class="min-w-0 flex-1">
              <RouterLink :to="{ name: 'user', params: { id: m.user.id } }" class="text-sm font-medium text-brand-600 hover:underline">{{ m.user.name }}</RouterLink>
              <p class="text-xs text-ink-muted">{{ m.user.email }} · {{ formatDate(m.joined_at) }}</p>
            </div>
            <AppBadge>{{ $t(`roles.${m.role}`) }}</AppBadge>
          </li>
        </ul>
      </AppCard>
    </template>
    <ReasonDialog
      :open="action !== null"
      :title="dialog.title"
      :help="dialog.help"
      :confirm-label="dialog.label"
      :danger="dialog.danger"
      :loading="busy"
      :error="actionError"
      @close="action = null"
      @confirm="confirm"
    />
  </div>
</template>
