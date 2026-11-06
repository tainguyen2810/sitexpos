<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{ active: string }>()
const { t } = useI18n()
const ITEMS = [
  { key: 'plans', to: '/billing/plans' },
  { key: 'subscriptions', to: '/billing/subscriptions' },
  { key: 'orders', to: '/billing/orders' },
  { key: 'reconciliation', to: '/billing/reconciliation' },
  { key: 'refunds', to: '/billing/refunds' },
  { key: 'credits', to: '/billing/credits' },
  { key: 'reports', to: '/billing/reports' },
  { key: 'settings', to: '/billing/settings' },
  { key: 'audit', to: '/audit?action=billing.' },
] as const
</script>

<template>
  <nav class="-mt-2 mb-6 flex gap-1 overflow-x-auto border-b border-line" :aria-label="t('billAdmin.title')">
    <RouterLink
      v-for="i in ITEMS"
      :key="i.key"
      :to="i.to"
      class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
      :class="active === i.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:text-ink'"
      :data-testid="`billadmin-nav-${i.key}`"
    >
      {{ t(`billAdmin.nav.${i.key}`) }}
    </RouterLink>
  </nav>
</template>
