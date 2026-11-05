/**
 * Typed fetch wrapper for the SiteX Post API.
 * - Sanctum SPA auth: session cookie + XSRF-TOKEN header (no tokens in storage).
 * - Errors are normalized from the API envelope into ApiError.
 */
export interface ApiErrorBody {
  error?: { code?: string; message?: string; details?: Record<string, string[]>; request_id?: string }
  message?: string
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details: Record<string, string[]> = {},
    public readonly requestId: string | null = null,
  ) {
    super(message)
    this.name = 'ApiError'
  }

  static fromResponse(status: number, body: ApiErrorBody | null): ApiError {
    if (body?.error) {
      return new ApiError(
        status,
        body.error.code ?? 'error',
        body.error.message ?? '',
        body.error.details ?? {},
        body.error.request_id ?? null,
      )
    }
    if (status === 423) {
      return new ApiError(status, 'password_confirmation_required', body?.message ?? '')
    }
    return new ApiError(status, status === 0 ? 'network_error' : 'error', body?.message ?? '')
  }

  fieldError(field: string): string | undefined {
    return this.details[field]?.[0]
  }
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

interface Config {
  baseUrl: string
  locale: () => string
  onUnauthenticated?: (error: ApiError) => void
}

const config: Config = {
  baseUrl: (import.meta.env.VITE_API_BASE_URL ?? '').replace(/\/$/, ''),
  locale: () => 'vi',
}

export function configureApi(partial: Partial<Config>): void {
  Object.assign(config, partial)
}

export function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie('XSRF-TOKEN')) return
  await fetch(`${config.baseUrl}/sanctum/csrf-cookie`, { credentials: 'include' })
}

export interface RequestOptions {
  query?: Record<string, string | number | boolean | undefined | null>
  /** Do not trigger the global 401 handler (e.g. for the initial /me probe). */
  silent401?: boolean
  /** Extra request headers (e.g. Idempotency-Key). Auth headers cannot be overridden. */
  headers?: Record<string, string>
}

export async function request<T>(method: Method, path: string, body?: unknown, options: RequestOptions = {}, retried = false): Promise<T> {
  if (method !== 'GET') await ensureCsrfCookie()

  const url = new URL(`${config.baseUrl}${path}`, window.location.origin)
  for (const [k, v] of Object.entries(options.query ?? {})) {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v))
  }

  const headers: Record<string, string> = {
    ...(options.headers ?? {}),
    Accept: 'application/json',
    'Accept-Language': config.locale(),
    'X-Requested-With': 'XMLHttpRequest',
  }
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  let response: Response
  try {
    response = await fetch(url.toString(), {
      method,
      headers,
      credentials: 'include',
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError(0, 'network_error', '')
  }

  if (response.status === 419 && !retried) {
    await ensureCsrfCookie(true)
    return request<T>(method, path, body, options, true)
  }

  if (response.status === 204) return undefined as T

  const json = (await response.json().catch(() => null)) as unknown

  if (!response.ok) {
    const error = ApiError.fromResponse(response.status, json as ApiErrorBody | null)
    if (error.status === 401 && !options.silent401) config.onUnauthenticated?.(error)
    throw error
  }

  return json as T
}

export const api = {
  get: <T>(path: string, options?: RequestOptions) => request<T>('GET', path, undefined, options),
  post: <T>(path: string, body?: unknown, options?: RequestOptions) => request<T>('POST', path, body ?? {}, options),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body ?? {}),
  patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body ?? {}),
  delete: <T>(path: string, body?: unknown) => request<T>('DELETE', path, body),
}
