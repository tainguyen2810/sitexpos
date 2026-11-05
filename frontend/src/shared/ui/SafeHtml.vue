<script setup lang="ts">
import { computed } from 'vue'
import DOMPurify from 'dompurify'

// The ONLY place allowed to render raw HTML (ESLint enforces vue/no-v-html elsewhere).
const props = defineProps<{ html: string; tag?: string }>()
const clean = computed(() => DOMPurify.sanitize(props.html, { USE_PROFILES: { svg: true, html: true } }))
</script>

<template>
  <!-- `tag` is always a native element name, never a component. -->
  <!-- eslint-disable-next-line vue/no-v-text-v-html-on-component -->
  <component :is="tag ?? 'div'" v-html="clean" />
</template>
