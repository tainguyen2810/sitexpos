<script setup lang="ts">
import SubNav from '@/shared/ui/SubNav.vue'
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
  <SubNav
    :items="items.map((i) => ({ key: i.key, label: t(`sys.nav.${i.key}`), to: i.to, testid: `sys-nav-${i.key}` }))"
    :active="active"
    :label="t('sys.title')"
    :max="6"
  />
</template>
