<script setup lang="ts">
import { workspaceApi } from '@/shared/api/workspaces'
import { useAsync } from '@/shared/composables/useAsync'
import { useSessionStore } from '@/shared/stores/session'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import { useWorkspaceStore } from '../stores/workspace'

const ws = useWorkspaceStore()
const session = useSessionStore()
const { data, loading, error, run } = useAsync(() => workspaceApi.dashboard(ws.current!.id))
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-ink">{{ $t('dashboard.welcome', { name: session.user?.name ?? '' }) }}</h1>
      <p class="mt-1 text-sm text-ink-muted">{{ ws.current?.name }} · {{ ws.current?.my_role ? $t(`roles.${ws.current.my_role}`) : '' }}</p>
    </div>

    <LoadingState v-if="loading" :rows="3" />
    <ErrorState v-else-if="error" :error="error" @retry="run" />
    <template v-else-if="data">
      <div class="grid gap-4 sm:grid-cols-3">
        <AppCard>
          <p class="text-sm text-ink-muted">{{ $t('dashboard.sites') }}</p>
          <p class="mt-1 text-3xl font-bold">{{ data.connected_sites_count }}<span class="text-base font-normal text-ink-muted"> / {{ data.sites_count }}</span></p>
          <RouterLink :to="{ name: 'sites' }" class="mt-1 inline-block text-sm text-brand-600 hover:underline">{{ $t('dashboard.manageSites') }}</RouterLink>
        </AppCard>
        <AppCard>
          <p class="text-sm text-ink-muted">{{ $t('dashboard.members') }}</p>
          <p class="mt-1 text-3xl font-bold">{{ data.members_count }}</p>
        </AppCard>
        <AppCard>
          <p class="text-sm text-ink-muted">{{ $t('dashboard.pendingInvitations') }}</p>
          <p class="mt-1 text-3xl font-bold">{{ data.pending_invitations_count }}</p>
        </AppCard>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <AppCard :title="$t('dashboard.gettingStarted')">
          <ul class="space-y-3">
            <li v-for="step in data.onboarding" :key="step.key" class="flex items-center gap-3 text-sm">
              <span class="grid h-6 w-6 place-items-center rounded-full" :class="step.done ? 'bg-success-50 text-success-600' : 'bg-surface-muted text-ink-subtle'">
                <AppIcon :name="step.done ? 'check' : 'plus'" :size="14" />
              </span>
              <span :class="step.done ? 'text-ink-muted line-through' : 'text-ink'">{{ $t(`dashboard.steps.${step.key}`) }}</span>
              <AppBadge v-if="step.available === false" class="ml-auto">{{ $t('common.comingSoon') }}</AppBadge>
              <RouterLink v-else-if="!step.done && step.key === 'connect_site'" :to="{ name: 'sites' }" class="ml-auto text-brand-600 hover:underline">{{ $t('sites.add') }}</RouterLink>
              <RouterLink v-else-if="!step.done && step.key === 'invite_team'" :to="{ name: 'members' }" class="ml-auto text-brand-600 hover:underline">{{ $t('members.invite') }}</RouterLink>
            </li>
          </ul>
        </AppCard>
        <AppCard :title="$t('dashboard.modulesTitle')">
          <ul class="space-y-3">
            <li v-for="(mod, key) in data.modules" :key="key" class="flex items-center justify-between text-sm">
              <span>{{ $t(`dashboard.modules.${key}`) }}</span>
              <AppBadge>{{ $t('common.phase', { n: mod.phase }) }}</AppBadge>
            </li>
          </ul>
          <p class="mt-4 text-xs text-ink-subtle">{{ $t('dashboard.noFakeData') }}</p>
        </AppCard>
      </div>
    </template>
  </div>
</template>
