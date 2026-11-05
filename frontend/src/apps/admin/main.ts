import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@/styles.css'
import App from './App.vue'
import { router } from './router'
import { i18n, currentLocale } from '@/shared/i18n'
import { configureApi } from '@/shared/api/client'
import { useSessionStore } from '@/shared/stores/session'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia).use(i18n).use(router)

configureApi({
  locale: currentLocale,
  onUnauthenticated: () => {
    useSessionStore(pinia).clear()
    const current = router.currentRoute.value
    if (!current.meta.guest) void router.replace({ name: 'login', query: { redirect: current.fullPath } })
  },
})

app.mount('#app')
