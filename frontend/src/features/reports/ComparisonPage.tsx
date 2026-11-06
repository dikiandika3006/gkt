import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { formatCompact, formatRupiah } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { TransactionType, YearComparison } from '@/types'
import { Field, PrintTitle, ReportToolbar, SummaryCard, YearInput } from './components'
import { deltaLabel } from './utils'

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']

/** Tahun ini vs tahun lalu: per bulan dan per kelompok kategori (operasional kas umum). */
export default function ComparisonPage() {
  const [year, setYear] = useState(() => new Date().getFullYear())

  const { data } = useQuery({
    queryKey: ['reports', 'comparison', year],
    queryFn: async () => (await api.get<YearComparison>('/reports/comparison', { params: { year } })).data,
  })

  const prev = year - 1
  const chart = (data?.months ?? []).map((m) => ({
    label: MONTHS[m.month - 1],
    [`Penerimaan ${year}`]: m.income,
    [`Penerimaan ${prev}`]: m.prev_income,
    [`Pengeluaran ${year}`]: m.expense,
    [`Pengeluaran ${prev}`]: m.prev_expense,
  }))
  const num = 'text-right tabular-nums whitespace-nowrap'
  const deltaClass = (now: number, before: number, type: TransactionType) =>
    before === 0 || now === before ? '' : (now > before) === (type === 'income') ? 'text-success' : 'text-destructive'

  return (
    <>
      <ReportToolbar exportName="comparison" exportParams={{ year }} filename={`perbandingan-${prev}-${year}.xlsx`}>
        <Field label="Tahun">
          <YearInput value={year} onChange={setYear} />
        </Field>
        <p className="self-center text-xs text-muted-foreground">Dibandingkan dengan {prev}. Operasional kas umum (tanpa titipan, pinjaman, dana khusus).</p>
      </ReportToolbar>

      <PrintTitle title={`Perbandingan ${prev} vs ${year}`} subtitle="Operasional kas umum" />

      {data && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <SummaryCard label={`Penerimaan ${year}`} value={data.totals.income} hint={`${deltaLabel(data.totals.income, data.totals.prev_income)} vs ${prev} (${formatRupiah(data.totals.prev_income)})`} />
            <SummaryCard label={`Pengeluaran ${year}`} value={data.totals.expense} hint={`${deltaLabel(data.totals.expense, data.totals.prev_expense)} vs ${prev} (${formatRupiah(data.totals.prev_expense)})`} />
            <SummaryCard label={`Surplus ${year}`} value={data.totals.income - data.totals.expense} tone={data.totals.income < data.totals.expense ? 'negative' : 'positive'} />
            <SummaryCard label={`Surplus ${prev}`} value={data.totals.prev_income - data.totals.prev_expense} tone={data.totals.prev_income < data.totals.prev_expense ? 'negative' : 'positive'} />
          </div>

          <Card className="print:hidden">
            <CardHeader>
              <CardTitle>Tren per bulan</CardTitle>
              <CardDescription>Garis putus-putus = {prev}</CardDescription>
            </CardHeader>
            <CardContent className="h-72">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={chart}>
                  <CartesianGrid vertical={false} stroke="var(--border)" />
                  <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={12} stroke="var(--muted-foreground)" />
                  <YAxis tickFormatter={formatCompact} tickLine={false} axisLine={false} fontSize={12} width={48} stroke="var(--muted-foreground)" />
                  <Tooltip formatter={(v) => formatRupiah(Number(v))} contentStyle={{ borderRadius: 8, borderColor: 'var(--border)', fontSize: 12 }} />
                  <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
                  <Line type="monotone" dataKey={`Penerimaan ${year}`} stroke="var(--chart-1)" strokeWidth={2} dot={false} />
                  <Line type="monotone" dataKey={`Penerimaan ${prev}`} stroke="var(--chart-1)" strokeDasharray="4 4" dot={false} />
                  <Line type="monotone" dataKey={`Pengeluaran ${year}`} stroke="var(--chart-3)" strokeWidth={2} dot={false} />
                  <Line type="monotone" dataKey={`Pengeluaran ${prev}`} stroke="var(--chart-3)" strokeDasharray="4 4" dot={false} />
                </LineChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Per bulan</CardTitle>
            </CardHeader>
            <CardContent className="px-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="pl-4">Bulan</TableHead>
                    <TableHead className="text-right">Penerimaan {prev}</TableHead>
                    <TableHead className="text-right">Penerimaan {year}</TableHead>
                    <TableHead className="text-right">Δ</TableHead>
                    <TableHead className="text-right">Pengeluaran {prev}</TableHead>
                    <TableHead className="text-right">Pengeluaran {year}</TableHead>
                    <TableHead className="pr-4 text-right">Δ</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.months.map((m) => (
                    <TableRow key={m.month}>
                      <TableCell className="pl-4">{MONTHS[m.month - 1]}</TableCell>
                      <TableCell className={cn(num, 'text-muted-foreground')}>{formatRupiah(m.prev_income)}</TableCell>
                      <TableCell className={num}>{formatRupiah(m.income)}</TableCell>
                      <TableCell className={cn(num, 'text-xs', deltaClass(m.income, m.prev_income, 'income'))}>{deltaLabel(m.income, m.prev_income)}</TableCell>
                      <TableCell className={cn(num, 'text-muted-foreground')}>{formatRupiah(m.prev_expense)}</TableCell>
                      <TableCell className={num}>{formatRupiah(m.expense)}</TableCell>
                      <TableCell className={cn(num, 'pr-4 text-xs', deltaClass(m.expense, m.prev_expense, 'expense'))}>{deltaLabel(m.expense, m.prev_expense)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
                <TableFooter>
                  <TableRow>
                    <TableCell className="pl-4">Jumlah</TableCell>
                    <TableCell className={num}>{formatRupiah(data.totals.prev_income)}</TableCell>
                    <TableCell className={num}>{formatRupiah(data.totals.income)}</TableCell>
                    <TableCell className={cn(num, 'text-xs')}>{deltaLabel(data.totals.income, data.totals.prev_income)}</TableCell>
                    <TableCell className={num}>{formatRupiah(data.totals.prev_expense)}</TableCell>
                    <TableCell className={num}>{formatRupiah(data.totals.expense)}</TableCell>
                    <TableCell className={cn(num, 'pr-4 text-xs')}>{deltaLabel(data.totals.expense, data.totals.prev_expense)}</TableCell>
                  </TableRow>
                </TableFooter>
              </Table>
            </CardContent>
          </Card>

          <div className="grid gap-4 lg:grid-cols-2">
            {(['income', 'expense'] as const).map((type) => (
              <Card key={type}>
                <CardHeader>
                  <CardTitle>{type === 'income' ? 'Penerimaan' : 'Pengeluaran'} per kelompok</CardTitle>
                </CardHeader>
                <CardContent className="px-0">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="pl-4">Kelompok</TableHead>
                        <TableHead className="text-right">{prev}</TableHead>
                        <TableHead className="text-right">{year}</TableHead>
                        <TableHead className="pr-4 text-right">Δ</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {data.groups[type].map((g) => (
                        <TableRow key={g.group}>
                          <TableCell className="pl-4">{g.group}</TableCell>
                          <TableCell className={cn(num, 'text-muted-foreground')}>{formatRupiah(g.prev_total)}</TableCell>
                          <TableCell className={num}>{formatRupiah(g.total)}</TableCell>
                          <TableCell className={cn(num, 'pr-4 text-xs', deltaClass(g.total, g.prev_total, type))}>{deltaLabel(g.total, g.prev_total)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </CardContent>
              </Card>
            ))}
          </div>
        </>
      )}
    </>
  )
}
