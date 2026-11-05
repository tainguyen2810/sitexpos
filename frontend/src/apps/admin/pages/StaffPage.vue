<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi } from '@/shared/api/admin'
import { ApiError } from '@/shared/api/client'
import type { PlatformRole } from '@/shared/api/types'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'

const { t } = useI18n()
const toast = useToast()
const session = useSessionStore()
const { data: staff, loading, error, run } = useAsync(() => adminApi.staff())

const roles: PlatformRole[] = ['SUPER_ADMIN', 'SUPPORT', 'BILLING_ADMIN']
const roleOptions = computed(() => roles.map((r) => ({ value: r, label: t(`roles.${r}`) })))

const addOpen = ref(false)
const form = ref<{ email: string; platform_role: PlatformRole }>({ email: '', platform_role: 'SUPPORT' })
const addForm = useApiForm()
async function add() {
  const ok = await addForm.submit(() => adminApi.addStaff(form.value.email, form.value.platform_role))
  if (!ok) return
  addOpen.value = false
  form.value = { email: '', platform_role: 'SUPPORT' }
  toast.success(t('common.saved'))
  await run()
}

async function update(id: string, role: PlatformRole | null) {
  try {
    await adminApi.updateStaff(id, role)
    toast.success(t('common.saved'))
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  } finally {
    await run()
  }
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">{{ $t('admin.staff') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ $t('admin.addStaffHelp') }}</p>
      </div>
      <AppButton @click="addOpen = true">{{ $t('admin.addStaff') }}</AppButton>
    </div>
    <AppCard :padded="false">
      <LoadingState v-if="loading" class="p-5" />
      <ErrorState v-else-if="error" :error="error" class="p-5" @retry="run" />
      <ul v-else class="divide-y divide-line">
        <li v-for="u in staff ?? []" :key="u.id" class="flex flex-wrap items-center gap-3 px-5 py-4">
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium">{{ u.name }} <AppBadge v-if="u.id === session.user?.id" tone="brand">{{ $t('common.you') }}</AppBadge></p>
            <p class="text-xs text-ink-muted">{{ u.email }} · {{ $t('admin.twoFactor') }}: {{ u.two_factor_enabled ? $t('common.yes') : $t('common.no') }}</p>
          </div>
          <template v-if="u.id !== session.user?.id">
            <AppSelect class="w-48" :model-value="u.platform_role ?? ''" :options="roleOptions" @update:model-value="(v: string) => update(u.id, v as PlatformRole)" />
            <AppButton size="sm" variant="ghost" @click="update(u.id, null)">{{ $t('admin.removeRole') }}</AppButton>
          </template>
          <AppBadge v-else tone="brand">{{ u.platform_role ? $t(`roles.${u.platform_role}`) : '' }}</AppBadge>
        </li>
      </ul>
    </AppCard>
    <AppModal :open="addOpen" :title="$t('admin.addStaff')" width="sm" @close="addOpen = false">
      <form id="staff-form" class="space-y-4" novalidate @submit.prevent="add">
        <p v-if="addForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ addForm.formError.value }}</p>
        <AppInput v-model="form.email" :label="$t('auth.email')" type="email" :error="addForm.errors.email" />
        <AppSelect v-model="form.platform_role" :label="$t('admin.platformRole')" :options="roleOptions" :error="addForm.errors.platform_role" />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="addOpen = false">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="staff-form" :loading="addForm.submitting.value">{{ $t('common.confirm') }}</AppButton>
      </template>
    </AppModal>
  </div>
</template>
