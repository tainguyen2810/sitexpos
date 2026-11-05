export type WorkspaceRole = 'OWNER' | 'ADMIN' | 'EDITOR' | 'VIEWER'
export type PlatformRole = 'SUPER_ADMIN' | 'SUPPORT' | 'BILLING_ADMIN'

export interface Envelope<T> {
  data: T
}

export interface Paginated<T> {
  data: T[]
  meta: { next_cursor: string | null; prev_cursor: string | null; per_page: number }
}

export interface WorkspaceSummary {
  id: string
  name: string
  slug: string
  role: WorkspaceRole
  status: string
  is_demo: boolean
}

export interface Me {
  id: string
  name: string
  email: string
  email_verified: boolean
  locale: string
  timezone: string
  two_factor_enabled: boolean
  platform_role: PlatformRole | null
  platform_permissions: string[]
  status: string
  created_at: string
  workspaces: WorkspaceSummary[]
  impersonation: { id: string; workspace_id: string; workspace_name: string | null; read_only: boolean; expires_at: string } | null
}

export interface Workspace {
  id: string
  name: string
  slug: string
  type: string
  locale: string
  timezone: string
  status: string
  is_demo: boolean
  my_role: WorkspaceRole | null
  permissions: string[]
  impersonating: boolean
  created_at: string
}

export interface Member {
  id: string
  user: { id: string; name: string; email: string }
  role: WorkspaceRole
  status: string
  joined_at: string
  is_me: boolean
}

export interface Invitation {
  id: string
  email: string
  role: WorkspaceRole
  status: 'pending' | 'accepted' | 'revoked' | 'expired'
  invited_by?: { id: string; name: string } | null
  expires_at: string
  created_at: string
}

export interface InvitationPreview {
  workspace_name: string
  inviter_name: string | null
  email: string
  role: WorkspaceRole
  status: Invitation['status']
  expires_at: string
}

export interface AuditLogEntry {
  id: number
  workspace_id: string | null
  action: string
  actor_type: string
  actor?: { id: string; name: string; email: string } | null
  impersonator?: { id: string; name: string } | null
  subject_type: string | null
  subject_id: string | null
  changes: Record<string, unknown> | null
  ip: string | null
  created_at: string
}

export interface WorkspaceDashboard {
  members_count: number
  pending_invitations_count: number
  modules: Record<string, { available: boolean; phase: number }>
  onboarding: { key: string; done: boolean; available?: boolean }[]
}

export interface SessionInfo {
  id: string
  ip_address: string | null
  user_agent: string | null
  last_active_at: string
  is_current: boolean
}

export interface AdminUser {
  id: string
  name: string
  email: string
  email_verified: boolean
  platform_role: PlatformRole | null
  two_factor_enabled: boolean
  status: string
  last_login_at: string | null
  created_at: string
  workspaces?: WorkspaceSummary[]
}

export interface AdminWorkspace {
  id: string
  name: string
  slug: string
  type: string
  status: string
  is_demo: boolean
  owner?: { id: string; name: string; email: string } | null
  members_count?: number
  created_at: string
  members?: { id: string; user: { id: string; name: string; email: string }; role: WorkspaceRole; status: string; joined_at: string }[]
}

export interface AdminDashboard {
  users: { total: number; verified: number; suspended: number; new_7d: number; new_30d: number }
  workspaces: { total: number; active: number; suspended: number; new_7d: number; new_30d: number }
  invitations_pending: number
  generated_at: string
}
