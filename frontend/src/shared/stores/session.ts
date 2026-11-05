import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { authApi } from '@/shared/api/auth'
import { ApiError } from '@/shared/api/client'
import type { Me } from '@/shared/api/types'
import { setLocale } from '@/shared/i18n'

export const useSessionStore = defineStore('session', () => {
  const user = ref<Me | null>(null)
  const loaded = ref(false)

  const isAuthenticated = computed(() => user.value !== null)
  const isStaff = computed(() => user.value?.platform_role != null)

  async function fetchMe(): Promise<Me | null> {
    try {
      user.value = await authApi.me(true)
      setLocale(user.value.locale)
    } catch (e) {
      if (!(e instanceof ApiError) || e.status !== 401) throw e
      user.value = null
    } finally {
      loaded.value = true
    }
    return user.value
  }

  async function ensureLoaded(): Promise<Me | null> {
    if (!loaded.value) await fetchMe()
    return user.value
  }

  async function logout(): Promise<void> {
    try {
      await authApi.logout()
    } finally {
      user.value = null
    }
  }

  function clear() {
    user.value = null
  }

  return { user, loaded, isAuthenticated, isStaff, fetchMe, ensureLoaded, logout, clear }
})
