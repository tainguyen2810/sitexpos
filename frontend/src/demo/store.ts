import { reactive, ref, watch } from 'vue'
import { DEMO_DB_VERSION, generateDemoDb } from './data/seed'
import type { DemoDb } from './data/types'

// The demo "backend": a reactive in-browser database persisted to localStorage
// (sample data only — no tokens or personal data). Reset regenerates the seed.
const STORAGE_KEY = 'sitex.demo.db'
const SETTINGS_KEY = 'sitex.demo.settings'

function load(): DemoDb {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) {
      const parsed = JSON.parse(raw) as DemoDb
      if (parsed.version === DEMO_DB_VERSION) return parsed
    }
  } catch {
    /* corrupted or unavailable storage → regenerate */
  }
  return generateDemoDb()
}

export const db = reactive<DemoDb>(load())

let saveTimer: ReturnType<typeof setTimeout> | undefined
watch(
  db,
  () => {
    clearTimeout(saveTimer)
    saveTimer = setTimeout(() => {
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(db))
      } catch {
        /* quota exceeded: keep working in memory */
      }
    }, 300)
  },
  { deep: true },
)

/** Restores the original sample dataset (the "Reset demo" button). */
export function resetDemo(): void {
  Object.assign(db, generateDemoDb())
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(db))
  } catch {
    /* ignore */
  }
}

// ---- Simulated network behaviour (Demo panel) ----
interface DemoSettings {
  latency: number
  failRequests: boolean
}
function loadSettings(): DemoSettings {
  try {
    const raw = localStorage.getItem(SETTINGS_KEY)
    if (raw) return { latency: 450, failRequests: false, ...(JSON.parse(raw) as Partial<DemoSettings>) }
  } catch {
    /* ignore */
  }
  return { latency: 450, failRequests: false }
}
export const demoSettings = reactive<DemoSettings>(loadSettings())
watch(demoSettings, () => {
  try {
    localStorage.setItem(SETTINGS_KEY, JSON.stringify(demoSettings))
  } catch {
    /* ignore */
  }
})

export class DemoApiError extends Error {
  constructor(
    message: string,
    public readonly code = 'demo_simulated_error',
  ) {
    super(message)
  }
}

export const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms))

/** Simulates one API round-trip: latency + optional injected failure. */
export async function simulateRequest(extraMs = 0): Promise<void> {
  await sleep(demoSettings.latency + extraMs)
  if (demoSettings.failRequests) throw new DemoApiError('Lỗi mô phỏng: máy chủ demo trả về 500. Tắt "Mô phỏng lỗi API" trong bảng Demo để tiếp tục.')
}

/** Runs a mutation as if it went through the API. */
export async function demoMutation<T>(fn: () => T, extraMs = 0): Promise<T> {
  await simulateRequest(extraMs)
  return fn()
}

/** Simulated background job (AI, crawl…) with progress updates. Never calls a real provider. */
export function simulateJob(steps: string[], onDone: () => void, stepMs = 700) {
  const progress = ref(0)
  const step = ref(steps[0] ?? '')
  const running = ref(true)
  const failed = ref<string | null>(null)
  let i = 0
  const tick = () => {
    if (demoSettings.failRequests && i === Math.floor(steps.length / 2)) {
      failed.value = 'Tác vụ mô phỏng thất bại (đang bật "Mô phỏng lỗi API").'
      running.value = false
      return
    }
    i += 1
    progress.value = Math.round((i / steps.length) * 100)
    if (i >= steps.length) {
      running.value = false
      onDone()
      return
    }
    step.value = steps[i] ?? ''
    setTimeout(tick, stepMs)
  }
  setTimeout(tick, stepMs)
  return { progress, step, running, failed }
}

let seq = 0
export function newId(prefix: string): string {
  seq += 1
  return `${prefix}_demo_new${Date.now().toString(36)}${seq}`
}
