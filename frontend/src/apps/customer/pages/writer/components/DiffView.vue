<script setup lang="ts">
import type { DiffOp } from '@/shared/api/writer'

// Plain-text diff: ops are rendered as text nodes only, never as HTML.
defineProps<{ ops: DiffOp[] }>()
</script>

<template>
  <p class="whitespace-pre-wrap break-words text-sm leading-relaxed">
    <template v-for="(o, i) in ops" :key="i">
      <ins v-if="o.op === 'ins'" class="bg-success-50 text-success-600 no-underline">{{ o.text }}</ins>
      <del v-else-if="o.op === 'del'" class="bg-danger-50 text-danger-600">{{ o.text }}</del>
      <span v-else>{{ o.text }}</span>
    </template>
  </p>
</template>
