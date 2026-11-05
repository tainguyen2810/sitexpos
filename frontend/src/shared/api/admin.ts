import { api } from './client'
import type { AdminDashboard, AdminUser, AdminWorkspace, AuditLogEntry, Envelope, Paginated, PlatformRole } from './types'

const B = '/api/admin/v1'

export const adminApi = {
  dashboard: () => api.get<Envelope<AdminDashboard>>(`${B}/dashboard`).then((r) => r.data),

  workspaces: (query: { q?: string; status?: string; cursor?: string | null }) => api.get<Paginated<AdminWorkspace>>(`${B}/workspaces`, { query }),
  workspace: (id: string) => api.get<Envelope<AdminWorkspace>>(`${B}/workspaces/${id}`).then((r) => r.data),
  suspendWorkspace: (id: string, reason: string) => api.post<Envelope<AdminWorkspace>>(`${B}/workspaces/${id}/suspend`, { reason }),
  unsuspendWorkspace: (id: string, reason: string) => api.post<Envelope<AdminWorkspace>>(`${B}/workspaces/${id}/unsuspend`, { reason }),

  users: (query: { q?: string; status?: string; staff?: boolean; cursor?: string | null }) => api.get<Paginated<AdminUser>>(`${B}/users`, { query }),
  user: (id: string) => api.get<Envelope<AdminUser>>(`${B}/users/${id}`).then((r) => r.data),
  suspendUser: (id: string, reason: string) => api.post<Envelope<AdminUser>>(`${B}/users/${id}/suspend`, { reason }),
  unsuspendUser: (id: string, reason: string) => api.post<Envelope<AdminUser>>(`${B}/users/${id}/unsuspend`, { reason }),

  staff: () => api.get<Envelope<AdminUser[]>>(`${B}/staff`).then((r) => r.data),
  addStaff: (email: string, platform_role: PlatformRole) => api.post<Envelope<AdminUser>>(`${B}/staff`, { email, platform_role }),
  updateStaff: (id: string, platform_role: PlatformRole | null) => api.patch<Envelope<AdminUser>>(`${B}/staff/${id}`, { platform_role }),

  startImpersonation: (workspace_id: string, reason: string) =>
    api.post<Envelope<{ id: string; workspace_id: string; workspace_name: string; expires_at: string }>>(`${B}/impersonations`, { workspace_id, reason }).then((r) => r.data),
  endImpersonation: () => api.delete<void>(`${B}/impersonations/current`),

  auditLogs: (query: { cursor?: string | null; workspace_id?: string; 'filter[action]'?: string }) => api.get<Paginated<AuditLogEntry>>(`${B}/audit-logs`, { query }),
}
