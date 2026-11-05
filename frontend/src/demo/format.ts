const vi = 'vi-VN'

export const fmtNumber = (n: number | null | undefined) => (n === null || n === undefined ? '—' : new Intl.NumberFormat(vi).format(n))
export const fmtCompact = (n: number) => new Intl.NumberFormat(vi, { notation: 'compact', maximumFractionDigits: 1 }).format(n)
export const fmtVnd = (minor: number) => new Intl.NumberFormat(vi, { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(minor)
export const fmtUsd = (usd: number, digits = 2) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: digits }).format(usd)
export const fmtPct = (n: number, digits = 1) => `${n.toFixed(digits).replace('.', ',')}%`
export const fmtDate = (iso: string | null | undefined) => (iso ? new Intl.DateTimeFormat(vi, { dateStyle: 'medium' }).format(new Date(iso)) : '—')
export const fmtDateTime = (iso: string | null | undefined) => (iso ? new Intl.DateTimeFormat(vi, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso)) : '—')
export const fmtBytes = (b: number) => (b > 1024 * 1024 ? `${(b / 1024 / 1024).toFixed(1)} MB` : `${Math.round(b / 1024)} KB`)

export function fmtRelative(iso: string | null | undefined): string {
  if (!iso) return '—'
  const diff = (new Date(iso).getTime() - Date.now()) / 1000
  const rtf = new Intl.RelativeTimeFormat(vi, { numeric: 'auto' })
  const abs = Math.abs(diff)
  if (abs < 60) return rtf.format(Math.round(diff), 'second')
  if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute')
  if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour')
  if (abs < 86400 * 30) return rtf.format(Math.round(diff / 86400), 'day')
  return fmtDate(iso)
}

export function toDateInput(iso: string): string {
  const d = new Date(iso)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}
export function toDateTimeInput(iso: string): string {
  const d = new Date(iso)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${toDateInput(iso)}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}
