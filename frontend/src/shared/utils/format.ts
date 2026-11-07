import { currentLocale } from '@/shared/i18n'

export function formatDateTime(iso: string | null | undefined, timeZone?: string): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat(currentLocale() === 'vi' ? 'vi-VN' : 'en-US', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone,
  }).format(new Date(iso))
}

export function formatDate(iso: string | null | undefined, timeZone?: string): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat(currentLocale() === 'vi' ? 'vi-VN' : 'en-US', { dateStyle: 'medium', timeZone }).format(new Date(iso))
}

export function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(-2)
    .map((p) => p[0]?.toUpperCase() ?? '')
    .join('')
}

export const TIMEZONES = ['Asia/Ho_Chi_Minh', 'Asia/Bangkok', 'Asia/Singapore', 'Asia/Tokyo', 'Australia/Sydney', 'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Los_Angeles', 'UTC']

/** "5 phút trước", "hôm qua", "3 ngày trước" — falls back to the date after ~30 days. */
export function formatRelative(iso: string | null | undefined, now: number = Date.now()): string {
  if (!iso) return '—'
  const then = new Date(iso).getTime()
  if (Number.isNaN(then)) return '—'
  const diff = Math.round((then - now) / 1000)
  const abs = Math.abs(diff)
  const rtf = new Intl.RelativeTimeFormat('vi', { numeric: 'auto' })
  if (abs < 60) return rtf.format(0, 'second')
  if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute')
  if (abs < 86_400) return rtf.format(Math.round(diff / 3600), 'hour')
  if (abs < 30 * 86_400) return rtf.format(Math.round(diff / 86_400), 'day')
  return formatDate(iso)
}
