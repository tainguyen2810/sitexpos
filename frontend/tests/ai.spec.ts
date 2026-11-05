import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { i18n } from '@/shared/i18n'
import { aiApi, formatPerMtok, formatUsd, newIdempotencyKey, usdToMicro, type WorkflowRun } from '@/shared/api/ai'
import WorkflowRunDetail from '@/shared/components/ai/WorkflowRunDetail.vue'
import WorkflowStatusBadge from '@/shared/components/ai/WorkflowStatusBadge.vue'
import { aiErrorMessage } from '@/shared/components/ai/aiErrors'
import viMessages from '@/locales/vi'
import enMessages from '@/locales/en'

const global = { plugins: [i18n] }

const run = (over: Partial<WorkflowRun> = {}): WorkflowRun => ({
  id: '01jrun', type: 'ai.test', queue: 'writing', status: 'succeeded', current_step: null, attempts: 1, max_attempts: 3, cycle: 1,
  cancel_requested: false, next_attempt_at: null, error: null, input: { prompt: 'Xin chào' }, job_run_id: 'j1',
  started_at: null, finished_at: null, created_at: '2026-11-05T00:00:00Z', ...over,
})

describe('money formatting (integer micro-USD)', () => {
  it('formats costs and per-1M prices without float drift', () => {
    expect(formatUsd(13_000)).toBe('$0.0130')
    expect(formatUsd(2_500_000)).toBe('$2.500')
    expect(formatUsd(123_456_789_000)).toBe('$123,456.79')
    expect(formatUsd(null)).toBe('—')
    expect(formatPerMtok(100_000)).toBe('$0.10')
    expect(usdToMicro('0.075')).toBe(75_000)
    expect(usdToMicro('2,5')).toBe(2_500_000)
    expect(usdToMicro('abc')).toBe(0)
  })
})

describe('aiApi.createTask', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('sends the Idempotency-Key header and the prompt', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ data: { workflow_run: run({ status: 'pending' }), job_run: { id: 'j1', status: 'queued', type: 'workflow.ai.test' } } }), { status: 202 }))
    vi.stubGlobal('fetch', fetchMock)

    const res = await aiApi.createTask('ws1', { prompt: 'hello', structured: true }, 'key-12345678')

    expect(res.workflow_run.status).toBe('pending')
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toContain('/api/v1/workspaces/ws1/ai/tasks')
    expect((init.headers as Record<string, string>)['Idempotency-Key']).toBe('key-12345678')
    expect(JSON.parse(String(init.body))).toEqual({ prompt: 'hello', structured: true })
  })

  it('generates distinct idempotency keys', () => {
    expect(newIdempotencyKey()).not.toBe(newIdempotencyKey())
  })
})

describe('WorkflowRunDetail', () => {
  it('shows result, model, tokens and cost', () => {
    const w = mount(WorkflowRunDetail, {
      props: { run: run({ output: { text: 'Phản hồi', model_key: 'fake/fake-echo', model: 'fake-echo', cost_micro_usd: 42, usage: { input_tokens: 10, output_tokens: 5 } } }) },
      global,
    })
    expect(w.get('[data-testid=run-text]').text()).toBe('Phản hồi')
    expect(w.get('[data-testid=run-usage]').text()).toContain('fake/fake-echo')
    expect(w.get('[data-testid=run-usage]').text()).toContain('$0.000042')
  })

  it('renders structured JSON safely as text', () => {
    const w = mount(WorkflowRunDetail, { props: { run: run({ output: { json: { summary: '<img src=x onerror=alert(1)>' }, model: 'm', model_key: 'p/m' } }) }, global })
    expect(w.get('[data-testid=run-json]').text()).toContain('<img src=x')
    expect(w.find('img').exists()).toBe(false)
  })

  it('explains a billing-uncertain failure in plain language', () => {
    i18n.global.locale.value = 'vi'
    const w = mount(WorkflowRunDetail, { props: { run: run({ status: 'failed', error: { code: 'response_timeout', message: 'raw' } }) }, global })
    expect(w.get('[data-testid=run-error]').text()).toContain('không tự thử lại')
  })
})

describe('WorkflowStatusBadge & errors', () => {
  it('labels every status', () => {
    for (const s of ['pending', 'running', 'retry_scheduled', 'succeeded', 'failed', 'canceled'] as const) {
      const w = mount(WorkflowStatusBadge, { props: { status: s }, global })
      expect(w.text()).not.toContain('ai.statuses')
    }
  })

  it('falls back to the server message for unknown error codes', () => {
    expect(aiErrorMessage({ code: 'weird_code', message: 'Server says' })).toBe('Server says')
    expect(aiErrorMessage(null)).toBe('')
  })
})

describe('locales', () => {
  const keys = (o: object, p = ''): string[] => Object.entries(o).flatMap(([k, v]) => (typeof v === 'object' && v !== null ? keys(v, `${p}${k}.`) : [`${p}${k}`]))

  it('has every Phase 3 English key that Vietnamese has', () => {
    const vi_ = keys({ ai: viMessages.ai, aiAdmin: viMessages.aiAdmin, jobs: viMessages.jobs })
    const en = new Set(keys({ ai: enMessages.ai, aiAdmin: enMessages.aiAdmin, jobs: enMessages.jobs }))
    expect(vi_.filter((k) => !en.has(k))).toEqual([])
  })
})
