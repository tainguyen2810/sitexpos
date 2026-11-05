import '@/styles.css'
import { bootstrap } from './bootstrap'

// Inline env check (not an imported const) so Rollup drops the dynamic imports in builds.
if (import.meta.env.DEV && import.meta.env.VITE_DEMO_MODE === 'true') {
  // Development-only UI demo (npm run demo). The env check is constant-false in production
  // builds, so this branch and the whole src/demo tree are removed from the bundle.
  void Promise.all([import('@/demo/boot'), import('@/demo/customer/routes')]).then(([{ bootDemo }, { customerDemoRoutes }]) =>
    bootDemo(customerDemoRoutes, 'SiteX Post'),
  )
} else {
  bootstrap()
}
