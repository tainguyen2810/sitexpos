<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { billingApi, type BillingProfile } from '@/shared/api/billing'
import { useAsync } from '@/shared/composables/useAsync'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import BillingNav from './BillingNav.vue'
import { useBilling } from './useBilling'

const FIELDS: { key: keyof BillingProfile; label: string; type?: string }[] = [
  { key: 'company_name', label: 'bill.profile.company' },
  { key: 'tax_code', label: 'bill.profile.taxCode' },
  { key: 'address', label: 'bill.profile.address' },
  { key: 'contact_name', label: 'bill.profile.contact' },
  { key: 'email', label: 'bill.profile.email', type: 'email' },
  { key: 'phone', label: 'bill.profile.phone' },
]
const { t } = useI18n()
const { workspaceId, canManage, toast, toastError } = useBilling()
const form = reactive<Record<keyof BillingProfile, string>>({ company_name: '', tax_code: '', address: '', contact_name: '', email: '', phone: '' })
const saving = ref(false)
const { data, error, loading, run } = useAsync(async () => {
  const p = await billingApi.profile(workspaceId.value)
  for (const f of FIELDS) form[f.key] = p[f.key] ?? ''
  return p
})
async function save() {
  saving.value = true
  try {
    const body = Object.fromEntries(FIELDS.map((f) => [f.key, form[f.key] || null])) as unknown as BillingProfile
    await billingApi.updateProfile(workspaceId.value, body)
    toast.success(t('common.saved'))
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('bill.title')" :description="t('bill.profile.subtitle')" />
    <BillingNav active="profile" />
    <LoadingState v-if="loading && !data" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <AppCard v-else-if="data">
      <form class="grid gap-4 md:grid-cols-2" @submit.prevent="save">
        <AppInput v-for="f in FIELDS" :key="f.key" v-model="form[f.key]" :type="f.type" :label="t(f.label)" :disabled="!canManage" />
        <div v-if="canManage" class="md:col-span-2 flex justify-end"><AppButton type="submit" :loading="saving" data-testid="save-profile">{{ t('common.save') }}</AppButton></div>
      </form>
    </AppCard>
  </div>
</template>
