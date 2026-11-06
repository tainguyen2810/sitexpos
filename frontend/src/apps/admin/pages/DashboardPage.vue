<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi } from '@/shared/api/admin'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'

const { t } = useI18n()
const { data, loading, error, run } = useAsync(() => adminApi.dashboard())

const stats = computed(() => {
  const d = data.value
  if (!d) return []
  return [
    { label: t('admin.totalUsers'), value: d.users.total },
    { label: t('admin.verifiedUsers'), value: d.users.verified },
    { label: t('admin.suspendedUsers'), value: d.users.suspended },
    { label: t('admin.newUsers7'), value: d.users.new_7d },
    { label: t('admin.totalWorkspaces'), value: d.workspaces.total },
    { label: t('admin.activeWorkspaces'), value: d.workspaces.active },
    { label: t('admin.newUsers30'), value: d.users.new_30d },
    { label: t('admin.pendingInvitations'), value: d.invitations_pending },
  ]
})
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold">{{ $t('admin.dashboard') }}</h1>
      <p v-if="data" class="mt-1 text-sm text-ink-muted">{{ $t('admin.generatedAt', { time: formatDateTime(data.generated_at) }) }}</p>
    </div>
    <LoadingState v-if="loading" :rows="3" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <AppCard v-for="s in stats" :key="s.label">
        <p class="text-sm text-ink-muted">{{ s.label }}</p>
        <p class="mt-1 text-3xl font-bold">{{ s.value }}</p>
      </AppCard>
    </div>
  </div>
</template>
