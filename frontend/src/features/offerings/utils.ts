import { addDays, format, parseISO } from 'date-fns'
import type { OfferingCheckStatus, OfferingSection } from '@/types'

/** Nama gereja pada laporan warta. */
export const CHURCH_NAME = 'GKT Sinai Batu'

export const SECTIONS: { key: OfferingSection; title: string; byName: boolean; side: 'left' | 'right' }[] = [
  { key: 'ibadah', title: 'Persembahan Ibadah', byName: false, side: 'left' },
  { key: 'syukur', title: 'Persembahan Syukur', byName: true, side: 'left' },
  { key: 'perpuluhan', title: 'Perpuluhan', byName: true, side: 'left' },
  { key: 'lainnya', title: 'Persembahan Lain', byName: false, side: 'right' },
  { key: 'pengeluaran', title: 'Pengeluaran', byName: false, side: 'right' },
]

export const STATUS: Record<OfferingCheckStatus, { label: string; className: string }> = {
  belum: { label: 'Belum dicek', className: 'border-border text-muted-foreground' },
  cocok: { label: 'Cocok', className: 'border-success/40 text-success' },
  kurang: { label: 'Kurang', className: 'border-destructive/40 text-destructive' },
  lebih: { label: 'Lebih', className: 'border-warning/50 text-warning' },
}

/** Periode warta: Jumat sebelum (atau pada) tanggal ibadah s/d Kamis sesudahnya, mis. Minggu 25 Okt → 23–29 Okt. */
export function weekPeriod(serviceDate: string): { start: string; end: string } {
  const date = parseISO(serviceDate)
  const start = addDays(date, -((date.getDay() - 5 + 7) % 7))
  return { start: format(start, 'yyyy-MM-dd'), end: format(addDays(start, 6), 'yyyy-MM-dd') }
}

/** Angka gaya warta (tanpa "Rp"), mis. 1.270.500. */
export const formatPlain = (value: number) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(value)
