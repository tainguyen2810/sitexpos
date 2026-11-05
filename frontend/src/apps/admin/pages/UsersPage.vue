<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi } from '@/shared/api/admin'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDate, formatDateTime } from '@/shared/utils/format'
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
const staffOnly = ref(false)
const statusOptions = [
  { value: '', label: t('common.all') },
  { value: 'active', label: t('statuses.active') },
  { value: 'suspended', label: t('statuses.suspended') },
]
const list = useCursorList((cursor) => adminApi.users({ q: q.value || undefined, status: status.value || undefined, staff: staffOnly.value || undefined, cursor }))
void list.reload()
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ $t('admin.users') }}</h1>
    <form class="flex flex-wrap items-center gap-2" @submit.prevent="list.reload">
      <AppInput v-model="q" class="min-w-64 flex-1" :placeholder="$t('admin.searchUsers')" />
      <AppSelect v-model="status" class="w-40" :options="statusOptions" />
      <label class="flex items-center gap-2 text-sm text-ink-muted"><input v-model="staffOnly" type="checkbox" class="rounded border-line" /> {{ $t('admin.staffOnly') }}</label>
      <AppButton type="submit" variant="secondary">{{ $t('common.search') }}</AppButton>
    </form>
    <AppCard :padded="false">
      <LoadingState v-if="list.loading.value" class="p-5" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" class="p-5" @retry="list.reload" />
      <EmptyState v-else-if="list.items.value.length === 0" icon="users" :title="$t('errors.notFound')" />
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase tracking-wide text-ink-subtle">
            <tr>
              <th class="px-5 py-3 font-medium">{{ $t('auth.name') }}</th>
              <th class="px-5 py-3 font-medium">{{ $t('admin.platformRole') }}</th>
              <th class="px-5 py-3 font-medium">{{ $t('common.status') }}</th>
              <th class="px-5 py-3 font-medium">{{ $t('admin.lastLogin') }}</th>
              <th class="px-5 py-3 font-medium">{{ $t('common.createdAt') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="u in list.items.value" :key="u.id" class="hover:bg-surface-muted">
              <td class="px-5 py-3">
                <RouterLink :to="{ name: 'user', params: { id: u.id } }" class="font-medium text-brand-600 hover:underline">{{ u.name }}</RouterLink>
                <p class="text-xs text-ink-muted">{{ u.email }}</p>
              </td>
              <td class="px-5 py-3">
                <AppBadge v-if="u.platform_role" tone="brand">{{ $t(`roles.${u.platform_role}`) }}</AppBadge><span v-else class="text-ink-subtle">—</span>
              </td>
              <td class="px-5 py-3"><AppBadge :tone="u.status === 'active' ? 'success' : 'warning'">{{ $t(`statuses.${u.status}`) }}</AppBadge></td>
              <td class="px-5 py-3 text-ink-muted">{{ u.last_login_at ? formatDateTime(u.last_login_at) : $t('admin.never') }}</td>
              <td class="px-5 py-3 text-ink-muted">{{ formatDate(u.created_at) }}</td>
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
