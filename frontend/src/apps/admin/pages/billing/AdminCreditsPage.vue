<script setup lang="ts">
import { onMounted, reactive, ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatCredits, formatMicroUsd, type WalletSummary } from '@/shared/api/billing'
import { billingAdminApi } from '@/shared/api/billingAdmin'
import { useCursorList } from '@/shared/composables/useCursorList'
import { formatDateTime } from '@/shared/utils/format'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AdminBillingNav from './AdminBillingNav.vue'
import { toInt, useAdminBilling } from './useAdminBilling'

const KINDS = ['promotional', 'bonus', 'correction', 'refund'] as const
const { t } = useI18n()
const { toast, toastError } = useAdminBilling()
const form = reactive({ workspaceId: '', amount: '', kind: 'promotional', reason: '', expires_at: '' })
const wallet = shallowRef<{ wallet: WalletSummary; entries: { id: number; entry_type: string; amount_mc: number; balance_after_mc: number; description: string | null; created_at: string }[] } | null>(null)
const busy = ref(false)
const adjustments = useCursorList((cursor) => billingAdminApi.adjustments({ cursor }))
const uncertain = useCursorList((cursor) => billingAdminApi.uncertain({ cursor }))
onMounted(() => {
  void adjustments.reload()
  void uncertain.reload()
})

async function lookup() {
  if (!form.workspaceId.trim()) return
  try {
    wallet.value = await billingAdminApi.wallet(form.workspaceId.trim())
  } catch (e) {
    wallet.value = null
    toastError(e)
  }
}
async function adjust() {
  busy.value = true
  try {
    await billingAdminApi.adjust(form.workspaceId.trim(), { amount_mc: toInt(form.amount) ?? 0, kind: form.kind, reason: form.reason.trim(), expires_at: form.expires_at || null })
    toast.success(t('billAdmin.credits.adjusted'))
    form.amount = ''
    form.reason = ''
    await Promise.all([lookup(), adjustments.reload()])
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('billAdmin.title')" :description="t('billAdmin.credits.subtitle')" />
    <AdminBillingNav active="credits" />
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <AppCard :title="t('billAdmin.credits.adjust')">
        <form class="space-y-3" @submit.prevent="adjust">
          <div class="flex items-end gap-2"><AppInput v-model="form.workspaceId" class="flex-1" :label="t('billAdmin.credits.workspaceId')" required /><AppButton variant="secondary" @click="lookup">{{ t('billAdmin.credits.lookup') }}</AppButton></div>
          <AppInput v-model="form.amount" type="number" :label="t('billAdmin.credits.amount')" :hint="t('billAdmin.plans.mcHint')" required />
          <AppSelect v-model="form.kind" :label="t('billAdmin.credits.kind')" :options="KINDS.map((k) => ({ value: k, label: t(`billAdmin.credits.kinds.${k}`) }))" />
          <AppInput v-model="form.expires_at" type="date" :label="t('billAdmin.credits.expiresAt')" />
          <AppInput v-model="form.reason" :label="t('billAdmin.common.reason')" required />
          <div class="flex justify-end"><AppButton type="submit" :loading="busy" :disabled="form.reason.trim().length < 5 || !form.workspaceId.trim()" data-testid="adjust">{{ t('billAdmin.credits.adjust') }}</AppButton></div>
        </form>
      </AppCard>
      <AppCard :title="t('bill.nav.credits')">
        <p v-if="!wallet" class="text-sm text-ink-muted">{{ t('billAdmin.credits.lookup') }}…</p>
        <template v-else>
          <dl class=" ">
            <div><dt class="text-ink-muted">{{ t('bill.credits.balance') }}</dt><dd class="font-semibold tabular-nums">{{ formatCredits(wallet.wallet.balance_mc) }}</dd></div>
            <div><dt class="text-ink-muted">{{ t('bill.credits.available') }}</dt><dd class="font-semibold tabular-nums">{{ formatCredits(wallet.wallet.available_mc) }}</dd></div>
            <div><dt class="text-ink-muted">{{ t('bill.credits.held') }}</dt><dd class="font-semibold tabular-nums">{{ formatCredits(wallet.wallet.held_mc) }}</dd></div>
          </dl>
          <ul class="max-h-64 divide-y divide-line overflow-y-auto text-xs">
            <li v-for="e in wallet.entries" :key="e.id" class="flex justify-between gap-2 py-1"><span>{{ formatDateTime(e.created_at) }} · {{ t(`bill.transactions.types.${e.entry_type}`) }}</span><span class="tabular-nums" :class="e.amount_mc < 0 ? 'text-danger-600' : 'text-success-600'">{{ formatCredits(e.amount_mc) }}</span></li>
          </ul>
        </template>
      </AppCard>

      <AppCard :title="t('billAdmin.credits.history')" :padded="false" class="lg:col-span-2">
        <LoadingState v-if="adjustments.loading.value" />
        <ErrorState v-else-if="adjustments.error.value" :error="adjustments.error.value" @retry="adjustments.reload" />
        <EmptyState v-else-if="!adjustments.items.value.length" icon="coins" :title="t('billAdmin.common.empty')" />
        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted"><tr><th class="px-4 py-2">{{ t('bill.orders.created') }}</th><th class="px-4 py-2">{{ t('billAdmin.common.workspace') }}</th><th class="px-4 py-2">{{ t('billAdmin.credits.kind') }}</th><th class="px-4 py-2 text-right">Credits</th><th class="px-4 py-2">{{ t('billAdmin.common.reason') }}</th></tr></thead>
            <tbody class="divide-y divide-line">
              <tr v-for="a in adjustments.items.value" :key="a.id">
                <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ formatDateTime(a.created_at) }}</td>
                <td class="px-4 py-2 font-mono text-xs"><RouterLink :to="`/workspaces/${a.workspace_id}`" class="hover:underline">{{ a.workspace_id }}</RouterLink></td>
                <td class="px-4 py-2">{{ t(`billAdmin.credits.kinds.${a.kind}`) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ formatCredits(a.amount_mc) }}</td>
                <td class="px-4 py-2">{{ a.reason }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>

      <AppCard :title="t('billAdmin.credits.uncertain')" :subtitle="t('billAdmin.credits.uncertainHint')" :padded="false" class="lg:col-span-2">
        <LoadingState v-if="uncertain.loading.value" />
        <ErrorState v-else-if="uncertain.error.value" :error="uncertain.error.value" @retry="uncertain.reload" />
        <EmptyState v-else-if="!uncertain.items.value.length" icon="check" :title="t('billAdmin.common.empty')" />
        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted"><tr><th class="px-4 py-2">{{ t('bill.orders.created') }}</th><th class="px-4 py-2">Task</th><th class="px-4 py-2">{{ t('bill.orders.status') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.credits.estimate') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.credits.charged') }}</th><th class="px-4 py-2 text-right">{{ t('billAdmin.credits.uncollected') }}</th></tr></thead>
            <tbody class="divide-y divide-line">
              <tr v-for="u in uncertain.items.value" :key="u.id">
                <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ formatDateTime(u.created_at) }}</td>
                <td class="px-4 py-2">{{ u.purpose }}</td>
                <td class="px-4 py-2">{{ u.status }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ formatMicroUsd(u.estimate_micro_usd) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ formatCredits(u.charged_mc) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ formatCredits(u.uncollected_mc) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>
    </div>
  </div>
</template>
