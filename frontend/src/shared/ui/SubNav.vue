<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, type RouteLocationRaw } from 'vue-router'
import AppIcon from './AppIcon.vue'

export interface SubNavItem {
  key: string
  label: string
  to: RouteLocationRaw
  testid?: string
  count?: number | null
}

/**
 * Section tabs under a page title. Only the first `max` tabs are shown; the
 * rest go into a "Thêm" menu so long sections (e.g. SEO Analytics) stay
 * readable. The active tab is always visible.
 */
const props = withDefaults(defineProps<{ items: SubNavItem[]; active: string; label: string; max?: number }>(), { max: 6 })

const visible = computed(() => {
  if (props.items.length <= props.max + 1) return props.items
  const head = props.items.slice(0, props.max)
  const current = props.items.find((i) => i.key === props.active)
  if (current && !head.includes(current)) return [...head.slice(0, props.max - 1), current]
  return head
})
const overflow = computed(() => props.items.filter((i) => !visible.value.includes(i)))

const open = ref(false)
const root = ref<HTMLElement | null>(null)
const route = useRoute()
watch(() => route.fullPath, () => (open.value = false))
function onDoc(e: MouseEvent) {
  if (root.value && !root.value.contains(e.target as Node)) open.value = false
}
onMounted(() => document.addEventListener('click', onDoc))
onBeforeUnmount(() => document.removeEventListener('click', onDoc))
</script>

<template>
  <nav ref="root" class="relative -mt-2 mb-6 flex items-end gap-1 border-b border-line" :aria-label="label">
    <div class="flex min-w-0 flex-1 gap-1 overflow-x-auto">
      <RouterLink
        v-for="i in visible"
        :key="i.key"
        :to="i.to"
        class="flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition-colors"
        :class="active === i.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:border-line-strong hover:text-ink'"
        :aria-current="active === i.key ? 'page' : undefined"
        :data-testid="i.testid"
      >
        {{ i.label }}
        <span v-if="i.count" class="rounded-full px-1.5 text-xs font-semibold" :class="active === i.key ? 'bg-brand-100 text-brand-700' : 'bg-slate-100 text-ink-muted'">{{ i.count }}</span>
      </RouterLink>
    </div>
    <div v-if="overflow.length" class="shrink-0">
      <button
        type="button"
        class="flex items-center gap-1 whitespace-nowrap border-b-2 border-transparent px-3 py-2.5 text-sm font-medium text-ink-muted hover:text-ink"
        :aria-expanded="open"
        data-testid="subnav-more"
        @click="open = !open"
      >
        {{ $t('common.more') }}
        <AppIcon name="chevronDown" :size="14" />
      </button>
      <div v-if="open" class="absolute right-0 top-full z-30 mt-1 w-56 rounded-xl bg-surface p-1.5 shadow-lg ring-1 ring-slate-900/5">
        <RouterLink
          v-for="i in overflow"
          :key="i.key"
          :to="i.to"
          class="flex items-center justify-between rounded-lg px-3 py-2 text-sm text-ink hover:bg-slate-50"
          :data-testid="i.testid"
        >
          {{ i.label }}
          <span v-if="i.count" class="rounded-full bg-slate-100 px-1.5 text-xs text-ink-muted">{{ i.count }}</span>
        </RouterLink>
      </div>
    </div>
  </nav>
</template>
