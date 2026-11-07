import { defineStore } from 'pinia'
import { ref } from 'vue'
import { writerApi } from '@/shared/api/writer'
import { publishingApi } from '@/shared/api/publishing'
import { agentApi } from '@/shared/api/agent'
import { billingApi } from '@/shared/api/billing'
import { siteApi } from '@/shared/api/sites'

/**
 * Counters of things waiting for the user, shown as badges in the menu, in the
 * bell and in "Việc cần bạn xử lý" on the dashboard. Every number comes from an
 * existing tenant endpoint (the API still enforces permissions); a call that
 * fails or is forbidden simply leaves its counter at null (= not shown).
 */
export interface Attention {
  needsReview: number | null
  failedJobs: number | null
  failedMore: boolean
  openConflicts: number | null
  conflictsMore: boolean
  agentApprovals: number | null
  brokenSites: number | null
  creditsMc: number | null
}

const empty = (): Attention => ({
  needsReview: null,
  failedJobs: null,
  failedMore: false,
  openConflicts: null,
  conflictsMore: false,
  agentApprovals: null,
  brokenSites: null,
  creditsMc: null,
})

const TTL_MS = 60_000

async function settle<T>(p: Promise<T>): Promise<T | null> {
  try {
    return await p
  } catch {
    return null
  }
}

export const useAttentionStore = defineStore('attention', () => {
  const data = ref<Attention>(empty())
  const workspaceId = ref<string | null>(null)
  let loadedAt = 0
  let inflight: Promise<void> | null = null

  async function load(ws: string, force = false): Promise<void> {
    if (!force && ws === workspaceId.value && Date.now() - loadedAt < TTL_MS) return
    if (inflight && ws === workspaceId.value) return inflight
    if (ws !== workspaceId.value) data.value = empty()
    workspaceId.value = ws
    inflight = (async () => {
      const [overview, failed, conflicts, tasks, billing, sites] = await Promise.all([
        settle(writerApi.overview(ws)),
        settle(publishingApi.jobs(ws, { status: 'FAILED' })),
        settle(publishingApi.conflicts(ws, { status: 'open' })),
        settle(agentApi.tasks(ws, { status: 'awaiting_approval' })),
        settle(billingApi.overview(ws)),
        settle(siteApi.list(ws)),
      ])
      if (workspaceId.value !== ws) return
      data.value = {
        needsReview: overview?.needs_review ?? null,
        failedJobs: failed ? failed.data.length : null,
        failedMore: !!failed?.meta.next_cursor,
        openConflicts: conflicts ? conflicts.data.length : null,
        conflictsMore: !!conflicts?.meta.next_cursor,
        agentApprovals: tasks ? tasks.data.length : null,
        brokenSites: sites ? sites.filter((s) => s.status === 'degraded' || s.status === 'revoked').length : null,
        creditsMc: billing?.wallet.available_mc ?? null,
      }
      loadedAt = Date.now()
    })().finally(() => (inflight = null))
    return inflight
  }

  function clear() {
    data.value = empty()
    workspaceId.value = null
    loadedAt = 0
  }

  return { data, load, clear }
})
