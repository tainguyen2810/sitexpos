import { computed, reactive, ref, watch, type Ref } from 'vue'

export type SortDir = 'asc' | 'desc'

export interface TableOptions<T, F extends Record<string, string>> {
  /** Text used by the search box. */
  search?: (item: T) => string
  /** Filter values ('' = no filter) and how each one matches. */
  filters?: { initial: F; match: { [K in keyof F]?: (item: T, value: string) => boolean } }
  /** Sort accessors by key. */
  sorts?: Record<string, (item: T) => string | number | null>
  defaultSort?: { key: string; dir: SortDir }
  pageSize?: number
}

function norm(s: string): string {
  return s
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/đ/g, 'd')
}

/** Client-side search / filter / sort / pagination for demo tables. */
export function useTable<T, F extends Record<string, string> = Record<string, string>>(source: () => T[], opts: TableOptions<T, F> = {}) {
  const q = ref('')
  const filters = reactive({ ...(opts.filters?.initial ?? {}) }) as F
  const sortKey = ref(opts.defaultSort?.key ?? '')
  const sortDir: Ref<SortDir> = ref(opts.defaultSort?.dir ?? 'asc')
  const page = ref(1)
  const pageSize = ref(opts.pageSize ?? 10)

  const filtered = computed(() => {
    const term = norm(q.value.trim())
    return source().filter((item) => {
      if (term && opts.search && !norm(opts.search(item)).includes(term)) return false
      for (const key in filters) {
        const value = filters[key]
        if (!value) continue
        const fn = opts.filters?.match[key]
        if (fn && !fn(item, value)) return false
      }
      return true
    })
  })

  const sorted = computed(() => {
    const accessor = opts.sorts?.[sortKey.value]
    if (!accessor) return filtered.value
    const dir = sortDir.value === 'asc' ? 1 : -1
    return [...filtered.value].sort((a, b) => {
      const va = accessor(a)
      const vb = accessor(b)
      if (va === vb) return 0
      if (va === null) return 1
      if (vb === null) return -1
      return (typeof va === 'number' && typeof vb === 'number' ? va - vb : String(va).localeCompare(String(vb), 'vi')) * dir
    })
  })

  const total = computed(() => filtered.value.length)
  const pageCount = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)))
  const rows = computed(() => sorted.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value))
  const hasActiveFilters = computed(() => !!q.value || Object.values(filters).some(Boolean))

  watch([q, () => ({ ...filters }), pageSize], () => (page.value = 1), { deep: true })
  watch(pageCount, (n) => {
    if (page.value > n) page.value = n
  })

  function toggleSort(key: string) {
    if (sortKey.value === key) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    else {
      sortKey.value = key
      sortDir.value = 'desc'
    }
  }
  function clearFilters() {
    q.value = ''
    for (const key in filters) (filters as Record<string, string>)[key] = ''
  }

  return { q, filters, sortKey, sortDir, page, pageSize, rows, total, pageCount, sorted, filtered, hasActiveFilters, toggleSort, clearFilters }
}

export type TableState = ReturnType<typeof useTable>
