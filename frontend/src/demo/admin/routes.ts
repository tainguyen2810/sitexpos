import type { RouteRecordRaw } from 'vue-router'

const pages = import.meta.glob('./pages/*.vue')
const p = (name: string) => pages[`./pages/${name}.vue`]!

export const adminDemoRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    component: () => import('./DemoAdminShell.vue'),
    children: [
      { path: '', redirect: '/overview' },
      { path: 'overview', name: 'a-overview', component: p('OverviewPage') },
      { path: 'customers', name: 'a-customers', component: p('CustomersPage') },
      { path: 'websites', name: 'a-websites', component: p('WebsitesPage') },
      { path: 'plans', name: 'a-plans', component: p('PlansPage') },
      { path: 'payments', name: 'a-payments', component: p('PaymentsPage') },
      { path: 'ai-providers', name: 'a-ai-providers', component: p('AiProvidersPage') },
      { path: 'ai-usage', name: 'a-ai-usage', component: p('AiUsagePage') },
      { path: 'queues', name: 'a-queues', component: p('QueueMonitorPage') },
      { path: 'failed-jobs', name: 'a-failed-jobs', component: p('FailedJobsPage') },
      { path: 'audit-logs', name: 'a-audit-logs', component: p('AuditLogsPage') },
      { path: 'settings', name: 'a-settings', component: p('SystemSettingsPage') },
      { path: 'support', name: 'a-support', component: p('SupportPage') },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'demo-not-found', component: () => import('../ui/DemoNotFound.vue') },
]
