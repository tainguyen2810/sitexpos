<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'danger' | 'ghost'
    size?: 'sm' | 'md'
    type?: 'button' | 'submit'
    loading?: boolean
    disabled?: boolean
    block?: boolean
  }>(),
  { variant: 'primary', size: 'md', type: 'button', loading: false, disabled: false, block: false },
)

const classes = computed(() => [
  'inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-60',
  props.size === 'sm' ? 'px-2.5 py-1.5 text-xs' : 'px-3.5 py-2 text-sm',
  props.block ? 'w-full' : '',
  {
    primary: 'bg-brand-600 text-white shadow-xs hover:bg-brand-500 focus-visible:outline-brand-600',
    secondary: 'bg-surface text-ink shadow-xs ring-1 ring-inset ring-line-strong hover:bg-surface-muted',
    danger: 'bg-danger-600 text-white shadow-xs hover:bg-danger-500 focus-visible:outline-danger-600',
    ghost: 'text-ink-muted hover:bg-slate-100 hover:text-ink',
  }[props.variant],
])
</script>

<template>
  <button :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading">
    <svg v-if="loading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
      <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" />
    </svg>
    <slot />
  </button>
</template>
