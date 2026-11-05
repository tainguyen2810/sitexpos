import { describe, expect, it } from 'vitest'
import { canManageMember, hasPermission, manageableRoles } from '@/shared/utils/permissions'

describe('permission helpers (UI only — mirrors backend WorkspaceRole)', () => {
  it('OWNER manages everyone but owners', () => {
    expect(manageableRoles('OWNER')).toEqual(['ADMIN', 'EDITOR', 'VIEWER'])
    expect(canManageMember('OWNER', 'OWNER')).toBe(false)
  })

  it('ADMIN cannot manage ADMIN or OWNER', () => {
    expect(canManageMember('ADMIN', 'ADMIN')).toBe(false)
    expect(canManageMember('ADMIN', 'OWNER')).toBe(false)
    expect(canManageMember('ADMIN', 'EDITOR')).toBe(true)
  })

  it('EDITOR and VIEWER manage nobody', () => {
    expect(manageableRoles('EDITOR')).toEqual([])
    expect(manageableRoles('VIEWER')).toEqual([])
    expect(manageableRoles(null)).toEqual([])
  })

  it('hasPermission checks the list from the API', () => {
    expect(hasPermission(['members.view'], 'members.view')).toBe(true)
    expect(hasPermission(['members.view'], 'members.manage')).toBe(false)
    expect(hasPermission(undefined, 'members.view')).toBe(false)
  })
})
