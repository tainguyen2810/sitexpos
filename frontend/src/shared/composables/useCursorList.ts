import { ref, shallowRef } from 'vue'
import type { Paginated } from '@/shared/api/types'

/** Cursor-paginated list with "load more", plus loading/error state. */
export function useCursorList<T>(loader: (cursor: string | null) => Promise<Paginated<T>>) {
  const items = shallowRef<T[]>([])
  const next = ref<string | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = shallowRef<unknown>(null)
  const loaded = ref(false)

  async function reload() {
    loading.value = true
    error.value = null
    try {
      const page = await loader(null)
      items.value = page.data
      next.value = page.meta.next_cursor
      loaded.value = true
    } catch (e) {
      error.value = e
    } finally {
      loading.value = false
    }
  }

  async function more() {
    if (!next.value) return
    loadingMore.value = true
    try {
      const page = await loader(next.value)
      items.value = [...items.value, ...page.data]
      next.value = page.meta.next_cursor
    } catch (e) {
      error.value = e
    } finally {
      loadingMore.value = false
    }
  }

  return { items, next, loading, loadingMore, error, loaded, reload, more }
}
