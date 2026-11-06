<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppButton from '@/shared/ui/AppButton.vue'
import { pluginDownloadUrl } from '@/shared/api/sites'

/**
 * Shows a one-time pairing code with the steps to enter it in the WordPress
 * plugin. The code is only ever displayed here, never stored by the UI.
 */
const props = defineProps<{ code: string; expiresAt: string; siteUrl?: string }>()
const { t } = useI18n()

const now = ref(Date.now())
const timer = window.setInterval(() => (now.value = Date.now()), 1000)
onBeforeUnmount(() => window.clearInterval(timer))

const remaining = computed(() => Math.max(0, Math.floor((new Date(props.expiresAt).getTime() - now.value) / 1000)))
const expired = computed(() => remaining.value === 0)
const countdown = computed(() => `${Math.floor(remaining.value / 60)}:${String(remaining.value % 60).padStart(2, '0')}`)

const copied = ref(false)
async function copy() {
  try {
    await navigator.clipboard.writeText(props.code)
    copied.value = true
    window.setTimeout(() => (copied.value = false), 2000)
  } catch {
    copied.value = false
  }
}
const pluginAdmin = computed(() => (props.siteUrl ? `${props.siteUrl.replace(/\/+$/, '')}/wp-admin/admin.php?page=sitex-post` : null))
</script>

<template>
  <div class="space-y-4" data-testid="pairing-panel">
    <div class="rounded-xl border border-line bg-surface-muted p-4 text-center">
      <p class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('sites.pairing.code') }}</p>
      <p class="mt-1 font-mono text-3xl font-bold tracking-[0.2em]" :class="expired ? 'text-ink-subtle line-through' : 'text-ink'" data-testid="pairing-code">{{ code }}</p>
      <p class="mt-2 text-sm" :class="expired ? 'text-danger-600' : 'text-ink-muted'">
        {{ expired ? t('sites.pairing.expired') : t('sites.pairing.expiresIn', { time: countdown }) }}
      </p>
      <AppButton v-if="!expired" class="mt-3" size="sm" variant="secondary" @click="copy">{{ copied ? t('sites.pairing.copied') : t('sites.pairing.copy') }}</AppButton>
    </div>
    <ol class="list-decimal space-y-2 pl-5 text-sm text-ink">
      <li>
        {{ t('sites.pairing.step1') }}
        <a :href="pluginDownloadUrl()" download class="text-brand-600 hover:underline" data-testid="plugin-download">{{ t('sites.pairing.download') }}</a>
      </li>
      <li>
        {{ t('sites.pairing.step2') }}
        <a v-if="pluginAdmin" :href="pluginAdmin" target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:underline">{{ t('sites.pairing.openPlugin') }}</a>
      </li>
      <li>{{ t('sites.pairing.step3') }}</li>
      <li>{{ t('sites.pairing.step4') }}</li>
    </ol>
    <p class="rounded-lg bg-brand-50 px-3 py-2 text-xs text-ink-muted">{{ t('sites.pairing.noPassword') }}</p>
  </div>
</template>
