/** Small seeded PRNG (mulberry32) so the demo dataset is identical on every reset. */
export function createRng(seed: number) {
  let a = seed >>> 0
  const next = () => {
    a = (a + 0x6d2b79f5) >>> 0
    let t = a
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
  const int = (min: number, max: number) => Math.floor(next() * (max - min + 1)) + min
  const pick = <T>(list: readonly T[]): T => list[Math.floor(next() * list.length)] as T
  const chance = (p: number) => next() < p
  const shuffle = <T>(list: readonly T[]): T[] => {
    const out = [...list]
    for (let i = out.length - 1; i > 0; i--) {
      const j = Math.floor(next() * (i + 1))
      ;[out[i], out[j]] = [out[j] as T, out[i] as T]
    }
    return out
  }
  const weighted = <T>(entries: readonly (readonly [T, number])[]): T => {
    const total = entries.reduce((s, [, w]) => s + w, 0)
    let r = next() * total
    for (const [v, w] of entries) {
      r -= w
      if (r <= 0) return v
    }
    return entries[entries.length - 1]![0]
  }
  return { next, int, pick, chance, shuffle, weighted }
}

export type Rng = ReturnType<typeof createRng>

let counter = 0
/** Deterministic, lowercase ULID-like ids (the real API uses lowercase ULIDs). */
export function demoId(prefix: string): string {
  counter += 1
  return `${prefix}_demo${counter.toString(36).padStart(5, '0')}`
}
export function resetIds(): void {
  counter = 0
}
