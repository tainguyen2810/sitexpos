import { onMounted, ref, shallowRef } from 'vue'
import { simulateRequest } from '../store'

/**
 * Gives a demo screen the same loading / error / ready lifecycle as a real API call.
 * Data itself is read reactively from the demo store; this only simulates the request.
 */
export function useDemoQuery(options: { extraMs?: number } = {}) {
  const loading = ref(true)
  const error = shallowRef<unknown>(null)

  async function reload() {
    loading.value = true
    error.value = null
    try {
      await simulateRequest(options.extraMs ?? 0)
    } catch (e) {
      error.value = e
    } finally {
      loading.value = false
    }
  }

  onMounted(reload)
  return { loading, error, reload }
}
