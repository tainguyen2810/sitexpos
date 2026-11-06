// Validated categorical order (dataviz reference palette, light mode). Assign in order, never cycle.
export const SERIES_COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'] as const
export const seriesColor = (i: number) => SERIES_COLORS[Math.min(i, SERIES_COLORS.length - 1)]!

export function niceMax(v: number): number {
  if (v <= 0) return 1
  const exp = Math.pow(10, Math.floor(Math.log10(v)))
  const f = v / exp
  const nice = f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10
  return nice * exp
}

export interface Series {
  name: string
  values: number[]
  color?: string
}
