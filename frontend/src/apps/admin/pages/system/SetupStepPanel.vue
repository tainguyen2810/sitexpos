<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { systemApi, healthTone, formatBytes, type HealthCheck, type SetupStep } from '@/shared/api/system'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppInput from '@/shared/ui/AppInput.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import Toggle from '@/shared/ui/Toggle.vue'
import { formatTime, useSystem } from './useSystem'

const props = defineProps<{ step: SetupStep }>()
const emit = defineEmits<{ changed: [] }>()
const { t, toast, toastError, fieldErrors } = useSystem()

type Row = Record<string, unknown>
const d = computed(() => props.step.data as Row)
const list = (k: string) => (Array.isArray(d.value[k]) ? (d.value[k] as Row[]) : [])
const str = (v: unknown) => (v === null || v === undefined ? '' : String(v))
const busy = ref(false)
const errors = ref<Record<string, string>>({})

async function act(fn: () => Promise<unknown>, success: string) {
  busy.value = true
  errors.value = {}
  try {
    await fn()
    toast.success(success)
    emit('changed')
  } catch (e) {
    errors.value = fieldErrors(e)
    toastError(e)
  } finally {
    busy.value = false
  }
}

// --- general
const general = reactive({
  brand_name: str(d.value.brand_name), support_email: str(d.value.support_email), default_locale: str(d.value.default_locale) || 'vi',
  default_timezone: str(d.value.default_timezone) || 'Asia/Ho_Chi_Minh', company_name: str(d.value.company_name), company_tax_code: str(d.value.company_tax_code),
  company_address: str(d.value.company_address), contact_phone: str(d.value.contact_phone), contact_email: str(d.value.contact_email),
})
const nul = (v: string) => (v.trim() === '' ? null : v.trim())
function saveGeneral() {
  return act(() => systemApi.updateGeneral({
    brand_name: general.brand_name.trim(), support_email: general.support_email.trim(), default_locale: general.default_locale, default_timezone: general.default_timezone,
    company_name: nul(general.company_name), company_tax_code: nul(general.company_tax_code), company_address: nul(general.company_address),
    contact_phone: nul(general.contact_phone), contact_email: nul(general.contact_email),
  }), t('sys.common.saved'))
}
const logoInput = ref<HTMLInputElement | null>(null)
function uploadLogo() {
  const f = logoInput.value?.files?.[0]
  if (f) void act(() => systemApi.uploadLogo(f), t('sys.setup.general.logoUploaded'))
}

// --- email
const mail = reactive({
  mailer: d.value.mailer === 'smtp' ? 'smtp' : 'env', host: str(d.value.host), port: str(d.value.port) || '587', encryption: str(d.value.encryption) || 'tls',
  username: str(d.value.username), password: '', from_address: str(d.value.from_address), from_name: str(d.value.from_name), test_to: '',
})
function saveMail() {
  const body = mail.mailer === 'env'
    ? { mailer: 'env' as const }
    : { mailer: 'smtp' as const, host: mail.host.trim(), port: Number(mail.port), encryption: mail.encryption as 'tls' | 'ssl' | 'none', username: nul(mail.username),
        from_address: mail.from_address.trim(), from_name: nul(mail.from_name), ...(mail.password ? { password: mail.password } : {}) }
  return act(async () => {
    await systemApi.updateMail(body)
    mail.password = ''
  }, t('sys.common.saved'))
}
const lastTest = computed(() => (d.value.last_test && typeof d.value.last_test === 'object' ? (d.value.last_test as Row) : null))

// --- google
const google = reactive({ client_id: str(d.value.client_id), client_secret: '', ga4_enabled: d.value.ga4_enabled === true })
function saveGoogle() {
  return act(async () => {
    await systemApi.updateGoogle({ client_id: nul(google.client_id), ga4_enabled: google.ga4_enabled, ...(google.client_secret ? { client_secret: google.client_secret } : {}) })
    google.client_secret = ''
  }, t('sys.common.saved'))
}

// --- plans
const draftResult = ref<string | null>(null)
function draftPlans() {
  return act(async () => {
    const r = await systemApi.draftPlans()
    draftResult.value = r.created.length ? t('sys.setup.plans.created', { list: r.created.join(', ') }) : t('sys.setup.plans.none')
  }, t('sys.common.saved'))
}

// --- security / queue
const securityItems = computed(() => Object.entries((d.value.items ?? {}) as Record<string, boolean>))
const queueChecks = computed(() => list('checks') as unknown as HealthCheck[])
const blocking = computed(() => (Array.isArray(d.value.blocking) ? (d.value.blocking as string[]) : []))
</script>

<template>
  <AppCard :title="t(`sys.setup.steps.${step.key}`)" :data-testid="`setup-panel-${step.key}`">
    <!-- 1. General -->
    <form v-if="step.key === 'general'" class="grid gap-4 md:grid-cols-2" @submit.prevent="saveGeneral">
      <AppInput v-model="general.brand_name" :label="t('sys.setup.general.brandName')" :error="errors.brand_name" required />
      <AppInput v-model="general.support_email" type="email" :label="t('sys.setup.general.supportEmail')" :error="errors.support_email" required />
      <AppSelect v-model="general.default_locale" :label="t('sys.setup.general.locale')" :options="[{ value: 'vi', label: 'Tiếng Việt' }, { value: 'en', label: 'English' }]" />
      <AppInput v-model="general.default_timezone" :label="t('sys.setup.general.timezone')" :error="errors.default_timezone" required />
      <AppInput v-model="general.company_name" :label="t('sys.setup.general.companyName')" :error="errors.company_name" />
      <AppInput v-model="general.company_tax_code" :label="t('sys.setup.general.taxCode')" :error="errors.company_tax_code" />
      <AppInput v-model="general.company_address" :label="t('sys.setup.general.address')" :error="errors.company_address" class="md:col-span-2" />
      <AppInput v-model="general.contact_phone" :label="t('sys.setup.general.phone')" :error="errors.contact_phone" />
      <AppInput v-model="general.contact_email" type="email" :label="t('sys.setup.general.contactEmail')" :error="errors.contact_email" />
      <div class="md:col-span-2 space-y-2">
        <p class="text-sm font-medium">{{ t('sys.setup.general.logo') }}</p>
        <div class="flex flex-wrap items-center gap-3">
          <img v-if="d.logo_url" :src="str(d.logo_url)" alt="" class="h-10 w-auto rounded border border-line bg-white p-1" />
          <input ref="logoInput" type="file" accept="image/png,image/jpeg,image/webp" class="text-sm" data-testid="logo-input" />
          <AppButton size="sm" variant="secondary" :loading="busy" @click="uploadLogo">{{ t('sys.setup.general.uploadLogo') }}</AppButton>
        </div>
        <p v-if="errors.logo" class="text-sm text-danger-600">{{ errors.logo }}</p>
      </div>
      <p class="md:col-span-2 text-xs text-ink-muted">{{ t('sys.setup.general.appUrl') }}: <span class="font-mono">{{ str(d.app_url) }}</span></p>
      <div class="md:col-span-2 flex justify-end"><AppButton type="submit" :loading="busy" data-testid="save-general">{{ t('common.save') }}</AppButton></div>
    </form>

    <!-- 2. AI providers -->
    <div v-else-if="step.key === 'ai_providers'" class="space-y-4">
      <p class="text-sm text-ink-muted">{{ t('sys.setup.ai.intro') }}</p>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-left text-xs text-ink-muted"><tr><th class="py-2">{{ t('sys.setup.ai.provider') }}</th><th>{{ t('sys.setup.ai.hasKey') }}</th><th>{{ t('sys.setup.ai.keySource') }}</th><th>{{ t('sys.setup.ai.circuit') }}</th><th>{{ t('sys.setup.ai.ready') }}</th></tr></thead>
          <tbody class="divide-y divide-line">
            <tr v-for="p in list('providers')" :key="str(p.code)">
              <td class="py-2 font-medium">{{ str(p.name) }}</td>
              <td>{{ p.has_key ? t('sys.common.yes') : t('sys.common.no') }}</td>
              <td class="font-mono text-xs">{{ str(p.key_source) }}</td>
              <td class="font-mono text-xs">{{ str(p.circuit) }}</td>
              <td><AppBadge :tone="p.ready ? 'success' : 'neutral'">{{ p.ready ? t('sys.common.yes') : t('sys.common.no') }}</AppBadge></td>
            </tr>
          </tbody>
        </table>
      </div>
      <RouterLink to="/ai/settings" class="text-sm font-medium text-brand-700 hover:underline">{{ t('sys.common.open') }} →</RouterLink>
    </div>

    <!-- 3. AI models per task -->
    <div v-else-if="step.key === 'ai_models'" class="space-y-4">
      <p v-if="step.status === 'BLOCKED'" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-600">{{ t('sys.setup.ai.blocked') }}</p>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-left text-xs text-ink-muted"><tr><th class="py-2">{{ t('sys.setup.ai.task') }}</th><th>{{ t('sys.setup.ai.primary') }}</th><th>{{ t('sys.setup.ai.readyModels') }}</th><th>{{ t('sys.setup.ai.ready') }}</th></tr></thead>
          <tbody class="divide-y divide-line">
            <tr v-for="r in list('tasks')" :key="str(r.task_key)">
              <td class="py-2"><span class="font-medium">{{ t(`sys.setup.ai.tasks.${str(r.task)}`) }}</span> <span class="block font-mono text-xs text-ink-muted">{{ str(r.task_key) }}</span></td>
              <td class="font-mono text-xs">{{ str(r.primary) || '—' }}</td>
              <td class="font-mono text-xs">{{ Array.isArray(r.ready_models) && r.ready_models.length ? r.ready_models.join(', ') : '—' }}</td>
              <td><AppBadge :tone="r.ready ? 'success' : 'warning'">{{ r.ready ? t('sys.common.yes') : t('sys.common.no') }}</AppBadge></td>
            </tr>
          </tbody>
        </table>
      </div>
      <RouterLink to="/ai/settings" class="text-sm font-medium text-brand-700 hover:underline">{{ t('sys.common.open') }} →</RouterLink>
    </div>

    <!-- 4. Email -->
    <div v-else-if="step.key === 'email'" class="space-y-4">
      <p class="text-sm text-ink-muted">{{ t('sys.setup.email.source') }}: {{ d.source === 'settings' ? t('sys.setup.email.sourceSettings') : t('sys.setup.email.sourceEnv') }} · {{ t('sys.setup.email.activeMailer') }}: <span class="font-mono">{{ str(d.active_mailer) }}</span></p>
      <form class="grid gap-4 md:grid-cols-2" @submit.prevent="saveMail">
        <AppSelect v-model="mail.mailer" class="md:col-span-2" :label="t('sys.setup.email.source')" :options="[{ value: 'env', label: t('sys.setup.email.useEnv') }, { value: 'smtp', label: t('sys.setup.email.useSmtp') }]" />
        <template v-if="mail.mailer === 'smtp'">
          <AppInput v-model="mail.host" :label="t('sys.setup.email.host')" :error="errors.host" required />
          <AppInput v-model="mail.port" type="number" :label="t('sys.setup.email.port')" :error="errors.port" required />
          <AppSelect v-model="mail.encryption" :label="t('sys.setup.email.encryption')" :options="['tls', 'ssl', 'none'].map((x) => ({ value: x, label: t(`sys.setup.email.encryptions.${x}`) }))" />
          <AppInput v-model="mail.username" :label="t('sys.setup.email.username')" :error="errors.username" autocomplete="off" />
          <AppInput v-model="mail.password" type="password" autocomplete="new-password" :label="t('sys.setup.email.password')" :hint="d.password_configured ? t('sys.common.secretKept') : undefined" :error="errors.password" />
          <AppInput v-model="mail.from_address" type="email" :label="t('sys.setup.email.fromAddress')" :error="errors.from_address" required />
          <AppInput v-model="mail.from_name" :label="t('sys.setup.email.fromName')" :error="errors.from_name" />
        </template>
        <div class="md:col-span-2 flex justify-end"><AppButton type="submit" :loading="busy" data-testid="save-mail">{{ t('common.save') }}</AppButton></div>
      </form>
      <form class="flex flex-wrap items-end gap-3 border-t border-line pt-4" @submit.prevent="act(() => systemApi.testMail(mail.test_to.trim()), t('sys.common.queued'))">
        <AppInput v-model="mail.test_to" type="email" :label="t('sys.setup.email.testTo')" :error="errors.to" required class="min-w-[16rem] flex-1" />
        <AppButton type="submit" variant="secondary" :loading="busy" data-testid="test-mail">{{ t('sys.setup.email.sendTest') }}</AppButton>
      </form>
      <p v-if="lastTest" class="text-sm" :class="lastTest.ok ? 'text-success-600' : 'text-danger-600'">
        {{ t('sys.common.lastTest') }} ({{ formatTime(str(lastTest.at)) }}): {{ lastTest.ok ? t('sys.common.ok') : t('sys.common.failed') }} <span v-if="lastTest.error" class="font-mono text-xs">— {{ str(lastTest.error) }}</span>
      </p>
    </div>

    <!-- 5. Plans -->
    <div v-else-if="step.key === 'plans'" class="space-y-4">
      <p class="text-sm text-ink-muted">{{ t('sys.setup.plans.intro') }}</p>
      <ul class="divide-y divide-line text-sm">
        <li v-for="p in list('plans')" :key="str(p.code)" class="flex items-center justify-between py-2">
          <span><span class="font-medium">{{ str(p.name) }}</span> <span class="font-mono text-xs text-ink-muted">{{ str(p.code) }}</span></span>
          <AppBadge :tone="p.published && p.status === 'active' ? 'success' : 'neutral'">{{ p.published ? t('sys.setup.plans.published') : t('sys.setup.plans.draft') }}</AppBadge>
        </li>
      </ul>
      <p v-if="list('missing_standard').length" class="text-sm text-ink-muted">{{ t('sys.setup.plans.missing', { list: (d.missing_standard as string[]).join(', ') }) }}</p>
      <p v-if="draftResult" class="text-sm text-success-600">{{ draftResult }}</p>
      <div class="flex flex-wrap gap-3">
        <AppButton v-if="list('missing_standard').length" :loading="busy" data-testid="draft-plans" @click="draftPlans">{{ t('sys.setup.plans.createDrafts') }}</AppButton>
        <RouterLink to="/billing/plans" class="self-center text-sm font-medium text-brand-700 hover:underline">{{ t('sys.setup.plans.manage') }} →</RouterLink>
      </div>
    </div>

    <!-- 6. Payments -->
    <div v-else-if="step.key === 'payments'" class="space-y-4">
      <p class="text-sm text-ink-muted">{{ t('sys.setup.payments.intro') }}</p>
      <ul class="divide-y divide-line text-sm">
        <li v-for="p in list('providers')" :key="str(p.code)" class="flex flex-wrap items-center justify-between gap-2 py-2">
          <span><span class="font-medium">{{ str(p.name) }}</span> <span class="font-mono text-xs text-ink-muted">{{ str(p.adapter) }}</span>
            <span v-if="Array.isArray(p.missing) && p.missing.length" class="block text-xs text-warning-600">{{ t('sys.setup.payments.missing') }}: {{ p.missing.join(', ') }}</span></span>
          <span class="flex gap-2">
            <AppBadge v-if="p.qr_ok !== null" :tone="p.qr_ok ? 'success' : 'danger'">{{ t('sys.setup.payments.qr') }}: {{ p.qr_ok ? t('sys.common.ok') : t('sys.common.failed') }}</AppBadge>
            <AppBadge :tone="p.enabled && p.configured ? 'success' : 'neutral'">{{ p.configured ? t('sys.common.configured') : t('sys.common.notConfigured') }}</AppBadge>
          </span>
        </li>
      </ul>
      <RouterLink to="/billing/settings" class="text-sm font-medium text-brand-700 hover:underline">{{ t('sys.setup.payments.manage') }} →</RouterLink>
    </div>

    <!-- 7. Credits -->
    <div v-else-if="step.key === 'credits'" class="space-y-3 text-sm">
      <p class="text-ink-muted">{{ t('sys.setup.credits.intro') }}</p>
      <p>{{ t('sys.setup.credits.policy') }}: <AppBadge :tone="(d.policy as Row)?.configured ? 'success' : 'neutral'">{{ (d.policy as Row)?.configured ? t('sys.common.configured') : t('sys.common.notConfigured') }}</AppBadge></p>
      <p>{{ t('sys.setup.credits.rate') }}: <span class="font-mono">{{ str((d.settings as Row)?.usd_to_currency_rate) || t('sys.common.notConfigured') }}</span></p>
      <div class="flex gap-4">
        <RouterLink to="/billing/credits" class="font-medium text-brand-700 hover:underline">{{ t('sys.setup.credits.manage') }} →</RouterLink>
        <RouterLink to="/billing/settings" class="font-medium text-brand-700 hover:underline">{{ t('sys.setup.payments.manage') }} →</RouterLink>
      </div>
    </div>

    <!-- 8. Google OAuth -->
    <div v-else-if="step.key === 'google'" class="space-y-4">
      <p class="text-sm text-ink-muted">{{ t('sys.setup.google.intro') }}</p>
      <form class="grid gap-4 md:grid-cols-2" @submit.prevent="saveGoogle">
        <AppInput v-model="google.client_id" :label="t('sys.setup.google.clientId')" :error="errors.client_id" autocomplete="off" />
        <AppInput v-model="google.client_secret" type="password" autocomplete="new-password" :label="t('sys.setup.google.clientSecret')" :hint="d.client_secret_configured ? t('sys.common.secretKept') : undefined" :error="errors.client_secret" />
        <Toggle v-model="google.ga4_enabled" :label="t('sys.setup.google.ga4')" class="md:col-span-2" />
        <p class="md:col-span-2 text-xs text-ink-muted">{{ t('sys.setup.google.redirectUri') }}: <span class="break-all font-mono">{{ str(d.redirect_uri) || '—' }}</span></p>
        <p class="md:col-span-2 text-xs text-ink-muted">{{ t('sys.setup.google.scopes') }}: <span class="break-all font-mono">{{ list('scopes').join(' ') }}</span></p>
        <div class="md:col-span-2 flex flex-wrap justify-end gap-2">
          <AppButton v-if="d.source === 'settings'" variant="ghost" :loading="busy" @click="act(() => systemApi.updateGoogle({ clear: true }), t('sys.common.saved'))">{{ t('sys.setup.google.clear') }}</AppButton>
          <AppButton variant="secondary" :loading="busy" :disabled="!d.client_id || !d.client_secret_configured" data-testid="test-google" @click="act(() => systemApi.testGoogle(), t('sys.common.queued'))">{{ t('sys.setup.google.test') }}</AppButton>
          <AppButton type="submit" :loading="busy" data-testid="save-google">{{ t('common.save') }}</AppButton>
        </div>
      </form>
      <p v-if="lastTest" class="text-sm" :class="lastTest.ok ? 'text-success-600' : 'text-danger-600'">
        {{ t('sys.common.lastTest') }} ({{ formatTime(str(lastTest.at)) }}): {{ lastTest.ok ? t('sys.common.ok') : t('sys.common.failed') }} <span class="font-mono text-xs">{{ str(lastTest.code) }}</span>
      </p>
    </div>

    <!-- 9. WordPress plugin -->
    <div v-else-if="step.key === 'wordpress'" class="space-y-4 text-sm">
      <p class="text-ink-muted">{{ t('sys.setup.wordpress.intro') }}</p>
      <template v-if="d.available">
        <p>{{ t('sys.setup.wordpress.version') }}: <span class="font-mono">{{ str(d.version) }}</span> · {{ formatBytes(d.size_bytes as number) }}</p>
        <a :href="systemApi.pluginDownloadUrl()" class="inline-flex items-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" data-testid="download-plugin">{{ t('sys.setup.wordpress.download') }}</a>
      </template>
      <p v-else class="rounded-lg bg-warning-50 px-3 py-2 text-warning-600">{{ t('sys.setup.wordpress.unavailable') }}</p>
      <p>{{ t('sys.setup.wordpress.requirements') }}: WordPress 6.4+, PHP 7.4+, HTTPS</p>
      <p v-if="d.connector_url">{{ t('sys.setup.wordpress.connectorUrl') }}: <span class="break-all font-mono">{{ str(d.connector_url) }}</span></p>
      <AppButton v-if="!d.acknowledged" variant="secondary" :loading="busy" data-testid="ack-wordpress" @click="act(() => systemApi.acknowledge('wordpress'), t('sys.common.saved'))">{{ t('sys.setup.wordpress.acknowledge') }}</AppButton>
    </div>

    <!-- 10. Queue / worker / scheduler -->
    <div v-else-if="step.key === 'queue'" class="space-y-3">
      <p class="text-sm text-ink-muted">{{ t('sys.setup.queue.intro') }}</p>
      <div v-for="c in queueChecks" :key="c.key" class="rounded-lg border border-line p-3 text-sm">
        <div class="flex items-center justify-between gap-2"><span class="font-medium">{{ t(`sys.health.checks.${c.key}`) }}</span><AppBadge :tone="healthTone(c.status)">{{ t(`sys.healthStatus.${c.status}`) }}</AppBadge></div>
        <p class="mt-1 text-ink-muted">{{ c.summary }}</p>
        <p v-if="c.fix" class="mt-1 text-warning-600">{{ c.fix }}</p>
      </div>
    </div>

    <!-- 11. Security -->
    <div v-else-if="step.key === 'security'" class="space-y-3">
      <p class="text-sm text-ink-muted">{{ t('sys.setup.security.intro') }}</p>
      <ul class="space-y-1 text-sm">
        <li v-for="[k, ok] in securityItems" :key="k" class="flex items-center gap-2" :data-testid="`security-${k}`">
          <span :class="ok ? 'text-success-600' : 'text-danger-600'" aria-hidden="true">{{ ok ? '✓' : '✗' }}</span>
          <span>{{ t(`sys.setup.security.items.${k}`) }}</span>
          <span class="sr-only">{{ ok ? t('sys.common.ok') : t('sys.common.failed') }}</span>
        </li>
      </ul>
    </div>

    <!-- 12. Final -->
    <div v-else-if="step.key === 'final'" class="space-y-4 text-sm">
      <p class="text-ink-muted">{{ t('sys.setup.final.intro') }}</p>
      <div v-if="blocking.length" class="rounded-lg bg-danger-50 px-3 py-2 text-danger-600">
        <p class="font-medium">{{ t('sys.setup.final.blocking') }}</p>
        <ul class="ml-5 list-disc"><li v-for="b in blocking" :key="b">{{ t(`sys.setup.steps.${b}`) }}</li></ul>
      </div>
      <p v-if="d.completed_at" class="text-success-600">{{ t('sys.setup.completedAt', { time: formatTime(str(d.completed_at)) }) }}</p>
      <AppButton :disabled="blocking.length > 0" :loading="busy" data-testid="complete-setup" @click="act(() => systemApi.complete(), t('sys.setup.final.done'))">{{ t('sys.setup.final.complete') }}</AppButton>
    </div>
  </AppCard>
</template>
