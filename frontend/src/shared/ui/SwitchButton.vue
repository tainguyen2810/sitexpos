<script setup lang="ts">
/** Bare on/off switch (no label row). Emits `toggle`; the parent decides what happens. */
defineProps<{ checked: boolean; label: string; disabled?: boolean; loading?: boolean; size?: 'sm' | 'md' }>()
defineEmits<{ toggle: [] }>()
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="checked"
    :aria-label="label"
    :title="label"
    :disabled="disabled || loading"
    class="relative inline-flex shrink-0 rounded-full ring-1 ring-inset ring-slate-900/5 transition-colors duration-200 disabled:cursor-not-allowed disabled:opacity-50"
    :class="[size === 'sm' ? 'h-5 w-9' : 'h-6 w-11', checked ? 'bg-brand-600' : 'bg-slate-200']"
    @click="$emit('toggle')"
  >
    <span
      class="absolute top-0.5 rounded-full bg-white shadow transition-all"
      :class="size === 'sm' ? ['size-4', checked ? 'left-[18px]' : 'left-0.5'] : ['size-5', checked ? 'left-[22px]' : 'left-0.5']"
    />
  </button>
</template>
