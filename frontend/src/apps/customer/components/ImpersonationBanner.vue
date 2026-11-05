<script setup lang="ts">
import { ref } from 'vue'
import { adminApi } from '@/shared/api/admin'
import type { Me } from '@/shared/api/types'
import { useSessionStore } from '@/shared/stores/session'
import { formatDateTime } from '@/shared/utils/format'
import AppButton from '@/shared/ui/AppButton.vue'

defineProps<{ impersonation: NonNullable<Me['impersonation']> }>()
const session = useSessionStore()
const ending = ref(false)

async function end() {
  ending.value = true
  try {
    await adminApi.endImpersonation()
  } finally {
    ending.value = false
    await session.fetchMe()
    // Impersonation sessions are opened in a new tab from the admin app.
    if (window.opener) window.close()
    else window.location.assign('/')
  }
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-3 bg-warning-600 px-4 py-2 text-sm text-white" role="status">
    <span class="flex-1">{{ $t('impersonation.banner', { name: impersonation.workspace_name ?? '', time: formatDateTime(impersonation.expires_at) }) }}</span>
    <AppButton size="sm" variant="secondary" :loading="ending" @click="end">{{ $t('impersonation.end') }}</AppButton>
  </div>
</template>
