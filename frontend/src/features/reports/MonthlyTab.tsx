import { Fragment, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { format, subMonths } from 'date-fns'
import { ChevronDown, ChevronRight } from 'lucide-react'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { formatCompact, formatDate, formatRupiah } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { MatrixGroup, MonthlyReport } from '@/types'
import { CashAccountFilter, DateInput, Field, PrintTitle, ReportToolbar, SummaryCard } from './components'
import { toneOf } from './utils'

const monthLabel = (m: string) => formatDate(`${m}-01`, 'MMM yy')

/** Pengganti sheet Rekap Bulanan / Laporan Kas Umum / Analisa bagian 2. */
export function MonthlyTab() {
  const [params, setParams] = useState(() => ({
    start_period: format(subMonths(new Date(), 11), 'yyyy-MM'),
    end_period: format(new Date(), 'yyyy-MM'),
    cash_account_id: '',
  }))
  const query = { ...params, cash_account_id: params.cash_account_id || undefined }

  const { data } = useQuery({
    queryKey: ['reports', 'monthly', params],
    queryFn: async () => (await api.get<MonthlyReport>('/reports/monthly', { params: query })).data,
  })

  const months = data?.months ?? []
  const totals = months.reduce(
    (acc, m) => ({ income: acc.income + m.income, expense: acc.expense + m.expense, non: acc.non + m.non_operational_in - m.non_operational_out }),
    { income: 0, expense: 0, non: 0 },
  )
  const chart = months.map((m) => ({ label: monthLabel(m.month), Pemasukan: m.income, Pengeluaran: m.expense }))

  return (
    <>
      <ReportToolbar exportName="monthly" exportParams={query} filename={`rekap-bulanan-${params.start_period}-${params.end_period}.xlsx`}>
        <Field label="Dari bulan">
          <DateInput type="month" value={params.start_period} onChange={(v) => setParams((p) => ({ ...p, start_period: v }))} />
        </Field>
        <Field label="Sampai bulan">
          <DateInput type="month" value={params.end_period} onChange={(v) => setParams((p) => ({ ...p, end_period: v }))} />
        </Field>
        <CashAccountFilter value={params.cash_account_id} onChange={(v) => setParams((p) => ({ ...p, cash_account_id: v }))} />
      </ReportToolbar>

      <PrintTitle title="Rekap Bulanan" subtitle={`${params.start_period} s/d ${params.end_period}`} />

      {data && (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
          <SummaryCard label="Saldo Awal" value={data.opening_balance} />
          <SummaryCard label="Pemasukan Operasional" value={totals.income} />
          <SummaryCard label="Pengeluaran Operasional" value={totals.expense} />
          <SummaryCard label="Surplus / (Defisit)" value={totals.income - totals.expense} tone={toneOf(totals.income - totals.expense)} hint={`Titipan & pinjaman bersih ${formatRupiah(totals.non)}`} />
          <SummaryCard label="Saldo Akhir" value={data.closing_balance} />
        </div>
      )}

      <Card className="print:hidden">
        <CardHeader>
          <CardTitle>Pemasukan vs Pengeluaran Operasional</CardTitle>
        </CardHeader>
        <CardContent className="h-72">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={chart} barGap={2}>
              <CartesianGrid vertical={false} stroke="var(--border)" />
              <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={12} stroke="var(--muted-foreground)" />
              <YAxis tickFormatter={formatCompact} tickLine={false} axisLine={false} fontSize={12} width={48} stroke="var(--muted-foreground)" />
              <Tooltip formatter={(v) => formatRupiah(Number(v))} cursor={{ fill: 'var(--muted)' }} contentStyle={{ borderRadius: 8, borderColor: 'var(--border)', fontSize: 12 }} />
              <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
              <Bar dataKey="Pemasukan" fill="var(--chart-1)" radius={[4, 4, 0, 0]} maxBarSize={18} />
              <Bar dataKey="Pengeluaran" fill="var(--chart-3)" radius={[4, 4, 0, 0]} maxBarSize={18} />
            </BarChart>
          </ResponsiveContainer>
        </CardContent>
      </Card>

      <Card className="gap-0 pb-0">
        <CardHeader className="pb-3">
          <CardTitle>Ringkasan per Bulan</CardTitle>
        </CardHeader>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-4">Bulan</TableHead>
                <TableHead className="text-right">Saldo Awal</TableHead>
                <TableHead className="text-right">Pemasukan</TableHead>
                <TableHead className="text-right">Pengeluaran</TableHead>
                <TableHead className="text-right">Surplus/(Defisit)</TableHead>
                <TableHead className="text-right">Titipan/Pinjaman</TableHead>
                {params.cash_account_id && <TableHead className="text-right">Mutasi Kas</TableHead>}
                <TableHead className="pr-4 text-right">Saldo Akhir</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {months.map((m) => (
                <TableRow key={m.month}>
                  <TableCell className="pl-4 font-medium">{formatDate(`${m.month}-01`, 'MMMM yyyy')}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatRupiah(m.opening_balance)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatRupiah(m.income)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatRupiah(m.expense)}</TableCell>
                  <TableCell className={cn('text-right tabular-nums', m.net < 0 && 'text-destructive')}>{formatRupiah(m.net)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatRupiah(m.non_operational_in - m.non_operational_out)}</TableCell>
                  {params.cash_account_id && <TableCell className="text-right tabular-nums">{formatRupiah(m.transfer_in - m.transfer_out)}</TableCell>}
                  <TableCell className="pr-4 text-right font-medium tabular-nums">{formatRupiah(m.closing_balance)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </Card>

      {data && (
        <>
          <MatrixTable title="Pemasukan per Kelompok & Kategori" groups={data.income} months={months.map((m) => m.month)} />
          <MatrixTable title="Pengeluaran per Kelompok & Kategori" groups={data.expense} months={months.map((m) => m.month)} />
        </>
      )}
    </>
  )
}

const NATURE_NOTE: Record<string, string> = { titipan: 'titipan', pinjaman: 'pinjaman', transfer: 'mutasi kas' }

function MatrixTable({ title, groups, months }: { title: string; groups: MatrixGroup[]; months: string[] }) {
  const [open, setOpen] = useState<Record<string, boolean>>({})
  const totals = months.map((m) => groups.filter((g) => g.nature === 'operational').reduce((s, g) => s + (g.months[m] ?? 0), 0))

  return (
    <Card className="gap-0 pb-0">
      <CardHeader className="pb-3">
        <CardTitle>{title}</CardTitle>
        <CardDescription className="print:hidden">Klik kelompok untuk melihat rincian kategori.</CardDescription>
      </CardHeader>
      <div className="overflow-x-auto">
        <Table className="text-xs">
          <TableHeader>
            <TableRow>
              <TableHead className="sticky left-0 z-10 min-w-56 bg-card pl-4">Kelompok / Kategori</TableHead>
              {months.map((m) => (
                <TableHead key={m} className="text-right whitespace-nowrap">
                  {monthLabel(m)}
                </TableHead>
              ))}
              <TableHead className="pr-4 text-right">Total</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {groups.map((g) => {
              const key = `${g.nature}|${g.group}`
              const expanded = open[key]
              return (
                <Fragment key={key}>
                  <TableRow className="cursor-pointer bg-muted/30 font-medium" onClick={() => setOpen((o) => ({ ...o, [key]: !o[key] }))}>
                    <TableCell className="sticky left-0 z-10 bg-muted pl-2">
                      <span className="inline-flex items-center gap-1">
                        {expanded ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                        {g.group}
                        {g.nature !== 'operational' && <span className="font-normal text-muted-foreground">({NATURE_NOTE[g.nature]})</span>}
                      </span>
                    </TableCell>
                    {months.map((m) => (
                      <TableCell key={m} className="text-right tabular-nums">
                        {g.months[m] ? formatCompact(g.months[m]) : '-'}
                      </TableCell>
                    ))}
                    <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(g.total)}</TableCell>
                  </TableRow>
                  {expanded &&
                    g.categories.map((c) => (
                      <TableRow key={c.category_id}>
                        <TableCell className="sticky left-0 z-10 bg-card pl-8">{c.name}</TableCell>
                        {months.map((m) => (
                          <TableCell key={m} className="text-right text-muted-foreground tabular-nums">
                            {c.months[m] ? formatCompact(c.months[m]) : '-'}
                          </TableCell>
                        ))}
                        <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(c.total)}</TableCell>
                      </TableRow>
                    ))}
                </Fragment>
              )
            })}
            <TableRow className="border-t-2 font-semibold">
              <TableCell className="sticky left-0 z-10 bg-card pl-4">Total operasional</TableCell>
              {totals.map((t, i) => (
                <TableCell key={months[i]} className="text-right tabular-nums">
                  {formatCompact(t)}
                </TableCell>
              ))}
              <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(totals.reduce((a, b) => a + b, 0))}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
    </Card>
  )
}
