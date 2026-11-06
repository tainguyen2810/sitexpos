/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL?: string
  readonly VITE_CUSTOMER_APP_URL?: string
  /** 'true' bật giao diện demo — chỉ có hiệu lực khi import.meta.env.DEV (xem src/demo). */
  readonly VITE_DEMO_MODE?: string
  readonly VITE_DEMO_CUSTOMER_URL?: string
  readonly VITE_DEMO_ADMIN_URL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<object, object, unknown>
  export default component
}
