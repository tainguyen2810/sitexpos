import { createRouter, createWebHistory, type RouteLocationNormalized } from 'vue-router'
import { useSessionStore } from '@/shared/stores/session'
import { ApiError } from '@/shared/api/client'
import { lastWorkspace, useWorkspaceStore } from './stores/workspace'

declare module 'vue-router' {
  interface RouteMeta {
    guest?: boolean
    auth?: boolean
    /** Authenticated routes require a verified email unless this is false. */
    verified?: boolean
  }
}

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('./pages/LoginPage.vue'), meta: { guest: true } },
    { path: '/register', name: 'register', component: () => import('./pages/RegisterPage.vue'), meta: { guest: true } },
    { path: '/two-factor', name: 'two-factor', component: () => import('./pages/TwoFactorPage.vue'), meta: { guest: true } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('./pages/ForgotPasswordPage.vue'), meta: { guest: true } },
    { path: '/reset-password', name: 'reset-password', component: () => import('./pages/ResetPasswordPage.vue'), meta: { guest: true } },
    { path: '/verify-email', name: 'verify-email', component: () => import('./pages/VerifyEmailPage.vue'), meta: { auth: true, verified: false } },
    { path: '/invitations/:token', name: 'invitation', component: () => import('./pages/AcceptInvitationPage.vue'), props: true },
    { path: '/onboarding', name: 'onboarding', component: () => import('./pages/OnboardingPage.vue'), meta: { auth: true } },
    {
      path: '/',
      component: () => import('./components/CustomerShell.vue'),
      meta: { auth: true },
      children: [
        { path: '', name: 'home', component: () => import('./pages/HomeRedirect.vue') },
        { path: 'account', name: 'account', component: () => import('./pages/AccountPage.vue'), meta: { verified: false } },
        {
          path: 'w/:workspaceId',
          children: [
            { path: '', redirect: (to) => ({ name: 'dashboard', params: to.params }) },
            { path: 'dashboard', name: 'dashboard', component: () => import('./pages/DashboardPage.vue') },
            { path: 'sites', name: 'sites', component: () => import('./pages/SitesPage.vue') },
            { path: 'sites/:siteId', name: 'site', component: () => import('./pages/SiteDetailPage.vue') },
            { path: 'ai', name: 'ai', component: () => import('./pages/AiTasksPage.vue') },
            { path: 'members', name: 'members', component: () => import('./pages/MembersPage.vue') },
            { path: 'settings', name: 'settings', component: () => import('./pages/WorkspaceSettingsPage.vue') },
            { path: 'audit', name: 'audit', component: () => import('./pages/AuditLogPage.vue') },
          ],
        },
      ],
    },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('./pages/NotFoundPage.vue') },
  ],
})

async function loadWorkspace(to: RouteLocationNormalized) {
  const id = String(to.params.workspaceId)
  try {
    await useWorkspaceStore().load(id)
    return true
  } catch (e) {
    // Non-members get 404 from the API (no existence leak) — mirror that.
    if (e instanceof ApiError && (e.status === 404 || e.status === 403)) {
      return { name: 'not-found', params: { pathMatch: to.path.slice(1).split('/') }, query: { reason: e.code } }
    }
    throw e
  }
}

router.beforeEach(async (to) => {
  const session = useSessionStore()
  const user = await session.ensureLoaded()
  const needsAuth = to.matched.some((r) => r.meta.auth)

  if (to.meta.guest && user) return { name: 'home' }
  if (needsAuth && !user) return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  if (user && needsAuth && !user.email_verified && !user.impersonation && to.matched.every((r) => r.meta.verified !== false)) {
    return { name: 'verify-email' }
  }
  // Runs on every navigation (beforeEnter would not re-run when only the param changes).
  if (typeof to.params.workspaceId === 'string') return loadWorkspace(to)
  return true
})

/** Where to send an authenticated user who has no explicit destination. */
export function homeTarget(): { name: string; params?: Record<string, string> } {
  const user = useSessionStore().user
  if (!user) return { name: 'login' }
  if (user.impersonation) return { name: 'dashboard', params: { workspaceId: user.impersonation.workspace_id } }
  const active = user.workspaces.filter((w) => w.status === 'active')
  const last = lastWorkspace()
  const target = active.find((w) => w.id === last) ?? active[0]
  return target ? { name: 'dashboard', params: { workspaceId: target.id } } : { name: 'onboarding' }
}
