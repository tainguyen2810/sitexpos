<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { isValidScope, phase6AdminApi, type PublishingLimitRow } from '@/shared/api/phase6Admin'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useToast } from '@/shared/composables/useToast'
import { formatDateTime } from '@/shared/utils/format'
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
const overview = useAsync(() => phase6AdminApi.publishingOverview(7))
const limits = useAsync(() => phase6AdminApi.publishingLimits())
const form = reactive({ scope_key: 'global', max_publishes_per_day: '', max_bulk_items: '', max_schedule_days_ahead: '', auto_publish_allowed: false, publishing_enabled: true })
const saving = ref(false)
function edit(r: PublishingLimitRow) {
  Object.assign(form, { scope_key: r.scope_key, max_publishes_per_day: String(r.max_publishes_per_day ?? ''), max_bulk_items: String(r.max_bulk_items ?? ''), max_schedule_days_ahead: String(r.max_schedule_days_ahead ?? ''), auto_publish_allowed: r.auto_publish_allowed, publishing_enabled: r.publishing_enabled })
}
const n = (v: string | number) => (v === '' ? null : Number(v))
async function save() {
  saving.value = true
  try {
    await phase6AdminApi.updatePublishingLimits({ scope_key: form.scope_key, auto_publish_allowed: form.auto_publish_allowed, publishing_enabled: form.publishing_enabled, max_publishes_per_day: n(form.max_publishes_per_day), max_bulk_items: n(form.max_bulk_items), max_schedule_days_ahead: n(form.max_schedule_days_ahead) })
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
    <PageHeader :title="t('p6.admin.pub.title')" :description="t('p6.admin.pub.subtitle')" />
    <LoadingState v-if="overview.loading.value && !overview.data.value" />
    <ErrorState v-else-if="overview.error.value" :error="overview.error.value" @retry="overview.run" />
    <template v-else-if="overview.data.value">
      <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard :label="t('p6.admin.pub.published')" :value="overview.data.value.jobs.published" icon="send" />
        <StatCard :label="t('p6.admin.pub.stuck')" :value="overview.data.value.jobs.stuck" icon="alert" />
        <StatCard :label="t('p6.admin.pub.conflicts')" :value="overview.data.value.jobs.open_conflicts" icon="link" />
        <StatCard :label="t('p6.admin.pub.missed')" :value="overview.data.value.jobs.missed_schedules" icon="calendar" />
      </div>
      <div class="mb-6 grid gap-6 lg:grid-cols-2">
        <AppCard :title="t('p6.admin.pub.byStatus')">
          <ul class="flex flex-wrap gap-2 text-xs"><li v-for="(v, k) in overview.data.value.jobs.by_status" :key="k"><AppBadge tone="neutral">{{ t(`p6.pub.status.${k}`) }}: {{ v }}</AppBadge></li></ul>
          <h3 class="mb-1 mt-4 text-sm font-semibold">{{ t('p6.admin.pub.errors') }}</h3>
          <EmptyState v-if="!overview.data.value.errors.length" icon="check" :title="t('p6.admin.none')" />
          <ul v-else class="space-y-0.5 text-xs"><li v-for="e in overview.data.value.errors" :key="e.error_code">{{ e.error_code }} · {{ e.n }}</li></ul>
        </AppCard>
        <AppCard :title="t('p6.admin.pub.connectors')" :subtitle="t('p6.admin.pub.connectorsHint')">
          <p class="text-xs">{{ t('p6.admin.pub.pluginVersions') }}: {{ overview.data.value.connectors.plugin_versions.map((p) => `${p.plugin_version ?? '?'} (${p.n})`).join(', ') || '—' }}</p>
          <p class="text-xs">WordPress: {{ overview.data.value.connectors.wp_versions.map((p) => `${p.wp_version ?? '?'} (${p.n})`).join(', ') || '—' }}</p>
          <ul class="mt-2 space-y-0.5 text-xs text-danger-600"><li v-for="c in overview.data.value.connectors.connection_errors" :key="c.id">{{ c.url }} · {{ c.last_error_code }}</li></ul>
        </AppCard>
      </div>
      <AppCard :title="t('p6.admin.pub.failed')" :padded="false" class="mb-6">
        <EmptyState v-if="!overview.data.value.failed_jobs.length" icon="check" :title="t('p6.admin.none')" />
        <ul v-else class="divide-y divide-line text-xs">
          <li v-for="f in overview.data.value.failed_jobs" :key="f.id" class="px-5 py-2">{{ f.workspace_id }} · {{ f.mode }} · {{ f.attempts }} · <strong>{{ f.last_error_code }}</strong> {{ f.last_error_message }} · {{ formatDateTime(f.updated_at) }}</li>
        </ul>
      </AppCard>
    </template>
    <AppCard :title="t('p6.admin.limits')" :subtitle="t('p6.admin.pub.limitsHint')">
      <LoadingState v-if="limits.loading.value" />
      <ErrorState v-else-if="limits.error.value" :error="limits.error.value" @retry="limits.run" />
      <ul v-else class="mb-4 divide-y divide-line text-sm">
        <li v-for="r in limits.data.value?.data ?? []" :key="r.scope_key" class="flex flex-wrap items-center gap-2 py-2">
          <span class="font-mono text-xs">{{ r.scope_key }}</span>
          <AppBadge :tone="r.publishing_enabled ? 'success' : 'danger'">{{ r.publishing_enabled ? t('p6.pub.settings.enabled') : t('p6.pub.settings.disabled') }}</AppBadge>
          <AppBadge :tone="r.auto_publish_allowed ? 'warning' : 'neutral'">auto {{ r.auto_publish_allowed ? 'on' : 'off' }}</AppBadge>
          <AppButton size="sm" variant="ghost" class="ml-auto" @click="edit(r)">{{ t('common.edit') }}</AppButton>
        </li>
      </ul>
      <form class="grid gap-3 md:grid-cols-3" @submit.prevent="save">
        <AppInput v-model="form.scope_key" :label="t('p6.admin.scope')" :error="isValidScope(form.scope_key) ? undefined : t('p6.admin.scopeInvalid')" />
        <AppInput v-model="form.max_publishes_per_day" type="number" :label="t('p6.admin.pub.maxPerDay')" />
        <AppInput v-model="form.max_bulk_items" type="number" :label="t('p6.admin.pub.maxBulk')" />
        <AppInput v-model="form.max_schedule_days_ahead" type="number" :label="t('p6.admin.pub.maxDays')" />
        <Toggle v-model="form.publishing_enabled" :label="t('p6.admin.pub.killSwitch')" :description="t('p6.admin.pub.killSwitchHint')" />
        <Toggle v-model="form.auto_publish_allowed" :label="t('p6.admin.pub.autoAllowed')" />
        <div class="md:col-span-3 flex justify-end"><AppButton type="submit" :loading="saving" :disabled="!isValidScope(form.scope_key)">{{ t('common.save') }}</AppButton></div>
      </form>
    </AppCard>
  </div>
</template>
