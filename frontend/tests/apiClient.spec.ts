import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, configureApi, request } from '@/shared/api/client'

function jsonResponse(status: number, body: unknown): Response {
  return new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

afterEach(() => {
  vi.restoreAllMocks()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('ApiError.fromResponse', () => {
  it('reads the API error envelope', () => {
    const e = ApiError.fromResponse(422, { error: { code: 'validation_failed', message: 'Dữ liệu không hợp lệ', details: { email: ['Sai email'] }, request_id: 'req-1' } })
    expect(e.code).toBe('validation_failed')
    expect(e.fieldError('email')).toBe('Sai email')
    expect(e.requestId).toBe('req-1')
  })

  it('maps 423 to password_confirmation_required', () => {
    expect(ApiError.fromResponse(423, { message: 'Password confirmation required.' }).code).toBe('password_confirmation_required')
  })
})

describe('request', () => {
  it('sends the XSRF header, locale and credentials on mutating requests', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D'
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(null, { status: 204 }))
    configureApi({ locale: () => 'vi' })

    await request('POST', '/api/v1/workspaces', { name: 'A' })

    const [, init] = fetchMock.mock.calls[0]!
    const headers = (init as RequestInit).headers as Record<string, string>
    expect(headers['X-XSRF-TOKEN']).toBe('abc=')
    expect(headers['Accept-Language']).toBe('vi')
    expect((init as RequestInit).credentials).toBe('include')
  })

  it('never sends workspace_id unless the caller puts it there', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(null, { status: 204 }))
    await request('PATCH', '/api/v1/workspaces/01J/members/01K', { role: 'EDITOR' })
    expect(JSON.parse(String((fetchMock.mock.calls[0]![1] as RequestInit).body))).toEqual({ role: 'EDITOR' })
  })

  it('throws ApiError and calls the 401 handler', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse(401, { error: { code: 'unauthenticated', message: 'x' } }))
    const handler = vi.fn()
    configureApi({ onUnauthenticated: handler })

    await expect(request('GET', '/api/v1/me')).rejects.toMatchObject({ status: 401, code: 'unauthenticated' })
    expect(handler).toHaveBeenCalledOnce()
  })

  it('does not call the 401 handler for silent requests', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse(401, { error: { code: 'unauthenticated', message: 'x' } }))
    const handler = vi.fn()
    configureApi({ onUnauthenticated: handler })

    await expect(request('GET', '/api/v1/me', undefined, { silent401: true })).rejects.toBeInstanceOf(ApiError)
    expect(handler).not.toHaveBeenCalled()
  })

  it('refreshes the CSRF cookie and retries once on 419', async () => {
    document.cookie = 'XSRF-TOKEN=old'
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(jsonResponse(419, { error: { code: 'csrf_mismatch', message: '' } }))
      .mockResolvedValueOnce(new Response(null, { status: 204 })) // csrf-cookie
      .mockResolvedValueOnce(jsonResponse(200, { data: { ok: true } }))

    await expect(request('POST', '/api/v1/x', {})).resolves.toEqual({ data: { ok: true } })
    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(String(fetchMock.mock.calls[1]![0])).toContain('/sanctum/csrf-cookie')
  })

  it('maps network failures to network_error', async () => {
    vi.spyOn(globalThis, 'fetch').mockRejectedValue(new TypeError('Failed to fetch'))
    await expect(request('GET', '/api/v1/me')).rejects.toMatchObject({ status: 0, code: 'network_error' })
  })
})
