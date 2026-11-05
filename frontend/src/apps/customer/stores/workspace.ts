import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { workspaceApi } from '@/shared/api/workspaces'
import type { Workspace } from '@/shared/api/types'
import { hasPermission } from '@/shared/utils/permissions'

const LAST_KEY = 'sitex.lastWorkspace'

export function rememberWorkspace(id: string): void {
  try {
    localStorage.setItem(LAST_KEY, id) // UI preference only, never a credential
  } catch {
    /* ignore */
  }
}

export function lastWorkspace(): string | null {
  try {
    return localStorage.getItem(LAST_KEY)
  } catch {
    return null
  }
}

/**
 * Current workspace as returned by the API. The server decides membership and
 * permissions; this store only mirrors them for showing/hiding UI.
 */
export const useWorkspaceStore = defineStore('workspace', () => {
  const current = ref<Workspace | null>(null)

  const permissions = computed(() => current.value?.permissions ?? [])
  const can = (permission: string) => hasPermission(permissions.value, permission)

  async function load(id: string, force = false): Promise<Workspace> {
    if (!force && current.value?.id === id) return current.value
    current.value = await workspaceApi.get(id)
    rememberWorkspace(id)
    return current.value
  }

  function set(workspace: Workspace) {
    current.value = workspace
    rememberWorkspace(workspace.id)
  }

  function clear() {
    current.value = null
  }

  return { current, permissions, can, load, set, clear }
})
