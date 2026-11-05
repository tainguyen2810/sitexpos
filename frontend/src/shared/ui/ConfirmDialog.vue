<script setup lang="ts">
import AppButton from './AppButton.vue'
import AppModal from './AppModal.vue'

defineProps<{ open: boolean; title: string; message: string; confirmLabel?: string; danger?: boolean; loading?: boolean }>()
const emit = defineEmits<{ close: []; confirm: [] }>()
</script>

<template>
  <AppModal :open="open" :title="title" width="sm" @close="emit('close')">
    <p class="text-sm text-ink-muted">{{ message }}</p>
    <slot />
    <template #footer>
      <AppButton variant="secondary" @click="emit('close')">{{ $t('common.cancel') }}</AppButton>
      <AppButton :variant="danger ? 'danger' : 'primary'" :loading="loading" @click="emit('confirm')">{{ confirmLabel ?? $t('common.confirm') }}</AppButton>
    </template>
  </AppModal>
</template>
