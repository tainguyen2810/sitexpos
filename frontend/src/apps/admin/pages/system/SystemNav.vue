<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/shared/stores/session'
import { SYSTEM_NAV } from './useSystem'

defineProps<{ active: string }>()
const { t } = useI18n()
const session = useSessionStore()
const items = computed(() => SYSTEM_NAV.filter((i) => session.user?.platform_permissions.includes(i.permission)))
</script>

<template>
  <nav class="-mt-2 mb-6 flex gap-1 overflow-x-auto border-b border-line" :aria-label="t('sys.title')">
    <RouterLink
      v-for="i in items"
      :key="i.key"
      :to="i.to"
      class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
      :class="active === i.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:text-ink'"
      :data-testid="`sys-nav-${i.key}`"
    >
      {{ t(`sys.nav.${i.key}`) }}
    </RouterLink>
  </nav>
</template>
