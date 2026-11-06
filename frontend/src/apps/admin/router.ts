import { createRouter, createWebHistory } from 'vue-router'
import { useSessionStore } from '@/shared/stores/session'

declare module 'vue-router' {
  interface RouteMeta {
    guest?: boolean
    auth?: boolean
    /** Platform permission needed to show the page (the API enforces it again). */
    permission?: string
  }
}

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('./pages/LoginPage.vue'), meta: { guest: true } },
    { path: '/two-factor', name: 'two-factor', component: () => import('./pages/TwoFactorPage.vue'), meta: { guest: true } },
    { path: '/forgot-password', name: 'forgot-password', redirect: '/login' },
    { path: '/register', name: 'register', redirect: '/login' },
    { path: '/not-staff', name: 'not-staff', component: () => import('./pages/NotStaffPage.vue'), meta: { auth: true } },
    { path: '/setup-2fa', name: 'setup-2fa', component: () => import('./pages/SetupTwoFactorPage.vue'), meta: { auth: true } },
    {
      path: '/',
      component: () => import('./components/AdminShell.vue'),
      meta: { auth: true },
      children: [
        { path: '', name: 'dashboard', component: () => import('./pages/DashboardPage.vue'), meta: { permission: 'platform.dashboard.view' } },
        { path: 'workspaces', name: 'workspaces', component: () => import('./pages/WorkspacesPage.vue'), meta: { permission: 'platform.tenants.view' } },
        { path: 'workspaces/:id', name: 'workspace', component: () => import('./pages/WorkspaceDetailPage.vue'), props: true, meta: { permission: 'platform.tenants.view' } },
        { path: 'users', name: 'users', component: () => import('./pages/UsersPage.vue'), meta: { permission: 'platform.users.view' } },
        { path: 'users/:id', name: 'user', component: () => import('./pages/UserDetailPage.vue'), props: true, meta: { permission: 'platform.users.view' } },
        { path: 'staff', name: 'staff', component: () => import('./pages/StaffPage.vue'), meta: { permission: 'platform.staff.manage' } },
        { path: 'ai/usage', name: 'ai-usage', component: () => import('./pages/AiUsagePage.vue'), meta: { permission: 'platform.ai.view_costs' } },
        { path: 'ai/settings', name: 'ai-settings', component: () => import('./pages/AiSettingsPage.vue'), meta: { permission: 'platform.ai.view_costs' } },
        { path: 'research', name: 'research', component: () => import('./pages/ResearchAdminPage.vue') },
        { path: 'writer', name: 'writer', component: () => import('./pages/WriterAdminPage.vue') },
        { path: 'media', name: 'media', component: () => import('./pages/MediaAdminPage.vue'), meta: { permission: 'platform.ai.view_costs' } },
        { path: 'publishing', name: 'publishing', component: () => import('./pages/PublishingAdminPage.vue'), meta: { permission: 'platform.jobs.manage' } },
        { path: 'billing', redirect: '/billing/plans' },
        { path: 'billing/plans', name: 'billing-plans', component: () => import('./pages/billing/AdminPlansPage.vue'), meta: { permission: 'platform.billing.manage' } },
        { path: 'billing/subscriptions', name: 'billing-subscriptions', component: () => import('./pages/billing/AdminSubscriptionsPage.vue'), meta: { permission: 'platform.billing.manage' } },
        { path: 'billing/orders', name: 'billing-orders', component: () => import('./pages/billing/AdminOrdersPage.vue'), meta: { permission: 'platform.billing.manage' } },
        { path: 'billing/reconciliation', name: 'billing-reconciliation', component: () => import('./pages/billing/AdminReconciliationPage.vue'), meta: { permission: 'platform.billing.manage' } },
        { path: 'billing/refunds', name: 'billing-refunds', component: () => import('./pages/billing/AdminRefundsPage.vue'), meta: { permission: 'platform.billing.manage' } },
        { path: 'billing/credits', name: 'billing-credits', component: () => import('./pages/billing/AdminCreditsPage.vue'), meta: { permission: 'platform.billing.manage' } },
        { path: 'billing/reports', name: 'billing-reports', component: () => import('./pages/billing/AdminReportsPage.vue'), meta: { permission: 'platform.billing.manage' } },
        { path: 'billing/settings', name: 'billing-settings', component: () => import('./pages/billing/AdminBillingSettingsPage.vue'), meta: { permission: 'platform.billing.manage' } },
        { path: 'analytics', name: 'analytics', component: () => import('./pages/AnalyticsAdminPage.vue'), meta: { permission: 'platform.jobs.manage' } },
        { path: 'setup', name: 'setup', component: () => import('./pages/system/SetupWizardPage.vue'), meta: { permission: 'platform.system.manage' } },
        { path: 'system', redirect: '/system/health' },
        { path: 'system/health', name: 'system-health', component: () => import('./pages/system/SystemHealthPage.vue'), meta: { permission: 'platform.system.view' } },
        { path: 'system/upgrade', name: 'system-upgrade', component: () => import('./pages/system/UpgradePage.vue'), meta: { permission: 'platform.system.manage' } },
        { path: 'system/backups', name: 'system-backups', component: () => import('./pages/system/BackupsPage.vue'), meta: { permission: 'platform.system.manage' } },
        { path: 'system/alerts', name: 'system-alerts', component: () => import('./pages/system/AlertsPage.vue'), meta: { permission: 'platform.system.manage' } },
        { path: 'jobs', name: 'jobs', component: () => import('./pages/JobsPage.vue'), meta: { permission: 'platform.jobs.manage' } },
        { path: 'audit', name: 'audit', component: () => import('./pages/AuditLogPage.vue') },
        { path: 'account', name: 'account', component: () => import('./pages/AccountPage.vue') },
      ],
    },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('./pages/NotFoundPage.vue') },
  ],
})

router.beforeEach(async (to) => {
  const session = useSessionStore()
  const user = await session.ensureLoaded()
  const needsAuth = to.matched.some((r) => r.meta.auth)

  if (to.meta.guest && user) return { name: 'dashboard' }
  if (needsAuth && !user) return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  if (!user || !needsAuth) return true

  if (!user.platform_role) return to.name === 'not-staff' ? true : { name: 'not-staff' }
  // Platform staff must use 2FA (the API also refuses with two_factor_required).
  if (!user.two_factor_enabled) return to.name === 'setup-2fa' ? true : { name: 'setup-2fa' }
  if (to.name === 'setup-2fa' || to.name === 'not-staff') return { name: 'dashboard' }

  if (to.meta.permission && !user.platform_permissions.includes(to.meta.permission)) {
    return to.name === 'dashboard' ? { name: 'audit' } : { name: 'not-found', params: { pathMatch: to.path.slice(1).split('/') } }
  }
  return true
})
