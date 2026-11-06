<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWorkspaceStore } from '../../stores/workspace'
import { BILLING_NAV } from './useBilling'

const props = defineProps<{ active: string }>()
const { t } = useI18n()
const ws = useWorkspaceStore()
const items = computed(() => BILLING_NAV.filter((i) => !('manage' in i) || ws.can('billing.manage')))
</script>

<template>
  <nav class="-mt-2 mb-6 flex gap-1 overflow-x-auto border-b border-line" :aria-label="t('bill.title')">
    <RouterLink
      v-for="i in items"
      :key="i.key"
      :to="{ name: i.route, params: { workspaceId: ws.current!.id } }"
      class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
      :class="props.active === i.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:text-ink'"
      :data-testid="`bill-nav-${i.key}`"
    >
      {{ t(`bill.nav.${i.key}`) }}
    </RouterLink>
  </nav>
</template>
