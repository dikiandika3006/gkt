import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { AnnualReport, MatrixGroup } from '@/types'
import { Field, ReportToolbar, YearInput } from './components'

const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']
const money = (v: number) => (v < 0 ? `(${formatRupiah(-v)})` : formatRupiah(v))

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="grid gap-2 break-inside-avoid">
      <h3 className="font-semibold">{title}</h3>
      {children}
    </section>
  )
}

function SimpleTable({ head, rows, foot }: { head: string[]; rows: (string | number)[][]; foot?: (string | number)[] }) {
  const cell = (v: string | number, i: number) => (
    <td key={i} className={cn('py-0.5 pr-3', typeof v === 'number' && 'text-right tabular-nums whitespace-nowrap')}>
      {typeof v === 'number' ? money(v) : v}
    </td>
  )
  return (
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-foreground/40 text-xs">
          {head.map((h, i) => (
            <th key={h} className={cn('py-1 pr-3 font-medium', i === 0 ? 'text-left' : 'text-right')}>
              {h}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((r, i) => (
          <tr key={i}>{r.map(cell)}</tr>
        ))}
        {foot && <tr className="border-t border-foreground/40 font-semibold">{foot.map(cell)}</tr>}
      </tbody>
    </table>
  )
}

const groupRows = (groups: MatrixGroup[]) =>
  groups.filter((g) => g.nature === 'operational').flatMap((g) => [[g.group, g.total], ...g.categories.filter((c) => c.name !== g.group).map((c) => [`   ${c.name}`, c.total])])

/** LPJ Keuangan Tahunan untuk Rapat Jemaat: satu dokumen cetak + Excel multi-sheet. */
export default function AnnualPage() {
  const [year, setYear] = useState(() => new Date().getFullYear())

  const { data, isLoading } = useQuery({
    queryKey: ['reports', 'annual', year],
    queryFn: async () => (await api.get<AnnualReport>('/reports/annual', { params: { year } })).data,
  })

  const months = data?.summary.months ?? []
  const sum = (k: 'income' | 'expense' | 'net') => months.reduce((s, m) => s + m[k], 0)
  const operationalTotal = (groups: MatrixGroup[]) => groups.filter((g) => g.nature === 'operational').reduce((s, g) => s + g.total, 0)
  const fp = data?.fund_position

  return (
    <>
      <ReportToolbar exportName="annual" exportParams={{ year }} filename={`lpj-tahunan-${year}.xlsx`}>
        <Field label="Tahun">
          <YearInput value={year} onChange={setYear} />
        </Field>
        <p className="self-center text-xs text-muted-foreground">Kop & penandatangan diatur di menu Setting. Excel berisi 6 sheet.</p>
      </ReportToolbar>

      <Card className="mx-auto w-full max-w-4xl print:max-w-none print:border-0 print:shadow-none print:ring-0">
        <CardContent className="grid gap-6 p-6 sm:p-10 print:p-0 print:text-[12px]">
          {isLoading || !data || !fp ? (
            <Skeleton className="h-96" />
          ) : (
            <>
              <header className="border-b-2 border-foreground pb-3 text-center">
                <h2 className="text-lg font-bold uppercase tracking-wide">{data.header.church_name}</h2>
                <p className="font-semibold">Laporan Pertanggungjawaban Keuangan Tahun {data.year}</p>
                <p className="text-xs text-muted-foreground print:text-foreground">Data s/d {formatDate(data.as_of, 'd MMMM yyyy')}</p>
              </header>

              <Section title="I. Ringkasan per Bulan (Kas Umum)">
                <SimpleTable
                  head={['Bulan', 'Penerimaan', 'Pengeluaran', 'Surplus/(Defisit)', 'Saldo Akhir']}
                  rows={[
                    ['Saldo awal tahun', '', '', '', data.summary.opening_balance],
                    ...months.map((m) => [MONTHS[Number(m.month.slice(5, 7)) - 1], m.income, m.expense, m.net, m.closing_balance]),
                  ]}
                  foot={['Jumlah', sum('income'), sum('expense'), sum('net'), data.summary.closing_balance]}
                />
              </Section>

              <div className="grid gap-6 md:grid-cols-2 print:grid-cols-2">
                <Section title="II. Penerimaan Operasional">
                  <SimpleTable head={['Kelompok / Kategori', 'Setahun']} rows={groupRows(data.summary.income)} foot={['Jumlah', operationalTotal(data.summary.income)]} />
                </Section>
                <Section title="III. Pengeluaran Operasional">
                  <SimpleTable head={['Kelompok / Kategori', 'Setahun']} rows={groupRows(data.summary.expense)} foot={['Jumlah', operationalTotal(data.summary.expense)]} />
                </Section>
              </div>

              {data.budget.rows.length > 0 && (
                <Section title="IV. Realisasi Anggaran">
                  <SimpleTable
                    head={['Kategori', 'Anggaran', 'Realisasi', 'Sisa', '%']}
                    rows={data.budget.rows.map((r) => [
                      `${r.type === 'income' ? 'Penerimaan' : 'Pengeluaran'} · ${r.category?.name ?? ''}`,
                      r.amount,
                      r.actual,
                      r.remaining,
                      `${r.percentage.toLocaleString('id-ID')}%`,
                    ])}
                  />
                </Section>
              )}

              <Section title={`${data.budget.rows.length > 0 ? 'V' : 'IV'}. Posisi Dana per ${formatDate(fp.date, 'd MMMM yyyy')}`}>
                <SimpleTable
                  head={['Uraian', 'Jumlah']}
                  rows={[
                    ['Total uang di semua kas & bank', fp.total],
                    ['Dikurangi dana khusus (rekening ber-pocket)', -fp.special_funds],
                    ['Dikurangi titipan belum disalurkan', -fp.deposits],
                    ['Dikurangi utang pinjaman', -fp.loan_debt],
                    ...fp.accounts.map((a) => [`   Saldo ${a.name}`, a.balance]),
                  ]}
                  foot={['Dana bebas (operasional)', fp.free_funds]}
                />
              </Section>

              {data.pocket_accounts.map((acc) => (
                <Section key={acc.id} title={`Dana Khusus · ${acc.name}`}>
                  <SimpleTable
                    head={['Pocket', 'Saldo Awal', 'Masuk', 'Keluar', 'Saldo Akhir']}
                    rows={acc.pockets.map((p) => [p.name, p.opening_balance, p.income, p.expense, p.closing_balance])}
                    foot={[
                      'Jumlah',
                      acc.pockets.reduce((s, p) => s + p.opening_balance, 0),
                      acc.pockets.reduce((s, p) => s + p.income, 0),
                      acc.pockets.reduce((s, p) => s + p.expense, 0),
                      acc.pockets.reduce((s, p) => s + p.closing_balance, 0),
                    ]}
                  />
                </Section>
              ))}

              {data.deposits_loans.length > 0 && (
                <Section title="Titipan & Pinjaman">
                  <SimpleTable
                    head={['Kategori · Nama', 'Saldo Awal', 'Masuk', 'Keluar', 'Sisa']}
                    rows={data.deposits_loans.flatMap((c) => c.people.map((p) => [`${c.name} · ${p.name}`, p.opening, p.income, p.expense, p.closing]))}
                  />
                </Section>
              )}

              <section className="mt-4 grid grid-cols-2 gap-8 text-center text-sm break-inside-avoid">
                <p className="col-span-2 text-right">{formatDate(data.as_of, 'd MMMM yyyy')}</p>
                {data.header.signers.map((s) => (
                  <div key={s.title} className="grid gap-16">
                    <p>{s.title}</p>
                    <p className="font-semibold underline underline-offset-4">{s.name || '( ............................ )'}</p>
                  </div>
                ))}
              </section>
            </>
          )}
        </CardContent>
      </Card>
    </>
  )
}
