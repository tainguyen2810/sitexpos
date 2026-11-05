import { reactive, ref } from 'vue'

export type Rule<V> = (value: V, all: Record<string, unknown>) => string | true

export const rules = {
  required: (msg = 'Trường này là bắt buộc.') => (v: unknown) => (v === null || v === undefined || String(v).trim() === '' ? msg : true),
  minLength: (n: number) => (v: unknown) => (String(v ?? '').trim().length >= n ? true : `Tối thiểu ${n} ký tự.`),
  maxLength: (n: number) => (v: unknown) => (String(v ?? '').length <= n ? true : `Tối đa ${n} ký tự.`),
  email: () => (v: unknown) => (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v ?? '')) ? true : 'Email không hợp lệ.'),
  url: () => (v: unknown) => {
    try {
      const u = new URL(String(v ?? ''))
      return u.protocol === 'https:' || u.protocol === 'http:' ? true : 'URL phải bắt đầu bằng http:// hoặc https://'
    } catch {
      return 'URL không hợp lệ (ví dụ: https://tenmien.vn).'
    }
  },
  min: (n: number) => (v: unknown) => (Number(v) >= n ? true : `Giá trị tối thiểu là ${n}.`),
  max: (n: number) => (v: unknown) => (Number(v) <= n ? true : `Giá trị tối đa là ${n}.`),
}

/** Tiny form helper with per-field validation messages (Vietnamese). */
export function useForm<T extends Record<string, unknown>>(initial: () => T, fieldRules: Partial<Record<keyof T, ((v: unknown, all: Record<string, unknown>) => string | true)[]>> = {}) {
  const values = reactive(initial()) as T
  const errors = reactive<Partial<Record<keyof T, string>>>({}) as Partial<Record<keyof T, string>>
  const submitting = ref(false)

  function validateField(key: keyof T): boolean {
    for (const rule of fieldRules[key] ?? []) {
      const res = rule(values[key], values)
      if (res !== true) {
        errors[key] = res
        return false
      }
    }
    delete errors[key]
    return true
  }
  function validate(): boolean {
    let ok = true
    for (const key in fieldRules) if (!validateField(key)) ok = false
    return ok
  }
  function reset(next?: Partial<T>) {
    Object.assign(values, initial(), next ?? {})
    for (const key in errors) delete errors[key]
  }
  async function submit(fn: () => Promise<void> | void) {
    if (!validate()) return false
    submitting.value = true
    try {
      await fn()
      return true
    } finally {
      submitting.value = false
    }
  }
  return { values, errors, submitting, validate, validateField, reset, submit }
}
