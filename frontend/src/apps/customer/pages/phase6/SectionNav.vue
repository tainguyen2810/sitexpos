<script setup lang="ts">
import SubNav from '@/shared/ui/SubNav.vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWorkspaceStore } from '../../stores/workspace'
import { SECTION_NAV, sectionLocaleNs, type Phase6Section } from './sectionNav'

const props = defineProps<{ section: Phase6Section; active: string }>()
const { t } = useI18n()
const ws = useWorkspaceStore()
const items = computed(() => SECTION_NAV[props.section])
const ns = computed(() => sectionLocaleNs(props.section))
</script>

<template>
  <SubNav
    :items="items.map((i) => ({ key: i.key, label: t(`p6.${ns}.nav.${i.key}`), to: { name: i.route, params: { workspaceId: ws.current!.id } }, testid: `p6-nav-${i.key}` }))"
    :active="active"
    :label="t(`p6.${ns}.title`)"
    :max="6"
  />
</template>
