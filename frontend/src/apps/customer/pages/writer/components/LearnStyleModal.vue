<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { writerApi, type Preset } from '@/shared/api/writer'
import { siteApi, waitForJob, type Site, type SiteContentItem } from '@/shared/api/sites'
import { ApiError } from '@/shared/api/client'
import AppButton from '@/shared/ui/AppButton.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import SearchInput from '@/shared/ui/SearchInput.vue'
import WTextarea from './WTextarea.vue'

const MIN = 2
const MAX = 20

const props = defineProps<{ open: boolean; workspaceId: string; preset: Preset | null; sites: Site[] }>()
const emit = defineEmits<{ close: []; done: [presetId: string] }>()
const { t } = useI18n()

const siteId = ref('')
const q = ref('')
const posts = ref<SiteContentItem[]>([])
const loadingPosts = ref(false)
const selected = ref<string[]>([])
const samples = reactive<{ title: string; text: string }[]>([])
const running = ref(false)
const message = ref<{ tone: 'info' | 'error'; text: string } | null>(null)

const siteOptions = computed(() => props.sites.map((s) => ({ value: s.id, label: s.name })))
const pasted = computed(() => samples.filter((s) => s.text.trim().length >= 300))
const total = computed(() => selected.value.length + pasted.value.length)
const canStart = computed(() => total.value >= MIN && total.value <= MAX && !running.value)

watch(
  () => props.open,
  (o) => {
    if (!o) return
    siteId.value = props.preset?.site_id ?? props.sites[0]?.id ?? ''
    selected.value = []
    samples.splice(0, samples.length)
    message.value = null
    void loadPosts()
  },
)

let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(() => void loadPosts(), 300)
})

async function loadPosts() {
  if (!siteId.value) {
    posts.value = []
    return
  }
  loadingPosts.value = true
  try {
    const r = await siteApi.content(props.workspaceId, siteId.value, { 'filter[post_type]': 'post', q: q.value.trim() || undefined })
    posts.value = r.data.filter((p) => p.status === 'publish' && p.url)
  } catch {
    posts.value = []
  } finally {
    loadingPosts.value = false
  }
}
function onSite(v: string) {
  siteId.value = v
  selected.value = []
  void loadPosts()
}
function toggle(id: string) {
  const i = selected.value.indexOf(id)
  if (i >= 0) selected.value.splice(i, 1)
  else if (total.value < MAX) selected.value.push(id)
}

async function start() {
  if (!props.preset || !canStart.value) return
  running.value = true
  message.value = { tone: 'info', text: t('writer.presets.learn.running') }
  try {
    const job = await writerApi.learnStyle(props.workspaceId, props.preset.id, {
      site_id: siteId.value || null,
      content_item_ids: selected.value,
      samples: pasted.value.map((s) => ({ title: s.title.trim() || null, text: s.text.trim() })),
    })
    const run = await waitForJob(() => siteApi.jobRun(props.workspaceId, job.id), { intervalMs: 2000, timeoutMs: 180000 })
    if (run.status === 'succeeded') {
      emit('done', props.preset.id)
    } else if (run.status === 'failed') {
      message.value = { tone: 'error', text: t('writer.presets.learn.failed', { msg: run.error?.message ?? run.error?.code ?? '' }) }
    } else {
      message.value = { tone: 'info', text: t('writer.presets.learn.stillRunning') }
    }
  } catch (e) {
    message.value = { tone: 'error', text: e instanceof ApiError ? e.message : t('writer.presets.learn.failed', { msg: '' }) }
  } finally {
    running.value = false
  }
}
</script>

<template>
  <AppModal :open="open" :title="t('writer.presets.learn.title')" width="xl" @close="emit('close')">
    <div class="space-y-4" data-testid="learn-style">
      <p class="text-sm text-ink-muted">{{ t('writer.presets.learn.intro') }}</p>
      <p v-if="message" :class="['rounded-lg px-3 py-2 text-sm', message.tone === 'error' ? 'bg-danger-50 text-danger-600' : 'bg-brand-50 text-brand-700']" role="status">
        {{ message.text }}
      </p>

      <section>
        <h3 class="mb-2 text-sm font-medium">{{ t('writer.presets.learn.fromSite') }}</h3>
        <div class="mb-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
          <AppSelect :model-value="siteId" :label="t('writer.presets.learn.site')" :options="siteOptions" @update:model-value="onSite" />
          <div class="flex items-end"><SearchInput v-model="q" /></div>
        </div>
        <LoadingState v-if="loadingPosts" />
        <p v-else-if="!posts.length" class="text-sm text-ink-muted">{{ t('writer.presets.learn.noPosts') }}</p>
        <ul v-else class="max-h-64 divide-y divide-line overflow-y-auto rounded-lg border border-line" data-testid="learn-posts">
          <li v-for="p in posts" :key="p.id">
            <label class="flex cursor-pointer items-start gap-2 px-3 py-2 text-sm hover:bg-surface-muted">
              <input type="checkbox" class="mt-1" :checked="selected.includes(p.id)" @change="toggle(p.id)" />
              <span class="min-w-0"><span class="block truncate">{{ p.title }}</span><span class="block truncate text-xs text-ink-muted">{{ p.url }}</span></span>
            </label>
          </li>
        </ul>
        <p class="mt-1 text-xs text-ink-muted">{{ t('writer.presets.learn.selected', { n: selected.length }) }}</p>
      </section>

      <section>
        <h3 class="mb-2 text-sm font-medium">{{ t('writer.presets.learn.paste') }}</h3>
        <div v-for="(s, i) in samples" :key="i" class="mb-3 space-y-2 rounded-lg border border-line p-3">
          <div class="flex gap-2">
            <AppInput v-model="s.title" class="flex-1" :placeholder="t('writer.presets.learn.pasteTitle')" />
            <AppButton size="sm" variant="ghost" :aria-label="t('common.delete')" @click="samples.splice(i, 1)">✕</AppButton>
          </div>
          <WTextarea v-model="s.text" :rows="5" :placeholder="t('writer.presets.learn.pasteText')" />
        </div>
        <AppButton v-if="samples.length < 10" size="sm" variant="ghost" @click="samples.push({ title: '', text: '' })">+ {{ t('writer.presets.learn.addSample') }}</AppButton>
      </section>
    </div>
    <template #footer>
      <span class="mr-auto text-xs text-ink-muted">{{ t('writer.presets.learn.total', { n: total, min: MIN, max: MAX }) }}</span>
      <AppButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</AppButton>
      <AppButton :disabled="!canStart" :loading="running" data-testid="learn-start" @click="start">{{ t('writer.presets.learn.start') }}</AppButton>
    </template>
  </AppModal>
</template>
