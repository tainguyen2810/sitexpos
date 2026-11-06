<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import AppIcon from '@/shared/ui/AppIcon.vue'

export interface DemoNotification {
  id: string
  title: string
  body: string
  at: string
  to?: string
}
const props = defineProps<{ items: DemoNotification[] }>()
const open = ref(false)
const read = ref<string[]>([])
const root = ref<HTMLElement | null>(null)
function onDoc(e: MouseEvent) {
  if (root.value && !root.value.contains(e.target as Node)) open.value = false
}
onMounted(() => document.addEventListener('click', onDoc))
onBeforeUnmount(() => document.removeEventListener('click', onDoc))
const unread = () => props.items.filter((n) => !read.value.includes(n.id)).length
</script>

<template>
  <div ref="root" class="relative">
    <button type="button" class="relative rounded-lg p-2 text-ink-muted hover:bg-surface-muted" :aria-label="`Thông báo (${unread()} chưa đọc)`" :aria-expanded="open" @click="open = !open">
      <AppIcon name="bell" :size="20" />
      <span v-if="unread()" class="absolute right-1 top-1 grid h-4 min-w-4 place-items-center rounded-full bg-danger-600 px-1 text-[10px] font-bold text-white">{{ unread() }}</span>
    </button>
    <div v-if="open" class="absolute right-0 z-40 mt-2 w-80 rounded-xl border border-line bg-surface shadow-xl">
      <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <p class="text-sm font-semibold">Thông báo</p>
        <button type="button" class="text-xs text-brand-700 hover:underline" @click="read = items.map((n) => n.id)">Đánh dấu đã đọc</button>
      </div>
      <ul class="max-h-96 divide-y divide-line overflow-y-auto">
        <li v-if="!items.length" class="px-4 py-6 text-center text-sm text-ink-muted">Không có thông báo mới.</li>
        <li v-for="n in items" :key="n.id">
          <component :is="n.to ? 'RouterLink' : 'div'" :to="n.to" class="block px-4 py-3 hover:bg-surface-muted" @click="read.push(n.id); open = false">
            <p class="flex items-center gap-2 text-sm font-medium" :class="read.includes(n.id) ? 'text-ink-muted' : 'text-ink'">
              <span v-if="!read.includes(n.id)" class="h-2 w-2 shrink-0 rounded-full bg-brand-600" />{{ n.title }}
            </p>
            <p class="mt-0.5 text-xs text-ink-muted">{{ n.body }}</p>
            <p class="mt-1 text-[11px] text-ink-subtle">{{ n.at }}</p>
          </component>
        </li>
      </ul>
    </div>
  </div>
</template>
