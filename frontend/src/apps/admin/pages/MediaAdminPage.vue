<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatBytes, microUsd } from '@/shared/api/media'
import { isValidScope, phase6AdminApi, type MediaLimitRow } from '@/shared/api/phase6Admin'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useToast } from '@/shared/composables/useToast'
import { useSessionStore } from '@/shared/stores/session'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import StatCard from '@/shared/ui/StatCard.vue'
import Toggle from '@/shared/ui/Toggle.vue'

const { t } = useI18n()
const toast = useToast()
const session = useSessionStore()
const canManage = computed(() => session.user?.platform_permissions.includes('platform.ai.manage') ?? false)
const overview = useAsync(() => phase6AdminApi.mediaOverview(30))
const limits = useAsync(() => (canManage.value ? phase6AdminApi.mediaLimits() : Promise.resolve([] as MediaLimitRow[])))
const form = reactive({ scope_key: 'global', max_storage_bytes: '', max_uploads_per_day: '', max_ai_images_per_day: '', max_ai_cost_per_job_micro_usd: '', ai_images_enabled: true })
const saving = ref(false)
function edit(r: MediaLimitRow) {
  Object.assign(form, { scope_key: r.scope_key, ai_images_enabled: r.ai_images_enabled, max_storage_bytes: String(r.max_storage_bytes ?? ''), max_uploads_per_day: String(r.max_uploads_per_day ?? ''), max_ai_images_per_day: String(r.max_ai_images_per_day ?? ''), max_ai_cost_per_job_micro_usd: String(r.max_ai_cost_per_job_micro_usd ?? '') })
}
const n = (v: string | number) => (v === '' ? null : Number(v))
async function save() {
  saving.value = true
  try {
    await phase6AdminApi.updateMediaLimits({ scope_key: form.scope_key, ai_images_enabled: form.ai_images_enabled, max_storage_bytes: n(form.max_storage_bytes), max_uploads_per_day: n(form.max_uploads_per_day), max_ai_images_per_day: n(form.max_ai_images_per_day), max_ai_cost_per_job_micro_usd: n(form.max_ai_cost_per_job_micro_usd) })
    toast.success(t('common.saved'))
    await limits.run()
  } catch (e) {
    toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('p6.admin.media.title')" :description="t('p6.admin.media.subtitle')" />
    <LoadingState v-if="overview.loading.value && !overview.data.value" />
    <ErrorState v-else-if="overview.error.value" :error="overview.error.value" @retry="overview.run" />
    <template v-else-if="overview.data.value">
      <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard :label="t('p6.admin.media.jobs')" :value="overview.data.value.generation.jobs" icon="sparkles" />
        <StatCard :label="t('p6.admin.media.images')" :value="overview.data.value.generation.images" icon="image" />
        <StatCard :label="t('p6.admin.media.cost')" :value="microUsd(overview.data.value.generation.cost_micro_usd)" icon="coins" />
        <StatCard :label="t('p6.admin.media.storage')" :value="formatBytes(overview.data.value.storage.bytes)" icon="server" :hint="`${overview.data.value.storage.assets} assets`" />
      </div>
      <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <AppCard :title="t('p6.admin.media.route')" :subtitle="t('p6.admin.media.routeHint')">
          <AppBadge :tone="overview.data.value.route.enabled ? 'success' : 'danger'">{{ overview.data.value.route.enabled ? t('p6.pub.settings.enabled') : t('p6.pub.settings.disabled') }}</AppBadge>
          <EmptyState v-if="!overview.data.value.route.models.length" icon="cpu" :title="t('p6.admin.media.noModels')" />
          <ul v-else class="mt-2 space-y-1 text-sm">
            <li v-for="m in overview.data.value.route.models" :key="m.model_key">{{ m.provider }} / {{ m.model_key }}
              <AppBadge :tone="m.image_generation ? 'success' : 'danger'">{{ m.image_generation ? t('p6.admin.media.canDraw') : t('p6.admin.media.cannotDraw') }}</AppBadge>
            </li>
          </ul>
        </AppCard>
        <AppCard :title="t('p6.admin.media.abuse')">
          <EmptyState v-if="!overview.data.value.abuse.rejected_uploads.length && !overview.data.value.abuse.heavy_generators.length" icon="shield" :title="t('p6.admin.none')" />
          <ul class="space-y-1 text-xs">
            <li v-for="r in overview.data.value.abuse.rejected_uploads" :key="`${r.workspace_id}-${r.error_code}`">{{ r.workspace_id }} · {{ r.error_code }} · {{ r.n }}</li>
            <li v-for="g in overview.data.value.abuse.heavy_generators" :key="g.workspace_id">{{ g.workspace_id }} · {{ g.images }} img · {{ microUsd(g.cost) }}</li>
          </ul>
        </AppCard>
      </div>
    </template>
    <AppCard v-if="canManage" :title="t('p6.admin.limits')" :subtitle="t('p6.admin.limitsHint')">
      <LoadingState v-if="limits.loading.value" />
      <ErrorState v-else-if="limits.error.value" :error="limits.error.value" @retry="limits.run" />
      <ul v-else class="mb-4 divide-y divide-line text-sm">
        <li v-for="r in limits.data.value ?? []" :key="r.scope_key" class="flex flex-wrap items-center gap-2 py-2">
          <span class="font-mono text-xs">{{ r.scope_key }}</span>
          <AppBadge :tone="r.ai_images_enabled ? 'success' : 'danger'">AI {{ r.ai_images_enabled ? 'on' : 'off' }}</AppBadge>
          <span class="text-xs text-ink-muted">{{ formatBytes(r.max_storage_bytes) }} · {{ r.max_ai_images_per_day ?? '—' }}/day</span>
          <AppButton size="sm" variant="ghost" class="ml-auto" @click="edit(r)">{{ t('common.edit') }}</AppButton>
        </li>
      </ul>
      <form class="grid grid-cols-1 gap-3 md:grid-cols-3" @submit.prevent="save">
        <AppInput v-model="form.scope_key" :label="t('p6.admin.scope')" :error="isValidScope(form.scope_key) ? undefined : t('p6.admin.scopeInvalid')" />
        <AppInput v-model="form.max_storage_bytes" type="number" :label="t('p6.admin.media.maxStorage')" />
        <AppInput v-model="form.max_uploads_per_day" type="number" :label="t('p6.admin.media.maxUploads')" />
        <AppInput v-model="form.max_ai_images_per_day" type="number" :label="t('p6.admin.media.maxAi')" />
        <AppInput v-model="form.max_ai_cost_per_job_micro_usd" type="number" :label="t('p6.admin.media.maxCost')" />
        <Toggle v-model="form.ai_images_enabled" :label="t('p6.admin.media.aiEnabled')" />
        <div class="md:col-span-3 flex justify-end"><AppButton type="submit" :loading="saving" :disabled="!isValidScope(form.scope_key)">{{ t('common.save') }}</AppButton></div>
      </form>
    </AppCard>
  </div>
</template>
