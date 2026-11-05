import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { db } from '../store'
import type { DemoWorkspace } from '../data/types'

/** Current demo workspace from the URL (/w/:workspaceId/...), like the real customer app. */
export function useWorkspace() {
  const route = useRoute()
  const ws = computed<DemoWorkspace>(() => db.workspaces.find((w) => w.id === route.params.workspaceId) ?? db.workspaces[0]!)
  const base = computed(() => `/w/${ws.value.id}`)
  function mine<T extends { workspaceId: string }>(list: T[]): T[] {
    return list.filter((x) => x.workspaceId === ws.value.id)
  }
  const sites = computed(() => mine(db.sites))
  const siteName = (id: string | null) => db.sites.find((s) => s.id === id)?.name ?? '—'
  const campaignName = (id: string | null) => db.campaigns.find((c) => c.id === id)?.name ?? '—'
  const crumbs = (...rest: { label: string; to?: string }[]) => [{ label: ws.value.name, to: `${base.value}/dashboard` }, ...rest]
  return { ws, base, mine, sites, siteName, campaignName, crumbs }
}
