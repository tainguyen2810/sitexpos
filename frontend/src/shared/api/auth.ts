import { api } from './client'
import type { Envelope, Me, SessionInfo } from './types'

const A = '/api/v1/auth'

export const authApi = {
  me: (silent = false) => api.get<Envelope<Me>>('/api/v1/me', { silent401: silent }).then((r) => r.data),
  updateProfile: (data: Partial<Pick<Me, 'name' | 'locale' | 'timezone'>>) => api.patch<Envelope<Me>>('/api/v1/me', data).then((r) => r.data),

  login: (email: string, password: string, remember = false) =>
    api.post<{ two_factor: boolean }>(`${A}/login`, { email, password, remember }, { silent401: true }),
  twoFactorChallenge: (payload: { code?: string; recovery_code?: string }) => api.post<void>(`${A}/two-factor-challenge`, payload),
  logout: () => api.post<void>(`${A}/logout`),
  register: (data: { name: string; email: string; password: string; password_confirmation: string; locale?: string }) =>
    api.post<void>(`${A}/register`, data),
  forgotPassword: (email: string) => api.post<{ data: { message: string } }>(`${A}/forgot-password`, { email }),
  resetPassword: (data: { token: string; email: string; password: string; password_confirmation: string }) =>
    api.post<{ message?: string }>(`${A}/reset-password`, data),
  resendVerification: () => api.post<void>(`${A}/email/verification-notification`),
  /** `link` is the signed API URL from the verification email. Only its path+query are used. */
  verifyEmail: (link: string) => {
    const parsed = new URL(link, window.location.origin)
    if (!parsed.pathname.startsWith(`${A}/email/verify/`)) throw new Error('invalid_link')
    return api.get<void>(parsed.pathname + parsed.search)
  },
  updatePassword: (data: { current_password: string; password: string; password_confirmation: string }) =>
    api.put<void>(`${A}/user/password`, data),

  confirmPassword: (password: string) => api.post<void>(`${A}/user/confirm-password`, { password }),
  enableTwoFactor: () => api.post<void>(`${A}/user/two-factor-authentication`),
  disableTwoFactor: () => api.delete<void>(`${A}/user/two-factor-authentication`),
  confirmTwoFactor: (code: string) => api.post<void>(`${A}/user/confirmed-two-factor-authentication`, { code }),
  twoFactorQr: () => api.get<{ svg: string; url: string }>(`${A}/user/two-factor-qr-code`),
  twoFactorSecret: () => api.get<{ secretKey: string }>(`${A}/user/two-factor-secret-key`),
  recoveryCodes: () => api.get<string[]>(`${A}/user/two-factor-recovery-codes`),
  regenerateRecoveryCodes: () => api.post<void>(`${A}/user/two-factor-recovery-codes`),

  sessions: () => api.get<{ data: SessionInfo[]; meta: { supported: boolean } }>('/api/v1/me/sessions'),
  revokeSession: (id: string) => api.delete<void>(`/api/v1/me/sessions/${encodeURIComponent(id)}`),
  revokeOtherSessions: (password: string) => api.delete<{ data: { revoked: number } }>('/api/v1/me/sessions/others', { password }),
}
