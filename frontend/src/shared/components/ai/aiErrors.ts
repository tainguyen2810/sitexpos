import { i18n } from '@/shared/i18n'

/** Friendly message for a workflow / gateway error code, falling back to the server text. */
export function aiErrorMessage(error: { code: string; message?: string | null } | null | undefined): string {
  if (!error) return ''
  const key = `ai.errors.${error.code}`
  const g = i18n.global
  return g.te(key) || g.te(key, 'vi') ? g.t(key) : error.message || error.code
}
