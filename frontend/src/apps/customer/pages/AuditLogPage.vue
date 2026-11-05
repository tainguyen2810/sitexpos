<script setup lang="ts">
import { ref } from 'vue'
import { workspaceApi } from '@/shared/api/workspaces'
import { useCursorList } from '@/shared/composables/useCursorList'
import AuditLogTable from '@/shared/components/AuditLogTable.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import { useWorkspaceStore } from '../stores/workspace'

const ws = useWorkspaceStore()
const filter = ref('')
const list = useCursorList((cursor) => workspaceApi.auditLogs(ws.current!.id, { cursor, 'filter[action]': filter.value || undefined }))
void list.reload()
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold">{{ $t('audit.title') }}</h1>
      <p class="mt-1 text-sm text-ink-muted">{{ $t('audit.subtitle') }}</p>
    </div>
    <form class="flex max-w-xl gap-2" @submit.prevent="list.reload">
      <AppInput v-model="filter" class="flex-1" :placeholder="$t('audit.filterAction')" />
      <AppButton type="submit" variant="secondary">{{ $t('common.search') }}</AppButton>
    </form>
    <AppCard :padded="false">
      <LoadingState v-if="list.loading.value" class="p-5" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" class="p-5" @retry="list.reload" />
      <EmptyState v-else-if="list.items.value.length === 0" icon="list" :title="$t('audit.empty')" />
      <AuditLogTable v-else :entries="list.items.value" />
    </AppCard>
    <div v-if="list.next.value" class="text-center">
      <AppButton variant="secondary" :loading="list.loadingMore.value" @click="list.more">{{ $t('common.loadMore') }}</AppButton>
    </div>
  </div>
</template>
