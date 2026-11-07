import type { AiProvider, Capability } from '@/shared/api/ai'

/** Env variable that supplies each built-in provider's key (shown when the key comes from .env). */
export const PROVIDER_ENV: Record<string, string> = {
  openai: 'OPENAI_API_KEY', gemini: 'GEMINI_API_KEY', anthropic: 'ANTHROPIC_API_KEY', deepseek: 'DEEPSEEK_API_KEY', fake: 'SITEX_AI_FAKE_PROVIDER=true',
}
export const MODEL_CAPS: Capability[] = ['structured_output', 'web_search', 'image_generation', 'temperature', 'vision']

const AVATAR = [
  'bg-sky-50 text-sky-700 ring-sky-600/15', 'bg-emerald-50 text-emerald-700 ring-emerald-600/15', 'bg-violet-50 text-violet-700 ring-violet-600/15',
  'bg-amber-50 text-amber-700 ring-amber-600/20', 'bg-rose-50 text-rose-700 ring-rose-600/15', 'bg-indigo-50 text-indigo-700 ring-indigo-600/15',
]

export function providerInitials(name: string): string {
  const w = name.replace(/[^\p{L}\p{N}\s]/gu, ' ').trim().split(/\s+/)
  return (w.length > 1 ? (w[0]?.[0] ?? '') + (w[1]?.[0] ?? '') : (w[0] ?? name).slice(0, 2)).toUpperCase()
}

export function providerAvatarClass(p: AiProvider): string {
  if (!p.is_enabled || !p.has_api_key) return 'bg-slate-100 text-slate-500 ring-slate-500/15'
  let h = 0
  for (const c of p.code) h = (h * 31 + c.charCodeAt(0)) >>> 0
  return AVATAR[h % AVATAR.length] ?? AVATAR[0]!
}

/** Keyed providers first (in use, then paused), then the ones still missing a key, the test-only fake provider last. */
export function providerRank(p: AiProvider): number {
  if (p.has_api_key) return p.is_enabled ? 0 : 1
  return p.driver === 'fake' ? 3 : 2
}
