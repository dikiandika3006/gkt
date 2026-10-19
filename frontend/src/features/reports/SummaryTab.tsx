import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { endOfMonth, endOfYear, startOfMonth, startOfYear } from 'date-fns'
import { Button } from '@/components/ui/button'
import { Card, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { formatDate, formatRupiah, toDateInput } from '@/lib/format'
import type { CategoryTotal, ReportSummary } from '@/types'
import { CashAccountFilter, DateInput, Field, PrintTitle, ReportToolbar, SummaryCard } from './components'
import { toneOf } from './utils'

/** Rekap pemasukan & pengeluaran per kategori untuk rentang tanggal bebas. */
export function SummaryTab() {
  const [params, setParams] = useState(() => ({
    start_date: toDateInput(startOfMonth(new Date())),
    end_date: toDateInput(endOfMonth(new Date())),
    cash_account_id: '',
  }))
  const query = { ...params, cash_account_id: params.cash_account_id || undefined }
  const preset = (start: Date, end: Date) => setParams((p) => ({ ...p, start_date: toDateInput(start), end_date: toDateInput(end) }))

  const { data } = useQuery({
    queryKey: ['reports', 'summary', params],
    queryFn: async () => (await api.get<ReportSummary>('/reports/summary', { params: query })).data,
  })

  const split = (rows: CategoryTotal[]) => ({
    operational: rows.filter((r) => r.nature === 'operational'),
    other: rows.filter((r) => r.nature !== 'operational'),
  })
  const income = split(data?.categories.income ?? [])
  const expense = split(data?.categories.expense ?? [])

  return (
    <>
      <ReportToolbar exportName="monthly" exportParams={{ start_period: params.start_date.slice(0, 7), end_period: params.end_date.slice(0, 7), cash_account_id: query.cash_account_id }} filename="rekap.xlsx">
        <Field label="Dari">
          <DateInput value={params.start_date} onChange={(v) => setParams((p) => ({ ...p, start_date: v }))} />
        </Field>
        <Field label="Sampai">
          <DateInput value={params.end_date} onChange={(v) => setParams((p) => ({ ...p, end_date: v }))} />
        </Field>
        <CashAccountFilter value={params.cash_account_id} onChange={(v) => setParams((p) => ({ ...p, cash_account_id: v }))} />
        <div className="flex gap-2">
          <Button variant="secondary" size="sm" onClick={() => preset(startOfMonth(new Date()), endOfMonth(new Date()))}>
            Bulan ini
          </Button>
          <Button variant="secondary" size="sm" onClick={() => preset(startOfYear(new Date()), endOfYear(new Date()))}>
            Tahun ini
          </Button>
        </div>
      </ReportToolbar>

      <PrintTitle title="Ringkasan Periode" subtitle={`${formatDate(params.start_date)} – ${formatDate(params.end_date)}`} />

      {data && (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
          <SummaryCard label="Saldo Awal" value={data.opening_balance} />
          <SummaryCard label="Pemasukan Operasional" value={data.income} />
          <SummaryCard label="Pengeluaran Operasional" value={data.expense} />
          <SummaryCard
            label="Surplus / (Defisit)"
            value={data.net}
            tone={toneOf(data.net)}
            hint={`Titipan/pinjaman bersih ${formatRupiah(data.non_operational_in - data.non_operational_out)}`}
          />
          <SummaryCard label="Saldo Akhir" value={data.closing_balance} />
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        <CategoryTable title="Pemasukan" rows={income.operational} other={income.other} />
        <CategoryTable title="Pengeluaran" rows={expense.operational} other={expense.other} />
      </div>
    </>
  )
}

function CategoryTable({ title, rows, other }: { title: string; rows: CategoryTotal[]; other: CategoryTotal[] }) {
  const total = rows.reduce((s, r) => s + r.total, 0)
  return (
    <Card className="gap-0 pb-0">
      <CardHeader className="pb-3">
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="pl-4">Kategori</TableHead>
            <TableHead>Kelompok</TableHead>
            <TableHead className="text-right">Trx</TableHead>
            <TableHead className="pr-4 text-right">Jumlah</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.length === 0 && (
            <TableRow>
              <TableCell colSpan={4} className="py-6 text-center text-muted-foreground">
                Tidak ada data.
              </TableCell>
            </TableRow>
          )}
          {rows.map((r) => (
            <TableRow key={r.category_id}>
              <TableCell className="pl-4">{r.name}</TableCell>
              <TableCell className="text-muted-foreground">{r.group}</TableCell>
              <TableCell className="text-right tabular-nums">{r.count}</TableCell>
              <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(r.total)}</TableCell>
            </TableRow>
          ))}
        </TableBody>
        <TableFooter>
          <TableRow>
            <TableCell colSpan={3} className="pl-4">
              Total {title} Operasional
            </TableCell>
            <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(total)}</TableCell>
          </TableRow>
          {other.map((r) => (
            <TableRow key={r.category_id} className="font-normal text-muted-foreground">
              <TableCell colSpan={3} className="pl-4">
                {r.name} (di luar operasional)
              </TableCell>
              <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(r.total)}</TableCell>
            </TableRow>
          ))}
        </TableFooter>
      </Table>
    </Card>
  )
}
