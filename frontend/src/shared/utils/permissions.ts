import type { WorkspaceRole } from '@/shared/api/types'

/** UI-only helpers. The backend always re-checks every permission. */
export function hasPermission(permissions: readonly string[] | undefined, permission: string): boolean {
  return permissions?.includes(permission) ?? false
}

/** Mirrors WorkspaceRole::manageableRoles() on the backend. */
export function manageableRoles(actor: WorkspaceRole | null | undefined): WorkspaceRole[] {
  if (actor === 'OWNER') return ['ADMIN', 'EDITOR', 'VIEWER']
  if (actor === 'ADMIN') return ['EDITOR', 'VIEWER']
  return []
}

export function canManageMember(actor: WorkspaceRole | null | undefined, target: WorkspaceRole): boolean {
  return manageableRoles(actor).includes(target)
}
