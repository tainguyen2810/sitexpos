import { db, newId } from '../store'
import type { DemoAuditLog } from '../data/types'

/** Signed-in demo staff account (fictional). */
export const STAFF = { name: 'Quản trị viên Demo', email: 'admin@sitex-demo.example' }

export const adminCrumbs = (...rest: { label: string; to?: string }[]) => [{ label: 'Quản trị hệ thống', to: '/overview' }, ...rest]
export const wsName = (id: string | null) => (id ? (db.workspaces.find((w) => w.id === id)?.name ?? 'Workspace đã xóa') : 'Hệ thống')
export const planName = (code: string) => db.plans.find((p) => p.code === code)?.name ?? code

export function staffAudit(action: string, subject: string, workspaceId: string | null = null): void {
  const log: DemoAuditLog = { id: newId('log'), workspaceId, actorName: STAFF.name, actorType: 'staff', action, subject, ip: '198.51.100.7', createdAt: new Date().toISOString() }
  db.auditLogs.unshift(log)
}

/** Monthly recurring revenue (VND, minor units) from active paid subscriptions. */
export function mrrMinor(): number {
  return db.subscriptions
    .filter((s) => s.status === 'active' || s.status === 'past_due')
    .reduce((sum, s) => {
      const p = db.plans.find((x) => x.code === s.planCode)
      if (!p) return sum
      return sum + (s.interval === 'month' ? p.priceMonthMinor : Math.round(p.priceYearMinor / 12))
    }, 0)
}
