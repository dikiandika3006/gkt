import { Fragment, useState } from 'react'
import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { format } from 'date-fns'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { BudgetMonthlyReport, BudgetStatus, TransactionType } from '@/types'
import { DateInput, Field, PrintTitle, ReportToolbar } from './components'

const STATUS: Record<BudgetStatus, { label: string; className: string }> = {
  aman: { label: 'Aman', className: 'text-success' },
  waspada: { label: 'Waspada', className: 'text-warning' },
  lewat: { label: 'Lewat', className: 'text-destructive font-medium' },
  tercapai: { label: 'Tercapai', className: 'text-success' },
  tertinggal: { label: 'Tertinggal', className: 'text-warning' },
}

const SECTIONS: { type: TransactionType; label: string }[] = [
  { type: 'income', label: 'Penerimaan' },
  { type: 'expense', label: 'Pengeluaran' },
]

/** Anggaran vs realisasi per kategori: bulan ini & kumulatif sejak Januari. */
export default function BudgetReportPage() {
  const [month, setMonth] = useState(() => format(new Date(), 'yyyy-MM'))

  const { data } = useQuery({
    queryKey: ['reports', 'budget', month],
    queryFn: async () => (await api.get<BudgetMonthlyReport>('/reports/budget', { params: { month } })).data,
  })

  const monthLabel = formatDate(`${month}-01`, 'MMMM yyyy')
  const num = 'text-right tabular-nums whitespace-nowrap'

  return (
    <>
      <ReportToolbar exportName="budget" exportParams={{ month }} filename={`realisasi-anggaran-${month}.xlsx`}>
        <Field label="S/d bulan">
          <DateInput type="month" value={month} onChange={setMonth} />
        </Field>
      </ReportToolbar>

      <PrintTitle title={`Realisasi Anggaran ${month.slice(0, 4)}`} subtitle={`s/d ${monthLabel}`} />

      {data && data.rows.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Belum ada anggaran tahun {month.slice(0, 4)}. Isi dulu di menu{' '}
            <Link to="/budgets" className="underline underline-offset-4">
              Anggaran
            </Link>
            .
          </CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent className="px-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-4">Kategori</TableHead>
                  <TableHead className="text-right">Anggaran setahun</TableHead>
                  <TableHead className="text-right">Anggaran s/d bulan ini</TableHead>
                  <TableHead className="text-right">Realisasi {formatDate(`${month}-01`, 'MMM')}</TableHead>
                  <TableHead className="text-right">Realisasi s/d bulan ini</TableHead>
                  <TableHead className="text-right">Sisa</TableHead>
                  <TableHead className="text-right">%</TableHead>
                  <TableHead className="pr-4">Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {SECTIONS.map(({ type, label }) => {
                  const rows = data?.rows.filter((r) => r.type === type) ?? []
                  if (rows.length === 0) return null
                  const t = data!.totals[type]

                  return (
                    <Fragment key={type}>
                      <TableRow className="bg-muted/50 hover:bg-muted/50">
                        <TableCell colSpan={8} className="pl-4 font-semibold">
                          {label}
                        </TableCell>
                      </TableRow>
                      {rows.map((r) => {
                        const over = r.type === 'expense' ? r.actual > r.budget_to_date : r.actual < r.budget_to_date
                        return (
                          <TableRow key={r.id}>
                            <TableCell className="pl-4">
                              {r.category?.name}
                              <span className="block text-xs text-muted-foreground">{r.category?.code}</span>
                            </TableCell>
                            <TableCell className={num}>{formatRupiah(r.amount)}</TableCell>
                            <TableCell className={num}>{formatRupiah(r.budget_to_date)}</TableCell>
                            <TableCell className={num}>{formatRupiah(r.month_actual)}</TableCell>
                            <TableCell className={cn(num, over && 'font-medium', over && r.type === 'expense' && 'text-destructive')}>{formatRupiah(r.actual)}</TableCell>
                            <TableCell className={cn(num, r.remaining < 0 && 'text-destructive')}>{formatRupiah(r.remaining)}</TableCell>
                            <TableCell className={num}>{r.percentage.toLocaleString('id-ID')}%</TableCell>
                            <TableCell className={cn('pr-4 text-sm', STATUS[r.status].className)}>{STATUS[r.status].label}</TableCell>
                          </TableRow>
                        )
                      })}
                      <TableRow className="font-semibold">
                        <TableCell className="pl-4">Jumlah {label.toLowerCase()}</TableCell>
                        <TableCell className={num}>{formatRupiah(t.amount)}</TableCell>
                        <TableCell className={num}>{formatRupiah(t.budget_to_date)}</TableCell>
                        <TableCell className={num}>{formatRupiah(t.month_actual)}</TableCell>
                        <TableCell className={num}>{formatRupiah(t.actual)}</TableCell>
                        <TableCell className={num}>{formatRupiah(t.remaining)}</TableCell>
                        <TableCell className={num}>{t.amount > 0 ? `${((t.actual / t.amount) * 100).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%` : '—'}</TableCell>
                        <TableCell />
                      </TableRow>
                    </Fragment>
                  )
                })}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </>
  )
}
