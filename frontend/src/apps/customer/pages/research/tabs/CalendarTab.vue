<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { researchApi, monthGrid, type Campaign, type CalendarItem, type CalendarStatus, type Idea } from '@/shared/api/research'
import { ApiError } from '@/shared/api/client'
import { useToast } from '@/shared/composables/useToast'
import AppCard from '@/shared/ui/AppCard.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import Tabs from '@/shared/ui/Tabs.vue'

const props = defineProps<{ workspaceId: string; campaign: Campaign; canOperate: boolean }>()
const { t, tm, locale } = useI18n()
const toast = useToast()
const err = (e: unknown) => toast.error(e instanceof ApiError && e.message ? e.message : t('errors.generic'))

const today = new Date().toISOString().slice(0, 10)
const view = ref<'month' | 'week'>('month')
const viewTabs = computed(() => [{ key: 'month', label: t('research.calendar.month') }, { key: 'week', label: t('research.calendar.week') }])
const anchor = ref(new Date(`${today}T00:00:00Z`))

const weeks = computed<string[][]>(() => {
  if (view.value === 'month') return monthGrid(anchor.value)
  const start = new Date(anchor.value)
  start.setUTCDate(start.getUTCDate() - ((start.getUTCDay() + 6) % 7))
  return [Array.from({ length: 7 }, (_, i) => {
    const d = new Date(start)
    d.setUTCDate(start.getUTCDate() + i)
    return d.toISOString().slice(0, 10)
  })]
})
const range = computed(() => ({ from: weeks.value[0]?.[0] ?? today, to: weeks.value[weeks.value.length - 1]?.[6] ?? today }))
const title = computed(() => new Intl.DateTimeFormat(locale.value === 'en' ? 'en-US' : 'vi-VN', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(anchor.value))
const weekdays = computed(() => tm('research.calendar.weekdays') as unknown as string[])

function shift(dir: -1 | 1) {
  const d = new Date(anchor.value)
  if (view.value === 'month') d.setUTCMonth(d.getUTCMonth() + dir, 1)
  else d.setUTCDate(d.getUTCDate() + 7 * dir)
  anchor.value = d
  void load()
}
function goToday() {
  anchor.value = new Date(`${today}T00:00:00Z`)
  void load()
}
function setView(v: string) {
  view.value = v as 'month' | 'week'
  void load()
}

const items = shallowRef<CalendarItem[]>([])
const loading = ref(false)
const error = shallowRef<unknown>(null)
async function load() {
  loading.value = true
  error.value = null
  try {
    items.value = await researchApi.calendar(props.workspaceId, props.campaign.id, range.value)
  } catch (e) {
    error.value = e
  } finally {
    loading.value = false
  }
}
void load()
const byDay = computed(() => {
  const m = new Map<string, CalendarItem[]>()
  for (const it of items.value) {
    const list = m.get(it.scheduled_for) ?? []
    list.push(it)
    m.set(it.scheduled_for, list)
  }
  for (const list of m.values()) list.sort((a, b) => a.position - b.position)
  return m
})

const unscheduled = shallowRef<Idea[]>([])
async function loadUnscheduled() {
  try {
    unscheduled.value = (await researchApi.ideas(props.workspaceId, props.campaign.id, { status: 'approved', unscheduled: '1', per_page: 50 })).data
  } catch {
    unscheduled.value = []
  }
}
void loadUnscheduled()

const tone = (s: CalendarStatus) => (({ proposed: 'warning', approved: 'success', paused: 'neutral', canceled: 'neutral', done: 'brand' }) as const)[s]

// ---- drag & drop (HTML5): items move dates, ideas get scheduled ----
type Drag = { kind: 'item'; id: string } | { kind: 'idea'; id: string }
const dragging = ref<Drag | null>(null)
const over = ref<string | null>(null)
function onDragStart(e: DragEvent, d: Drag) {
  if (!props.canOperate) return
  dragging.value = d
  e.dataTransfer?.setData('text/plain', `${d.kind}:${d.id}`)
  if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move'
}
async function onDrop(day: string) {
  const d = dragging.value
  dragging.value = null
  over.value = null
  if (!d || !props.canOperate) return
  if (day < today) {
    toast.error(t('research.calendar.pastDate'))
    return
  }
  const position = byDay.value.get(day)?.length ?? 0
  try {
    if (d.kind === 'item') {
      const it = items.value.find((x) => x.id === d.id)
      if (!it || it.scheduled_for === day) return
      const updated = await researchApi.updateItem(props.workspaceId, props.campaign.id, d.id, { scheduled_for: day, position })
      items.value = items.value.map((x) => (x.id === updated.id ? updated : x))
    } else {
      const created = await researchApi.schedule_item(props.workspaceId, props.campaign.id, { content_idea_id: d.id, scheduled_for: day, position })
      items.value = [...items.value, created]
      unscheduled.value = unscheduled.value.filter((i) => i.id !== d.id)
    }
  } catch (e) {
    err(e)
  }
}

// ---- item actions ----
const active = ref<CalendarItem | null>(null)
async function setStatus(it: CalendarItem, status: CalendarStatus) {
  try {
    const updated = await researchApi.updateItem(props.workspaceId, props.campaign.id, it.id, { status })
    items.value = items.value.map((x) => (x.id === updated.id ? updated : x))
    active.value = updated
  } catch (e) {
    err(e)
  }
}
async function remove(it: CalendarItem) {
  try {
    await researchApi.removeItem(props.workspaceId, props.campaign.id, it.id)
    items.value = items.value.filter((x) => x.id !== it.id)
    active.value = null
    void loadUnscheduled()
  } catch (e) {
    err(e)
  }
}

const planning = ref(false)
async function autoPlan() {
  planning.value = true
  try {
    const res = await researchApi.autoPlan(props.workspaceId, props.campaign.id)
    toast.success(t('research.calendar.autoPlanned', { n: res.scheduled }))
    await Promise.all([load(), loadUnscheduled()])
  } catch (e) {
    err(e)
  } finally {
    planning.value = false
  }
}
const inMonth = (day: string) => view.value === 'week' || day.slice(0, 7) === anchor.value.toISOString().slice(0, 7)
</script>

<template>
  <div class="grid grid-cols-1 gap-6 xl:grid-cols-4">
    <AppCard class="xl:col-span-3" :padded="false">
      <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
        <AppButton size="sm" variant="ghost" :aria-label="t('common.previous')" @click="shift(-1)">‹</AppButton>
        <AppButton size="sm" variant="ghost" :aria-label="t('common.next')" @click="shift(1)">›</AppButton>
        <AppButton size="sm" variant="secondary" @click="goToday">{{ t('research.calendar.today') }}</AppButton>
        <h3 class="ml-2 text-sm font-semibold capitalize">{{ title }}</h3>
        <div class="ml-auto flex items-center gap-2">
          <Tabs :model-value="view" :tabs="viewTabs" @update:model-value="setView" />
          <AppButton v-if="canOperate" size="sm" :loading="planning" data-testid="auto-plan" @click="autoPlan"><AppIcon name="sparkles" :size="14" /> {{ t('research.calendar.autoPlan') }}</AppButton>
        </div>
      </div>
      <p class="border-b border-line px-4 py-2 text-xs text-ink-muted">{{ t('research.calendar.dragHint') }}</p>

      <LoadingState v-if="loading && !items.length" class="p-5" />
      <ErrorState v-else-if="error" :error="error" class="p-5" @retry="load" />
      <div v-else class="overflow-x-auto">
        <div class="min-w-[720px]">
          <div class="">
            <div v-for="w in weekdays" :key="w" class="py-2">{{ w }}</div>
          </div>
          <div v-for="(week, wi) in weeks" :key="wi" class="">
            <div
              v-for="day in week"
              :key="day"
              class="border-r border-line p-1.5 last:border-r-0"
              :class="[view === 'week' ? 'min-h-64' : 'min-h-28', inMonth(day) ? '' : 'bg-surface-muted/60', over === day ? 'bg-brand-50' : '']"
              :data-day="day"
              @dragover.prevent="over = day"
              @dragleave="over === day && (over = null)"
              @drop.prevent="onDrop(day)"
            >
              <p class="mb-1 text-right text-xs" :class="day === today ? 'font-bold text-brand-700' : 'text-ink-muted'">{{ Number(day.slice(8)) }}</p>
              <button
                v-for="it in byDay.get(day) ?? []"
                :key="it.id"
                type="button"
                :draggable="canOperate"
                class="mb-1 block w-full truncate rounded border px-1.5 py-1 text-left text-xs"
                :class="it.status === 'paused' ? 'border-line bg-surface-muted text-ink-muted line-through' : it.status === 'proposed' ? 'border-warning-600/40 bg-warning-50' : 'border-brand-200 bg-brand-50'"
                :title="it.idea?.title ?? ''"
                @dragstart="onDragStart($event, { kind: 'item', id: it.id })"
                @click="active = it"
              >
                {{ it.idea?.title ?? '—' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </AppCard>

    <div class="space-y-6">
      <AppCard v-if="active" :title="active.idea?.title ?? ''">
        <template #actions><AppButton size="sm" variant="ghost" :aria-label="t('common.close')" @click="active = null"><AppIcon name="x" :size="14" /></AppButton></template>
        <div class="space-y-2 text-sm">
          <p><AppBadge :tone="tone(active.status)">{{ t(`research.calendar.status.${active.status}`) }}</AppBadge> · {{ active.scheduled_for }}</p>
          <p v-if="active.idea" class="text-xs text-ink-muted">{{ t(`research.contentType.${active.idea.content_type}`) }} · {{ t(`research.intent.${active.idea.search_intent}`) }}</p>
          <div v-if="canOperate" class="flex flex-wrap gap-2 pt-2">
            <AppButton v-if="active.status === 'proposed' || active.status === 'paused'" size="sm" @click="setStatus(active, 'approved')">{{ active.status === 'paused' ? t('research.calendar.resume') : t('research.calendar.approve') }}</AppButton>
            <AppButton v-if="active.status === 'approved' || active.status === 'proposed'" size="sm" variant="secondary" @click="setStatus(active, 'paused')">{{ t('research.calendar.pause') }}</AppButton>
            <AppButton size="sm" variant="ghost" @click="remove(active)">{{ t('research.calendar.remove') }}</AppButton>
          </div>
        </div>
      </AppCard>

      <AppCard :title="t('research.calendar.unscheduled')">
        <p v-if="!unscheduled.length" class="text-sm text-ink-muted">{{ t('research.calendar.noUnscheduled') }}</p>
        <ul v-else class="space-y-1.5">
          <li
            v-for="i in unscheduled"
            :key="i.id"
            :draggable="canOperate"
            class="rounded-lg border border-line px-2 py-1.5 text-xs"
            :class="canOperate ? 'cursor-grab hover:border-brand-500' : ''"
            @dragstart="onDragStart($event, { kind: 'idea', id: i.id })"
          >
            {{ i.title }}
          </li>
        </ul>
      </AppCard>
    </div>
  </div>
</template>
