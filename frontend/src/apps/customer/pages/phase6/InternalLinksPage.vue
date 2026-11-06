<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { seoApi, type LinkSuggestion } from '@/shared/api/seo'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import ArticlePicker from './ArticlePicker.vue'
import SectionNav from './SectionNav.vue'
import { useArticleParam } from './useArticleParam'
import { usePhase6Errors, usePhase6Perms } from './usePhase6'

const { t } = useI18n()
const { workspaceId, canEdit } = usePhase6Perms()
const { toastError, toast } = usePhase6Errors()
const links = shallowRef<LinkSuggestion[]>([])
const loading = ref(false)
const error = shallowRef<unknown>(null)
const suggesting = ref(false)
async function load(id: string) {
  links.value = []
  if (!id) return
  loading.value = true
  error.value = null
  try {
    links.value = await seoApi.links(workspaceId.value, id)
  } catch (e) {
    error.value = e
  } finally {
    loading.value = false
  }
}
const { articles, articleId } = useArticleParam('seo-links', (id) => void load(id))
async function suggest() {
  suggesting.value = true
  try {
    const r = await seoApi.suggestLinks(workspaceId.value, articleId.value)
    links.value = r.data
    toast.success(t('p6.seo.links.suggested', { n: r.suggested }))
  } catch (e) {
    toastError(e)
  } finally {
    suggesting.value = false
  }
}
async function decide(l: LinkSuggestion, status: 'accepted' | 'rejected') {
  try {
    const updated = await seoApi.decideLink(workspaceId.value, l.id, status)
    links.value = links.value.map((x) => (x.id === l.id ? updated : x))
  } catch (e) {
    toastError(e)
  }
}
const accepted = computed(() => links.value.filter((l) => l.status === 'accepted').length)
const tone = (s: string) => (s === 'accepted' ? 'success' : s === 'rejected' ? 'neutral' : s === 'stale' ? 'warning' : 'brand') as 'success' | 'neutral' | 'warning' | 'brand'
</script>

<template>
  <div>
    <PageHeader :title="t('p6.seo.links.title')" :description="t('p6.seo.links.subtitle')" />
    <SectionNav section="seo" active="links" />
    <AppCard class="mb-6"><ArticlePicker v-model="articleId" :articles="articles" /></AppCard>
    <EmptyState v-if="!articleId" icon="link" :title="t('p6.common.pickArticleFirst')" />
    <LoadingState v-else-if="loading" />
    <ErrorState v-else-if="error" :error="error" @retry="load(articleId)" />
    <AppCard v-else :title="t('p6.seo.links.list')" :subtitle="t('p6.seo.links.rule', { n: accepted })" :padded="false">
      <template #actions>
        <AppButton v-if="canEdit" size="sm" :loading="suggesting" data-testid="suggest-links" @click="suggest">{{ t('p6.seo.links.suggest') }}</AppButton>
      </template>
      <EmptyState v-if="!links.length" icon="link" :title="t('p6.seo.links.empty')" :description="t('p6.seo.links.emptyHint')" />
      <ul v-else class="divide-y divide-line" data-testid="link-suggestions">
        <li v-for="l in links" :key="l.id" class="space-y-1 px-5 py-3 text-sm">
          <div class="flex flex-wrap items-center gap-2">
            <AppBadge :tone="tone(l.status)">{{ t(`p6.seo.links.status.${l.status}`) }}</AppBadge>
            <span>“<strong>{{ l.anchor_text }}</strong>” →</span>
            <a :href="l.target_url" target="_blank" rel="noopener noreferrer" class="truncate text-brand-600 underline">{{ l.target_title }}</a>
            <span class="ml-auto text-xs tabular-nums text-ink-muted">{{ t('p6.seo.links.relevance') }} {{ l.relevance }}</span>
          </div>
          <p class="text-xs text-ink-muted">{{ l.reason }}</p>
          <p v-if="l.signals" class="text-xs text-ink-muted">{{ t('p6.seo.links.signals', { semantic: l.signals.semantic, intent: l.signals.intent_fit, anchor: l.signals.anchor_quality }) }}<span v-if="l.signals.same_cluster"> · {{ t('p6.seo.links.sameCluster') }}</span></p>
          <div v-if="canEdit && l.status !== 'stale'" class="flex gap-2">
            <AppButton size="sm" variant="secondary" :disabled="l.status === 'accepted'" @click="decide(l, 'accepted')">{{ t('p6.seo.links.accept') }}</AppButton>
            <AppButton size="sm" variant="ghost" :disabled="l.status === 'rejected'" @click="decide(l, 'rejected')">{{ t('p6.seo.links.reject') }}</AppButton>
          </div>
        </li>
      </ul>
    </AppCard>
  </div>
</template>
