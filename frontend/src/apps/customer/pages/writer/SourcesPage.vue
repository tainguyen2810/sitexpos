<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { writerApi, type SourceRule, type SourceRuleMode } from '@/shared/api/writer'
import { useAsync } from '@/shared/composables/useAsync'
import { useApiForm } from '@/shared/composables/useApiForm'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import WriterNav from './components/WriterNav.vue'
import { useWriterErrors, useWriterPerms } from './useWriter'

/** Workspace source filter: preferred / blocked domains (FACT-CHECK-POLICY §7). */
const PREFER_BONUS = 15
const { t } = useI18n()
const { workspaceId: id, readOnly } = useWriterPerms()
const { toast, toastError } = useWriterErrors()
const rules = useAsync(() => writerApi.sourceRules(id.value))
void rules.run()

const canManage = computed(() => !!rules.data.value?.meta.can_manage && !readOnly.value)
const byMode = (m: SourceRuleMode) => (rules.data.value?.data ?? []).filter((r) => r.mode === m)
const groups = computed(() => (['prefer', 'block'] as const).map((mode) => ({ mode, items: byMode(mode) })))
const minIndependent = computed(() => rules.data.value?.meta.high_risk_min_independent_sources ?? 2)

const f = reactive<{ domain: string; mode: SourceRuleMode; note: string }>({ domain: '', mode: 'prefer', note: '' })
const modeOptions = computed(() => [{ value: 'prefer', label: t('writer.sourceRules.prefer') }, { value: 'block', label: t('writer.sourceRules.block') }])
const { errors, formError, submitting, submit } = useApiForm()
async function add() {
  const r = await submit(() => writerApi.saveSourceRule(id.value, { domain: f.domain, mode: f.mode, note: f.note || null }))
  if (r) {
    toast.success(t('writer.sourceRules.saved', { domain: r.domain }))
    f.domain = ''
    f.note = ''
    await rules.run()
  }
}

const removing = ref<SourceRule | null>(null)
const busy = ref(false)
async function confirmRemove() {
  if (!removing.value) return
  busy.value = true
  try {
    await writerApi.deleteSourceRule(id.value, removing.value.id)
    toast.success(t('writer.sourceRules.removed', { domain: removing.value.domain }))
    removing.value = null
    await rules.run()
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('writer.sourceRules.title')" :description="t('writer.sourceRules.subtitle')" />
    <WriterNav active="sources" />

    <LoadingState v-if="rules.loading.value && !rules.data.value" />
    <ErrorState v-else-if="rules.error.value" :error="rules.error.value" @retry="rules.run" />
    <div v-else-if="rules.data.value" class="space-y-4">
      <AppCard>
        <h2 class="text-sm font-semibold">{{ t('writer.sourceRules.how') }}</h2>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-ink-muted" data-testid="source-rules-how">
          <li>{{ t('writer.sourceRules.howPrefer', { bonus: PREFER_BONUS }) }}</li>
          <li>{{ t('writer.sourceRules.howBlock') }}</li>
          <li>{{ minIndependent > 1 ? t('writer.sourceRules.howIndependent', { n: minIndependent }) : t('writer.sourceRules.howIndependentOff') }}</li>
          <li>{{ t('writer.sourceRules.howSerp') }}</li>
        </ul>
      </AppCard>

      <AppCard v-if="canManage">
        <form class="grid gap-3 sm:grid-cols-[2fr_1fr_2fr_auto] sm:items-end" data-testid="source-rule-form" @submit.prevent="add">
          <AppInput v-model="f.domain" :label="t('writer.sourceRules.domain')" :placeholder="t('writer.sourceRules.domainPlaceholder')" required :error="errors.domain" />
          <AppSelect v-model="f.mode" :label="t('writer.sourceRules.mode')" :options="modeOptions" :error="errors.mode" />
          <AppInput v-model="f.note" :label="t('writer.sourceRules.note')" :error="errors.note" />
          <AppButton type="submit" :loading="submitting">{{ t('writer.sourceRules.add') }}</AppButton>
        </form>
        <p class="mt-2 text-xs text-ink-muted">{{ t('writer.sourceRules.subdomains') }} · {{ t('writer.sourceRules.count', { n: rules.data.value.data.length, max: rules.data.value.meta.max_rules }) }}</p>
        <p v-if="formError" class="mt-2 text-sm text-danger-600" role="alert">{{ formError }}</p>
      </AppCard>
      <p v-else class="rounded-lg bg-surface-muted px-4 py-3 text-sm text-ink-muted">{{ t('writer.sourceRules.readOnly') }}</p>

      <div class="grid gap-4 lg:grid-cols-2">
        <AppCard v-for="g in groups" :key="g.mode" :padded="false">
          <h2 class="border-b border-line px-5 py-3 text-sm font-semibold">{{ g.mode === 'prefer' ? '⭐ ' : '⛔ ' }}{{ t(`writer.sourceRules.${g.mode}`) }} ({{ g.items.length }})</h2>
          <EmptyState v-if="!g.items.length" :icon="g.mode === 'prefer' ? 'star' : 'lock'" :title="t(g.mode === 'prefer' ? 'writer.sourceRules.emptyPrefer' : 'writer.sourceRules.emptyBlock')" />
          <div v-else class="overflow-x-auto">
            <table class="data-table w-full text-left text-sm" :data-testid="`source-rules-${g.mode}`">
              <thead class="border-b border-line text-xs text-ink-muted">
                <tr><th class="px-5 py-2">{{ t('writer.sourceRules.domain') }}</th><th class="px-2">{{ t('writer.sourceRules.note') }}</th><th v-if="canManage" class="px-5" /></tr>
              </thead>
              <tbody class="divide-y divide-line">
                <tr v-for="r in g.items" :key="r.id">
                  <td class="px-5 py-2 font-medium">{{ r.domain }}</td>
                  <td class="max-w-[14rem] truncate px-2 text-ink-muted" :title="r.note ?? ''">{{ r.note ?? '—' }}</td>
                  <td v-if="canManage" class="px-5 text-right"><AppButton variant="ghost" size="sm" @click="removing = r">{{ t('writer.sourceRules.remove') }}</AppButton></td>
                </tr>
              </tbody>
            </table>
          </div>
        </AppCard>
      </div>
      <p v-if="rules.data.value.meta.platform_blocked.length" class="text-xs text-ink-muted">{{ t('writer.sourceRules.platform', { list: rules.data.value.meta.platform_blocked.join(', ') }) }}</p>
    </div>

    <ConfirmDialog
:open="!!removing" :title="t('writer.sourceRules.remove')" :message="t('writer.sourceRules.removeConfirm', { domain: removing?.domain ?? '' })"
      danger :loading="busy" @close="removing = null" @confirm="confirmRemove" />
  </div>
</template>
