<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { splitLines } from '@/shared/api/writer'
import AppButton from '@/shared/ui/AppButton.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import WTextarea from './WTextarea.vue'

/** Research stopped for lack of evidence: add URLs / web search, then research again. */
const props = defineProps<{ settings: Record<string, unknown>; loading?: boolean }>()
const emit = defineEmits<{ submit: [payload: { source_urls: string[]; allow_web_search: boolean }] }>()
const { t } = useI18n()

const urls = ref('')
const search = ref(true)
const error = ref('')
watch(() => props.settings, (s) => {
  urls.value = (Array.isArray(s.source_urls) ? s.source_urls : []).map((u) => (typeof u === 'string' ? u : String((u as { url?: string }).url ?? ''))).join('\n')
  search.value = s.allow_web_search !== false
}, { immediate: true })

function submit() {
  const list = splitLines(urls.value.replace(/\s+/g, '\n'))
  error.value = list.length > 20 ? t('writer.sources.tooMany') : ''
  if (!error.value) emit('submit', { source_urls: list, allow_web_search: search.value })
}
</script>

<template>
  <form class="mt-3 space-y-3 rounded-lg border border-line p-3" data-testid="add-sources" @submit.prevent="submit">
    <p class="text-sm font-semibold">{{ t('writer.sources.title') }}</p>
    <WTextarea v-model="urls" :rows="4" :label="t('writer.sources.urls')" :hint="t('writer.sources.urlsHint')" :error="error" placeholder="https://" />
    <Toggle v-model="search" :label="t('writer.sources.webSearch')" :description="t('writer.wizard.webSearchHint')" />
    <AppButton type="submit" size="sm" :loading="loading">{{ t('writer.sources.retry') }}</AppButton>
  </form>
</template>
