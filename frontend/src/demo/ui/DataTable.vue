<script setup lang="ts" generic="T extends { id: string }">
import { computed, type Ref } from 'vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import DemoErrorState from './DemoErrorState.vue'
import Pagination from './Pagination.vue'

export interface Column {
  key: string
  label: string
  sortable?: boolean
  class?: string
  align?: 'left' | 'right' | 'center'
  /** Hide below this breakpoint (responsive tables). */
  hideBelow?: 'sm' | 'md' | 'lg'
}

interface TableLike {
  rows: Ref<T[]>
  total: Ref<number>
  pageCount: Ref<number>
  page: Ref<number>
  pageSize: Ref<number>
  sortKey: Ref<string>
  sortDir: Ref<'asc' | 'desc'>
  hasActiveFilters: Ref<boolean>
  toggleSort: (key: string) => void
  clearFilters: () => void
}

const props = withDefaults(
  defineProps<{
    table: TableLike
    columns: Column[]
    loading?: boolean
    error?: unknown
    emptyTitle?: string
    emptyDescription?: string
    selectable?: boolean
    clickable?: boolean
    paginate?: boolean
  }>(),
  { loading: false, error: undefined, emptyTitle: 'Chưa có dữ liệu', emptyDescription: undefined, selectable: false, clickable: false, paginate: true },
)
const emit = defineEmits<{ retry: []; rowClick: [row: T] }>()
const selected = defineModel<string[]>('selected', { default: () => [] })

const rows = computed(() => props.table.rows.value)
const allChecked = computed(() => rows.value.length > 0 && rows.value.every((r) => selected.value.includes(r.id)))
function toggleAll() {
  const ids = rows.value.map((r) => r.id)
  selected.value = allChecked.value ? selected.value.filter((id) => !ids.includes(id)) : [...new Set([...selected.value, ...ids])]
}
function toggle(id: string) {
  selected.value = selected.value.includes(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id]
}
function setPage(p: number) {
  const t = props.table
  t.page.value = p
}
function setPageSize(n: number) {
  const t = props.table
  t.pageSize.value = n
}
const hide = { sm: 'hidden sm:table-cell', md: 'hidden md:table-cell', lg: 'hidden lg:table-cell' }
function cellClass(c: Column) {
  return [c.class, c.hideBelow ? hide[c.hideBelow] : '', c.align === 'right' ? 'text-right' : c.align === 'center' ? 'text-center' : 'text-left']
}
</script>

<template>
  <div>
    <LoadingState v-if="loading" :rows="5" />
    <DemoErrorState v-else-if="error" :error="error" @retry="emit('retry')" />
    <template v-else>
      <EmptyState v-if="!table.total.value" :title="table.hasActiveFilters.value ? 'Không có kết quả phù hợp' : emptyTitle" :description="table.hasActiveFilters.value ? 'Thử đổi từ khóa tìm kiếm hoặc bỏ bớt bộ lọc.' : emptyDescription" :icon="table.hasActiveFilters.value ? 'search' : 'inbox'">
        <button v-if="table.hasActiveFilters.value" type="button" class="text-sm font-medium text-brand-700 hover:underline" @click="table.clearFilters()">Xóa bộ lọc</button>
        <slot v-else name="empty-action" />
      </EmptyState>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
          <thead class="bg-surface-muted/60">
            <tr>
              <th v-if="selectable" class="w-10 px-4 py-2.5">
                <input type="checkbox" class="rounded border-line" :checked="allChecked" aria-label="Chọn tất cả trên trang" @change="toggleAll" />
              </th>
              <th v-for="c in columns" :key="c.key" scope="col" class="whitespace-nowrap px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-ink-muted" :class="cellClass(c)" :aria-sort="table.sortKey.value === c.key ? (table.sortDir.value === 'asc' ? 'ascending' : 'descending') : undefined">
                <button v-if="c.sortable" type="button" class="inline-flex items-center gap-1 uppercase hover:text-ink" @click="table.toggleSort(c.key)">
                  {{ c.label }}
                  <AppIcon :name="table.sortKey.value === c.key ? (table.sortDir.value === 'asc' ? 'arrowUp' : 'arrowDown') : 'chevronDown'" :size="12" :class="table.sortKey.value === c.key ? 'text-brand-600' : 'text-ink-subtle'" />
                </button>
                <span v-else>{{ c.label }}</span>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line bg-surface">
            <tr v-for="row in rows" :key="row.id" class="hover:bg-surface-muted/50" :class="[clickable ? 'cursor-pointer' : '', selected.includes(row.id) ? 'bg-brand-50/50' : '']" @click="clickable && emit('rowClick', row)">
              <td v-if="selectable" class="px-4 py-3" @click.stop>
                <input type="checkbox" class="rounded border-line" :checked="selected.includes(row.id)" aria-label="Chọn dòng" @change="toggle(row.id)" />
              </td>
              <td v-for="c in columns" :key="c.key" class="px-4 py-3 align-middle" :class="cellClass(c)">
                <slot :name="`cell-${c.key}`" :row="row">{{ (row as Record<string, unknown>)[c.key] }}</slot>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination v-if="paginate && table.total.value" :page="table.page.value" :page-size="table.pageSize.value" :total="table.total.value" :page-count="table.pageCount.value" @update:page="setPage" @update:page-size="setPageSize" />
    </template>
  </div>
</template>
