import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'

/**
 * Submits a form and maps API validation errors to fields.
 */
export function useApiForm() {
  const { t } = useI18n()
  const errors = reactive<Record<string, string | undefined>>({})
  const formError = ref<string | null>(null)
  const submitting = ref(false)

  function reset() {
    for (const key of Object.keys(errors)) errors[key] = undefined
    formError.value = null
  }

  async function submit<T>(fn: () => Promise<T>): Promise<T | undefined> {
    reset()
    submitting.value = true
    try {
      return await fn()
    } catch (e) {
      if (e instanceof ApiError) {
        for (const [field, messages] of Object.entries(e.details)) errors[field] = messages[0]
        formError.value = e.code === 'validation_failed' && Object.keys(e.details).length > 0 ? null : messageFor(e)
      } else {
        formError.value = t('errors.generic')
      }
      return undefined
    } finally {
      submitting.value = false
    }
  }

  function messageFor(e: ApiError): string {
    if (e.status === 0) return t('errors.network')
    return e.message || t('errors.generic')
  }

  return { errors, formError, submitting, submit, reset }
}
