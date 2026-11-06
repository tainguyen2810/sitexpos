import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { agentApi, type AgentTask } from '@/shared/api/agent'
import { useAnalytics } from '../analytics/useAnalytics'

/** Approve / reject / retry an agent task; the server re-checks permission and policy each time. */
export function useTaskActions(after: () => unknown) {
  const { t } = useI18n()
  const { workspaceId, toast, toastError } = useAnalytics()
  const busy = ref('')
  async function act(task: AgentTask, kind: 'approve' | 'reject' | 'retry', note?: string) {
    busy.value = task.id
    try {
      if (kind === 'approve') await agentApi.approve(workspaceId.value, task.id, note || undefined)
      if (kind === 'reject') await agentApi.reject(workspaceId.value, task.id, note || undefined)
      if (kind === 'retry') await agentApi.retry(workspaceId.value, task.id)
      toast.success(t(`ag.task.done.${kind}`))
      await after()
    } catch (e) {
      toastError(e)
    } finally {
      busy.value = ''
    }
  }
  return { busy, act }
}
