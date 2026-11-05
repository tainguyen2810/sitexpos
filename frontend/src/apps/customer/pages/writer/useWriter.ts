import { computed, onBeforeUnmount, watch, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'
import { useToast } from '@/shared/composables/useToast'
import { useWorkspaceStore } from '../../stores/workspace'

/** Permission-aware flags for writer screens (the API enforces them too). */
export function useWriterPerms() {
  const ws = useWorkspaceStore()
  const readOnly = computed(() => !!ws.current?.impersonating)
  return {
    workspaceId: computed(() => ws.current!.id),
    canEdit: computed(() => ws.can('content.edit') && !readOnly.value),
    canApprove: computed(() => ws.can('content.publish') && !readOnly.value),
    readOnly,
  }
}

/** Calls `fn` every `ms` while `active` is true (stops on unmount). */
export function usePolling(fn: () => unknown, active: Ref<boolean>, ms = 4000) {
  let timer: ReturnType<typeof setInterval> | null = null
  const stop = () => {
    if (timer) clearInterval(timer)
    timer = null
  }
  watch(
    active,
    (on) => {
      stop()
      if (on) timer = setInterval(() => void fn(), ms)
    },
    { immediate: true },
  )
  onBeforeUnmount(stop)
  return { stop }
}

/** Toast an API error with a localized message for known writer codes. */
export function useWriterErrors() {
  const { t, te } = useI18n()
  const toast = useToast()
  function message(e: unknown): string {
    if (e instanceof ApiError) {
      if (e.status === 0) return t('errors.network')
      const key = `writer.errors.${e.code}`
      if (te(key)) return e.message || t(key)
      return e.message || t('errors.generic')
    }
    return t('errors.generic')
  }
  return { message, toastError: (e: unknown) => toast.error(message(e)), toast }
}
