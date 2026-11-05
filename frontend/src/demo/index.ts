/**
 * Demo mode = a frontend-only prototype of every planned SiteX Post screen, backed by
 * generated, clearly labelled sample data kept in the browser. It never calls the
 * backend, an AI provider or a payment gateway.
 *
 * Safety: the flag requires `import.meta.env.DEV`, which Vite replaces with `false` in
 * every `vite build`, so the demo code is dead-code-eliminated from production bundles.
 */
export const DEMO_MODE: boolean = import.meta.env.DEV && import.meta.env.VITE_DEMO_MODE === 'true'

export const DEMO_CUSTOMER_URL = import.meta.env.VITE_DEMO_CUSTOMER_URL ?? 'http://localhost:5173'
export const DEMO_ADMIN_URL = import.meta.env.VITE_DEMO_ADMIN_URL ?? 'http://localhost:5174'
