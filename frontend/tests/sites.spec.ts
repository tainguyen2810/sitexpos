import { describe, expect, it, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { i18n } from '@/shared/i18n'
import { siteStatusTone, waitForJob, type JobRun } from '@/shared/api/sites'
import PairingCodePanel from '@/apps/customer/components/PairingCodePanel.vue'
import vi_ from '@/locales/vi'
import en from '@/locales/en'

const global = { plugins: [i18n] }
const run = (status: JobRun['status']): JobRun => ({ id: 'j1', type: 'sites.sync', status, progress: 0, step: null, error: null, started_at: null, finished_at: null, created_at: '2026-11-05T00:00:00Z' })

describe('waitForJob', () => {
  it('polls until the job finishes', async () => {
    const states: JobRun['status'][] = ['queued', 'running', 'succeeded']
    const load = vi.fn(async () => run(states.shift() ?? 'succeeded'))
    const result = await waitForJob(load, { sleep: async () => {} })
    expect(result.status).toBe('succeeded')
    expect(load).toHaveBeenCalledTimes(3)
  })

  it('gives up after the timeout and returns the unfinished run', async () => {
    const load = vi.fn(async () => run('running'))
    const result = await waitForJob(load, { timeoutMs: 0, sleep: async () => {} })
    expect(result.status).toBe('running')
    expect(load).toHaveBeenCalledTimes(1)
  })
})

describe('siteStatusTone', () => {
  it('maps every status', () => {
    expect(siteStatusTone('connected')).toBe('success')
    expect(siteStatusTone('degraded')).toBe('warning')
    expect(siteStatusTone('revoked')).toBe('danger')
    expect(siteStatusTone('pending_pairing')).toBe('neutral')
  })
})

describe('PairingCodePanel', () => {
  afterEach(() => vi.useRealTimers())

  it('shows the code, a countdown and a link to the plugin page, and never asks for a password', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-05T10:00:00Z'))
    const w = mount(PairingCodePanel, { props: { code: 'ABCD-EFGH', expiresAt: '2026-11-05T10:15:00Z', siteUrl: 'https://blog.example.com/' }, global })
    expect(w.get('[data-testid=pairing-code]').text()).toBe('ABCD-EFGH')
    expect(w.text()).toContain('15:00')
    const admin = w.get('a[href="https://blog.example.com/wp-admin/admin.php?page=sitex-post"]')
    expect(admin.attributes('rel')).toContain('noopener')
    expect(w.get('[data-testid=plugin-download]').attributes('href')).toMatch(/\/api\/v1\/plugin\/sitex-post\.zip$/)
    expect(w.find('input[type=password]').exists()).toBe(false)
  })

  it('marks an expired code', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-05T11:00:00Z'))
    const w = mount(PairingCodePanel, { props: { code: 'ABCD-EFGH', expiresAt: '2026-11-05T10:15:00Z' }, global })
    expect(w.text()).toContain(i18n.global.t('sites.pairing.expired'))
    expect(w.find('button').exists()).toBe(false)
  })
})

describe('sites locale', () => {
  it('has the same keys in Vietnamese and English', () => {
    const keys = (o: object, p = ''): string[] =>
      Object.entries(o).flatMap(([k, v]) => (v && typeof v === 'object' ? keys(v as object, `${p}${k}.`) : [`${p}${k}`]))
    expect(keys(en.sites).sort()).toEqual(keys(vi_.sites).sort())
  })
})
