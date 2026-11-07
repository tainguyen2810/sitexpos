<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi } from '@/shared/api/admin'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDate } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'

const { t } = useI18n()
const q = ref('')
const status = ref('')
const statusOptions = [
  { value: '', label: t('common.all') },
  { value: 'active', label: t('statuses.active') },
  { value: 'suspended', label: t('statuses.suspended') },
  { value: 'pending_deletion', label: t('statuses.pending_deletion') },
]
const list = useCursorList((cursor) => adminApi.workspaces({ q: q.value || undefined, status: status.value || undefined, cursor }))
void list.reload()
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ $t('admin.workspaces') }}</h1>
    <form class="flex flex-wrap gap-2" @submit.prevent="list.reload">
      <AppInput v-model="q" class="min-w-64 flex-1" :placeholder="$t('admin.searchWorkspaces')" />
      <AppSelect v-model="status" class="w-48" :options="statusOptions" />
      <AppButton type="submit" variant="secondary">{{ $t('common.search') }}</AppButton>
    </form>
    <AppCard :padded="false">
      <LoadingState v-if="list.loading.value" class="p-5" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" class="p-5" @retry="list.reload" />
      <EmptyState v-else-if="list.items.value.length === 0" icon="building" :title="$t('errors.notFound')" />
      <div v-else class="overflow-x-auto">
        <table class="data-table min-w-full divide-y divide-line text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase tracking-wide text-ink-subtle">
            <tr>
              <th class="px-5 py-3 font-medium">{{ $t('settings.name') }}</th>
              <th class="px-5 py-3 font-medium">{{ $t('admin.owner') }}</th>
              <th class="px-5 py-3 font-medium">{{ $t('admin.membersCount') }}</th>
              <th class="px-5 py-3 font-medium">{{ $t('common.status') }}</th>
              <th class="px-5 py-3 font-medium">{{ $t('common.createdAt') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="w in list.items.value" :key="w.id" class="hover:bg-surface-muted">
              <td class="px-5 py-3">
                <RouterLink :to="{ name: 'workspace', params: { id: w.id } }" class="font-medium text-brand-600 hover:underline">{{ w.name }}</RouterLink>
                <AppBadge v-if="w.is_demo" class="ml-2">{{ $t('common.demo') }}</AppBadge>
                <p class="text-xs text-ink-subtle">{{ w.slug }}</p>
              </td>
              <td class="px-5 py-3 text-ink-muted">{{ w.owner?.email ?? '—' }}</td>
              <td class="px-5 py-3">{{ w.members_count ?? '—' }}</td>
              <td class="px-5 py-3"><AppBadge :tone="w.status === 'active' ? 'success' : 'warning'">{{ $t(`statuses.${w.status}`) }}</AppBadge></td>
              <td class="px-5 py-3 text-ink-muted">{{ formatDate(w.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppCard>
    <div v-if="list.next.value" class="text-center">
      <AppButton variant="secondary" :loading="list.loadingMore.value" @click="list.more">{{ $t('common.loadMore') }}</AppButton>
    </div>
  </div>
</template>
