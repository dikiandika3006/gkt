/** Perubahan relatif terhadap pembanding. Null jika pembanding 0 (persentase tidak bermakna). */
export function percentChange(current: number, previous: number): number | null {
  if (!previous) return null
  return ((current - previous) / Math.abs(previous)) * 100
}

export type Tone = 'good' | 'bad' | 'neutral'

/** Warna arah perubahan: naik bagus untuk pemasukan/saldo, buruk untuk pengeluaran. */
export function deltaTone(change: number | null, upIsGood: boolean): Tone {
  if (change === null || Math.abs(change) < 0.05) return 'neutral'
  return change > 0 === upIsGood ? 'good' : 'bad'
}

export const TONE_TEXT: Record<Tone, string> = {
  good: 'text-success',
  bad: 'text-destructive',
  neutral: 'text-muted-foreground',
}

export type ResilienceLevel = 'kritis' | 'waspada' | 'aman'

/** Ketahanan kas: < 1 bulan kritis, 1–3 bulan waspada, ≥ 3 bulan aman. */
export function resilienceLevel(months: number | null): ResilienceLevel {
  if (months === null || months >= 3) return 'aman'
  return months < 1 ? 'kritis' : 'waspada'
}
