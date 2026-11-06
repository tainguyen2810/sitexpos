<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

/** Picks one article from a list already loaded by the page (Writer articles exposed through the SEO / Publishing APIs). */
const props = defineProps<{ articles: { id: string; title: string }[]; label?: string }>()
const model = defineModel<string>({ default: '' })
const { t } = useI18n()
// An article opened by link may not be in the list (e.g. not approved yet): keep it selectable instead of a blank select.
const options = computed(() => {
  const list = props.articles.map((a) => ({ value: a.id, label: a.title }))
  return model.value !== '' && !list.some((o) => o.value === model.value) ? [{ value: model.value, label: t('p6.common.currentArticle') }, ...list] : list
})
</script>

<template>
  <label class="block space-y-1.5 text-sm">
    <span class="font-medium text-ink">{{ label ?? t('p6.common.article') }}</span>
    <select v-model="model" class="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm" data-testid="article-picker">
      <option value="">{{ t('p6.common.chooseArticle') }}</option>
      <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
    </select>
  </label>
</template>
