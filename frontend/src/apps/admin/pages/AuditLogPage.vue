<script setup lang="ts">
import { ref } from 'vue'
import { adminApi } from '@/shared/api/admin'
import { useCursorList } from '@/shared/composables/useCursorList'
import AuditLogTable from '@/shared/components/AuditLogTable.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'

const action = ref('')
const workspaceId = ref('')
const list = useCursorList((cursor) =>
  adminApi.auditLogs({ cursor, workspace_id: workspaceId.value.trim() || undefined, 'filter[action]': action.value.trim() || undefined }),
)
void list.reload()
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ $t('admin.auditTitle') }}</h1>
    <form class="flex flex-wrap gap-2" @submit.prevent="list.reload">
      <AppInput v-model="action" class="min-w-64 flex-1" :placeholder="$t('audit.filterAction')" />
      <AppInput v-model="workspaceId" class="w-72" :placeholder="$t('admin.workspaceId')" />
      <AppButton type="submit" variant="secondary">{{ $t('common.search') }}</AppButton>
    </form>
    <AppCard :padded="false">
      <LoadingState v-if="list.loading.value" class="p-5" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" class="p-5" @retry="list.reload" />
      <EmptyState v-else-if="list.items.value.length === 0" icon="list" :title="$t('audit.empty')" />
      <AuditLogTable v-else :entries="list.items.value" show-workspace />
    </AppCard>
    <div v-if="list.next.value" class="text-center">
      <AppButton variant="secondary" :loading="list.loadingMore.value" @click="list.more">{{ $t('common.loadMore') }}</AppButton>
    </div>
  </div>
</template>
