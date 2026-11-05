/// <reference types="vitest/config" />
import { existsSync, renameSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig, loadEnv, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// Dev server: serve <app>.html for every client-side route (history mode).
function spaEntry(app: string): Plugin {
  const passthrough = ['/@', '/src/', '/node_modules/', '/api/', '/sanctum/']
  return {
    name: 'sitex-spa-entry',
    configureServer(server) {
      server.middlewares.use((req, _res, next) => {
        const url = req.url ?? '/'
        const path = url.split('?')[0] ?? '/'
        if (req.method === 'GET' && !passthrough.some((p) => url.startsWith(p)) && !path.includes('.')) {
          req.url = `/${app}.html`
        }
        next()
      })
    },
  }
}

// Emit dist/<app>/index.html so Nginx `try_files ... /index.html` works.
function indexHtml(app: string): Plugin {
  let outDir = ''
  return {
    name: 'sitex-index-html',
    apply: 'build',
    configResolved(config) {
      outDir = config.build.outDir
    },
    closeBundle() {
      const from = resolve(outDir, `${app}.html`)
      if (existsSync(from)) renameSync(from, resolve(outDir, 'index.html'))
    },
  }
}

// One Vite project, two SPAs. SITEX_APP=customer|admin selects the entry.
// Production: dist/customer -> app.<domain>, dist/admin -> admin.<domain>.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const app = (process.env.SITEX_APP ?? 'customer') === 'admin' ? 'admin' : 'customer'
  const proxyTarget = env.VITE_DEV_API_PROXY ?? 'http://localhost:8000'

  return {
    plugins: [vue(), tailwindcss(), spaEntry(app), indexHtml(app)],
    resolve: {
      alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    server: {
      port: app === 'admin' ? 5174 : 5173,
      strictPort: true,
      // Same-origin API in development: cookies + CSRF work without CORS.
      proxy: {
        '/api': { target: proxyTarget, changeOrigin: true },
        '/sanctum': { target: proxyTarget, changeOrigin: true },
      },
    },
    build: {
      outDir: `dist/${app}`,
      emptyOutDir: true,
      rollupOptions: {
        input: fileURLToPath(new URL(`./${app}.html`, import.meta.url)),
      },
    },
    test: {
      environment: 'jsdom',
      include: ['tests/**/*.spec.ts'],
    },
  }
})
