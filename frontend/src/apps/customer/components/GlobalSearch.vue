<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '@/shared/ui/AppIcon.vue'
import { writerApi, type Article } from '@/shared/api/writer'
import type { NavGroup } from '@/shared/layouts/ShellLayout.vue'

const props = defineProps<{ workspaceId: string; groups: NavGroup[] }>()
const router = useRouter()

const q = ref('')
const open = ref(false)
const articles = ref<Article[]>([])
const loading = ref(false)
const active = ref(0)
const root = ref<HTMLElement | null>(null)
const input = ref<HTMLInputElement | null>(null)

const norm = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').toLowerCase()
const pages = computed(() => {
  const term = norm(q.value.trim())
  if (!term) return []
  return props.groups.flatMap((g) => g.items).filter((i) => norm(i.label).includes(term)).slice(0, 5)
})
const results = computed(() => [
  ...pages.value.map((p) => ({ key: `p:${p.to}`, label: p.label, to: p.to, kind: 'page' as const, icon: p.icon })),
  ...articles.value.map((a) => ({ key: `a:${a.id}`, label: a.title, to: `/w/${props.workspaceId}/writer/articles/${a.id}`, kind: 'article' as const, icon: 'file' as const })),
])

let timer: ReturnType<typeof setTimeout> | undefined
let seq = 0
watch(q, (v) => {
  active.value = 0
  clearTimeout(timer)
  const term = v.trim()
  if (term.length < 2) {
    articles.value = []
    return
  }
  timer = setTimeout(async () => {
    const mine = ++seq
    loading.value = true
    try {
      const r = await writerApi.articles(props.workspaceId, { q: term, per_page: 5 })
      if (mine === seq) articles.value = r.data.slice(0, 5)
    } catch {
      if (mine === seq) articles.value = []
    } finally {
      if (mine === seq) loading.value = false
    }
  }, 250)
})

function go(to: string) {
  open.value = false
  q.value = ''
  void router.push(to)
}
function onKey(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    active.value = Math.min(active.value + 1, results.value.length - 1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    active.value = Math.max(active.value - 1, 0)
  } else if (e.key === 'Enter') {
    const r = results.value[active.value]
    if (r) go(r.to)
  } else if (e.key === 'Escape') {
    open.value = false
    input.value?.blur()
  }
}
function onDoc(e: MouseEvent) {
  if (root.value && !root.value.contains(e.target as Node)) open.value = false
}
function onGlobalKey(e: KeyboardEvent) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    input.value?.focus()
  }
}
onMounted(() => {
  document.addEventListener('click', onDoc)
  document.addEventListener('keydown', onGlobalKey)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDoc)
  document.removeEventListener('keydown', onGlobalKey)
  clearTimeout(timer)
})
</script>

<template>
  <div ref="root" class="relative w-full max-w-md">
    <AppIcon name="search" :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-subtle" />
    <input
      ref="input"
      v-model="q"
      type="search"
      class="w-full rounded-lg border-0 bg-slate-100 py-2 pl-9 pr-12 text-sm text-ink placeholder:text-ink-subtle focus:bg-surface focus:ring-2 focus:ring-brand-500"
      :placeholder="$t('shell.search.placeholder')"
      :aria-label="$t('shell.search.placeholder')"
      role="combobox"
      :aria-expanded="open && !!q.trim()"
      data-testid="global-search"
      @focus="open = true"
      @input="open = true"
      @keydown="onKey"
    />
    <kbd class="pointer-events-none absolute right-2.5 top-1/2 hidden -translate-y-1/2 rounded border border-line bg-surface px-1.5 text-[10px] font-medium text-ink-subtle sm:block">Ctrl K</kbd>
    <div v-if="open && q.trim()" class="absolute left-0 right-0 z-30 mt-2 max-h-96 overflow-y-auto rounded-xl bg-surface p-2 shadow-lg ring-1 ring-slate-900/5" role="listbox">
      <template v-if="results.length">
        <p v-if="pages.length" class="px-2 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-wider text-ink-subtle">{{ $t('shell.search.pages') }}</p>
        <template v-for="(r, i) in results" :key="r.key">
          <p v-if="r.kind === 'article' && (i === 0 || results[i - 1]?.kind === 'page')" class="px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-ink-subtle">{{ $t('shell.search.articles') }}</p>
          <button
            type="button"
            role="option"
            :aria-selected="i === active"
            class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left text-sm"
            :class="i === active ? 'bg-brand-50 text-brand-700' : 'text-ink hover:bg-slate-50'"
            @mouseenter="active = i"
            @click="go(r.to)"
          >
            <AppIcon :name="r.icon" :size="16" class="shrink-0 text-ink-subtle" />
            <span class="truncate">{{ r.label }}</span>
          </button>
        </template>
      </template>
      <p v-else-if="!loading" class="px-2 py-3 text-sm text-ink-muted">{{ $t('shell.search.none') }}</p>
      <p v-else class="px-2 py-3 text-sm text-ink-muted">…</p>
    </div>
  </div>
</template>
