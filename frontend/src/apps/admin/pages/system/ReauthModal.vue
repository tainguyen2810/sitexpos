<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'

/** Asks for the staff password again before a sensitive action. The password is kept only in this component's memory. */
const props = defineProps<{ open: boolean; title: string; body: string; loading?: boolean; error?: string }>()
const emit = defineEmits<{ close: []; submit: [password: string] }>()
const { t } = useI18n()
const password = ref('')
watch(
  () => props.open,
  () => (password.value = ''),
)
function submit() {
  if (password.value) emit('submit', password.value)
}
</script>

<template>
  <AppModal :open="open" :title="title" width="sm" @close="emit('close')">
    <form id="reauth-form" class="space-y-3" @submit.prevent="submit">
      <p class="text-sm text-ink-muted">{{ body }}</p>
      <slot />
      <AppInput v-model="password" type="password" autocomplete="current-password" :label="t('sys.reauth.password')" :error="error" required data-testid="reauth-password" />
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</AppButton>
      <AppButton type="submit" form="reauth-form" :loading="loading" :disabled="!password" data-testid="reauth-submit">{{ t('sys.reauth.submit') }}</AppButton>
    </template>
  </AppModal>
</template>
