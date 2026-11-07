<script setup lang="ts">
import SubNav from '@/shared/ui/SubNav.vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWorkspaceStore } from '../../stores/workspace'
import { BILLING_NAV } from './useBilling'

defineProps<{ active: string }>()
const { t } = useI18n()
const ws = useWorkspaceStore()
const items = computed(() => BILLING_NAV.filter((i) => !('manage' in i) || ws.can('billing.manage')))
</script>

<template>
  <SubNav
    :items="items.map((i) => ({ key: i.key, label: t(`bill.nav.${i.key}`), to: { name: i.route, params: { workspaceId: ws.current!.id } }, testid: `bill-nav-${i.key}` }))"
    :active="active"
    :label="t('bill.title')"
    :max="6"
  />
</template>
