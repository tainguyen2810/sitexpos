<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { SEARCH_TYPES, SORTS, type Filters } from '@/shared/api/analytics'

/** Dashboard filters: period, search type, device, country, text and sort. */
const props = withDefaults(defineProps<{ sort?: boolean; search?: boolean }>(), { sort: false, search: false })
const model = defineModel<Filters>({ required: true })
const emit = defineEmits<{ apply: [] }>()
const { t } = useI18n()

function set<K extends keyof Filters>(k: K, v: Filters[K]) {
  model.value = { ...model.value, [k]: v }
}
</script>

<template>
  <form class="flex flex-wrap items-end gap-3 text-sm" data-testid="an-filters" @submit.prevent="emit('apply')">
    <label class="flex flex-col gap-1">
      <span class="text-xs text-ink-muted">{{ t('an.filters.from') }}</span>
      <input type="date" class="rounded-lg border border-line bg-surface px-2 py-1.5" :value="model.from" @change="set('from', ($event.target as HTMLInputElement).value)" />
    </label>
    <label class="flex flex-col gap-1">
      <span class="text-xs text-ink-muted">{{ t('an.filters.to') }}</span>
      <input type="date" class="rounded-lg border border-line bg-surface px-2 py-1.5" :value="model.to" @change="set('to', ($event.target as HTMLInputElement).value)" />
    </label>
    <label class="flex flex-col gap-1">
      <span class="text-xs text-ink-muted">{{ t('an.filters.searchType') }}</span>
      <select class="rounded-lg border border-line bg-surface px-2 py-1.5" :value="model.search_type ?? 'web'" @change="set('search_type', ($event.target as HTMLSelectElement).value)">
        <option v-for="s in SEARCH_TYPES" :key="s" :value="s">{{ t(`an.searchType.${s}`) }}</option>
      </select>
    </label>
    <label class="flex flex-col gap-1">
      <span class="text-xs text-ink-muted">{{ t('an.filters.device') }}</span>
      <select class="rounded-lg border border-line bg-surface px-2 py-1.5" :value="model.device ?? ''" @change="set('device', ($event.target as HTMLSelectElement).value || undefined)">
        <option value="">{{ t('an.filters.all') }}</option>
        <option v-for="d in ['desktop', 'mobile', 'tablet']" :key="d" :value="d">{{ t(`an.device.${d}`) }}</option>
      </select>
    </label>
    <label class="flex flex-col gap-1">
      <span class="text-xs text-ink-muted">{{ t('an.filters.country') }}</span>
      <input
        class="w-24 rounded-lg border border-line bg-surface px-2 py-1.5 uppercase"
        maxlength="3"
        :placeholder="t('an.filters.countryHint')"
        :value="model.country ?? ''"
        @change="set('country', ($event.target as HTMLInputElement).value.toLowerCase() || undefined)"
      />
    </label>
    <label v-if="props.search" class="flex flex-col gap-1">
      <span class="text-xs text-ink-muted">{{ t('an.filters.q') }}</span>
      <input class="rounded-lg border border-line bg-surface px-2 py-1.5" :value="model.q ?? ''" @change="set('q', ($event.target as HTMLInputElement).value || undefined)" />
    </label>
    <label v-if="props.sort" class="flex flex-col gap-1">
      <span class="text-xs text-ink-muted">{{ t('an.filters.sort') }}</span>
      <select class="rounded-lg border border-line bg-surface px-2 py-1.5" :value="model.sort ?? 'clicks'" @change="set('sort', ($event.target as HTMLSelectElement).value)">
        <option v-for="s in SORTS" :key="s" :value="s">{{ t(`an.metrics.${s}`) }}</option>
      </select>
    </label>
    <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 font-medium text-white hover:bg-brand-700">{{ t('an.filters.apply') }}</button>
  </form>
</template>
