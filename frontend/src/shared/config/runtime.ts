/**
 * Runtime configuration (docs/DOCKER.md).
 *
 * The Docker image is built once and runs on any domain, so the API / customer
 * app URLs cannot be baked in at build time. `/config.js` (loaded before the
 * app bundle) may set `window.__SITEX_CONFIG__`; the container entrypoint
 * writes it from SITEX_PUBLIC_* variables. Classic (non-Docker) builds keep
 * using VITE_API_BASE_URL / VITE_CUSTOMER_APP_URL, which act as fallbacks.
 */
export interface RuntimeConfig {
  apiBaseUrl?: string
  customerAppUrl?: string
}

declare global {
  interface Window {
    __SITEX_CONFIG__?: RuntimeConfig
  }
}

function runtime(): RuntimeConfig {
  return (typeof window !== 'undefined' && window.__SITEX_CONFIG__) || {}
}

function clean(value: string | undefined): string {
  return (value ?? '').trim().replace(/\/+$/, '')
}

/** Absolute API origin, or '' for same-origin requests. */
export function apiBaseUrl(): string {
  return clean(runtime().apiBaseUrl || import.meta.env.VITE_API_BASE_URL)
}

/** Customer SPA origin (used by the admin app for impersonation links). */
export function customerAppUrl(): string {
  return clean(runtime().customerAppUrl || import.meta.env.VITE_CUSTOMER_APP_URL)
}
