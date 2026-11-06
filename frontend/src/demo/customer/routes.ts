import type { RouteRecordRaw } from 'vue-router'
import { db } from '../store'

const pages = import.meta.glob('./pages/*.vue')
const p = (name: string) => pages[`./pages/${name}.vue`]!

export const customerDemoRoutes: RouteRecordRaw[] = [
  { path: '/', redirect: () => `/w/${db.workspaces[0]?.id ?? 'none'}/dashboard` },
  {
    path: '/w/:workspaceId',
    component: () => import('./DemoCustomerShell.vue'),
    beforeEnter: (to) => (db.workspaces.some((w) => w.id === to.params.workspaceId) ? true : { name: 'demo-not-found' }),
    children: [
      { path: '', redirect: (to) => `/w/${String(to.params.workspaceId)}/dashboard` },
      { path: 'dashboard', name: 'c-dashboard', component: p('DashboardPage') },
      { path: 'sites', name: 'c-sites', component: p('SitesPage') },
      { path: 'sites/:siteId', name: 'c-site', component: p('SiteDetailPage') },
      { path: 'connect', name: 'c-connect', component: p('ConnectWizardPage') },
      { path: 'campaigns', name: 'c-campaigns', component: p('CampaignsPage') },
      { path: 'campaigns/:campaignId', name: 'c-campaign', component: p('CampaignDetailPage') },
      { path: 'topics', name: 'c-topics', component: p('TopicDiscoveryPage') },
      { path: 'keywords', name: 'c-keywords', component: p('KeywordsPage') },
      { path: 'clusters', name: 'c-clusters', component: p('ClustersPage') },
      { path: 'calendar', name: 'c-calendar', component: p('CalendarPage') },
      { path: 'articles', name: 'c-articles', component: p('ArticlesPage') },
      { path: 'articles/:articleId', name: 'c-article', component: p('ArticleEditorPage') },
      { path: 'fact-checks', name: 'c-fact-checks', component: p('FactChecksPage') },
      { path: 'fact-checks/:reportId', name: 'c-fact-check', component: p('FactCheckDetailPage') },
      { path: 'media', name: 'c-media', component: p('MediaStudioPage') },
      { path: 'seo-audit', name: 'c-seo-audit', component: p('SeoAuditPage') },
      { path: 'publishing', name: 'c-publishing', component: p('PublishingPage') },
      { path: 'analytics', name: 'c-analytics', component: p('AnalyticsPage') },
      { path: 'billing', name: 'c-billing', component: p('BillingPage') },
      { path: 'usage', name: 'c-usage', component: p('UsagePage') },
      { path: 'members', name: 'c-members', component: p('MembersPage') },
      { path: 'settings', name: 'c-settings', component: p('SettingsPage') },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'demo-not-found', component: () => import('../ui/DemoNotFound.vue') },
]
