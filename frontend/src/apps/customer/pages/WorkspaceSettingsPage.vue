<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { workspaceApi } from '@/shared/api/workspaces'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useAsync } from '@/shared/composables/useAsync'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
import { TIMEZONES } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import { useWorkspaceStore } from '../stores/workspace'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const session = useSessionStore()
const ws = useWorkspaceStore()
const workspace = computed(() => ws.current!)
const readOnly = computed(() => workspace.value.impersonating)
const canUpdate = computed(() => ws.can('workspace.update') && !readOnly.value)

// ---- general ----
const form = ref({ name: workspace.value.name, timezone: workspace.value.timezone, locale: workspace.value.locale })
const general = useApiForm()
const tzOptions = computed(() => {
  const list = TIMEZONES.includes(form.value.timezone) ? TIMEZONES : [form.value.timezone, ...TIMEZONES]
  return list.map((z) => ({ value: z, label: z }))
})
const localeOptions = [
  { value: 'vi', label: 'Tiếng Việt' },
  { value: 'en', label: 'English' },
]
async function saveGeneral() {
  const res = await general.submit(() => workspaceApi.update(workspace.value.id, form.value))
  if (!res) return
  ws.set(res)
  await session.fetchMe()
  toast.success(t('common.saved'))
}

// ---- transfer ownership ----
const canTransfer = computed(() => ws.can('workspace.transfer') && !readOnly.value)
const members = useAsync(async () => (canTransfer.value ? workspaceApi.members(workspace.value.id) : []))
const memberOptions = computed(() => (members.data.value ?? []).filter((m) => !m.is_me).map((m) => ({ value: m.id, label: `${m.user.name} (${m.user.email})` })))
const transferOpen = ref(false)
const transfer = ref({ member_id: '', password: '' })
const transferForm = useApiForm()
async function doTransfer() {
  const ok = await transferForm.submit(async () => {
    await workspaceApi.transferOwnership(workspace.value.id, transfer.value.member_id, transfer.value.password)
    return true
  })
  if (!ok) return
  transferOpen.value = false
  toast.success(t('settings.transferDone'))
  await ws.load(workspace.value.id, true)
  await session.fetchMe()
}

// ---- delete ----
const canDelete = computed(() => ws.can('workspace.delete') && !readOnly.value)
const deleteOpen = ref(false)
const del = ref({ confirm_name: '', password: '' })
const deleteForm = useApiForm()
async function doDelete() {
  const ok = await deleteForm.submit(async () => {
    await workspaceApi.remove(workspace.value.id, del.value.confirm_name, del.value.password)
    return true
  })
  if (!ok) return
  deleteOpen.value = false
  toast.success(t('settings.deleteDone'))
  ws.clear()
  await session.fetchMe()
  await router.replace({ name: 'home' })
}
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ $t('settings.title') }}</h1>

    <AppCard :title="$t('settings.general')">
      <p v-if="!canUpdate" class="mb-4 rounded-lg bg-surface-muted px-3 py-2 text-sm text-ink-muted">{{ $t('settings.readOnly') }}</p>
      <form class="grid max-w-xl gap-4" novalidate @submit.prevent="saveGeneral">
        <p v-if="general.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ general.formError.value }}</p>
        <AppInput v-model="form.name" :label="$t('settings.name')" :disabled="!canUpdate" :error="general.errors.name" />
        <AppSelect v-model="form.timezone" :label="$t('settings.timezone')" :options="tzOptions" :disabled="!canUpdate" :error="general.errors.timezone" />
        <AppSelect v-model="form.locale" :label="$t('settings.language')" :options="localeOptions" :disabled="!canUpdate" :error="general.errors.locale" />
        <div v-if="canUpdate"><AppButton type="submit" :loading="general.submitting.value">{{ $t('common.save') }}</AppButton></div>
      </form>
    </AppCard>

    <AppCard v-if="canTransfer || canDelete" :title="$t('settings.dangerZone')">
      <div class="space-y-6">
        <div v-if="canTransfer" class="flex flex-wrap items-center justify-between gap-3">
          <div class="max-w-xl">
            <p class="text-sm font-medium">{{ $t('settings.transfer') }}</p>
            <p class="text-sm text-ink-muted">{{ $t('settings.transferHelp') }}</p>
          </div>
          <AppButton variant="secondary" :disabled="memberOptions.length === 0" @click="transferOpen = true">{{ $t('settings.transfer') }}</AppButton>
        </div>
        <div v-if="canDelete" class="flex flex-wrap items-center justify-between gap-3">
          <div class="max-w-xl">
            <p class="text-sm font-medium text-danger-600">{{ $t('settings.deleteTitle') }}</p>
            <p class="text-sm text-ink-muted">{{ $t('settings.deleteHelp') }}</p>
          </div>
          <AppButton variant="danger" @click="deleteOpen = true">{{ $t('settings.deleteTitle') }}</AppButton>
        </div>
      </div>
    </AppCard>

    <AppModal :open="transferOpen" :title="$t('settings.transfer')" @close="transferOpen = false">
      <form id="transfer-form" class="space-y-4" novalidate @submit.prevent="doTransfer">
        <p v-if="transferForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ transferForm.formError.value }}</p>
        <AppSelect v-model="transfer.member_id" :label="$t('settings.transferTo')" :options="[{ value: '', label: '—' }, ...memberOptions]" :error="transferForm.errors.member_id" />
        <AppInput v-model="transfer.password" :label="$t('common.password')" type="password" autocomplete="current-password" :error="transferForm.errors.password" />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="transferOpen = false">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="transfer-form" :loading="transferForm.submitting.value">{{ $t('common.confirm') }}</AppButton>
      </template>
    </AppModal>

    <AppModal :open="deleteOpen" :title="$t('settings.deleteTitle')" @close="deleteOpen = false">
      <form id="delete-form" class="space-y-4" novalidate @submit.prevent="doDelete">
        <p class="text-sm text-ink-muted">{{ $t('settings.deleteHelp') }}</p>
        <p v-if="deleteForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ deleteForm.formError.value }}</p>
        <AppInput v-model="del.confirm_name" :label="$t('settings.deleteConfirmName', { name: workspace.name })" :error="deleteForm.errors.confirm_name" />
        <AppInput v-model="del.password" :label="$t('common.password')" type="password" autocomplete="current-password" :error="deleteForm.errors.password" />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="deleteOpen = false">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="delete-form" variant="danger" :disabled="del.confirm_name !== workspace.name" :loading="deleteForm.submitting.value">{{ $t('common.delete') }}</AppButton>
      </template>
    </AppModal>
  </div>
</template>
