<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { siteApi, siteStatusTone, type Site } from '@/shared/api/sites'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import PairingCodePanel from '../components/PairingCodePanel.vue'
import { useWorkspaceStore } from '../stores/workspace'

const { t } = useI18n()
const router = useRouter()
const ws = useWorkspaceStore()
const id = ws.current!.id
const canManage = computed(() => ws.can('sites.manage') && !ws.current?.impersonating)

const sites = useAsync(() => siteApi.list(id))

// ---- add website ----
const addOpen = ref(false)
const draft = ref({ name: '', url: '', content_language: 'vi' })
const addForm = useApiForm()
const { errors: addErrors, formError: addError, submitting: adding } = addForm
const created = ref<{ site: Site; code: string; expiresAt: string } | null>(null)
const languageOptions = computed(() => [
  { value: 'vi', label: t('sites.languages.vi') },
  { value: 'en', label: t('sites.languages.en') },
])

function openAdd() {
  draft.value = { name: '', url: '', content_language: ws.current?.locale === 'en' ? 'en' : 'vi' }
  created.value = null
  addForm.reset()
  addOpen.value = true
}
async function submitAdd() {
  const res = await addForm.submit(() => siteApi.create(id, draft.value))
  if (!res) return
  created.value = { site: res.data, code: res.meta.pairing_code, expiresAt: res.meta.pairing_expires_at }
  await sites.run()
}
function closeAdd() {
  addOpen.value = false
  if (created.value) void router.push({ name: 'site', params: { workspaceId: id, siteId: created.value.site.id } })
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">{{ $t('sites.title') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ $t('sites.subtitle') }}</p>
      </div>
      <AppButton v-if="canManage" data-testid="add-site" @click="openAdd">{{ $t('sites.add') }}</AppButton>
    </div>

    <AppCard :padded="false">
      <LoadingState v-if="sites.loading.value" class="p-5" />
      <ErrorState v-else-if="sites.error.value" :error="sites.error.value" class="p-5" @retry="sites.run" />
      <EmptyState v-else-if="!sites.data.value?.length" icon="globe" :title="$t('sites.emptyTitle')" :description="$t('sites.emptyBody')" />
      <ul v-else class="divide-y divide-line">
        <li v-for="s in sites.data.value" :key="s.id">
          <RouterLink :to="{ name: 'site', params: { workspaceId: id, siteId: s.id } }" class="flex flex-wrap items-center gap-3 px-5 py-4 hover:bg-surface-muted">
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-ink">{{ s.name }}</p>
              <p class="truncate text-xs text-ink-muted">{{ s.url }}</p>
            </div>
            <div class="hidden text-right text-xs text-ink-muted sm:block">
              <p v-if="s.versions.wordpress">WordPress {{ s.versions.wordpress }}</p>
              <p>{{ $t('sites.lastHealth') }}: {{ formatDateTime(s.last_health_at) }}</p>
            </div>
            <AppBadge v-if="s.issues.length" tone="warning">{{ $t('sites.issuesCount', { n: s.issues.length }) }}</AppBadge>
            <AppBadge :tone="siteStatusTone(s.status)">{{ $t(`sites.status.${s.status}`) }}</AppBadge>
          </RouterLink>
        </li>
      </ul>
    </AppCard>

    <AppModal :open="addOpen" :title="created ? $t('sites.pairing.title') : $t('sites.addTitle')" @close="closeAdd">
      <PairingCodePanel v-if="created" :code="created.code" :expires-at="created.expiresAt" :site-url="created.site.url" />
      <form v-else id="add-site-form" class="space-y-4" novalidate @submit.prevent="submitAdd">
        <p v-if="addError" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ addError }}</p>
        <AppInput v-model="draft.name" :label="$t('sites.name')" :placeholder="$t('sites.namePlaceholder')" required :error="addErrors.name" />
        <AppInput v-model="draft.url" :label="$t('sites.url')" type="url" placeholder="https://example.com" required :hint="$t('sites.urlHint')" :error="addErrors.url" />
        <AppSelect v-model="draft.content_language" :label="$t('sites.contentLanguage')" :options="languageOptions" :error="addErrors.content_language" />
        <p class="text-xs text-ink-muted">{{ $t('sites.pluginRequired') }}</p>
      </form>
      <template #footer>
        <template v-if="created">
          <AppButton @click="closeAdd">{{ $t('sites.pairing.done') }}</AppButton>
        </template>
        <template v-else>
          <AppButton variant="secondary" @click="addOpen = false">{{ $t('common.cancel') }}</AppButton>
          <AppButton type="submit" form="add-site-form" :loading="adding">{{ $t('sites.addSubmit') }}</AppButton>
        </template>
      </template>
    </AppModal>
  </div>
</template>
