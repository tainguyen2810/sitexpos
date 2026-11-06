<script setup lang="ts">
import { reactive, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { publishingApi, type PolicyView, type SitePolicy } from '@/shared/api/publishing'
import { SCHEMA_TYPES, seoApi, type SchemaType, type SiteSeoConfig } from '@/shared/api/seo'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import SectionNav from './SectionNav.vue'
import { usePhase6Errors, usePhase6Perms, useSites } from './usePhase6'

const { t } = useI18n()
const { workspaceId, canManageSites } = usePhase6Perms()
const { toastError, toast } = usePhase6Errors()
const sites = useSites({ required: true })
const policy = shallowRef<PolicyView | null>(null)
const seo = shallowRef<SiteSeoConfig | null>(null)
const loading = ref(false)
const error = shallowRef<unknown>(null)
const saving = ref(false)
const savingSeo = ref(false)
const confirmAuto = ref(false)
type Num = 'max_posts_per_day' | 'max_posts_per_campaign_per_day' | 'min_minutes_between_posts' | 'window_start_hour' | 'window_end_hour' | 'max_schedule_days_ahead' | 'max_attempts' | 'missed_grace_minutes'
const NUMS: Num[] = ['max_posts_per_day', 'max_posts_per_campaign_per_day', 'min_minutes_between_posts', 'window_start_hour', 'window_end_hour', 'max_schedule_days_ahead', 'max_attempts', 'missed_grace_minutes']
const form = reactive({ default_mode: 'manual', require_seo_pass: true, require_media_ready: true, allow_update_published: true, window_days: [] as number[], nums: {} as Record<Num, string> })
const seoForm = reactive({ injection_enabled: false, enabled_types: [] as SchemaType[], organization_name: '', logo_url: '', breadcrumbs_supported: false, allow_canonical_override: false, allow_robots_override: false })

function fill(p: PolicyView) {
  policy.value = p
  Object.assign(form, { default_mode: p.default_mode, require_seo_pass: p.require_seo_pass, require_media_ready: p.require_media_ready, allow_update_published: p.allow_update_published, window_days: [...(p.window_days ?? [])] })
  form.nums = Object.fromEntries(NUMS.map((k) => [k, String(p[k])])) as Record<Num, string>
}
function fillSeo(c: SiteSeoConfig) {
  seo.value = c
  Object.assign(seoForm, { injection_enabled: c.injection_enabled, enabled_types: [...c.enabled_types], organization_name: c.organization_name ?? '', logo_url: c.logo_url ?? '', breadcrumbs_supported: c.breadcrumbs_supported, allow_canonical_override: c.allow_canonical_override, allow_robots_override: c.allow_robots_override })
}
async function load() {
  if (!sites.siteId.value) return
  loading.value = true
  error.value = null
  try {
    const [p, c] = await Promise.all([publishingApi.policy(workspaceId.value, sites.siteId.value), seoApi.siteConfig(workspaceId.value, sites.siteId.value)])
    fill(p)
    fillSeo(c)
  } catch (e) {
    error.value = e
  } finally {
    loading.value = false
  }
}
watch(() => sites.siteId.value, load, { immediate: true })

async function save(extra: Partial<SitePolicy> & { confirm_auto_publish?: boolean } = {}) {
  saving.value = true
  try {
    const nums = Object.fromEntries(NUMS.map((k) => [k, Number(form.nums[k])]))
    fill(await publishingApi.updatePolicy(workspaceId.value, sites.siteId.value, {
      default_mode: form.default_mode as 'manual' | 'wp_draft', require_seo_pass: form.require_seo_pass, require_media_ready: form.require_media_ready,
      allow_update_published: form.allow_update_published, window_days: form.window_days.length ? form.window_days : null, ...nums, ...extra,
    }))
    toast.success(t('common.saved'))
  } catch (e) {
    toastError(e)
  } finally {
    saving.value = false
  }
}
async function setAuto(on: boolean) {
  confirmAuto.value = false
  await save(on ? { auto_publish_enabled: true, confirm_auto_publish: true } : { auto_publish_enabled: false })
}
async function saveSeo() {
  savingSeo.value = true
  try {
    fillSeo(await seoApi.updateSiteConfig(workspaceId.value, sites.siteId.value, { ...seoForm, organization_name: seoForm.organization_name || null, logo_url: seoForm.logo_url || null }))
    toast.success(t('common.saved'))
  } catch (e) {
    toastError(e)
  } finally {
    savingSeo.value = false
  }
}
function toggleDay(d: number) {
  form.window_days = form.window_days.includes(d) ? form.window_days.filter((x) => x !== d) : [...form.window_days, d].sort()
}
function toggleType(type: SchemaType) {
  seoForm.enabled_types = seoForm.enabled_types.includes(type) ? seoForm.enabled_types.filter((x) => x !== type) : [...seoForm.enabled_types, type]
}
</script>

<template>
  <div>
    <PageHeader :title="t('p6.pub.settings.title')" :description="t('p6.pub.settings.subtitle')" />
    <SectionNav section="publishing" active="settings" />
    <AppCard class="mb-6"><AppSelect v-model="sites.siteId.value" :label="t('p6.common.site')" :options="sites.options.value" /></AppCard>
    <LoadingState v-if="sites.loading.value || loading" />
    <ErrorState v-else-if="sites.error.value || error" :error="sites.error.value || error" @retry="load" />
    <EmptyState v-else-if="!sites.sites.value.length" icon="globe" :title="t('p6.common.noSite')" />
    <div v-else-if="policy && seo" class="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <AppCard :title="t('p6.pub.settings.connection')">
        <dl class="">
          <dt class="text-ink-muted">{{ t('p6.pub.settings.timezone') }}</dt><dd>{{ policy.site.timezone }}</dd>
          <dt class="text-ink-muted">{{ t('p6.pub.settings.canPublish') }}</dt><dd><AppBadge :tone="policy.site.can_publish ? 'success' : 'danger'">{{ policy.site.can_publish ? t('common.yes') : t('common.no') }}</AppBadge></dd>
          <dt class="text-ink-muted">{{ t('p6.pub.settings.seoPlugin') }}</dt><dd>{{ policy.site.yoast ? 'Yoast SEO' : policy.site.rank_math ? 'Rank Math' : t('p6.pub.settings.native') }}</dd>
          <dt class="text-ink-muted">WordPress / plugin</dt><dd>{{ policy.site.wp_version ?? '—' }} / {{ policy.site.plugin_version ?? '—' }}</dd>
          <dt class="text-ink-muted">{{ t('p6.pub.settings.platform') }}</dt><dd><AppBadge :tone="policy.platform.publishing_enabled ? 'success' : 'danger'">{{ policy.platform.publishing_enabled ? t('p6.pub.settings.enabled') : t('p6.pub.settings.disabled') }}</AppBadge></dd>
        </dl>
      </AppCard>

      <AppCard :title="t('p6.pub.settings.auto')">
        <p class="mb-3 text-sm text-ink-muted">{{ t('p6.pub.settings.autoHint') }}</p>
        <div class="flex flex-wrap items-center gap-3">
          <AppBadge :tone="policy.auto_publish_enabled ? 'warning' : 'neutral'" data-testid="auto-state">{{ policy.auto_publish_enabled ? t('p6.pub.settings.autoOn') : t('p6.pub.settings.autoOff') }}</AppBadge>
          <template v-if="canManageSites">
            <AppButton v-if="!policy.auto_publish_enabled" size="sm" variant="secondary" :disabled="!policy.platform.auto_publish_allowed" data-testid="enable-auto" @click="confirmAuto = true">{{ t('p6.pub.settings.enableAuto') }}</AppButton>
            <AppButton v-else size="sm" variant="secondary" @click="setAuto(false)">{{ t('p6.pub.settings.disableAuto') }}</AppButton>
          </template>
        </div>
        <p v-if="!policy.platform.auto_publish_allowed" class="mt-2 text-xs text-ink-muted">{{ t('p6.pub.settings.autoNotAllowed') }}</p>
      </AppCard>

      <AppCard :title="t('p6.pub.settings.policy')" class="lg:col-span-2">
        <form class="grid grid-cols-1 gap-4 md:grid-cols-2" @submit.prevent="save()">
          <div class="space-y-1">
            <AppSelect v-model="form.default_mode" :label="t('p6.pub.settings.defaultMode')" :options="[{ value: 'manual', label: t('p6.pub.settings.modeManual') }, { value: 'wp_draft', label: t('p6.pub.mode.wp_draft') }]" :disabled="!canManageSites" />
            <Toggle v-model="form.require_seo_pass" :label="t('p6.pub.settings.requireSeo')" :description="t('p6.pub.settings.requireSeoHint')" :disabled="!canManageSites" />
            <Toggle v-model="form.require_media_ready" :label="t('p6.pub.settings.requireMedia')" :disabled="!canManageSites" />
            <Toggle v-model="form.allow_update_published" :label="t('p6.pub.settings.allowUpdate')" :disabled="!canManageSites" />
            <div>
              <p class="text-sm font-medium">{{ t('p6.pub.settings.days') }}</p>
              <div class="mt-1 flex flex-wrap gap-2 text-sm">
                <label v-for="d in [1, 2, 3, 4, 5, 6, 7]" :key="d" class="flex items-center gap-1"><input type="checkbox" :checked="form.window_days.includes(d)" :disabled="!canManageSites" @change="toggleDay(d)" />{{ t(`p6.pub.settings.dow.${d}`) }}</label>
              </div>
              <p class="text-xs text-ink-muted">{{ t('p6.pub.settings.daysHint') }}</p>
            </div>
          </div>
          <div class="">
            <AppInput v-for="k in NUMS" :key="k" v-model="form.nums[k]" type="number" :label="t(`p6.pub.settings.num.${k}`)" :disabled="!canManageSites" />
          </div>
          <div v-if="canManageSites" class="md:col-span-2 flex justify-end"><AppButton type="submit" :loading="saving" data-testid="save-policy">{{ t('common.save') }}</AppButton></div>
        </form>
      </AppCard>

      <AppCard :title="t('p6.pub.settings.schema')" :subtitle="t('p6.pub.settings.schemaHint')" class="lg:col-span-2">
        <p v-if="seo.yoast || seo.rank_math" class="mb-3 rounded bg-brand-50 px-3 py-2 text-xs text-brand-700">{{ t('p6.pub.settings.pluginSchema') }}</p>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div class="space-y-1">
            <Toggle v-model="seoForm.injection_enabled" :label="t('p6.pub.settings.injection')" :description="t('p6.pub.settings.injectionHint')" :disabled="!canManageSites" />
            <Toggle v-model="seoForm.breadcrumbs_supported" :label="t('p6.pub.settings.breadcrumbs')" :disabled="!canManageSites" />
            <Toggle v-model="seoForm.allow_canonical_override" :label="t('p6.pub.settings.allowCanonical')" :description="t('p6.pub.settings.dangerHint')" :disabled="!canManageSites" />
            <Toggle v-model="seoForm.allow_robots_override" :label="t('p6.pub.settings.allowRobots')" :description="t('p6.pub.settings.dangerHint')" :disabled="!canManageSites" />
          </div>
          <div class="space-y-3">
            <div class="flex flex-wrap gap-2 text-sm">
              <label v-for="s in SCHEMA_TYPES" :key="s" class="flex items-center gap-1"><input type="checkbox" :checked="seoForm.enabled_types.includes(s)" :disabled="!canManageSites" @change="toggleType(s)" />{{ s }}</label>
            </div>
            <AppInput v-model="seoForm.organization_name" :label="t('p6.pub.settings.orgName')" :disabled="!canManageSites" />
            <AppInput v-model="seoForm.logo_url" :label="t('p6.pub.settings.logoUrl')" :disabled="!canManageSites" />
          </div>
        </div>
        <div v-if="canManageSites" class="mt-4 flex justify-end"><AppButton :loading="savingSeo" data-testid="save-schema" @click="saveSeo">{{ t('common.save') }}</AppButton></div>
      </AppCard>
    </div>
    <ConfirmDialog :open="confirmAuto" :title="t('p6.pub.settings.enableAuto')" :message="t('p6.pub.settings.autoConfirm')" danger @close="confirmAuto = false" @confirm="setAuto(true)" />
  </div>
</template>
