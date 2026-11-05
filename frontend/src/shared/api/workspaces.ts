import { api } from './client'
import type { AuditLogEntry, Envelope, Invitation, InvitationPreview, Member, Paginated, Workspace, WorkspaceDashboard, WorkspaceRole, WorkspaceSummary } from './types'

const W = (id: string) => `/api/v1/workspaces/${encodeURIComponent(id)}`

export const workspaceApi = {
  list: () => api.get<Envelope<WorkspaceSummary[]>>('/api/v1/workspaces').then((r) => r.data),
  create: (data: { name: string; timezone?: string; locale?: string }) => api.post<Envelope<Workspace>>('/api/v1/workspaces', data).then((r) => r.data),
  get: (id: string) => api.get<Envelope<Workspace>>(W(id)).then((r) => r.data),
  update: (id: string, data: Partial<Pick<Workspace, 'name' | 'timezone' | 'locale'>>) => api.patch<Envelope<Workspace>>(W(id), data).then((r) => r.data),
  remove: (id: string, confirm_name: string, password: string) => api.delete<void>(W(id), { confirm_name, password }),
  transferOwnership: (id: string, member_id: string, password: string) => api.post<void>(`${W(id)}/transfer-ownership`, { member_id, password }),
  dashboard: (id: string) => api.get<Envelope<WorkspaceDashboard>>(`${W(id)}/dashboard`).then((r) => r.data),

  members: (id: string) => api.get<Envelope<Member[]>>(`${W(id)}/members`).then((r) => r.data),
  updateMember: (id: string, memberId: string, role: WorkspaceRole) => api.patch<Envelope<Member>>(`${W(id)}/members/${memberId}`, { role }).then((r) => r.data),
  removeMember: (id: string, memberId: string) => api.delete<void>(`${W(id)}/members/${memberId}`),

  invitations: (id: string) => api.get<Envelope<Invitation[]>>(`${W(id)}/invitations`).then((r) => r.data),
  invite: (id: string, email: string, role: WorkspaceRole) => api.post<Envelope<Invitation>>(`${W(id)}/invitations`, { email, role }).then((r) => r.data),
  revokeInvitation: (id: string, invitationId: string) => api.delete<void>(`${W(id)}/invitations/${invitationId}`),

  auditLogs: (id: string, query: { cursor?: string | null; 'filter[action]'?: string }) =>
    api.get<Paginated<AuditLogEntry>>(`${W(id)}/audit-logs`, { query }),

  previewInvitation: (token: string) => api.get<Envelope<InvitationPreview>>(`/api/v1/invitations/${encodeURIComponent(token)}`, { silent401: true }).then((r) => r.data),
  acceptInvitation: (token: string) => api.post<Envelope<Workspace>>(`/api/v1/invitations/${encodeURIComponent(token)}/accept`).then((r) => r.data),
}
