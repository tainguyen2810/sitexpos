<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/shared/ui/AppIcon.vue'

const props = defineProps<{ total: number; pageCount: number; sizes?: number[] }>()
const page = defineModel<number>('page', { required: true })
const pageSize = defineModel<number>('pageSize', { required: true })

const pages = computed(() => {
  const n = props.pageCount
  const cur = page.value
  const set = new Set([1, n, cur - 1, cur, cur + 1].filter((p) => p >= 1 && p <= n))
  const sorted = [...set].sort((a, b) => a - b)
  const out: (number | '…')[] = []
  sorted.forEach((p, i) => {
    if (i && p - sorted[i - 1]! > 1) out.push('…')
    out.push(p)
  })
  return out
})
const from = computed(() => (props.total ? (page.value - 1) * pageSize.value + 1 : 0))
const to = computed(() => Math.min(props.total, page.value * pageSize.value))
</script>

<template>
  <div class="flex flex-col items-center justify-between gap-3 border-t border-line px-4 py-3 text-sm text-ink-muted sm:flex-row sm:px-5">
    <div class="flex items-center gap-2">
      <span>{{ from }}–{{ to }} / {{ total }}</span>
      <label class="flex items-center gap-1">
        <span class="sr-only">Số dòng mỗi trang</span>
        <select v-model.number="pageSize" class="rounded-md border border-line bg-surface py-1 pl-2 pr-6 text-xs">
          <option v-for="s in sizes ?? [10, 20, 50]" :key="s" :value="s">{{ s }} / trang</option>
        </select>
      </label>
    </div>
    <nav class="flex items-center gap-1" aria-label="Phân trang">
      <button type="button" class="rounded-md p-1.5 hover:bg-surface-muted disabled:opacity-40" :disabled="page <= 1" aria-label="Trang trước" @click="page--">
        <AppIcon name="chevronLeft" :size="16" />
      </button>
      <template v-for="(p, i) in pages" :key="i">
        <span v-if="p === '…'" class="px-1">…</span>
        <button
          v-else
          type="button"
          class="min-w-8 rounded-md px-2 py-1 text-sm"
          :class="p === page ? 'bg-brand-600 font-medium text-white' : 'hover:bg-surface-muted'"
          :aria-current="p === page ? 'page' : undefined"
          @click="page = p"
        >
          {{ p }}
        </button>
      </template>
      <button type="button" class="rounded-md p-1.5 hover:bg-surface-muted disabled:opacity-40" :disabled="page >= pageCount" aria-label="Trang sau" @click="page++">
        <AppIcon name="chevronRight" :size="16" />
      </button>
    </nav>
  </div>
</template>
