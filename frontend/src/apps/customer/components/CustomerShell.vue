<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/shared/stores/session'
import ShellLayout, { type NavGroup } from '@/shared/layouts/ShellLayout.vue'
import UserMenu from '@/shared/ui/UserMenu.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import { formatCredits } from '@/shared/api/billing'
import { useWorkspaceStore, lastWorkspace } from '../stores/workspace'
import { useAttentionStore } from '../stores/attention'
import { useAttentionItems } from '../composables/useAttentionItems'
import WorkspaceSwitcher from './WorkspaceSwitcher.vue'
import ImpersonationBanner from './ImpersonationBanner.vue'
import GlobalSearch from './GlobalSearch.vue'
import NotificationsMenu from './NotificationsMenu.vue'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const session = useSessionStore()
const ws = useWorkspaceStore()
const attention = useAttentionStore()

const routeWorkspaceId = computed(() => (typeof route.params.workspaceId === 'string' ? route.params.workspaceId : null))
const navWorkspaceId = computed(() => {
  if (routeWorkspaceId.value) return routeWorkspaceId.value
  const list = session.user?.workspaces ?? []
  return list.find((w) => w.id === lastWorkspace())?.id ?? list[0]?.id ?? ws.current?.id ?? null
})
const currentName = computed(() => (ws.current && ws.current.id === navWorkspaceId.value ? ws.current.name : null))
const { items: attentionItems } = useAttentionItems(() => navWorkspaceId.value)

// Refresh the counters when the user moves around (cached for a minute).
watch(
  () => [navWorkspaceId.value, route.name] as const,
  ([id]) => {
    if (id) void attention.load(id)
  },
  { immediate: true },
)

const groups = computed<NavGroup[]>(() => {
  const id = navWorkspaceId.value
  if (!id) return []
  const base = `/w/${id}`
  const a = attention.data
  // UI hint only: the API enforces every permission on its own.
  const current = ws.current?.id === id ? ws.current : null
  const can = (p: string) => !current || ws.can(p)
  const publishIssues = (a.failedJobs ?? 0) + (a.openConflicts ?? 0)
  const account: NavGroup['items'] = [
    { label: t('nav.billing'), to: `${base}/billing`, icon: 'card' },
    { label: t('nav.ai'), to: `${base}/ai`, icon: 'sparkles' },
    { label: t('nav.members'), to: `${base}/members`, icon: 'users' },
  ]
  if (can('audit.view')) account.push({ label: t('nav.audit'), to: `${base}/audit`, icon: 'list' })
  if (can('workspace.update')) account.push({ label: t('nav.settings'), to: `${base}/settings`, icon: 'settings' })
  return [
    { label: '', items: [{ label: t('nav.dashboard'), to: `${base}/dashboard`, icon: 'home' }] },
    {
      label: t('shell.groups.content'),
      items: [
        { label: t('nav.writer'), to: `${base}/writer`, icon: 'pen', badge: a.needsReview },
        { label: t('nav.research'), to: `${base}/research`, icon: 'target' },
        { label: t('nav.media'), to: `${base}/media`, icon: 'image' },
      ],
    },
    {
      label: t('shell.groups.optimize'),
      items: [
        { label: t('nav.seo'), to: `${base}/seo`, icon: 'search' },
        { label: t('nav.publishing'), to: `${base}/publishing`, icon: 'send', badge: publishIssues, badgeTone: 'danger' },
        { label: t('nav.analytics'), to: `${base}/analytics`, icon: 'chart' },
        { label: t('nav.agent'), to: `${base}/agent`, icon: 'cpu', badge: a.agentApprovals },
      ],
    },
    { label: t('shell.groups.website'), items: [{ label: t('nav.sites'), to: `${base}/sites`, icon: 'globe', badge: a.brokenSites, badgeTone: 'danger' }] },
    { label: t('shell.groups.account'), items: account },
  ]
})

async function logout() {
  await session.logout()
  ws.clear()
  attention.clear()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <ShellLayout :groups="groups">
    <template #banner>
      <ImpersonationBanner v-if="session.user?.impersonation" :impersonation="session.user.impersonation" />
    </template>
    <template #sidebar-top>
      <WorkspaceSwitcher v-if="navWorkspaceId && !session.user?.impersonation" :current-id="navWorkspaceId" :current-name="currentName" />
    </template>
    <template #sidebar-bottom>
      <RouterLink v-if="navWorkspaceId && attention.data.creditsMc !== null" :to="`/w/${navWorkspaceId}/billing/credits`" class="flex items-center gap-2.5 rounded-lg bg-brand-50 px-3 py-2.5 text-sm hover:bg-brand-100" data-testid="sidebar-credits">
        <AppIcon name="coins" :size="18" class="text-brand-600" />
        <span class="min-w-0 flex-1 text-xs text-brand-800">{{ $t('shell.creditsTitle') }}</span>
        <span class="font-semibold tabular-nums text-brand-800">{{ formatCredits(attention.data.creditsMc) }}</span>
      </RouterLink>
    </template>
    <template #topbar>
      <div class="flex items-center gap-3">
        <GlobalSearch v-if="navWorkspaceId" class="hidden sm:block" :workspace-id="navWorkspaceId" :groups="groups" />
        <div v-else class="truncate text-sm font-medium text-ink">{{ $t('common.appName') }}</div>
        <span v-if="navWorkspaceId" class="truncate text-sm font-semibold text-ink sm:hidden">{{ currentName ?? $t('common.appName') }}</span>
        <AppBadge v-if="ws.current?.is_demo && currentName" tone="warning">{{ $t('common.demo') }}</AppBadge>
      </div>
    </template>
    <template #topbar-right>
      <RouterLink v-if="navWorkspaceId" :to="`/w/${navWorkspaceId}/writer/new`" class="hidden items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white shadow-xs hover:bg-brand-500 md:inline-flex" data-testid="topbar-write">
        <AppIcon name="plus" :size="16" />{{ $t('shell.writeNew') }}
      </RouterLink>
      <NotificationsMenu v-if="navWorkspaceId" :items="attentionItems" :dashboard-to="`/w/${navWorkspaceId}/dashboard`" />
      <UserMenu v-if="session.user" :name="session.user.name" :email="session.user.email" account-to="/account" @logout="logout" />
    </template>
    <template #floating>
      <RouterLink v-if="navWorkspaceId && route.name !== 'writer-new'" :to="`/w/${navWorkspaceId}/writer/new`" class="fixed bottom-5 right-5 z-30 inline-flex items-center gap-2 rounded-full bg-brand-600 px-5 py-3 text-sm font-semibold text-white shadow-lg hover:bg-brand-500 md:hidden">
        <AppIcon name="plus" :size="18" />{{ $t('shell.writeShort') }}
      </RouterLink>
    </template>
    <RouterView :key="routeWorkspaceId ?? 'account'" />
  </ShellLayout>
</template>
