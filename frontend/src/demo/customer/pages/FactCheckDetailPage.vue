<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import DTextarea from '../../ui/DTextarea.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import DonutChart from '../../ui/charts/DonutChart.vue'
import { db, demoMutation } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { rules, useForm } from '../../composables/useForm'
import { label, options } from '../../labels'
import { fmtDateTime } from '../../format'
import { downloadFile } from '../../download'
import type { DemoClaim } from '../../data/types'
import { useWorkspace } from '../useWorkspace'

const route = useRoute()
const toast = useToast()
const { ws, base, mine, crumbs } = useWorkspace()
const { loading, error, reload } = useDemoQuery()

const report = computed(() => mine(db.factChecks).find((r) => r.id === route.params.reportId))
const article = computed(() => db.articles.find((a) => a.id === report.value?.articleId))
const statusFilter = ref('')
const severityFilter = ref('')
const claims = computed(() => (report.value?.claims ?? []).filter((c) => (!statusFilter.value || c.status === statusFilter.value) && (!severityFilter.value || c.severity === severityFilter.value)))
const dist = computed(() =>
  (['supported', 'needs_human', 'unsupported', 'contradicted'] as const)
    .map((s, i) => ({ name: label('claim', s), value: report.value?.claims.filter((c) => c.status === s).length ?? 0, color: ['#1baf7a', '#eda100', '#e34948', '#eb6834'][i] }))
    .filter((x) => x.value),
)

function recompute() {
  const r = report.value
  const a = article.value
  if (!r) return
  const supported = r.claims.filter((c) => c.status === 'supported').length
  r.score = Math.round((supported / Math.max(1, r.claims.length)) * 100)
  const blocking = r.claims.some((c) => ['unsupported', 'contradicted'].includes(c.status) && c.severity === 'high')
  r.result = blocking ? 'failed' : 'passed'
  if (a && !r.overriddenBy && a.status !== 'published') {
    a.verificationStatus = r.result
    if (r.result === 'passed' && a.status === 'needs_revision') a.status = 'ready_for_review'
  }
}

// Resolve a claim manually (human review)
const resolving = ref<DemoClaim | null>(null)
const resolveForm = useForm(() => ({ decision: 'supported', note: '' }), { note: [rules.required('Ghi chú cách bạn đã xác minh.'), rules.minLength(10)] })
function openResolve(c: DemoClaim) {
  resolving.value = c
  resolveForm.reset()
}
async function saveResolve() {
  const c = resolving.value
  if (!c) return
  await resolveForm
    .submit(async () => {
      await demoMutation(() => {
        c.status = resolveForm.values.decision as DemoClaim['status']
        c.resolution = `${ws.value.ownerName}: ${resolveForm.values.note}`
        recompute()
      })
      toast.success('Đã cập nhật nhận định.')
      resolving.value = null
    })
    .catch((e: Error) => toast.error(e.message))
}
async function removeClaim(c: DemoClaim) {
  const r = report.value
  if (!r) return
  try {
    await demoMutation(() => {
      c.status = 'supported'
      c.resolution = 'Đã gỡ nhận định khỏi bài viết (biên tập viên).'
      recompute()
    })
    toast.success('Đã đánh dấu nhận định đã được gỡ khỏi bài.')
  } catch (e) {
    toast.error((e as Error).message)
  }
}

// Override (explicit reason, audited)
const overrideOpen = ref(false)
const overrideForm = useForm(() => ({ reason: '', confirm: false }), {
  reason: [rules.required(), rules.minLength(20)],
  confirm: [(v) => (v ? true : 'Bạn cần xác nhận chịu trách nhiệm về nội dung.')],
})
async function saveOverride() {
  const r = report.value
  const a = article.value
  if (!r || !a) return
  await overrideForm
    .submit(async () => {
      await demoMutation(() => {
        r.overriddenBy = ws.value.ownerName
        r.overrideReason = overrideForm.values.reason
        a.verificationStatus = 'overridden'
        if (a.status === 'needs_revision') a.status = 'ready_for_review'
        db.auditLogs.unshift({ id: `log_${Date.now()}`, workspaceId: ws.value.id, actorName: ws.value.ownerName, actorType: 'user', action: 'factcheck.overridden', subject: 'Báo cáo kiểm chứng', ip: '203.0.113.10', createdAt: new Date().toISOString() })
      })
      toast.success('Đã duyệt thủ công. Hành động được ghi vào nhật ký kiểm toán.')
      overrideOpen.value = false
    })
    .catch((e: Error) => toast.error(e.message))
}
function exportJson() {
  const r = report.value
  if (!r) return
  downloadFile(`fact-check-${r.id}.json`, JSON.stringify({ demo: true, article: article.value?.title, ...r }, null, 2), 'application/json')
  toast.success('Đã xuất báo cáo (JSON, dữ liệu demo).')
}
</script>

<template>
  <div>
    <LoadingState v-if="loading" :rows="5" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <AppCard v-else-if="!report"><EmptyState title="Không tìm thấy báo cáo" icon="shieldCheck" /></AppCard>
    <template v-else>
      <PageHeader :title="`Báo cáo kiểm chứng v${report.versionNo}`" :description="article?.title" :crumbs="crumbs({ label: 'Fact-Check Report', to: `${base}/fact-checks` }, { label: `v${report.versionNo}` })">
        <template #actions>
          <AppBadge :tone="report.overriddenBy ? 'warning' : report.result === 'passed' ? 'success' : 'danger'">{{ report.overriddenBy ? 'Duyệt thủ công' : report.result === 'passed' ? 'Đạt' : 'Không đạt' }}</AppBadge>
          <AppButton variant="secondary" @click="exportJson"><AppIcon name="download" :size="16" /> Xuất JSON</AppButton>
          <RouterLink v-if="article" :to="`${base}/articles/${article.id}`"><AppButton variant="secondary"><AppIcon name="pen" :size="16" /> Mở bài viết</AppButton></RouterLink>
          <AppButton v-if="report.result === 'failed' && !report.overriddenBy" variant="danger" @click="overrideOpen = true">Duyệt thủ công…</AppButton>
        </template>
      </PageHeader>

      <div v-if="report.overriddenBy" class="mb-4 rounded-lg border border-amber-200 bg-warning-50 p-4 text-sm text-amber-900">
        <strong>Đã duyệt thủ công bởi {{ report.overriddenBy }}.</strong> Lý do: {{ report.overrideReason }}
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
          <AppCard :padded="false">
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
              <h2 class="mr-auto text-base font-semibold">Nhận định ({{ claims.length }})</h2>
              <FilterSelect v-model="statusFilter" label="Trạng thái" :options="options('claim')" />
              <FilterSelect v-model="severityFilter" label="Mức độ" :options="[{ value: '', label: 'Tất cả' }, { value: 'high', label: 'Cao' }, { value: 'medium', label: 'Trung bình' }, { value: 'low', label: 'Thấp' }]" />
            </div>
            <EmptyState v-if="!claims.length" title="Không có nhận định phù hợp bộ lọc" icon="search" />
            <ul v-else class="divide-y divide-line">
              <li v-for="c in claims" :key="c.id" class="px-5 py-4">
                <div class="mb-2 flex flex-wrap items-start gap-2">
                  <p class="min-w-0 flex-1 text-sm font-medium">“{{ c.text }}”</p>
                  <StatusBadge group="severity" :value="c.severity" />
                  <StatusBadge group="claim" :value="c.status" />
                </div>
                <ul v-if="c.evidence.length" class="space-y-2">
                  <li v-for="(e, i) in c.evidence" :key="i" class="rounded-lg bg-surface-muted p-3 text-xs">
                    <p class="flex items-center gap-1 font-medium"><AppIcon :name="e.quoteFound ? 'check' : 'alert'" :size="12" :class="e.quoteFound ? 'text-success-600' : 'text-warning-600'" /> {{ e.sourceTitle }} <span class="text-ink-subtle">({{ e.stance === 'supports' ? 'ủng hộ' : 'phản bác' }})</span></p>
                    <p class="mt-1 italic text-ink-muted">{{ e.quote }}</p>
                    <a :href="e.url" target="_blank" rel="noopener noreferrer nofollow" class="mt-1 inline-block text-brand-700 hover:underline">{{ e.url }}</a>
                  </li>
                </ul>
                <p v-else class="text-xs text-danger-600">Không tìm thấy nguồn nào trong danh sách nguồn đã tải xác nhận nhận định này.</p>
                <p v-if="c.resolution" class="mt-2 text-xs text-ink-muted">✍️ {{ c.resolution }}</p>
                <div v-if="c.status !== 'supported'" class="mt-3 flex gap-2">
                  <AppButton size="sm" variant="secondary" @click="openResolve(c)">Xác minh thủ công</AppButton>
                  <AppButton size="sm" variant="ghost" @click="removeClaim(c)">Đã gỡ khỏi bài</AppButton>
                </div>
              </li>
            </ul>
          </AppCard>
        </div>
        <div class="space-y-6">
          <AppCard title="Tổng quan">
            <DonutChart :segments="dist" center-label="nhận định" :size="140" />
            <dl class="mt-4 space-y-2 border-t border-line pt-4 text-sm">
              <div class="flex justify-between"><dt class="text-ink-muted">Tỷ lệ có nguồn</dt><dd class="font-semibold">{{ report.score }}%</dd></div>
              <div class="flex justify-between"><dt class="text-ink-muted">Nguồn đã tải</dt><dd>{{ report.sourcesCount }}</dd></div>
              <div class="flex justify-between"><dt class="text-ink-muted">Thời gian</dt><dd>{{ fmtDateTime(report.createdAt) }}</dd></div>
            </dl>
          </AppCard>
          <AppCard title="Quy tắc kiểm chứng">
            <ul class="list-disc space-y-1 pl-4 text-xs text-ink-muted">
              <li>Nhận định mức <strong>Cao</strong> (số liệu, y tế, pháp lý, giá) không có nguồn → bài <strong>không đạt</strong>.</li>
              <li>Trích dẫn phải xuất hiện nguyên văn trong nguồn đã tải.</li>
              <li>Chỉ dùng nguồn trong danh sách đã tải; URL ngoài danh sách bị loại.</li>
              <li>Nguồn trong demo là địa chỉ mẫu <code>nguon-mau.example</code>.</li>
            </ul>
          </AppCard>
        </div>
      </div>

      <AppModal :open="!!resolving" title="Xác minh thủ công" @close="resolving = null">
        <form id="resolve" class="space-y-4" @submit.prevent="saveResolve">
          <p class="rounded-lg bg-surface-muted p-3 text-sm">“{{ resolving?.text }}”</p>
          <fieldset class="space-y-2 text-sm">
            <legend class="mb-1 font-medium">Kết luận</legend>
            <label class="flex items-center gap-2"><input v-model="resolveForm.values.decision" type="radio" value="supported" /> Đúng – đã có nguồn xác nhận</label>
            <label class="flex items-center gap-2"><input v-model="resolveForm.values.decision" type="radio" value="contradicted" /> Sai – mâu thuẫn với nguồn</label>
          </fieldset>
          <DTextarea v-model="resolveForm.values.note" label="Ghi chú xác minh" :rows="3" :error="resolveForm.errors.note" :maxlength="300" />
        </form>
        <template #footer>
          <AppButton variant="secondary" @click="resolving = null">Hủy</AppButton>
          <AppButton type="submit" form="resolve" :loading="resolveForm.submitting.value">Lưu</AppButton>
        </template>
      </AppModal>

      <AppModal :open="overrideOpen" title="Duyệt thủ công báo cáo không đạt" @close="overrideOpen = false">
        <form id="override" class="space-y-4" @submit.prevent="saveOverride">
          <p class="rounded-lg bg-danger-50 p-3 text-sm text-danger-600">Bạn đang cho phép bài viết tiếp tục dù có nhận định chưa được xác minh. Hành động này được ghi vào nhật ký kiểm toán.</p>
          <DTextarea v-model="overrideForm.values.reason" label="Lý do (tối thiểu 20 ký tự)" :rows="3" :error="overrideForm.errors.reason" :maxlength="500" />
          <label class="flex items-start gap-2 text-sm"><input v-model="overrideForm.values.confirm" type="checkbox" class="mt-0.5" /> Tôi xác nhận đã kiểm tra và chịu trách nhiệm về tính chính xác của nội dung.</label>
          <p v-if="overrideForm.errors.confirm" class="text-xs text-danger-600">{{ overrideForm.errors.confirm }}</p>
        </form>
        <template #footer>
          <AppButton variant="secondary" @click="overrideOpen = false">Hủy</AppButton>
          <AppButton type="submit" form="override" variant="danger" :loading="overrideForm.submitting.value">Duyệt thủ công</AppButton>
        </template>
      </AppModal>
    </template>
  </div>
</template>
