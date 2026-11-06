import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { i18n } from '@/shared/i18n'
import viMessages from '@/locales/vi'
import { formatBytes, HEALTH_KEYS, healthTone, SETUP_STEPS, STEP_STATUSES, stepTone, systemApi, type SetupStep } from '@/shared/api/system'
import ReauthModal from '@/apps/admin/pages/system/ReauthModal.vue'
import SetupStepPanel from '@/apps/admin/pages/system/SetupStepPanel.vue'
import { detailText } from '@/apps/admin/pages/system/useSystem'

type Dict = Record<string, unknown>
const sys = (viMessages as unknown as { sys: Record<string, Dict> }).sys
const global = { plugins: [i18n], stubs: { RouterLink: true, Teleport: true } }

describe('system locale coverage', () => {
  it('labels every health check, setup step, status and security item in Vietnamese', () => {
    for (const k of HEALTH_KEYS) expect((sys.health!.checks as Dict)[k]).toBeTruthy()
    for (const k of SETUP_STEPS) expect((sys.setup!.steps as Dict)[k]).toBeTruthy()
    for (const s of STEP_STATUSES) expect(sys.stepStatus![s]).toBeTruthy()
    const items = ['app_env_production', 'app_debug_off', 'app_key_set', 'https', 'secure_cookies', 'session_http_only', 'installer_locked', 'env_not_world_readable', 'scheduler_running', 'backup_configured', 'super_admin_2fa']
    for (const k of items) expect(((sys.setup!.security as Dict).items as Dict)[k]).toBeTruthy()
    for (const b of ['destructive_migrations', 'requirements', 'in_progress']) expect(((sys.upgrade!.blockers as Dict)[b])).toBeTruthy()
  })

  it('states that QR codes are not proof of payment and restores are SSH-only', () => {
    expect(String((sys.setup!.payments as Dict).intro)).toContain('không phải bằng chứng đã thanh toán')
    expect(String(sys.backups!.restoreHint)).toContain('SSH')
  })
})

describe('system helpers', () => {
  it('maps statuses to badge tones', () => {
    expect(healthTone('ok')).toBe('success')
    expect(healthTone('critical')).toBe('danger')
    expect(healthTone('unknown')).toBe('neutral')
    expect(stepTone('COMPLETE')).toBe('success')
    expect(stepTone('BLOCKED')).toBe('danger')
    expect(stepTone('IN_PROGRESS')).toBe('brand')
  })

  it('formats sizes and detail values as plain text', () => {
    expect(formatBytes(null)).toBe('—')
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(1536)).toBe('1,5 KB')
    expect(detailText(true)).toBe('✓')
    expect(detailText({ a: 1 })).toBe('{"a":1}')
    expect(detailText('<b>x</b>')).toBe('<b>x</b>')
  })

  it('builds the plugin download link on the admin API', () => {
    expect(systemApi.pluginDownloadUrl()).toMatch(/\/api\/admin\/v1\/system\/plugin\/download$/)
  })
})

describe('ReauthModal', () => {
  it('emits the password only on submit and clears it when reopened', async () => {
    const w = mount(ReauthModal, { props: { open: true, title: 'X', body: 'Y' }, global })
    const input = w.get('[data-testid="reauth-password"] input')
    await input.setValue('Secret-123!')
    await w.get('form').trigger('submit')
    expect(w.emitted('submit')![0]).toEqual(['Secret-123!'])
    await w.setProps({ open: false })
    await w.setProps({ open: true })
    expect((w.get('[data-testid="reauth-password"] input').element as HTMLInputElement).value).toBe('')
  })
})

describe('SetupStepPanel', () => {
  const step = (key: SetupStep['key'], data: Dict, status: SetupStep['status'] = 'IN_PROGRESS'): SetupStep => ({ key, status, required: true, data })

  it('disables completion while required steps block readiness', () => {
    const w = mount(SetupStepPanel, { props: { step: step('final', { readiness: 'NOT_READY', blocking: ['email', 'plans'], completed_at: null }) }, global })
    expect(w.get('[data-testid="complete-setup"]').attributes('disabled')).toBeDefined()
    expect(w.text()).toContain('Email (SMTP)')
  })

  it('never shows a saved SMTP password, only that one is configured', () => {
    const w = mount(SetupStepPanel, { props: { step: step('email', { mailer: 'smtp', host: 'smtp.example.vn', port: 587, password_configured: true, source: 'settings', active_mailer: 'smtp' }) }, global })
    const pw = w.findAll('input[type="password"]')
    expect(pw).toHaveLength(1)
    expect((pw[0]!.element as HTMLInputElement).value).toBe('')
    expect(w.text()).toContain('không bao giờ được hiển thị lại')
  })

  it('shows a build hint instead of a dead download button when no plugin zip exists', () => {
    const w = mount(SetupStepPanel, { props: { step: step('wordpress', { available: false, acknowledged: false }, 'WARNING') }, global })
    expect(w.find('[data-testid="download-plugin"]').exists()).toBe(false)
    expect(w.text()).toContain('build-plugin.sh')
  })
})
