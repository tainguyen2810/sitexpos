<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '@/shared/ui/AppIcon.vue'
import UserMenu from '@/shared/ui/UserMenu.vue'
import { useToast } from '@/shared/composables/useToast'
import DemoShellLayout, { type NavGroup } from '../ui/DemoShellLayout.vue'
import DemoPanel from '../ui/DemoPanel.vue'
import NotificationsMenu from '../ui/NotificationsMenu.vue'
import StatusBadge from '../ui/StatusBadge.vue'
import { db } from '../store'
import { fmtRelative } from '../format'
import { useWorkspace } from './useWorkspace'

const router = useRouter()
const toast = useToast()
const { ws, base, mine } = useWorkspace()

const groups = computed<NavGroup[]>(() => {
  const b = base.value
  const review = mine(db.articles).filter((a) => ['awaiting_approval', 'ready_for_review'].includes(a.status)).length
  const failed = mine(db.publishJobs).filter((j) => j.status === 'failed').length
  return [
    { label: 'Tổng quan', items: [{ label: 'Dashboard', to: `${b}/dashboard`, icon: 'home' }] },
    {
      label: 'Website',
      items: [
        { label: 'Website WordPress', to: `${b}/sites`, icon: 'globe' },
        { label: 'Kết nối website', to: `${b}/connect`, icon: 'plug' },
      ],
    },
    {
      label: 'Chiến lược SEO',
      items: [
        { label: 'Chiến dịch SEO', to: `${b}/campaigns`, icon: 'target' },
        { label: 'AI Topic Discovery', to: `${b}/topics`, icon: 'sparkles' },
        { label: 'Keyword Explorer', to: `${b}/keywords`, icon: 'key' },
        { label: 'Topic Cluster', to: `${b}/clusters`, icon: 'layers' },
        { label: 'Lịch nội dung', to: `${b}/calendar`, icon: 'calendar' },
      ],
    },
    {
      label: 'Nội dung',
      items: [
        { label: 'AI Article Writer', to: `${b}/articles`, icon: 'pen', badge: review || undefined },
        { label: 'Fact-Check Report', to: `${b}/fact-checks`, icon: 'shieldCheck' },
        { label: 'Media Studio', to: `${b}/media`, icon: 'image' },
      ],
    },
    {
      label: 'Tối ưu & xuất bản',
      items: [
        { label: 'SEO Audit', to: `${b}/seo-audit`, icon: 'activity' },
        { label: 'Hàng đợi xuất bản', to: `${b}/publishing`, icon: 'send', badge: failed || undefined },
        { label: 'Search Console', to: `${b}/analytics`, icon: 'chart' },
      ],
    },
    {
      label: 'Tài khoản',
      items: [
        { label: 'Gói cước & Thanh toán', to: `${b}/billing`, icon: 'card' },
        { label: 'Usage & Credits', to: `${b}/usage`, icon: 'coins' },
        { label: 'Thành viên', to: `${b}/members`, icon: 'users' },
        { label: 'Cài đặt', to: `${b}/settings`, icon: 'settings' },
      ],
    },
  ]
})

const notifications = computed(() => {
  const list: { id: string; title: string; body: string; at: string; to?: string }[] = []
  mine(db.articles)
    .filter((a) => a.status === 'awaiting_approval')
    .slice(0, 2)
    .forEach((a) => list.push({ id: `n-${a.id}`, title: 'Bài viết chờ duyệt', body: a.title, at: fmtRelative(a.updatedAt), to: `${base.value}/articles/${a.id}` }))
  mine(db.publishJobs)
    .filter((j) => j.status === 'failed')
    .slice(0, 2)
    .forEach((j) => list.push({ id: `n-${j.id}`, title: 'Đăng bài thất bại', body: j.lastError ?? '', at: fmtRelative(j.createdAt), to: `${base.value}/publishing` }))
  mine(db.sites)
    .filter((s) => s.status === 'degraded' || s.status === 'disconnected')
    .forEach((s) => list.push({ id: `n-${s.id}`, title: 'Website cần kiểm tra', body: `${s.name}: kết nối không ổn định`, at: fmtRelative(s.lastSeenAt), to: `${base.value}/sites/${s.id}` }))
  return list
})

// Workspace switcher (each demo customer = one workspace)
const switcherOpen = ref(false)
const switcher = ref<HTMLElement | null>(null)
function onDoc(e: MouseEvent) {
  if (switcher.value && !switcher.value.contains(e.target as Node)) switcherOpen.value = false
}
onMounted(() => document.addEventListener('click', onDoc))
onBeforeUnmount(() => document.removeEventListener('click', onDoc))
function switchTo(id: string) {
  switcherOpen.value = false
  const current = router.currentRoute.value.path.split('/').slice(3, 4)[0] ?? 'dashboard'
  void router.push(`/w/${id}/${['sites', 'campaigns', 'articles', 'fact-checks'].includes(current) ? current : current || 'dashboard'}`)
}

// Global quick search
const q = ref('')
function search() {
  const term = q.value.trim()
  if (!term) return
  void router.push({ path: `${base.value}/articles`, query: { q: term } })
}
function logout() {
  toast.success('Chế độ demo: đăng xuất được mô phỏng, bạn vẫn ở lại trang demo.')
}
</script>

<template>
  <DemoShellLayout :groups="groups">
    <template #sidebar-top>
      <div ref="switcher" class="relative">
        <button type="button" class="flex w-full items-center gap-2 rounded-lg border border-line px-3 py-2 text-left hover:bg-surface-muted" :aria-expanded="switcherOpen" @click="switcherOpen = !switcherOpen">
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-amber-100 text-xs font-bold text-amber-800">{{ ws.name.slice(0, 2).toUpperCase() }}</span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium">{{ ws.name }}</span>
            <span class="block truncate text-xs text-ink-muted">Gói {{ db.plans.find((p) => p.code === ws.planCode)?.name }}</span>
          </span>
          <AppIcon name="chevronDown" :size="16" class="text-ink-subtle" />
        </button>
        <div v-if="switcherOpen" class="absolute inset-x-0 z-50 mt-1 rounded-xl border border-line bg-surface p-1 shadow-lg">
          <p class="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-ink-subtle">Khách hàng demo</p>
          <button v-for="w in db.workspaces" :key="w.id" type="button" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-muted" @click="switchTo(w.id)">
            <span class="min-w-0 flex-1 truncate">{{ w.name }}</span>
            <StatusBadge v-if="w.status !== 'active'" group="workspace" :value="w.status" />
            <AppIcon v-if="w.id === ws.id" name="check" :size="16" class="text-brand-600" />
          </button>
        </div>
      </div>
    </template>
    <template #topbar>
      <form class="relative hidden max-w-md md:block" role="search" @submit.prevent="search">
        <AppIcon name="search" :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-subtle" />
        <input v-model="q" type="search" placeholder="Tìm bài viết… (Enter)" aria-label="Tìm nhanh bài viết" class="w-full rounded-lg border border-line bg-surface-muted py-2 pl-9 pr-3 text-sm focus:border-brand-500 focus:bg-surface focus:outline-none focus:ring-2 focus:ring-brand-100" />
      </form>
    </template>
    <template #topbar-right>
      <RouterLink :to="`${base}/usage`" class="hidden items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-ink-muted hover:bg-surface-muted sm:flex" title="Credit còn lại (demo)">
        <AppIcon name="coins" :size="16" class="text-warning-600" /><span class="font-semibold tabular-nums text-ink">{{ ws.credits.toLocaleString('vi-VN') }}</span> credit
      </RouterLink>
      <DemoPanel app="customer" />
      <NotificationsMenu :items="notifications" />
      <UserMenu :name="ws.ownerName" :email="ws.ownerEmail" @logout="logout" />
    </template>
    <RouterView :key="ws.id" />
  </DemoShellLayout>
</template>
