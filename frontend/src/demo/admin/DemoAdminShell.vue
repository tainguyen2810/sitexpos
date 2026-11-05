<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '@/shared/ui/AppIcon.vue'
import UserMenu from '@/shared/ui/UserMenu.vue'
import { useToast } from '@/shared/composables/useToast'
import DemoShellLayout, { type NavGroup } from '../ui/DemoShellLayout.vue'
import DemoPanel from '../ui/DemoPanel.vue'
import NotificationsMenu from '../ui/NotificationsMenu.vue'
import { db } from '../store'
import { fmtRelative } from '../format'
import { STAFF } from './useAdmin'

const router = useRouter()
const toast = useToast()

const groups = computed<NavGroup[]>(() => {
  const failed = db.failedJobs.length
  const openTickets = db.tickets.filter((t) => t.status === 'open').length
  return [
    { label: 'Tổng quan', items: [{ label: 'Tổng quan hệ thống', to: '/overview', icon: 'home' }] },
    {
      label: 'Khách hàng',
      items: [
        { label: 'Khách hàng', to: '/customers', icon: 'building' },
        { label: 'Website', to: '/websites', icon: 'globe' },
      ],
    },
    {
      label: 'Doanh thu',
      items: [
        { label: 'Gói cước', to: '/plans', icon: 'layers' },
        { label: 'Thanh toán & giao dịch', to: '/payments', icon: 'receipt' },
      ],
    },
    {
      label: 'AI',
      items: [
        { label: 'AI Providers & Models', to: '/ai-providers', icon: 'cpu' },
        { label: 'AI Usage & Costs', to: '/ai-usage', icon: 'chart' },
      ],
    },
    {
      label: 'Vận hành',
      items: [
        { label: 'Queue Monitor', to: '/queues', icon: 'server' },
        { label: 'Failed Jobs', to: '/failed-jobs', icon: 'bug', badge: failed || undefined },
        { label: 'Audit Logs', to: '/audit-logs', icon: 'list' },
      ],
    },
    {
      label: 'Hệ thống',
      items: [
        { label: 'Cài đặt hệ thống', to: '/settings', icon: 'settings' },
        { label: 'Công cụ hỗ trợ', to: '/support', icon: 'lifebuoy', badge: openTickets || undefined },
      ],
    },
  ]
})

const notifications = computed(() => {
  const list: { id: string; title: string; body: string; at: string; to?: string }[] = []
  db.aiProviders.filter((p) => p.status !== 'healthy').forEach((p) => list.push({ id: `p-${p.code}`, title: 'Nhà cung cấp AI bất thường', body: `${p.name}: tỷ lệ lỗi ${p.errorRate.toFixed(1)}%`, at: 'vừa xong', to: '/ai-providers' }))
  db.failedJobs.slice(0, 2).forEach((j) => list.push({ id: `f-${j.id}`, title: 'Job thất bại', body: `${j.jobClass}: ${j.exception.slice(0, 60)}`, at: fmtRelative(j.failedAt), to: '/failed-jobs' }))
  db.tickets.filter((t) => t.priority === 'urgent' && t.status !== 'resolved').forEach((t) => list.push({ id: `t-${t.id}`, title: 'Ticket khẩn cấp', body: t.subject, at: fmtRelative(t.createdAt), to: '/support' }))
  return list
})

const q = ref('')
function search() {
  const term = q.value.trim()
  if (term) void router.push({ path: '/customers', query: { q: term } })
}
</script>

<template>
  <DemoShellLayout :groups="groups" brand-suffix="Admin">
    <template #topbar>
      <form class="relative hidden max-w-md md:block" role="search" @submit.prevent="search">
        <AppIcon name="search" :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-subtle" />
        <input v-model="q" type="search" placeholder="Tìm khách hàng… (Enter)" aria-label="Tìm khách hàng" class="w-full rounded-lg border border-line bg-surface-muted py-2 pl-9 pr-3 text-sm focus:border-brand-500 focus:bg-surface focus:outline-none focus:ring-2 focus:ring-brand-100" />
      </form>
    </template>
    <template #topbar-right>
      <span v-if="db.systemSettings.maintenanceMode" class="hidden rounded-full bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-600 sm:inline">Đang bảo trì</span>
      <DemoPanel app="admin" />
      <NotificationsMenu :items="notifications" />
      <UserMenu :name="STAFF.name" :email="STAFF.email" @logout="toast.success('Chế độ demo: đăng xuất được mô phỏng.')" />
    </template>
    <RouterView />
  </DemoShellLayout>
</template>
