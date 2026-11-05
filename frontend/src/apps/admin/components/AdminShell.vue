<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/shared/stores/session'
import ShellLayout, { type NavItem } from '@/shared/layouts/ShellLayout.vue'
import UserMenu from '@/shared/ui/UserMenu.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'

const router = useRouter()
const { t } = useI18n()
const session = useSessionStore()

const nav = computed<NavItem[]>(() => {
  const perms = session.user?.platform_permissions ?? []
  const items: (NavItem & { permission?: string })[] = [
    { label: t('nav.dashboard'), to: '/', icon: 'home', permission: 'platform.dashboard.view' },
    { label: t('nav.workspaces'), to: '/workspaces', icon: 'building', permission: 'platform.tenants.view' },
    { label: t('nav.users'), to: '/users', icon: 'users', permission: 'platform.users.view' },
    { label: t('nav.staff'), to: '/staff', icon: 'shield', permission: 'platform.staff.manage' },
    { label: t('nav.aiUsage'), to: '/ai/usage', icon: 'chart', permission: 'platform.ai.view_costs' },
    { label: t('nav.aiSettings'), to: '/ai/settings', icon: 'sparkles', permission: 'platform.ai.view_costs' },
    { label: t('nav.jobs'), to: '/jobs', icon: 'layers', permission: 'platform.jobs.manage' },
    { label: t('nav.audit'), to: '/audit', icon: 'list' },
    { label: t('nav.billing'), icon: 'card', soon: true },
  ]
  return items.filter((i) => !i.permission || perms.includes(i.permission))
})

async function logout() {
  await session.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <ShellLayout :nav="nav" brand-suffix="Admin">
    <template #topbar>
      <span class="text-sm font-medium">{{ $t('admin.title') }}</span>
    </template>
    <template #topbar-right>
      <AppBadge v-if="session.user?.platform_role" tone="brand">{{ $t(`roles.${session.user.platform_role}`) }}</AppBadge>
      <UserMenu v-if="session.user" :name="session.user.name" :email="session.user.email" account-to="/account" @logout="logout" />
    </template>
    <RouterView />
  </ShellLayout>
</template>
