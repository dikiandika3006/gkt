import { Fragment, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { format } from 'date-fns'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { MonthlyStatement, StatementAccount, StatementGroup } from '@/types'
import { DateInput, Field, ReportToolbar } from './components'

const money = (v: number) => (v < 0 ? `(${formatRupiah(-v)})` : formatRupiah(v))

function Row({ label, value, indent, bold, border, muted }: { label: ReactNode; value?: number | null; indent?: boolean; bold?: boolean; border?: boolean; muted?: boolean }) {
  return (
    <tr className={cn(bold && 'font-semibold', border && 'border-t border-foreground/40', muted && 'text-muted-foreground')}>
      <td className={cn('py-0.5 pr-4', indent && 'pl-6')}>{label}</td>
      <td className="py-0.5 text-right tabular-nums whitespace-nowrap">{value == null ? '' : money(value)}</td>
    </tr>
  )
}

function Groups({ groups }: { groups: StatementGroup[] }) {
  if (groups.length === 0) return <Row label="Tidak ada" muted indent />

  return groups.map((g) => (
    <Fragment key={g.group}>
      {g.categories.length === 1 && g.categories[0].name === g.group ? (
        <Row label={g.group} value={g.total} indent />
      ) : (
        <>
          <Row label={g.group} indent bold />
          {g.categories.map((c) => (
            <Row key={c.code} label={<span className="pl-4">{c.name}</span>} value={c.total} indent />
          ))}
        </>
      )}
    </Fragment>
  ))
}

function AccountTable({ rows, total, pockets }: { rows: StatementAccount[]; total?: boolean; pockets?: (StatementAccount & { pockets: { id: number; name: string; opening_balance: number; income: number; expense: number; closing_balance: number }[] })[] }) {
  const sum = (k: keyof StatementAccount) => rows.reduce((s, r) => s + Number(r[k]), 0)
  const cell = 'py-0.5 text-right tabular-nums whitespace-nowrap'

  return (
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-foreground/40 text-left text-xs">
          <th className="py-1 font-medium">Kas / Rekening</th>
          <th className="py-1 text-right font-medium">Saldo Awal</th>
          <th className="py-1 text-right font-medium">Masuk</th>
          <th className="py-1 text-right font-medium">Keluar</th>
          <th className="py-1 text-right font-medium">Saldo Akhir</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((a) => (
          <tr key={a.id}>
            <td className="py-0.5 pr-2">{a.name}</td>
            <td className={cell}>{money(a.opening_balance)}</td>
            <td className={cell}>{money(a.income)}</td>
            <td className={cell}>{money(a.expense)}</td>
            <td className={cell}>{money(a.closing_balance)}</td>
          </tr>
        ))}
        {pockets?.map((acc) => (
          <Fragment key={acc.id}>
            <tr className="font-medium">
              <td className="pt-2 pr-2">{acc.name}</td>
              <td className={cn(cell, 'pt-2')}>{money(acc.opening_balance)}</td>
              <td className={cn(cell, 'pt-2')}>{money(acc.income)}</td>
              <td className={cn(cell, 'pt-2')}>{money(acc.expense)}</td>
              <td className={cn(cell, 'pt-2')}>{money(acc.closing_balance)}</td>
            </tr>
            {acc.pockets.map((p) => (
              <tr key={p.id} className="text-muted-foreground print:text-foreground">
                <td className="py-0.5 pr-2 pl-4">· {p.name}</td>
                <td className={cell}>{money(p.opening_balance)}</td>
                <td className={cell}>{money(p.income)}</td>
                <td className={cell}>{money(p.expense)}</td>
                <td className={cell}>{money(p.closing_balance)}</td>
              </tr>
            ))}
          </Fragment>
        ))}
        {total && rows.length > 1 && (
          <tr className="border-t border-foreground/40 font-semibold">
            <td className="py-0.5">Jumlah</td>
            <td className={cell}>{money(sum('opening_balance'))}</td>
            <td className={cell}>{money(sum('income'))}</td>
            <td className={cell}>{money(sum('expense'))}</td>
            <td className={cell}>{money(sum('closing_balance'))}</td>
          </tr>
        )}
      </tbody>
    </table>
  )
}

/** Laporan keuangan bulanan siap cetak (A4): kas umum, posisi kas, dana khusus, tanda tangan. */
export default function MonthlyStatementPage() {
  const [month, setMonth] = useState(() => format(new Date(), 'yyyy-MM'))

  const { data, isLoading } = useQuery({
    queryKey: ['reports', 'monthly-statement', month],
    queryFn: async () => (await api.get<MonthlyStatement>('/reports/monthly-statement', { params: { month } })).data,
  })

  const monthLabel = formatDate(`${month}-01`, 'MMMM yyyy')
  const g = data?.general
  const t = g?.totals
  const nonIn = g?.non_operational_in ?? []
  const nonOut = g?.non_operational_out ?? []
  const transferNet = t ? t.transfer_in - t.transfer_out : 0

  return (
    <>
      <ReportToolbar exportName="monthly-statement" exportParams={{ month }} filename={`laporan-bulanan-${month}.xlsx`}>
        <Field label="Bulan">
          <DateInput type="month" value={month} onChange={setMonth} />
        </Field>
        <p className="self-center text-xs text-muted-foreground">Nama gereja & penandatangan diatur di menu Setting.</p>
      </ReportToolbar>

      <Card className="mx-auto w-full max-w-3xl print:max-w-none print:border-0 print:shadow-none print:ring-0">
        <CardContent className="grid gap-6 p-6 sm:p-10 print:p-0 print:text-[12px]">
          {isLoading || !data || !g || !t ? (
            <Skeleton className="h-96" />
          ) : (
            <>
              <header className="border-b-2 border-foreground pb-3 text-center">
                <h2 className="text-lg font-bold uppercase tracking-wide">{data.header.church_name}</h2>
                <p className="font-semibold">Laporan Keuangan Bulan {monthLabel}</p>
                <p className="text-xs text-muted-foreground print:text-foreground">
                  Periode {formatDate(data.period.start_date, 'd MMMM yyyy')} s/d {formatDate(data.period.end_date, 'd MMMM yyyy')}
                </p>
              </header>

              <section className="grid gap-2">
                <h3 className="font-semibold">I. Kas Umum</h3>
                <table className="w-full text-sm">
                  <tbody>
                    <Row label="Saldo awal" value={g.opening_balance} bold />
                    <Row label={<span className="font-semibold">Penerimaan operasional</span>} />
                    <Groups groups={g.income} />
                    <Row label="Jumlah penerimaan" value={t.income} bold border />
                    <Row label={<span className="font-semibold">Pengeluaran operasional</span>} />
                    <Groups groups={g.expense} />
                    <Row label="Jumlah pengeluaran" value={t.expense} bold border />
                    <Row label={t.net < 0 ? 'Defisit bulan ini' : 'Surplus bulan ini'} value={t.net} bold />
                    {(nonIn.length > 0 || nonOut.length > 0) && (
                      <>
                        <Row label={<span className="font-semibold">Titipan & pinjaman</span>} />
                        {nonIn.map((r) => (
                          <Row key={`in-${r.code}`} label={`${r.name} (masuk)`} value={r.total} indent />
                        ))}
                        {nonOut.map((r) => (
                          <Row key={`out-${r.code}`} label={`${r.name} (keluar)`} value={-r.total} indent />
                        ))}
                      </>
                    )}
                    {transferNet !== 0 && <Row label="Mutasi dari/ke rekening dana khusus (bersih)" value={transferNet} />}
                    <Row label="Saldo akhir" value={g.closing_balance} bold border />
                  </tbody>
                </table>
              </section>

              <section className="grid gap-2 break-inside-avoid">
                <h3 className="font-semibold">II. Posisi Kas & Bank</h3>
                <AccountTable rows={g.accounts} total />
              </section>

              {data.pocket_accounts.length > 0 && (
                <section className="grid gap-2 break-inside-avoid">
                  <h3 className="font-semibold">III. Rekening Dana Khusus</h3>
                  <AccountTable rows={[]} pockets={data.pocket_accounts} />
                </section>
              )}

              <section className="mt-4 grid grid-cols-2 gap-8 text-center text-sm break-inside-avoid">
                <p className="col-span-2 text-right">{formatDate(data.period.end_date, 'd MMMM yyyy')}</p>
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
