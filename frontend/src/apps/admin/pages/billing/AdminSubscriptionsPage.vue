<script setup lang="ts">
import { ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney, statusTone, SUBSCRIPTION_STATUSES, type Subscription, type SubscriptionEvent } from '@/shared/api/billing'
import { billingAdminApi } from '@/shared/api/billingAdmin'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDate, formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import Drawer from '@/shared/ui/Drawer.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import ReasonDialog from '../../components/ReasonDialog.vue'
import AdminBillingNav from './AdminBillingNav.vue'
import { toInt, useAdminBilling } from './useAdminBilling'

const ACTIONS = ['extend', 'pause', 'resume', 'suspend', 'unsuspend', 'cancel'] as const
const { t, te } = useI18n()
const { toast, toastError } = useAdminBilling()
const status = ref('')
const live = ref(true)
const list = useCursorList((cursor) => billingAdminApi.subscriptions({ cursor, status: status.value || undefined, live: live.value ? 1 : undefined }))
watch([status, live], () => void list.reload(), { immediate: true })
const detail = shallowRef<(Subscription & { events: SubscriptionEvent[] }) | null>(null)
const action = ref<(typeof ACTIONS)[number] | null>(null)
const days = ref('7')
const busy = ref(false)
const actionError = ref<string | null>(null)

async function open(id: string) {
  try {
    detail.value = await billingAdminApi.subscription(id)
  } catch (e) {
    toastError(e)
  }
}
async function run(reason: string) {
  if (!detail.value || !action.value) return
  busy.value = true
  actionError.value = null
  try {
    await billingAdminApi.subscriptionAction(detail.value.id, { action: action.value, reason, days: action.value === 'extend' ? toInt(days.value) : null })
    toast.success(t('billAdmin.subs.done'))
    action.value = null
    await open(detail.value.id)
    await list.reload()
  } catch (e) {
    actionError.value = e instanceof Error ? e.message : String(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('billAdmin.title')" :description="t('billAdmin.subs.subtitle')" />
    <AdminBillingNav active="subscriptions" />
    <AppCard :padded="false">
      <template #actions>
        <div class="flex flex-wrap items-center gap-3">
          <Toggle v-model="live" :label="t('billAdmin.subs.liveOnly')" />
          <div class="w-48"><AppSelect v-model="status" :options="[{ value: '', label: t('billAdmin.common.all') }, ...SUBSCRIPTION_STATUSES.map((s) => ({ value: s, label: t(`bill.status.${s}`) }))]" /></div>
        </div>
      </template>
      <LoadingState v-if="list.loading.value" />
      <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload" />
      <EmptyState v-else-if="!list.items.value.length" icon="card" :title="t('billAdmin.common.empty')" />
      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted"><tr><th class="px-4 py-2">{{ t('billAdmin.common.workspace') }}</th><th class="px-4 py-2">{{ t('billAdmin.subs.plan') }}</th><th class="px-4 py-2">{{ t('billAdmin.subs.status') }}</th><th class="px-4 py-2 text-right">{{ t('bill.overview.price') }}</th><th class="px-4 py-2">{{ t('billAdmin.subs.periodEnd') }}</th><th /></tr></thead>
          <tbody class="divide-y divide-line">
            <tr v-for="s in list.items.value" :key="s.id">
              <td class="px-4 py-2"><RouterLink :to="`/workspaces/${s.workspace_id}`" class="hover:underline">{{ s.workspace_name }}</RouterLink></td>
              <td class="px-4 py-2">{{ s.plan_name }}</td>
              <td class="px-4 py-2"><AppBadge :tone="statusTone(s.status)">{{ t(`bill.status.${s.status}`) }}</AppBadge></td>
              <td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(s.price_minor, s.currency) }} · {{ t(`bill.interval.${s.interval}`) }}</td>
              <td class="px-4 py-2">{{ formatDate(s.current_period_end) }}</td>
              <td class="px-4 py-2 text-right"><AppButton size="sm" variant="ghost" @click="open(s.id)">{{ t('billAdmin.common.details') }}</AppButton></td>
            </tr>
          </tbody>
        </table>
        <div v-if="list.next.value" class="p-4 text-center"><AppButton variant="secondary" size="sm" :loading="list.loadingMore.value" @click="list.more">{{ t('common.loadMore') }}</AppButton></div>
      </div>
    </AppCard>

    <Drawer :open="!!detail" :title="detail?.plan?.name ?? ''" :subtitle="detail ? t(`bill.status.${detail.status}`) : ''" @close="detail = null">
      <div v-if="detail" class="space-y-4 text-sm">
        <dl class="grid grid-cols-2 gap-2">
          <dt class="text-ink-muted">{{ t('bill.overview.period') }}</dt><dd>{{ formatDate(detail.current_period_start) }} – {{ formatDate(detail.current_period_end) }}</dd>
          <dt class="text-ink-muted">{{ t('bill.overview.price') }}</dt><dd>{{ formatMoney(detail.price_minor, detail.currency) }}</dd>
        </dl>
        <div>
          <p class="mb-2 font-medium">{{ t('billAdmin.subs.actions') }}</p>
          <div class="flex flex-wrap gap-2">
            <AppButton v-for="a in ACTIONS" :key="a" size="sm" :variant="['suspend', 'cancel'].includes(a) ? 'danger' : 'secondary'" :data-testid="`sub-action-${a}`" @click="action = a">{{ t(`billAdmin.subs.action.${a}`) }}</AppButton>
          </div>
          <AppInput v-if="action === 'extend'" v-model="days" class="mt-2" type="number" :label="t('billAdmin.subs.days')" />
        </div>
        <div>
          <p class="mb-2 font-medium">{{ t('billAdmin.subs.events') }}</p>
          <ol class="space-y-2">
            <li v-for="e in detail.events" :key="e.id" class="rounded border border-line px-3 py-2">
              <p class="font-medium">{{ te(`bill.history.events.${e.event}`) ? t(`bill.history.events.${e.event}`) : e.event }}</p>
              <p class="text-xs text-ink-muted">{{ e.actor_type }} · {{ formatDateTime(e.created_at) }}<span v-if="e.reason"> · {{ e.reason }}</span></p>
            </li>
          </ol>
        </div>
      </div>
    </Drawer>
    <ReasonDialog :open="!!action" :title="action ? t(`billAdmin.subs.action.${action}`) : ''" :confirm-label="t('common.confirm')" :danger="action === 'suspend' || action === 'cancel'" :loading="busy" :error="actionError" @close="action = null" @confirm="run" />
  </div>
</template>
