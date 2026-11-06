<script setup lang="ts">
import { computed, ref } from 'vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppIcon from '@/shared/ui/AppIcon.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { useToast } from '@/shared/composables/useToast'
import PageHeader from '../../ui/PageHeader.vue'
import DataTable from '../../ui/DataTable.vue'
import Toolbar from '../../ui/Toolbar.vue'
import SearchInput from '../../ui/SearchInput.vue'
import FilterSelect from '../../ui/FilterSelect.vue'
import StatusBadge from '../../ui/StatusBadge.vue'
import Tabs from '../../ui/Tabs.vue'
import Toggle from '../../ui/Toggle.vue'
import DemoErrorState from '../../ui/DemoErrorState.vue'
import { db, demoMutation, demoSettings } from '../../store'
import { useDemoQuery } from '../../composables/useDemoQuery'
import { useTable } from '../../composables/useTable'
import { rules, useForm } from '../../composables/useForm'
import { fmtUsd } from '../../format'
import type { DemoAiModel, DemoAiProvider, DemoTaskRoute } from '../../data/types'
import { adminCrumbs, staffAudit } from '../useAdmin'

const toast = useToast()
const { loading, error, reload } = useDemoQuery()
const tab = ref('providers')
const tabs = computed(() => [
  { key: 'providers', label: 'Nhà cung cấp', count: db.aiProviders.length },
  { key: 'models', label: 'Model', count: db.aiModels.length },
  { key: 'routes', label: 'Định tuyến tác vụ', count: db.taskRoutes.length },
])
const providerName = (code: string) => db.aiProviders.find((p) => p.code === code)?.name ?? code
const modelName = (id: string | null) => (id ? (db.aiModels.find((m) => m.id === id)?.name ?? '—') : '—')
const sortedProviders = computed(() => [...db.aiProviders].sort((a, b) => a.priority - b.priority))

async function act(fn: () => void, msg: string) {
  try {
    await demoMutation(fn)
    toast.success(msg)
  } catch (e) {
    toast.error((e as Error).message)
  }
}

// Providers
function toggleProvider(p: DemoAiProvider) {
  if (p.enabled && db.aiProviders.filter((x) => x.enabled).length === 1) return toast.error('Cần ít nhất một nhà cung cấp đang bật.')
  void act(() => {
    p.enabled = !p.enabled
    staffAudit(p.enabled ? 'platform.ai_provider.enabled' : 'platform.ai_provider.disabled', p.name)
  }, p.enabled ? `Đã tắt ${p.name}. Tác vụ chuyển sang model dự phòng.` : `Đã bật ${p.name}.`)
}
function move(p: DemoAiProvider, dir: -1 | 1) {
  const list = sortedProviders.value
  const idx = list.indexOf(p)
  const other = list[idx + dir]
  if (!other) return
  void act(() => {
    ;[p.priority, other.priority] = [other.priority, p.priority]
    staffAudit('platform.ai_provider.reordered', list.map((x) => x.code).join(' > '))
  }, `Đã đổi thứ tự ưu tiên của ${p.name}.`)
}
const testing = ref<string | null>(null)
async function testProvider(p: DemoAiProvider) {
  testing.value = p.code
  try {
    await demoMutation(() => {
      const ok = p.keyConfigured && !demoSettings.failRequests && Math.random() > 0.15
      p.status = ok ? (p.errorRate > 2 ? 'degraded' : 'healthy') : 'down'
      p.latencyP50 = Math.round(900 + Math.random() * 1800)
    }, 900)
    if (p.status === 'down') toast.error(`${p.name}: kiểm tra thất bại (mô phỏng). Kiểm tra API key.`)
    else toast.success(`${p.name} phản hồi trong ${p.latencyP50} ms (mô phỏng – không gọi API thật).`)
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    testing.value = null
  }
}
const keyFor = ref<DemoAiProvider | null>(null)
const keyForm = useForm(() => ({ key: '' }), { key: [rules.required(), (v) => (String(v).trim().length >= 20 ? true : 'API key quá ngắn.')] })
async function saveKey() {
  const p = keyFor.value!
  await keyForm
    .submit(async () => {
      // Demo never stores the key itself; only the "configured" flag.
      await demoMutation(() => {
        p.keyConfigured = true
        staffAudit('platform.ai_provider.key_rotated', p.name)
      })
      toast.success(`Đã cập nhật API key cho ${p.name}. Demo không lưu giá trị key.`)
      keyFor.value = null
    })
    .catch((e: Error) => toast.error(e.message))
}

// Models
const modelTable = useTable(() => db.aiModels, {
  search: (m) => `${m.name} ${providerName(m.providerCode)}`,
  filters: { initial: { provider: '', capability: '', enabled: '' }, match: { provider: (m, v) => m.providerCode === v, capability: (m, v) => m.capabilities.includes(v), enabled: (m, v) => String(m.enabled) === v } },
  sorts: { name: (m) => m.name, input: (m) => m.inputPerMtokUsd, output: (m) => m.outputPerMtokUsd },
  defaultSort: { key: 'name', dir: 'asc' },
})
const providerOptions = computed(() => [{ value: '', label: 'Tất cả' }, ...db.aiProviders.map((p) => ({ value: p.code, label: p.name }))])
const usedBy = (id: string) => db.taskRoutes.filter((r) => r.primaryModelId === id || r.fallbackModelId === id)
function toggleModel(m: DemoAiModel) {
  if (m.enabled && db.taskRoutes.some((r) => r.primaryModelId === m.id)) return toast.error(`${m.name} đang là model chính của: ${usedBy(m.id).map((r) => r.label).join(', ')}. Đổi định tuyến trước.`)
  void act(() => {
    m.enabled = !m.enabled
    db.taskRoutes.filter((r) => r.fallbackModelId === m.id && !m.enabled).forEach((r) => (r.fallbackModelId = null))
    staffAudit(m.enabled ? 'platform.ai_model.enabled' : 'platform.ai_model.disabled', m.name)
  }, m.enabled ? `Đã tắt ${m.name}.` : `Đã bật ${m.name}.`)
}
const priceFor = ref<DemoAiModel | null>(null)
const priceForm = useForm(() => ({ input: '0', output: '0', image: '' }), {
  input: [(v) => (/^\d+(\.\d+)?$/.test(String(v)) ? true : 'Nhập số ≥ 0.')],
  output: [(v) => (/^\d+(\.\d+)?$/.test(String(v)) ? true : 'Nhập số ≥ 0.')],
  image: [(v) => (!v || /^\d+(\.\d+)?$/.test(String(v)) ? true : 'Nhập số ≥ 0 hoặc để trống.')],
})
function openPrice(m: DemoAiModel) {
  priceFor.value = m
  priceForm.reset({ input: String(m.inputPerMtokUsd), output: String(m.outputPerMtokUsd), image: m.imagePriceUsd === null ? '' : String(m.imagePriceUsd) })
}
async function savePrice() {
  const m = priceFor.value!
  await priceForm
    .submit(async () => {
      await demoMutation(() => {
        m.inputPerMtokUsd = Number(priceForm.values.input)
        m.outputPerMtokUsd = Number(priceForm.values.output)
        m.imagePriceUsd = priceForm.values.image === '' ? null : Number(priceForm.values.image)
        staffAudit('platform.ai_model.pricing_updated', m.name)
      })
      toast.success(`Đã cập nhật bảng giá ${m.name}. Áp dụng cho chi phí tính từ bây giờ.`)
      priceFor.value = null
    })
    .catch((e: Error) => toast.error(e.message))
}

// Task routes
const routeFor = ref<DemoTaskRoute | null>(null)
const needsImage = (r: DemoTaskRoute | null) => r?.taskKey.startsWith('media.') ?? false
const modelOptions = computed(() => db.aiModels.filter((m) => m.enabled && (needsImage(routeFor.value) ? m.capabilities.includes('image') : m.capabilities.includes('text'))).map((m) => ({ value: m.id, label: `${m.name} (${providerName(m.providerCode)})` })))
const routeForm = useForm(() => ({ primary: '', fallback: '', maxTokens: '2000', temperature: '0.3' }), {
  primary: [rules.required()],
  fallback: [(v, all) => (!v || v !== all.primary ? true : 'Model dự phòng phải khác model chính.')],
  maxTokens: [(v) => (/^\d+$/.test(String(v)) ? true : 'Số nguyên.'), rules.max(32000)],
  temperature: [(v) => (/^\d(\.\d+)?$/.test(String(v)) && Number(v) <= 1 ? true : 'Từ 0 đến 1.')],
})
function openRoute(r: DemoTaskRoute) {
  routeFor.value = r
  routeForm.reset({ primary: r.primaryModelId, fallback: r.fallbackModelId ?? '', maxTokens: String(r.maxOutputTokens), temperature: String(r.temperature) })
}
async function saveRoute() {
  const r = routeFor.value!
  await routeForm
    .submit(async () => {
      await demoMutation(() => {
        r.primaryModelId = routeForm.values.primary
        r.fallbackModelId = routeForm.values.fallback || null
        r.maxOutputTokens = Number(routeForm.values.maxTokens)
        r.temperature = Number(routeForm.values.temperature)
        staffAudit('platform.ai_route.updated', `${r.taskKey} → ${modelName(r.primaryModelId)}`)
      })
      toast.success(`Đã cập nhật định tuyến “${r.label}”.`)
      routeFor.value = null
    })
    .catch((e: Error) => toast.error(e.message))
}
</script>

<template>
  <div>
    <PageHeader title="AI Providers & Models" description="Nhà cung cấp, model, bảng giá và định tuyến tác vụ của AI Gateway. Demo không gọi API AI thật; giá là số liệu giả lập." :crumbs="adminCrumbs({ label: 'AI Providers & Models' })" />

    <LoadingState v-if="loading" :rows="5" />
    <AppCard v-else-if="error"><DemoErrorState :error="error" @retry="reload" /></AppCard>
    <div v-else class="space-y-6">
      <Tabs v-model="tab" :tabs="tabs" />

      <div v-if="tab === 'providers'" class="grid gap-4 lg:grid-cols-3">
        <AppCard v-for="(p, i) in sortedProviders" :key="p.code">
          <div class="flex items-start justify-between gap-2">
            <div>
              <p class="text-xs text-ink-muted">Ưu tiên #{{ i + 1 }}</p>
              <p class="text-lg font-semibold">{{ p.name }}</p>
            </div>
            <StatusBadge group="provider" :value="p.enabled ? p.status : 'down'" />
          </div>
          <dl class="my-4 grid grid-cols-3 gap-2 text-center text-sm">
            <div class="rounded-lg bg-surface-muted p-2"><dt class="text-xs text-ink-muted">P50</dt><dd class="font-semibold tabular-nums">{{ (p.latencyP50 / 1000).toFixed(1) }}s</dd></div>
            <div class="rounded-lg bg-surface-muted p-2"><dt class="text-xs text-ink-muted">Lỗi</dt><dd class="font-semibold tabular-nums" :class="p.errorRate > 2 ? 'text-danger-600' : ''">{{ p.errorRate.toFixed(1) }}%</dd></div>
            <div class="rounded-lg bg-surface-muted p-2"><dt class="text-xs text-ink-muted">Model</dt><dd class="font-semibold">{{ db.aiModels.filter((m) => m.providerCode === p.code).length }}</dd></div>
          </dl>
          <p class="mb-3 flex items-center gap-1.5 text-xs" :class="p.keyConfigured ? 'text-success-600' : 'text-danger-600'"><AppIcon :name="p.keyConfigured ? 'lock' : 'alert'" :size="14" />{{ p.keyConfigured ? 'API key đã cấu hình (lưu mã hóa)' : 'Chưa có API key' }}</p>
          <Toggle :model-value="p.enabled" label="Đang bật" @update:model-value="toggleProvider(p)" />
          <div class="mt-3 flex flex-wrap gap-2">
            <AppButton size="sm" variant="secondary" :loading="testing === p.code" @click="testProvider(p)">Kiểm tra</AppButton>
            <AppButton size="sm" variant="secondary" @click="(keyForm.reset(), (keyFor = p))">Đổi API key</AppButton>
            <AppButton size="sm" variant="ghost" :disabled="i === 0" title="Tăng ưu tiên" @click="move(p, -1)"><AppIcon name="chevronUp" :size="16" /></AppButton>
            <AppButton size="sm" variant="ghost" :disabled="i === sortedProviders.length - 1" title="Giảm ưu tiên" @click="move(p, 1)"><AppIcon name="chevronDown" :size="16" /></AppButton>
          </div>
        </AppCard>
      </div>

      <AppCard v-else-if="tab === 'models'" :padded="false">
        <Toolbar :count="modelTable.total.value" :filtered="modelTable.hasActiveFilters.value" @clear="modelTable.clearFilters()">
          <SearchInput v-model="modelTable.q.value" placeholder="Tìm model…" />
          <FilterSelect v-model="modelTable.filters.provider" label="Nhà cung cấp" :options="providerOptions" />
          <FilterSelect v-model="modelTable.filters.capability" label="Khả năng" :options="[{ value: '', label: 'Tất cả' }, { value: 'text', label: 'Văn bản' }, { value: 'json', label: 'JSON schema' }, { value: 'image', label: 'Ảnh' }]" />
          <FilterSelect v-model="modelTable.filters.enabled" label="Trạng thái" :options="[{ value: '', label: 'Tất cả' }, { value: 'true', label: 'Đang bật' }, { value: 'false', label: 'Đang tắt' }]" />
        </Toolbar>
        <DataTable :table="modelTable" :columns="[{ key: 'name', label: 'Model', sortable: true }, { key: 'caps', label: 'Khả năng', hideBelow: 'md' }, { key: 'input', label: 'Input / 1M token', sortable: true, align: 'right', hideBelow: 'sm' }, { key: 'output', label: 'Output / 1M token', sortable: true, align: 'right', hideBelow: 'sm' }, { key: 'used', label: 'Dùng cho', hideBelow: 'lg' }, { key: 'actions', label: '', align: 'right' }]" empty-title="Không có model phù hợp">
          <template #cell-name="{ row }"><p class="font-mono text-sm" :class="row.enabled ? '' : 'text-ink-subtle line-through'">{{ row.name }}</p><p class="text-xs text-ink-muted">{{ providerName(row.providerCode) }}</p></template>
          <template #cell-caps="{ row }"><span class="text-xs text-ink-muted">{{ row.capabilities.join(', ') }}</span></template>
          <template #cell-input="{ row }"><span class="tabular-nums">{{ row.imagePriceUsd !== null ? `${fmtUsd(row.imagePriceUsd, 3)}/ảnh` : fmtUsd(row.inputPerMtokUsd) }}</span></template>
          <template #cell-output="{ row }"><span class="tabular-nums">{{ row.imagePriceUsd !== null ? '—' : fmtUsd(row.outputPerMtokUsd) }}</span></template>
          <template #cell-used="{ row }"><span class="text-xs text-ink-muted">{{ usedBy(row.id).map((r) => r.label).join(', ') || '—' }}</span></template>
          <template #cell-actions="{ row }">
            <div class="flex justify-end gap-1">
              <AppButton size="sm" variant="ghost" @click="openPrice(row)">Giá</AppButton>
              <AppButton size="sm" variant="ghost" @click="toggleModel(row)">{{ row.enabled ? 'Tắt' : 'Bật' }}</AppButton>
            </div>
          </template>
        </DataTable>
        <p class="border-t border-line px-5 py-3 text-xs text-ink-subtle">Giá trong demo là số liệu giả lập để minh họa cách tính chi phí. Bản thật đồng bộ từ trang giá của nhà cung cấp.</p>
      </AppCard>

      <AppCard v-else :padded="false">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[640px] text-sm">
            <thead class="bg-surface-muted text-left text-xs text-ink-muted">
              <tr><th class="px-5 py-2.5 font-medium">Tác vụ</th><th class="px-3 py-2.5 font-medium">Model chính</th><th class="px-3 py-2.5 font-medium">Dự phòng</th><th class="px-3 py-2.5 text-right font-medium">Max tokens</th><th class="px-3 py-2.5 text-right font-medium">Temp.</th><th class="px-5 py-2.5" /></tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="r in db.taskRoutes" :key="r.taskKey">
                <td class="px-5 py-3"><p class="font-medium">{{ r.label }}</p><code class="text-xs text-ink-muted">{{ r.taskKey }}</code></td>
                <td class="px-3 py-3 font-mono text-xs">{{ modelName(r.primaryModelId) }}</td>
                <td class="px-3 py-3 font-mono text-xs text-ink-muted">{{ modelName(r.fallbackModelId) }}</td>
                <td class="px-3 py-3 text-right tabular-nums">{{ r.maxOutputTokens || '—' }}</td>
                <td class="px-3 py-3 text-right tabular-nums">{{ r.temperature }}</td>
                <td class="px-5 py-3 text-right"><AppButton size="sm" variant="ghost" @click="openRoute(r)"><AppIcon name="edit" :size="16" /></AppButton></td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>
    </div>

    <AppModal :open="!!keyFor" :title="`API key – ${keyFor?.name}`" width="sm" @close="keyFor = null">
      <form id="key" novalidate @submit.prevent="saveKey">
        <AppInput v-model="keyForm.values.key" type="password" label="API key mới" autocomplete="off" :error="keyForm.errors.key" hint="Bản thật lưu mã hóa phía server, không bao giờ hiển thị lại. Demo không lưu giá trị này." />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="keyFor = null">Hủy</AppButton>
        <AppButton type="submit" form="key" :loading="keyForm.submitting.value">Lưu</AppButton>
      </template>
    </AppModal>

    <AppModal :open="!!priceFor" :title="`Bảng giá – ${priceFor?.name}`" width="sm" @close="priceFor = null">
      <form id="price" class="space-y-3" novalidate @submit.prevent="savePrice">
        <AppInput v-model="priceForm.values.input" label="USD / 1M token input" :error="priceForm.errors.input" />
        <AppInput v-model="priceForm.values.output" label="USD / 1M token output" :error="priceForm.errors.output" />
        <AppInput v-model="priceForm.values.image" label="USD / ảnh (nếu là model ảnh)" :error="priceForm.errors.image" />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="priceFor = null">Hủy</AppButton>
        <AppButton type="submit" form="price" :loading="priceForm.submitting.value">Lưu</AppButton>
      </template>
    </AppModal>

    <AppModal :open="!!routeFor" :title="`Định tuyến – ${routeFor?.label}`" @close="routeFor = null">
      <form id="route" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="saveRoute">
        <AppSelect v-model="routeForm.values.primary" label="Model chính" :options="modelOptions" :error="routeForm.errors.primary" class="sm:col-span-2" />
        <AppSelect v-model="routeForm.values.fallback" label="Model dự phòng" :options="[{ value: '', label: 'Không dùng' }, ...modelOptions]" :error="routeForm.errors.fallback" class="sm:col-span-2" />
        <AppInput v-model="routeForm.values.maxTokens" label="Max output tokens" :error="routeForm.errors.maxTokens" />
        <AppInput v-model="routeForm.values.temperature" label="Temperature (0–1)" :error="routeForm.errors.temperature" />
        <p class="text-xs text-ink-muted sm:col-span-2">Chỉ hiện model đang bật và có khả năng phù hợp. Kết quả luôn được kiểm tra theo JSON schema trước khi lưu.</p>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="routeFor = null">Hủy</AppButton>
        <AppButton type="submit" form="route" :loading="routeForm.submitting.value">Lưu</AppButton>
      </template>
    </AppModal>
  </div>
</template>
