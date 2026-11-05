import { createApp, type Component } from 'vue'
import { createPinia } from 'pinia'
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { i18n } from '@/shared/i18n'
import DemoApp from './DemoApp.vue'

/** Mounts the demo SPA (customer or admin). Only reachable when DEMO_MODE is true. */
export function bootDemo(routes: RouteRecordRaw[], title: string, root: Component = DemoApp): void {
  document.title = `${title} · Demo`
  const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
  })
  createApp(root).use(createPinia()).use(i18n).use(router).mount('#app')
}
