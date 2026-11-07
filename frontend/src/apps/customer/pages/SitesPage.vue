<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { pluginDownloadUrl, siteApi, siteStatusTone, type Site } from '@/shared/api/sites'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { formatRelative } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
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
function hostOf(url: string): string {
  try {
    return new URL(url).host
  } catch {
    return url
  }
}

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
  <div>
    <PageHeader :title="$t('sites.title')" :description="$t('sites.subtitle')">
      <template #actions>
        <a
          :href="pluginDownloadUrl()"
          download
          :title="$t('sites.downloadPluginHint')"
          class="inline-flex items-center gap-2 rounded-lg bg-surface px-3.5 py-2 text-sm font-semibold text-ink shadow-xs ring-1 ring-inset ring-line-strong hover:bg-surface-muted"
          data-testid="download-plugin"
        ><AppIcon name="download" :size="16" />{{ $t('sites.downloadPlugin') }}</a>
        <AppButton v-if="canManage" data-testid="add-site" @click="openAdd"><AppIcon name="plus" :size="16" />{{ $t('sites.add') }}</AppButton>
      </template>
    </PageHeader>

    <AppCard :padded="false">
      <LoadingState v-if="sites.loading.value" class="p-5" />
      <ErrorState v-else-if="sites.error.value" :error="sites.error.value" class="p-5" @retry="sites.run" />
      <EmptyState v-else-if="!sites.data.value?.length" icon="globe" :title="$t('sites.emptyTitle')" :description="$t('sites.emptyBody')">
        <AppButton v-if="canManage" @click="openAdd"><AppIcon name="plus" :size="16" />{{ $t('sites.add') }}</AppButton>
      </EmptyState>
      <div v-else class="relative overflow-x-auto">
        <table class="data-table w-full min-w-[44rem] text-sm">
          <thead>
            <tr>
              <th>{{ $t('sites.title') }}</th>
              <th>{{ $t('sites.statusLabel') }}</th>
              <th>WordPress / Plugin</th>
              <th class="!text-right">{{ $t('sites.contentCount') }}</th>
              <th>{{ $t('sites.lastHealth') }}</th>
              <th><span class="sr-only">{{ $t('common.actions') }}</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in sites.data.value" :key="s.id" class="cursor-pointer" @click="router.push({ name: 'site', params: { workspaceId: id, siteId: s.id } })">
              <td>
                <div class="flex items-center gap-3">
                  <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-sm font-bold uppercase text-brand-700">{{ hostOf(s.url).slice(0, 1) }}</span>
                  <div class="min-w-0">
                    <RouterLink :to="{ name: 'site', params: { workspaceId: id, siteId: s.id } }" class="block truncate font-medium text-ink hover:text-brand-700" @click.stop>{{ s.name }}</RouterLink>
                    <p class="truncate text-xs text-ink-muted">{{ hostOf(s.url) }}</p>
                  </div>
                </div>
              </td>
              <td>
                <div class="flex flex-wrap items-center gap-1.5">
                  <AppBadge :tone="siteStatusTone(s.status)" dot>{{ $t(`sites.status.${s.status}`) }}</AppBadge>
                  <AppBadge v-if="s.issues.length" tone="warning">{{ $t('sites.issuesCount', { n: s.issues.length }) }}</AppBadge>
                </div>
              </td>
              <td class="whitespace-nowrap text-xs text-ink-muted">
                <span v-if="s.versions.wordpress">WP {{ s.versions.wordpress }}</span><span v-else>—</span>
                <span v-if="s.versions.plugin"> · v{{ s.versions.plugin }}</span>
              </td>
              <td class="text-right tabular-nums">{{ s.counts ? s.counts.content : '—' }}</td>
              <td class="whitespace-nowrap text-ink-muted">{{ formatRelative(s.last_health_at) }}</td>
              <td class="text-right"><AppIcon name="chevronRight" :size="16" class="inline text-ink-subtle" /></td>
            </tr>
          </tbody>
        </table>
      </div>
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
