<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { researchApi, splitList, type Campaign, type CampaignSettings, type Competitor, type CompetitorFinding, type Schedule, type SearchConsoleState } from '@/shared/api/research'
import { ApiError } from '@/shared/api/client'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import { useToast } from '@/shared/composables/useToast'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import Drawer from '@/shared/ui/Drawer.vue'
import Toggle from '@/shared/ui/Toggle.vue'

const props = defineProps<{ workspaceId: string; campaign: Campaign; canOperate: boolean; canManage: boolean }>()
const { t, tm } = useI18n()
const toast = useToast()
const err = (e: unknown) => toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))
const W = props.workspaceId
const C = props.campaign.id

// ---------------- discovery & planning settings ----------------
const usage = useAsync(() => researchApi.usage(W))
const maxIdeas = computed(() => usage.data.value?.limits.max_ideas_per_run ?? 100)
const settings = useAsync(async () => {
  const s = await researchApi.settings(W, C)
  fill(s)
  return s
})
const draft = ref({ ideas_per_run: '', scope: '', excluded: '', max_site_pages: '', max_competitor_pages: '', use_ai_expansion: true, ai_cap: '', posts_per_week: '', publish_weekdays: [] as number[], auto_calendar: false, notify_opportunities: false, notify_min_score: '' })
function fill(s: CampaignSettings) {
  draft.value = {
    ideas_per_run: String(s.ideas_per_run), scope: s.scope_keywords.join('\n'), excluded: s.excluded_topics.join('\n'),
    max_site_pages: String(s.max_site_pages), max_competitor_pages: String(s.max_competitor_pages), use_ai_expansion: s.use_ai_expansion,
    ai_cap: (s.max_ai_cost_per_run_micro_usd / 1_000_000).toString(), posts_per_week: String(s.posts_per_week), publish_weekdays: [...s.publish_weekdays],
    auto_calendar: s.auto_calendar, notify_opportunities: s.notify_opportunities, notify_min_score: String(s.notify_min_score),
  }
}
// Mon..Sun labels map to Carbon day numbers 1..6,0
const WEEKDAY_NUMBERS = [1, 2, 3, 4, 5, 6, 0]
const weekdayLabels = computed(() => tm('research.calendar.weekdays') as unknown as string[])
function toggleDay(n: number) {
  const s = new Set(draft.value.publish_weekdays)
  if (s.has(n)) s.delete(n)
  else s.add(n)
  draft.value.publish_weekdays = [...s].sort()
}
const settingsForm = useApiForm()
async function saveSettings() {
  const d = draft.value
  const res = await settingsForm.submit(() =>
    researchApi.updateSettings(W, C, {
      ideas_per_run: Number(d.ideas_per_run), scope_keywords: splitList(d.scope), excluded_topics: splitList(d.excluded),
      max_site_pages: Number(d.max_site_pages), max_competitor_pages: Number(d.max_competitor_pages), use_ai_expansion: d.use_ai_expansion,
      max_ai_cost_per_run_micro_usd: Math.round(Number(d.ai_cap) * 1_000_000), posts_per_week: Number(d.posts_per_week), publish_weekdays: d.publish_weekdays,
      auto_calendar: d.auto_calendar, notify_opportunities: d.notify_opportunities, notify_min_score: Number(d.notify_min_score),
    }),
  )
  if (!res) return
  fill(res)
  settings.data.value = res
  toast.success(t('common.saved'))
}

// ---------------- schedule ----------------
const schedule = useAsync(async () => {
  const s = await researchApi.schedule(W, C)
  sched.value = { is_enabled: s?.is_enabled ?? false, frequency: s?.frequency ?? 'weekly', day_of_week: String(s?.day_of_week ?? 1), day_of_month: String(s?.day_of_month ?? 1), hour_utc: String(s?.hour_utc ?? 1) }
  return s
})
const sched = ref({ is_enabled: false, frequency: 'weekly' as Schedule['frequency'], day_of_week: '1', day_of_month: '1', hour_utc: '1' })
const freqOptions = computed(() => (['daily', 'weekly', 'monthly'] as const).map((f) => ({ value: f, label: t(`research.settings.freq.${f}`) })))
const dowOptions = computed(() => WEEKDAY_NUMBERS.map((n, i) => ({ value: String(n), label: weekdayLabels.value[i] ?? String(n) })))
const domOptions = Array.from({ length: 28 }, (_, i) => ({ value: String(i + 1), label: String(i + 1) }))
const hourOptions = Array.from({ length: 24 }, (_, i) => ({ value: String(i), label: `${String(i).padStart(2, '0')}:00 UTC` }))
const scheduleForm = useApiForm()
async function saveSchedule() {
  const s = sched.value
  const res = await scheduleForm.submit(() =>
    researchApi.updateSchedule(W, C, {
      is_enabled: s.is_enabled, frequency: s.frequency, hour_utc: Number(s.hour_utc),
      day_of_week: s.frequency === 'weekly' ? Number(s.day_of_week) : null, day_of_month: s.frequency === 'monthly' ? Number(s.day_of_month) : null,
    }),
  )
  if (!res) return
  schedule.data.value = res
  toast.success(t('common.saved'))
}

// ---------------- competitors ----------------
const competitorList = useAsync(() => researchApi.competitors(W, C))
const competitorRows = computed(() => competitorList.data.value)
const competitorsLoading = computed(() => competitorList.loading.value)
const competitorsError = computed(() => competitorList.error.value)
const reloadCompetitors = () => void competitorList.run()
const competitorUrl = ref('')
const competitorForm = useApiForm()
async function addCompetitor() {
  const res = await competitorForm.submit(() => researchApi.addCompetitor(W, C, { url: competitorUrl.value }))
  if (!res) return
  competitorUrl.value = ''
  competitorList.data.value = [...(competitorList.data.value ?? []), res]
}
async function toggleCompetitor(c: Competitor) {
  try {
    const updated = await researchApi.updateCompetitor(W, C, c.id, { status: c.status === 'active' ? 'paused' : 'active' })
    competitorList.data.value = (competitorList.data.value ?? []).map((x) => (x.id === updated.id ? updated : x))
  } catch (e) {
    err(e)
  }
}
const removing = ref<Competitor | null>(null)
async function removeCompetitor() {
  const c = removing.value
  if (!c) return
  try {
    await researchApi.removeCompetitor(W, C, c.id)
    competitorList.data.value = (competitorList.data.value ?? []).filter((x) => x.id !== c.id)
  } catch (e) {
    err(e)
  } finally {
    removing.value = null
  }
}
const findingsOf = ref<Competitor | null>(null)
const findings = shallowRef<CompetitorFinding[] | null>(null)
async function openFindings(c: Competitor) {
  findingsOf.value = c
  findings.value = null
  try {
    findings.value = (await researchApi.findings(W, C, c.id, { per_page: 100 })).data
  } catch (e) {
    err(e)
    findings.value = []
  }
}

// ---------------- Search Console ----------------
const gsc = useAsync(() => researchApi.searchConsole(W, C))
const properties = shallowRef<{ site_url: string; permission: string }[] | null>(null)
const property = ref('')
const gscBusy = ref(false)
async function connectGsc() {
  gscBusy.value = true
  try {
    const { authorization_url } = await researchApi.connectSearchConsole(W, C)
    window.location.assign(authorization_url)
  } catch (e) {
    err(e)
    gscBusy.value = false
  }
}
async function loadProperties() {
  gscBusy.value = true
  try {
    properties.value = await researchApi.searchConsoleProperties(W, C)
    property.value = gsc.data.value?.property_url ?? properties.value[0]?.site_url ?? ''
  } catch (e) {
    err(e)
  } finally {
    gscBusy.value = false
  }
}
async function saveProperty() {
  gscBusy.value = true
  try {
    gsc.data.value = await researchApi.setSearchConsoleProperty(W, C, property.value)
    properties.value = null
    toast.success(t('common.saved'))
  } catch (e) {
    err(e)
  } finally {
    gscBusy.value = false
  }
}
const disconnectOpen = ref(false)
async function disconnectGsc() {
  gscBusy.value = true
  try {
    await researchApi.disconnectSearchConsole(W, C)
    await gsc.run()
  } catch (e) {
    err(e)
  } finally {
    gscBusy.value = false
    disconnectOpen.value = false
  }
}
const gscTone = (s: SearchConsoleState) => (s.connected && s.property_url ? 'success' : s.connected ? 'warning' : 'neutral')
</script>

<template>
  <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <!-- discovery + planning -->
    <AppCard :title="t('research.settings.discovery')" class="lg:row-span-2">
      <LoadingState v-if="settings.loading.value && !settings.data.value" :rows="4" />
      <ErrorState v-else-if="settings.error.value" :error="settings.error.value" @retry="settings.run" />
      <form v-else id="settings-form" class="space-y-4" @submit.prevent="saveSettings">
        <p v-if="settingsForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ settingsForm.formError.value }}</p>
        <AppInput v-model="draft.ideas_per_run" type="number" :label="t('research.settings.ideasPerRun')" :hint="t('research.settings.ideasPerRunHint', { max: maxIdeas })" :error="settingsForm.errors.ideas_per_run" :disabled="!canOperate" />
        <div class="space-y-1.5">
          <label class="block text-sm font-medium text-ink" for="scope">{{ t('research.settings.scope') }}</label>
          <textarea id="scope" v-model="draft.scope" rows="3" :disabled="!canOperate" class="block w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100" />
          <p class="text-xs text-ink-muted">{{ t('research.campaigns.listHint') }}</p>
        </div>
        <div class="space-y-1.5">
          <label class="block text-sm font-medium text-ink" for="excluded">{{ t('research.settings.excluded') }}</label>
          <textarea id="excluded" v-model="draft.excluded" rows="3" :disabled="!canOperate" class="block w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100" />
          <p class="text-xs text-ink-muted">{{ t('research.settings.excludedHint') }}</p>
          <p v-if="settingsForm.errors.excluded_topics" class="text-xs text-danger-600">{{ settingsForm.errors.excluded_topics }}</p>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <AppInput v-model="draft.max_site_pages" type="number" :label="t('research.settings.maxSitePages')" :error="settingsForm.errors.max_site_pages" :disabled="!canOperate" />
          <AppInput v-model="draft.max_competitor_pages" type="number" :label="t('research.settings.maxCompetitorPages')" :error="settingsForm.errors.max_competitor_pages" :disabled="!canOperate" />
        </div>
        <Toggle v-model="draft.use_ai_expansion" :label="t('research.settings.useAi')" :description="t('research.settings.useAiHint')" :disabled="!canOperate" />
        <AppInput v-model="draft.ai_cap" type="number" :label="t('research.settings.aiCap')" :error="settingsForm.errors.max_ai_cost_per_run_micro_usd" :disabled="!canOperate" />

        <h3 class="border-t border-line pt-4 text-sm font-semibold">{{ t('research.settings.planning') }}</h3>
        <AppInput v-model="draft.posts_per_week" type="number" :label="t('research.settings.postsPerWeek')" :error="settingsForm.errors.posts_per_week" :disabled="!canOperate" />
        <div>
          <p class="mb-1.5 text-sm font-medium text-ink">{{ t('research.settings.weekdays') }}</p>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="(n, i) in WEEKDAY_NUMBERS"
              :key="n"
              type="button"
              :disabled="!canOperate"
              :aria-pressed="draft.publish_weekdays.includes(n)"
              class="rounded-lg border px-3 py-1.5 text-sm"
              :class="draft.publish_weekdays.includes(n) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-line text-ink-muted'"
              @click="toggleDay(n)"
            >
              {{ weekdayLabels[i] }}
            </button>
          </div>
          <p v-if="settingsForm.errors.publish_weekdays" class="mt-1 text-xs text-danger-600">{{ settingsForm.errors.publish_weekdays }}</p>
        </div>
        <Toggle v-model="draft.auto_calendar" :label="t('research.settings.autoCalendar')" :disabled="!canOperate" />
        <Toggle :model-value="false" :label="t('research.settings.phase5')" disabled />
        <Toggle v-model="draft.notify_opportunities" :label="t('research.settings.notify')" :disabled="!canOperate" />
        <AppInput v-if="draft.notify_opportunities" v-model="draft.notify_min_score" type="number" :label="t('research.settings.notifyMin')" :error="settingsForm.errors.notify_min_score" :disabled="!canOperate" />
        <div v-if="canOperate" class="flex justify-end">
          <AppButton type="submit" :loading="settingsForm.submitting.value">{{ t('common.save') }}</AppButton>
        </div>
      </form>
    </AppCard>

    <!-- schedule -->
    <AppCard :title="t('research.settings.schedule')">
      <LoadingState v-if="schedule.loading.value && !schedule.data.value" :rows="2" />
      <form v-else class="space-y-4" @submit.prevent="saveSchedule">
        <p v-if="scheduleForm.formError.value" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ scheduleForm.formError.value }}</p>
        <Toggle v-model="sched.is_enabled" :label="t('research.settings.scheduleEnabled')" :disabled="!canOperate" />
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <AppSelect v-model="sched.frequency" :label="t('research.settings.frequency')" :options="freqOptions" :disabled="!canOperate" />
          <AppSelect v-if="sched.frequency === 'weekly'" v-model="sched.day_of_week" :label="t('research.settings.dayOfWeek')" :options="dowOptions" :disabled="!canOperate" />
          <AppSelect v-if="sched.frequency === 'monthly'" v-model="sched.day_of_month" :label="t('research.settings.dayOfMonth')" :options="domOptions" :disabled="!canOperate" />
          <AppSelect v-model="sched.hour_utc" :label="t('research.settings.hour')" :options="hourOptions" :disabled="!canOperate" />
        </div>
        <p v-if="schedule.data.value?.next_run_at && schedule.data.value.is_enabled" class="text-xs text-ink-muted">{{ t('research.overview.nextRun', { time: formatDateTime(schedule.data.value.next_run_at) }) }}</p>
        <div v-if="canOperate" class="flex justify-end"><AppButton type="submit" :loading="scheduleForm.submitting.value">{{ t('common.save') }}</AppButton></div>
      </form>
    </AppCard>

    <!-- Search Console -->
    <AppCard :title="t('research.settings.gsc')" :subtitle="t('research.settings.gscHint')">
      <LoadingState v-if="gsc.loading.value && !gsc.data.value" :rows="2" />
      <ErrorState v-else-if="gsc.error.value" :error="gsc.error.value" @retry="gsc.run" />
      <template v-else-if="gsc.data.value">
        <p v-if="!gsc.data.value.configured" class="rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-600">{{ t('research.settings.gscNotConfigured') }}</p>
        <template v-else-if="!gsc.data.value.connected">
          <AppButton v-if="canManage" :loading="gscBusy" data-testid="gsc-connect" @click="connectGsc"><AppIcon name="link" :size="14" /> {{ t('research.settings.gscConnect') }}</AppButton>
          <p v-else class="text-sm text-ink-muted">{{ t('research.noData') }}</p>
        </template>
        <div v-else class="space-y-3 text-sm">
          <p class="flex flex-wrap items-center gap-2">
            <AppBadge :tone="gscTone(gsc.data.value)">{{ gsc.data.value.status }}</AppBadge>
            {{ t('research.settings.gscConnected', { email: gsc.data.value.google_email ?? '' }) }}
          </p>
          <p><span class="text-ink-muted">{{ t('research.settings.gscProperty') }}:</span> {{ gsc.data.value.property_url ?? t('research.noData') }}</p>
          <p v-if="gsc.data.value.last_synced_at" class="text-xs text-ink-muted">{{ t('research.keywords.collected') }}: {{ formatDateTime(gsc.data.value.last_synced_at) }}</p>
          <p v-if="gsc.data.value.last_error_code" class="text-xs text-danger-600">{{ gsc.data.value.last_error_code }}</p>
          <div v-if="canManage" class="flex flex-wrap gap-2">
            <AppButton size="sm" variant="secondary" :loading="gscBusy" @click="loadProperties">{{ t('research.settings.gscChoose') }}</AppButton>
            <AppButton size="sm" variant="ghost" @click="disconnectOpen = true">{{ t('research.settings.gscDisconnect') }}</AppButton>
          </div>
          <div v-if="properties" class="flex flex-wrap items-end gap-2">
            <AppSelect v-model="property" class="min-w-60 flex-1" :label="t('research.settings.gscProperty')" :options="properties.map((p) => ({ value: p.site_url, label: `${p.site_url} (${p.permission})` }))" />
            <AppButton size="sm" :disabled="!property" :loading="gscBusy" @click="saveProperty">{{ t('common.save') }}</AppButton>
          </div>
        </div>
      </template>
    </AppCard>

    <!-- competitors -->
    <AppCard :title="t('research.settings.competitors')" :subtitle="t('research.settings.competitorsHint')" class="lg:col-span-2">
      <form v-if="canOperate" class="mb-4 flex flex-wrap items-start gap-2" @submit.prevent="addCompetitor">
        <AppInput v-model="competitorUrl" class="min-w-64 flex-1" type="url" placeholder="https://" :label="t('research.settings.competitorUrl')" :error="competitorForm.errors.url ?? competitorForm.formError.value ?? undefined" />
        <AppButton type="submit" class="mt-7" :loading="competitorForm.submitting.value"><AppIcon name="plus" :size="14" /> {{ t('research.settings.addCompetitor') }}</AppButton>
      </form>
      <LoadingState v-if="competitorsLoading && !competitorRows" :rows="2" />
      <ErrorState v-else-if="competitorsError" :error="competitorsError" @retry="reloadCompetitors" />
      <p v-else-if="!competitorRows?.length" class="text-sm text-ink-muted">{{ t('research.noData') }}</p>
      <ul v-else class="divide-y divide-line rounded-lg border border-line">
        <li v-for="c in competitorRows" :key="c.id" class="flex flex-wrap items-center gap-3 px-3 py-2 text-sm">
          <div class="min-w-0 flex-1">
            <p class="font-medium">{{ c.name }}</p>
            <p class="truncate text-xs text-ink-muted">{{ c.url }}</p>
          </div>
          <span class="text-xs text-ink-muted">{{ c.last_crawled_at ? t('research.settings.pagesCrawled', { n: c.pages_crawled }) + ' · ' + formatDateTime(c.last_crawled_at) : t('research.settings.noFindings') }}</span>
          <AppBadge v-if="c.robots_allowed === false" tone="warning">{{ t('research.run.robotsBlocked') }}</AppBadge>
          <AppBadge v-if="c.error" tone="danger" :title="c.error.message ?? ''">{{ c.error.code }}</AppBadge>
          <AppBadge :tone="c.status === 'active' ? 'success' : 'neutral'">{{ t(`research.campaigns.status.${c.status}`) }}</AppBadge>
          <AppButton size="sm" variant="ghost" @click="openFindings(c)">{{ t('research.settings.findings') }}</AppButton>
          <template v-if="canOperate">
            <AppButton size="sm" variant="ghost" @click="toggleCompetitor(c)">{{ c.status === 'active' ? t('research.campaigns.pause') : t('research.campaigns.resume') }}</AppButton>
            <AppButton size="sm" variant="ghost" :aria-label="t('common.delete')" @click="removing = c"><AppIcon name="trash" :size="14" /></AppButton>
          </template>
        </li>
      </ul>
    </AppCard>

    <Drawer :open="!!findingsOf" :title="findingsOf?.name ?? ''" :subtitle="t('research.settings.competitorsHint')" @close="findingsOf = null">
      <LoadingState v-if="!findings" :rows="3" />
      <p v-else-if="!findings.length" class="text-sm text-ink-muted">{{ t('research.settings.noFindings') }}</p>
      <ul v-else class="space-y-2 text-sm">
        <li v-for="f in findings" :key="f.id" class="rounded-lg border border-line px-3 py-2">
          <p class="font-medium">{{ f.title }}</p>
          <p v-if="f.url" class="truncate text-xs text-ink-muted">{{ f.url }}</p>
          <p v-if="f.headings.length" class="mt-1 text-xs text-ink-muted">{{ f.headings.slice(0, 6).join(' · ') }}</p>
          <p class="text-xs text-ink-subtle">{{ f.kind }} · {{ formatDateTime(f.fetched_at) }}</p>
        </li>
      </ul>
    </Drawer>

    <ConfirmDialog :open="!!removing" :title="t('common.delete')" :message="removing?.url ?? ''" danger @close="removing = null" @confirm="removeCompetitor" />
    <ConfirmDialog :open="disconnectOpen" :title="t('research.settings.gscDisconnect')" :message="t('research.settings.gscDisconnectConfirm')" danger :loading="gscBusy" @close="disconnectOpen = false" @confirm="disconnectGsc" />
  </div>
</template>
