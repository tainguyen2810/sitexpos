<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { billingApi, statusTone } from '@/shared/api/billing'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import BillingNav from './BillingNav.vue'
import { useBilling } from './useBilling'

const { t, te } = useI18n()
const { workspaceId } = useBilling()
const list = useCursorList((cursor) => billingApi.history(workspaceId.value, cursor))
onMounted(list.reload)
</script>

<template>
  <div>
    <PageHeader :title="t('bill.title')" :description="t('bill.history.subtitle')" />
    <BillingNav active="history" />
    <AppCard :padded="false">
      <LoadingState v-if="list.loading.value" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload" />
      <EmptyState v-else-if="!list.items.value.length" icon="clock" :title="t('bill.history.empty')" />
      <ol v-else class="divide-y divide-line">
        <li v-for="e in list.items.value" :key="e.id" class="flex flex-wrap items-start justify-between gap-2 px-4 py-3 text-sm">
          <div>
            <p class="font-medium">{{ te(`bill.history.events.${e.event}`) ? t(`bill.history.events.${e.event}`) : e.event }}</p>
            <p v-if="e.reason" class="text-xs text-ink-muted">{{ e.reason }}</p>
            <p class="text-xs text-ink-muted">{{ e.actor_type ? t(`bill.history.by.${e.actor_type}`) : '' }} · {{ formatDateTime(e.created_at) }}</p>
          </div>
          <AppBadge v-if="e.to_status" :tone="statusTone(e.to_status)">{{ t(`bill.status.${e.to_status}`) }}</AppBadge>
        </li>
      </ol>
      <div v-if="list.next.value" class="p-4 text-center"><AppButton variant="secondary" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton></div>
    </AppCard>
  </div>
</template>
