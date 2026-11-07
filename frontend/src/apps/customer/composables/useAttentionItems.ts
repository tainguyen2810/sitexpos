import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { IconName } from '@/shared/ui/AppIcon.vue'
import { useAttentionStore } from '../stores/attention'

export interface AttentionItem {
  key: 'review' | 'failed' | 'conflicts' | 'agent' | 'sites'
  icon: IconName
  tone: string
  title: string
  desc: string
  cta: string
  to: string
  count: number
}

/** The "things waiting for you" list shared by the bell and the dashboard. */
export function useAttentionItems(workspaceId: () => string | null) {
  const store = useAttentionStore()
  const { t } = useI18n()
  const items = computed<AttentionItem[]>(() => {
    const id = workspaceId()
    if (!id) return []
    const a = store.data
    const base = `/w/${id}`
    const list: AttentionItem[] = []
    const add = (key: AttentionItem['key'], n: number | null, more: boolean, icon: IconName, tone: string, to: string) => {
      if (!n) return
      const count = more ? `${n}+` : String(n)
      list.push({ key, icon, tone, to, count: n, title: t(`attention.${key}.title`, { n: count }), desc: t(`attention.${key}.desc`), cta: t(`attention.${key}.cta`) })
    }
    add('review', a.needsReview, false, 'pen', 'bg-brand-50 text-brand-600', `${base}/writer?status=NEEDS_HUMAN_REVIEW`)
    add('failed', a.failedJobs, a.failedMore, 'alert', 'bg-danger-50 text-danger-600', `${base}/publishing/history?status=FAILED`)
    add('conflicts', a.openConflicts, a.conflictsMore, 'refresh', 'bg-warning-50 text-warning-600', `${base}/publishing/conflicts`)
    add('agent', a.agentApprovals, false, 'cpu', 'bg-violet-50 text-violet-600', `${base}/agent/approvals`)
    add('sites', a.brokenSites, false, 'globe', 'bg-danger-50 text-danger-600', `${base}/sites`)
    return list
  })
  return { items }
}
