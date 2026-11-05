<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useSessionStore } from '@/shared/stores/session'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import { initials } from '@/shared/utils/format'

const props = defineProps<{ currentId: string | null; currentName: string | null }>()
const session = useSessionStore()
const open = ref(false)
const root = ref<HTMLElement | null>(null)

// The list only contains workspaces the API says this user is a member of.
const workspaces = computed(() => session.user?.workspaces ?? [])
const label = computed(() => props.currentName ?? workspaces.value.find((w) => w.id === props.currentId)?.name ?? '—')

function onDocClick(e: MouseEvent) {
  if (root.value && !root.value.contains(e.target as Node)) open.value = false
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>

<template>
  <div ref="root" class="relative">
    <button
      type="button"
      class="flex w-full items-center gap-2 rounded-lg border border-line px-3 py-2 text-left text-sm hover:bg-surface-muted"
      :aria-expanded="open"
      :aria-label="$t('nav.switchWorkspace')"
      @click="open = !open"
    >
      <span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-brand-100 text-xs font-semibold text-brand-700">{{ initials(label) }}</span>
      <span class="min-w-0 flex-1 truncate font-medium">{{ label }}</span>
      <AppIcon name="chevronDown" :size="16" class="text-ink-subtle" />
    </button>
    <div v-if="open" class="absolute left-0 right-0 z-50 mt-2 rounded-xl border border-line bg-surface p-1 shadow-lg">
      <p class="px-3 py-1.5 text-xs font-medium uppercase tracking-wide text-ink-subtle">{{ $t('nav.switchWorkspace') }}</p>
      <RouterLink
        v-for="w in workspaces"
        :key="w.id"
        :to="{ name: 'dashboard', params: { workspaceId: w.id } }"
        class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-surface-muted"
        :class="{ 'bg-brand-50 text-brand-700': w.id === currentId }"
        @click="open = false"
      >
        <span class="min-w-0 flex-1 truncate">{{ w.name }}</span>
        <AppBadge v-if="w.is_demo">{{ $t('common.demo') }}</AppBadge>
        <AppBadge v-if="w.status !== 'active'" tone="warning">{{ $t(`statuses.${w.status}`) }}</AppBadge>
        <span class="text-xs text-ink-subtle">{{ $t(`roles.${w.role}`) }}</span>
        <AppIcon v-if="w.id === currentId" name="check" :size="16" />
      </RouterLink>
      <RouterLink :to="{ name: 'onboarding' }" class="mt-1 flex items-center gap-2 border-t border-line px-3 py-2 text-sm text-brand-600 hover:bg-surface-muted" @click="open = false">
        <AppIcon name="plus" :size="16" /> {{ $t('nav.createWorkspace') }}
      </RouterLink>
    </div>
  </div>
</template>
