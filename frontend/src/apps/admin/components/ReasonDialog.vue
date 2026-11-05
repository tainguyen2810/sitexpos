<script setup lang="ts">
import { ref, watch } from 'vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppButton from '@/shared/ui/AppButton.vue'

const props = defineProps<{ open: boolean; title: string; help?: string; confirmLabel: string; danger?: boolean; loading?: boolean; error?: string | null }>()
const emit = defineEmits<{ close: []; confirm: [reason: string] }>()
const reason = ref('')
watch(
  () => props.open,
  (o) => {
    if (o) reason.value = ''
  },
)
</script>

<template>
  <AppModal :open="open" :title="title" width="sm" @close="emit('close')">
    <form id="reason-form" class="space-y-3" @submit.prevent="emit('confirm', reason.trim())">
      <p v-if="help" class="text-sm text-ink-muted">{{ help }}</p>
      <p v-if="error" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ error }}</p>
      <label class="block text-sm font-medium" for="reason-input">{{ $t('admin.reasonRequired') }}</label>
      <textarea id="reason-input" v-model="reason" rows="3" maxlength="500" required class="block w-full rounded-lg border border-line px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100" />
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="emit('close')">{{ $t('common.cancel') }}</AppButton>
      <AppButton type="submit" form="reason-form" :variant="danger ? 'danger' : 'primary'" :loading="loading" :disabled="reason.trim().length < 5">{{ confirmLabel }}</AppButton>
    </template>
  </AppModal>
</template>
