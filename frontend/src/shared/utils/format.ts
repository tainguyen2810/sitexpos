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
