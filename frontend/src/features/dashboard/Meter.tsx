import { cn } from '@/lib/utils'

type Level = 'good' | 'warning' | 'critical'

const FILL: Record<Level, string> = {
  good: 'bg-success',
  warning: 'bg-warning',
  critical: 'bg-destructive',
}

// Track = langkah lebih terang dari warna isian yang sama, agar status terbaca di seluruh bar.
const TRACK: Record<Level, string> = {
  good: 'bg-success/15',
  warning: 'bg-warning/20',
  critical: 'bg-destructive/15',
}

/** Bar meter: isian membawa tingkat keparahan. `value` 0–1. */
export function Meter({ value, level, label }: { value: number; level: Level; label: string }) {
  const pct = Math.min(1, Math.max(0, value)) * 100
  return (
    <div className={cn('h-2 w-full overflow-hidden rounded-full', TRACK[level])} role="meter" aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
      <div className={cn('h-full rounded-full transition-[width] duration-700', FILL[level])} style={{ width: `${pct}%` }} />
    </div>
  )
}
