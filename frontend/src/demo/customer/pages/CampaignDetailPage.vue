<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import StatCard from '../../ui/StatCard.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import Tabs from '../../ui/Tabs.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import DonutChart from '../../ui/charts/DonutChart.vue'
import CampaignFormModal from '../components/CampaignFormModal.vue'
import { db, demoMutation } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { label } from '../../labels'
import { fmtDate, fmtNumber } from '../../format'
import { useWorkspace } from '../useWorkspace'

const route = useRoute()
const toast = useToast()
const { base, mine, siteName, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const campaign = computed(() => mine(db.campaigns).find((c) => c.id === route.params.campaignId))
const articles = computed(() => db.articles.filter((a) => a.campaignId === campaign.value?.id))
const keywords = computed(() => db.keywords.filter((k) => k.campaignId === campaign.value?.id))
const clusters = computed(() => db.clusters.filter((c) => c.campaignId === campaign.value?.id))
const ideas = computed(() => db.ideas.filter((i) => i.campaignId === campaign.value?.id))
const published = computed(() => articles.value.filter((a) => a.status === 'published').length)
const byStatus = computed(() => {
  const m = new Map<string, number>()
  articles.value.forEach((a) => m.set(a.status, (m.get(a.status) ?? 0) + 1))
  return [...m.entries()].sort((a, b) => b[1] - a[1]).slice(0, 6).map(([k, v]) => ({ name: label('article', k), value: v }))
})
const weeksLeft = computed(() => (campaign.value ? Math.max(0, Math.ceil((new Date(campaign.value.endDate).getTime() - Date.now()) / (7 * 86_400_000))) : 0))
const onTrack = computed(() => {
  const c = campaign.value
  if (!c) return true
  const needed = c.targetArticles - published.value
  return needed <= weeksLeft.value * c.postsPerWeek
})
const tab = ref('articles')
const editOpen = ref(false)

async function toggle() {
  const c = campaign.value
  if (!c) return
  try {
    await demoMutation(() => (c.status = c.status === 'active' ? 'paused' : 'active'))
    toast.success(c.status === 'active' ? 'Chiến dịch đang chạy.' : 'Đã tạm dừng chiến dịch.')
  } catch (e) {
    toast.error((e as Error).message)
  }
}
</script>

<template>
  <div>
    <LoadingState v-if="loading" :rows="5" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <AppCard v-else-if="!campaign"><EmptyState title="Không tìm thấy chiến dịch" icon="target" /></AppCard>
    <template v-else>
      <PageHeader :title="campaign.name" :description="campaign.goal" :crumbs="crumbs({ label: 'Chiến dịch SEO', to: `${base}/campaigns` }, { label: campaign.name })">
        <template #actions>
          <StatusBadge group="campaign" :value="campaign.status" />
          <AppButton variant="secondary" @click="editOpen = true"><AppIcon name="edit" :size="16" /> Sửa</AppButton>
          <AppButton v-if="campaign.status !== 'completed'" :variant="campaign.status === 'active' ? 'secondary' : 'primary'" @click="toggle">
            <AppIcon :name="campaign.status === 'active' ? 'pause' : 'play'" :size="16" /> {{ campaign.status === 'active' ? 'Tạm dừng' : 'Kích hoạt' }}
          </AppButton>
        </template>
      </PageHeader>

      <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Đã đăng / mục tiêu" :value="`${published}/${campaign.targetArticles}`" icon="send" :hint="onTrack ? 'Đúng tiến độ' : 'Chậm tiến độ'" />
        <StatCard label="Từ khóa" :value="keywords.length" icon="key" :to="`${base}/keywords?campaign=${campaign.id}`" />
        <StatCard label="Ý tưởng" :value="ideas.length" icon="sparkles" :to="`${base}/topics?campaign=${campaign.id}`" />
        <StatCard label="Còn lại" :value="`${weeksLeft} tuần`" icon="clock" :hint="`${campaign.postsPerWeek} bài/tuần`" />
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <AppCard class="lg:col-span-2" :padded="false">
          <div class="px-5 pt-3"><Tabs v-model="tab" :tabs="[{ key: 'articles', label: 'Bài viết', count: articles.length }, { key: 'keywords', label: 'Từ khóa', count: keywords.length }, { key: 'clusters', label: 'Cụm chủ đề', count: clusters.length }]" /></div>
          <template v-if="tab === 'articles'">
            <EmptyState v-if="!articles.length" title="Chưa có bài viết" description="Chọn ý tưởng trong AI Topic Discovery để tạo bài." icon="file" />
            <ul v-else class="divide-y divide-line">
              <li v-for="a in articles" :key="a.id">
                <RouterLink :to="`${base}/articles/${a.id}`" class="flex items-center gap-3 px-5 py-3 hover:bg-surface-muted/60">
                  <span class="min-w-0 flex-1 truncate text-sm">{{ a.title }}</span>
                  <StatusBadge group="article" :value="a.status" />
                </RouterLink>
              </li>
            </ul>
          </template>
          <template v-else-if="tab === 'keywords'">
            <EmptyState v-if="!keywords.length" title="Chưa có từ khóa" icon="key" />
            <ul v-else class="divide-y divide-line">
              <li v-for="k in keywords" :key="k.id" class="flex items-center gap-3 px-5 py-2.5 text-sm">
                <span class="min-w-0 flex-1 truncate">{{ k.keyword }}</span>
                <span class="text-xs text-ink-muted">{{ k.metrics ? `${fmtNumber(k.metrics.volume)} lượt/tháng (giả lập)` : 'Chưa có dữ liệu' }}</span>
                <StatusBadge group="keywordStatus" :value="k.status" />
              </li>
            </ul>
          </template>
          <template v-else>
            <EmptyState v-if="!clusters.length" title="Chưa có cụm chủ đề" icon="layers" />
            <ul v-else class="divide-y divide-line">
              <li v-for="c in clusters" :key="c.id">
                <RouterLink :to="`${base}/clusters?open=${c.id}`" class="flex items-center gap-3 px-5 py-3 text-sm hover:bg-surface-muted/60">
                  <AppIcon name="layers" :size="16" class="text-brand-600" />
                  <span class="min-w-0 flex-1 truncate">{{ c.name }}</span>
                  <StatusBadge group="cluster" :value="c.status" />
                </RouterLink>
              </li>
            </ul>
          </template>
        </AppCard>
        <div class="space-y-6">
          <AppCard title="Phân bổ trạng thái bài viết">
            <DonutChart v-if="byStatus.length" :segments="byStatus" center-label="bài viết" :size="140" />
            <p v-else class="text-sm text-ink-muted">Chưa có dữ liệu.</p>
          </AppCard>
          <AppCard title="Thông tin">
            <dl class="space-y-2 text-sm">
              <div class="flex justify-between gap-2"><dt class="text-ink-muted">Website</dt><dd class="text-right">{{ siteName(campaign.siteId) }}</dd></div>
              <div class="flex justify-between gap-2"><dt class="text-ink-muted">Thời gian</dt><dd class="text-right">{{ fmtDate(campaign.startDate) }} – {{ fmtDate(campaign.endDate) }}</dd></div>
              <div class="flex justify-between gap-2"><dt class="text-ink-muted">Đối tượng</dt><dd class="text-right">{{ campaign.audience || '—' }}</dd></div>
            </dl>
            <RouterLink :to="`${base}/calendar?campaign=${campaign.id}`" class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline"><AppIcon name="calendar" :size="14" /> Xem lịch nội dung</RouterLink>
          </AppCard>
        </div>
      </div>
      <CampaignFormModal :open="editOpen" :campaign="campaign" @close="editOpen = false" />
    </template>
  </div>
</template>
