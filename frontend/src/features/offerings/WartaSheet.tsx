import { formatDate } from '@/lib/format'
import type { OfferingLine, OfferingReport } from '@/types'
import { CHURCH_NAME, formatPlain } from './utils'

// Saat dicetak hanya lembar ini yang tampil (tanpa sidebar/menu), warna tetap ikut tercetak.
const PRINT_CSS = `
@media print {
  @page { size: A4 portrait; margin: 12mm; }
  body * { visibility: hidden; }
  .warta-print, .warta-print * { visibility: visible; }
  .warta-print { position: absolute; left: 0; top: 0; box-shadow: none !important; }
}
.warta-print, .warta-print * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
`

/** "16 – 22 Oktober 2026", atau "30 Oktober – 5 November 2026" bila lintas bulan. */
function periodText(start: string, end: string): string {
  const sameMonth = start.slice(0, 7) === end.slice(0, 7)
  const sameYear = start.slice(0, 4) === end.slice(0, 4)
  return `${formatDate(start, sameMonth ? 'd' : sameYear ? 'd MMMM' : 'd MMMM yyyy')} – ${formatDate(end, 'd MMMM yyyy')}`
}

/**
 * Lembar "Laporan Persembahan Mingguan" untuk warta jemaat.
 * Warna sengaja tetap (tidak ikut mode gelap) agar hasil screenshot/cetak selalu sama.
 */
export function WartaSheet({ report }: { report: OfferingReport }) {
  const lines = report.lines ?? []
  const of = (section: OfferingLine['section']) => lines.filter((l) => l.section === section)
  const s = report.summary.sections
  const expenses = of('pengeluaran')

  return (
    <div className="warta-print w-[780px] max-w-none rounded-2xl bg-white p-7 font-sans text-[14px] font-medium text-neutral-900 shadow-sm">
      <style>{PRINT_CSS}</style>

      <div className="rounded-xl bg-gradient-to-r from-[#1e3a9a] via-[#2a56c6] to-[#3b82f6] py-4 text-center text-2xl font-bold tracking-wide text-white">
        Laporan Persembahan Mingguan
      </div>

      <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border border-neutral-200 bg-neutral-50 px-3 py-2.5 text-[15px]">
        <span>
          <b>Gereja:</b> {CHURCH_NAME}
        </span>
        <span>
          <b>Periode:</b> {periodText(report.period_start, report.period_end)}
        </span>
      </div>

      <div className="mt-4 grid grid-cols-2 items-start gap-x-6 gap-y-4">
        <SheetTable title="Penerimaan Ibadah" rows={of('ibadah').map((l) => [l.label ?? '', l.amount])} total={s.ibadah} />
        <SheetTable title="Perpuluhan" rows={of('perpuluhan').map((l) => [l.name ?? '', l.amount])} total={s.perpuluhan} />
        <SheetTable title="Persembahan Syukur" rows={of('syukur').map((l) => [l.name ?? '', l.amount])} total={s.syukur} />
        <SheetTable
          title="Persembahan Lain"
          rows={of('lainnya').map((l) => [l.name ? `${l.label ?? ''} (${l.name})` : (l.label ?? ''), l.amount])}
          total={s.lainnya}
        />
        {expenses.length > 0 && (
          <SheetTable title="Pengeluaran (catatan)" rows={expenses.map((l) => [[l.label, l.name].filter(Boolean).join(' · '), l.amount])} total={s.pengeluaran} />
        )}
      </div>

      <div className="mt-6 flex items-center justify-between border border-[#cfe9d6] border-l-[6px] border-l-[#2e9e55] bg-[#eef8f1] px-4 py-3">
        <span className="text-base font-bold tracking-wide">TOTAL PEMASUKAN</span>
        <span className="text-2xl font-bold text-[#21813f]">Rp {formatPlain(report.summary.total_offering)}</span>
      </div>
    </div>
  )
}

function SheetTable({ title, rows, total }: { title: string; rows: [string, number][]; total: number }) {
  return (
    <table className="w-full border-collapse">
      <thead>
        <tr className="bg-[#e3f3e7] text-[15px] font-bold text-[#1f6b39]">
          <td colSpan={2} className="border border-neutral-200 px-2 py-1.5">
            {title}
          </td>
          <td className="w-28 border border-neutral-200 px-2 py-1.5 text-right text-[14px]">Jumlah (Rp)</td>
        </tr>
      </thead>
      <tbody>
        {rows.map(([label, amount], i) => (
          <tr key={i}>
            <td className="w-8 border border-neutral-200 px-2 py-1.5 text-neutral-700">{i + 1}.</td>
            <td className="border border-neutral-200 px-2 py-1.5">{label}</td>
            <td className="border border-neutral-200 px-2 py-1.5 text-right tabular-nums">{formatPlain(amount)}</td>
          </tr>
        ))}
        {rows.length === 0 && (
          <tr>
            <td colSpan={3} className="border border-neutral-200 px-2 py-1.5 text-center text-neutral-400">
              —
            </td>
          </tr>
        )}
        <tr className="bg-neutral-100 font-bold">
          <td colSpan={2} className="border border-neutral-200 px-2 py-1.5">
            Total
          </td>
          <td className="border border-neutral-200 px-2 py-1.5 text-right tabular-nums">{formatPlain(total)}</td>
        </tr>
      </tbody>
    </table>
  )
}
