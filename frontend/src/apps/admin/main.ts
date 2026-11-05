import '@/styles.css'
import { bootstrap } from './bootstrap'

// Inline env check (not an imported const) so Rollup drops the dynamic imports in builds.
if (import.meta.env.DEV && import.meta.env.VITE_DEMO_MODE === 'true') {
  // Development-only UI demo (npm run demo:admin) — removed from production builds.
  void Promise.all([import('@/demo/boot'), import('@/demo/admin/routes')]).then(([{ bootDemo }, { adminDemoRoutes }]) =>
    bootDemo(adminDemoRoutes, 'SiteX Post Admin'),
  )
} else {
  bootstrap()
}
