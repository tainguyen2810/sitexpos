import { ref, shallowRef } from 'vue'

/**
 * Tracks the loading / error / data state of a request so every screen can
 * render loading, error and empty states consistently.
 */
export function useAsync<T>(loader: () => Promise<T>, options: { immediate?: boolean } = {}) {
  const data = shallowRef<T | null>(null)
  const error = shallowRef<unknown>(null)
  const loading = ref(false)

  async function run(): Promise<T | null> {
    loading.value = true
    error.value = null
    try {
      data.value = await loader()
      return data.value
    } catch (e) {
      error.value = e
      return null
    } finally {
      loading.value = false
    }
  }

  if (options.immediate !== false) void run()

  return { data, error, loading, run }
}
