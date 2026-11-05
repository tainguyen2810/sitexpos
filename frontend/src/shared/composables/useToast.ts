import { ref } from 'vue'

export interface Toast {
  id: number
  message: string
  tone: 'success' | 'error'
}

const toasts = ref<Toast[]>([])
let seq = 0

export function useToast() {
  function dismiss(id: number) {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  function push(message: string, tone: Toast['tone'] = 'success') {
    const id = ++seq
    toasts.value.push({ id, message, tone })
    setTimeout(() => dismiss(id), 5000)
  }

  return { toasts, dismiss, success: (m: string) => push(m, 'success'), error: (m: string) => push(m, 'error') }
}
