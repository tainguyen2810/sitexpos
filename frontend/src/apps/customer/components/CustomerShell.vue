<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/shared/stores/session'
import ShellLayout, { type NavItem } from '@/shared/layouts/ShellLayout.vue'
import UserMenu from '@/shared/ui/UserMenu.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import { useWorkspaceStore, lastWorkspace } from '../stores/workspace'
import WorkspaceSwitcher from './WorkspaceSwitcher.vue'
import ImpersonationBanner from './ImpersonationBanner.vue'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const session = useSessionStore()
const ws = useWorkspaceStore()

const routeWorkspaceId = computed(() => (typeof route.params.workspaceId === 'string' ? route.params.workspaceId : null))
const navWorkspaceId = computed(() => {
  if (routeWorkspaceId.value) return routeWorkspaceId.value
  const list = session.user?.workspaces ?? []
  return list.find((w) => w.id === lastWorkspace())?.id ?? list[0]?.id ?? ws.current?.id ?? null
})
const currentName = computed(() => (ws.current && ws.current.id === navWorkspaceId.value ? ws.current.name : null))

const nav = computed<NavItem[]>(() => {
  const id = navWorkspaceId.value
  if (!id) return []
  const base = `/w/${id}`
  const items: NavItem[] = [
    { label: t('nav.dashboard'), to: `${base}/dashboard`, icon: 'home' },
    { label: t('nav.sites'), to: `${base}/sites`, icon: 'globe' },
    { label: t('nav.writer'), to: `${base}/writer`, icon: 'pen' },
    { label: t('nav.research'), to: `${base}/research`, icon: 'target' },
    { label: t('nav.ai'), to: `${base}/ai`, icon: 'sparkles' },
    { label: t('nav.members'), to: `${base}/members`, icon: 'users' },
    { label: t('nav.audit'), to: `${base}/audit`, icon: 'list' },
    { label: t('nav.settings'), to: `${base}/settings`, icon: 'settings' },
    { label: t('nav.billing'), icon: 'card', soon: true },
  ]
  // UI hint only: the API enforces audit.view on its own.
  const current = ws.current?.id === id ? ws.current : null
  return items.filter((i) => {
    if (!current) return true
    if (i.icon === 'list') return ws.can('audit.view')
    if (i.icon === 'settings') return ws.can('workspace.update')
    return true
  })
})

async function logout() {
  await session.logout()
  ws.clear()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <ShellLayout :nav="nav">
    <template #banner>
      <ImpersonationBanner v-if="session.user?.impersonation" :impersonation="session.user.impersonation" />
    </template>
    <template #sidebar-top>
      <WorkspaceSwitcher v-if="navWorkspaceId && !session.user?.impersonation" :current-id="navWorkspaceId" :current-name="currentName" />
    </template>
    <template #topbar>
      <div class="flex items-center gap-2 truncate text-sm font-medium text-ink">
        <span class="truncate">{{ currentName ?? $t('common.appName') }}</span>
        <AppBadge v-if="ws.current?.is_demo && currentName" tone="warning">{{ $t('common.demo') }}</AppBadge>
      </div>
    </template>
    <template #topbar-right>
      <UserMenu v-if="session.user" :name="session.user.name" :email="session.user.email" account-to="/account" @logout="logout" />
    </template>
    <RouterView :key="routeWorkspaceId ?? 'account'" />
  </ShellLayout>
</template>
