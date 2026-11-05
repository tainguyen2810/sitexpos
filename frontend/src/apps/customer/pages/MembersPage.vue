<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { workspaceApi } from '@/shared/api/workspaces'
import { ApiError } from '@/shared/api/client'
import type { Invitation, Member, WorkspaceRole } from '@/shared/api/types'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
import { canManageMember, manageableRoles } from '@/shared/utils/permissions'
import { formatDate } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import { useWorkspaceStore } from '../stores/workspace'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const session = useSessionStore()
const ws = useWorkspaceStore()
const id = ws.current!.id
const myRole = computed(() => ws.current?.my_role ?? null)
const canManage = computed(() => ws.can('members.manage'))

const members = useAsync(() => workspaceApi.members(id))
const invitations = useAsync(async () => (canManage.value ? workspaceApi.invitations(id) : []))

const roleOptions = computed(() => manageableRoles(myRole.value).map((r) => ({ value: r, label: t(`roles.${r}`) })))

function errorMessage(e: unknown): string {
  return e instanceof ApiError && e.message ? e.message : t('errors.generic')
}

// ---- invite ----
const inviteOpen = ref(false)
const invite = ref<{ email: string; role: WorkspaceRole }>({ email: '', role: 'EDITOR' })
const inviteForm = useApiForm()
function openInvite() {
  invite.value = { email: '', role: roleOptions.value.some((o) => o.value === 'EDITOR') ? 'EDITOR' : (roleOptions.value[0]?.value as WorkspaceRole) }
  inviteForm.reset()
  inviteOpen.value = true
}
async function sendInvite() {
  const res = await inviteForm.submit(() => workspaceApi.invite(id, invite.value.email, invite.value.role))
  if (!res) return
  inviteOpen.value = false
  toast.success(t('members.invited', { email: res.email }))
  await invitations.run()
}

// ---- role change ----
const busyMember = ref<string | null>(null)
async function changeRole(member: Member, role: string) {
  if (role === member.role) return
  busyMember.value = member.id
  try {
    await workspaceApi.updateMember(id, member.id, role as WorkspaceRole)
    toast.success(t('members.roleChanged'))
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    busyMember.value = null
    await members.run()
  }
}

// ---- remove / leave ----
const removing = ref<Member | null>(null)
const removeBusy = ref(false)
async function confirmRemove() {
  const target = removing.value
  if (!target) return
  removeBusy.value = true
  try {
    await workspaceApi.removeMember(id, target.id)
    removing.value = null
    if (target.is_me) {
      ws.clear()
      await session.fetchMe()
      await router.replace({ name: 'home' })
      return
    }
    toast.success(t('members.removed'))
    await members.run()
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    removeBusy.value = false
  }
}

// ---- revoke invitation ----
async function revoke(inv: Invitation) {
  try {
    await workspaceApi.revokeInvitation(id, inv.id)
    toast.success(t('members.revoked'))
    await invitations.run()
  } catch (e) {
    toast.error(errorMessage(e))
  }
}

const readOnly = computed(() => ws.current?.impersonating === true)
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">{{ $t('members.title') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ $t('members.subtitle') }}</p>
      </div>
      <AppButton v-if="canManage && !readOnly" @click="openInvite">{{ $t('members.invite') }}</AppButton>
    </div>

    <AppCard :padded="false">
      <LoadingState v-if="members.loading.value" class="p-5" />
      <ErrorState v-else-if="members.error.value" :error="members.error.value" class="p-5" @retry="members.run" />
      <template v-else-if="members.data.value">
        <ul class="divide-y divide-line">
          <li v-for="m in members.data.value" :key="m.id" class="flex flex-wrap items-center gap-3 px-5 py-4">
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium">
                {{ m.user.name }} <AppBadge v-if="m.is_me" tone="brand">{{ $t('common.you') }}</AppBadge>
              </p>
              <p class="truncate text-xs text-ink-muted">{{ m.user.email }} · {{ $t('members.joined') }} {{ formatDate(m.joined_at) }}</p>
            </div>
            <AppSelect
              v-if="!readOnly && !m.is_me && canManage && canManageMember(myRole, m.role)"
              class="w-44"
              :model-value="m.role"
              :options="roleOptions"
              :disabled="busyMember === m.id"
              :aria-label="$t('members.changeRole')"
              @update:model-value="(v: string) => changeRole(m, v)"
            />
            <AppBadge v-else :tone="m.role === 'OWNER' ? 'brand' : 'neutral'">{{ $t(`roles.${m.role}`) }}</AppBadge>
            <AppButton v-if="!readOnly && m.is_me && m.role !== 'OWNER'" size="sm" variant="ghost" @click="removing = m">{{ $t('members.leave') }}</AppButton>
            <AppButton v-else-if="!readOnly && !m.is_me && canManage && canManageMember(myRole, m.role)" size="sm" variant="ghost" @click="removing = m">{{ $t('members.remove') }}</AppButton>
          </li>
        </ul>
        <EmptyState v-if="members.data.value.length <= 1" icon="users" :title="$t('members.emptyTitle')" :description="$t('members.emptyBody')" />
      </template>
    </AppCard>

    <AppCard v-if="canManage" :title="$t('members.invitationsTitle')" :padded="false">
      <LoadingState v-if="invitations.loading.value" class="p-5" />
      <ErrorState v-else-if="invitations.error.value" :error="invitations.error.value" class="p-5" @retry="invitations.run" />
      <EmptyState v-else-if="!invitations.data.value?.length" icon="mail" :title="$t('members.noInvitations')" />
      <ul v-else class="divide-y divide-line">
        <li v-for="inv in invitations.data.value" :key="inv.id" class="flex flex-wrap items-center gap-3 px-5 py-4">
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium">{{ inv.email }}</p>
            <p class="text-xs text-ink-muted">{{ $t('members.expires', { date: formatDate(inv.expires_at) }) }}</p>
          </div>
          <AppBadge>{{ $t(`roles.${inv.role}`) }}</AppBadge>
          <AppButton v-if="!readOnly && canManageMember(myRole, inv.role)" size="sm" variant="ghost" @click="revoke(inv)">{{ $t('members.revoke') }}</AppButton>
        </li>
      </ul>
    </AppCard>

    <AppModal :open="inviteOpen" :title="$t('members.inviteTitle')" @close="inviteOpen = false">
      <form id="invite-form" class="space-y-4" novalidate @submit.prevent="sendInvite">
        <p v-if="inviteForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ inviteForm.formError.value }}</p>
        <AppInput v-model="invite.email" :label="$t('auth.email')" type="email" required :error="inviteForm.errors.email" />
        <AppSelect v-model="invite.role" :label="$t('members.role')" :options="roleOptions" :error="inviteForm.errors.role" />
        <p v-if="invite.role !== 'OWNER'" class="text-xs text-ink-muted">{{ $t(`members.roleHelp.${invite.role}`) }}</p>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="inviteOpen = false">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="invite-form" :loading="inviteForm.submitting.value">{{ $t('members.inviteSend') }}</AppButton>
      </template>
    </AppModal>

    <ConfirmDialog
      :open="removing !== null"
      :title="removing?.is_me ? $t('members.leave') : $t('members.remove')"
      :message="removing?.is_me ? $t('members.leaveConfirm') : $t('members.removeConfirm', { name: removing?.user.name ?? '' })"
      danger
      :loading="removeBusy"
      @close="removing = null"
      @confirm="confirmRemove"
    />
  </div>
</template>
