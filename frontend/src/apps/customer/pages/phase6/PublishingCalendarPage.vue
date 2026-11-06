<script setup lang="ts">
import { computed, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isoDate, jobTone, publishingApi, type CalendarView } from '@/shared/api/publishing'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import FilterSelect from '@/shared/ui/FilterSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import JobDrawer from './JobDrawer.vue'
import SectionNav from './SectionNav.vue'
import { usePhase6Perms, useSites } from './usePhase6'

const { t, locale } = useI18n()
const { workspaceId } = usePhase6Perms()
const sites = useSites()
const month = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1))
const data = shallowRef<CalendarView | null>(null)
const loading = ref(false)
const error = shallowRef<unknown>(null)
const openJob = ref<string | null>(null)

const days = computed(() => {
  const first = month.value
  const start = new Date(first)
  start.setDate(1 - ((first.getDay() + 6) % 7)) // weeks start on Monday
  return Array.from({ length: 42 }, (_, i) => {
    const d = new Date(start)
    d.setDate(start.getDate() + i)
    return d
  })
})
async function load() {
  loading.value = true
  error.value = null
  try {
    data.value = await publishingApi.calendar(workspaceId.value, isoDate(days.value[0]!), isoDate(days.value[41]!), sites.siteId.value || undefined)
  } catch (e) {
    error.value = e
  } finally {
    loading.value = false
  }
}
watch([month, () => sites.siteId.value], load, { immediate: true })
function shift(n: number) {
  month.value = new Date(month.value.getFullYear(), month.value.getMonth() + n, 1)
}
const byDay = computed(() => {
  const map: Record<string, { jobs: CalendarView['jobs']; planned: CalendarView['planned'] }> = {}
  for (const j of data.value?.jobs ?? []) {
    const at = j.published_at ?? j.scheduled_at
    if (!at) continue
    const k = isoDate(new Date(at))
    ;(map[k] ??= { jobs: [], planned: [] }).jobs.push(j)
  }
  for (const p of data.value?.planned ?? []) {
    if (!p.scheduled_for) continue
    ;(map[p.scheduled_for] ??= { jobs: [], planned: [] }).planned.push(p)
  }
  return map
})
const title = computed(() => month.value.toLocaleDateString(locale.value, { month: 'long', year: 'numeric' }))
const siteOpts = computed(() => [{ value: '', label: t('p6.common.allSites') }, ...sites.options.value])
const today = isoDate(new Date())
</script>

<template>
  <div>
    <PageHeader :title="t('p6.pub.calendar.title')" :description="t('p6.pub.calendar.subtitle')" />
    <SectionNav section="publishing" active="calendar" />
    <AppCard :padded="false">
      <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
        <AppButton size="sm" variant="ghost" @click="shift(-1)">‹</AppButton>
        <span class="min-w-[10rem] text-center font-semibold capitalize">{{ title }}</span>
        <AppButton size="sm" variant="ghost" @click="shift(1)">›</AppButton>
        <FilterSelect v-model="sites.siteId.value" :label="t('p6.common.site')" :options="siteOpts" class="ml-auto" />
      </div>
      <LoadingState v-if="loading && !data" />
      <ErrorState v-else-if="error" :error="error" @retry="load" />
      <div v-else class="grid grid-cols-7 text-xs" data-testid="calendar-grid">
        <div v-for="d in ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN']" :key="d" class="border-b border-line px-2 py-1 font-medium text-ink-muted">{{ t(`p6.pub.calendar.dow.${d}`) }}</div>
        <div v-for="d in days" :key="d.toISOString()" class="min-h-24 border-b border-r border-line p-1" :class="[d.getMonth() !== month.getMonth() ? 'bg-surface-muted/60 text-ink-muted' : '', isoDate(d) === today ? 'ring-1 ring-brand-500' : '']">
          <p class="mb-1 text-right">{{ d.getDate() }}</p>
          <button v-for="j in byDay[isoDate(d)]?.jobs ?? []" :key="j.id" type="button" class="mb-0.5 block w-full truncate text-left" :title="j.article_title ?? ''" @click="openJob = j.id">
            <AppBadge :tone="jobTone(j.status)">{{ j.local_time ? j.local_time.slice(11, 16) : '' }} {{ j.article_title ?? '' }}</AppBadge>
          </button>
          <p v-for="p in byDay[isoDate(d)]?.planned ?? []" :key="p.id" class="truncate text-ink-muted" :title="p.title">◦ {{ p.title }}</p>
        </div>
      </div>
      <p class="px-5 py-3 text-xs text-ink-muted">{{ t('p6.pub.calendar.legend') }}</p>
    </AppCard>
    <JobDrawer :job-id="openJob" @close="openJob = null" @changed="load" />
  </div>
</template>
