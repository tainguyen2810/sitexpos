<script setup lang="ts">
import SubNav from '@/shared/ui/SubNav.vue'
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
const items = ITEMS
</script>

<template>
  <SubNav
    :items="items.map((i) => ({ key: i.key, label: t(`billAdmin.nav.${i.key}`), to: i.to, testid: `billadmin-nav-${i.key}` }))"
    :active="active"
    :label="t('billAdmin.title')"
    :max="6"
  />
</template>
