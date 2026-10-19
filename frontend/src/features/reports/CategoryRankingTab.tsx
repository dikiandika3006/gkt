import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { endOfMonth, startOfYear } from 'date-fns'
import { Card } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { formatDate, formatRupiah, toDateInput } from '@/lib/format'
import type { CategoryRankingReport } from '@/types'
import { DateInput, Field, PrintTitle, ReportToolbar } from './components'

/** Analisa pengeluaran operasional per kategori (peringkat, %, rata-rata per bulan). */
export function CategoryRankingTab() {
  const [params, setParams] = useState(() => ({
    start_date: toDateInput(startOfYear(new Date())),
    end_date: toDateInput(endOfMonth(new Date())),
  }))

  const { data } = useQuery({
    queryKey: ['reports', 'categories', params],
    queryFn: async () => (await api.get<CategoryRankingReport>('/reports/categories', { params })).data,
  })
  const rows = data?.rows ?? []
  const total = rows.reduce((s, r) => s + r.total, 0)

  return (
    <>
      <ReportToolbar exportName="categories" exportParams={params} filename={`analisa-kategori-${params.start_date}-${params.end_date}.xlsx`}>
        <Field label="Dari">
          <DateInput value={params.start_date} onChange={(v) => setParams((p) => ({ ...p, start_date: v }))} />
        </Field>
        <Field label="Sampai">
          <DateInput value={params.end_date} onChange={(v) => setParams((p) => ({ ...p, end_date: v }))} />
        </Field>
      </ReportToolbar>

      <PrintTitle title="Pengeluaran Operasional per Kategori" subtitle={`${formatDate(params.start_date)} – ${formatDate(params.end_date)}`} />

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-12 pl-4">#</TableHead>
              <TableHead>Kategori</TableHead>
              <TableHead className="w-1/3">Porsi</TableHead>
              <TableHead className="text-right">Trx</TableHead>
              <TableHead className="text-right">Rata-rata / Bulan</TableHead>
              <TableHead className="pr-4 text-right">Total</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((r) => (
              <TableRow key={r.category_id}>
                <TableCell className="pl-4 text-muted-foreground tabular-nums">{r.rank}</TableCell>
                <TableCell>
                  <div className="font-medium">{r.name}</div>
                  <div className="text-xs text-muted-foreground">{r.group}</div>
                </TableCell>
                <TableCell>
                  <div className="flex items-center gap-2">
                    <div className="h-2 flex-1 rounded-full bg-muted">
                      <div className="h-2 rounded-full bg-chart-1" style={{ width: `${Math.max(r.percentage, 0.5)}%` }} />
                    </div>
                    <span className="w-12 text-right text-xs tabular-nums">{r.percentage.toFixed(1)}%</span>
                  </div>
                </TableCell>
                <TableCell className="text-right tabular-nums">{r.count}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(r.monthly_average)}</TableCell>
                <TableCell className="pr-4 text-right font-medium tabular-nums">{formatRupiah(r.total)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
          <TableFooter>
            <TableRow>
              <TableCell colSpan={5} className="pl-4">
                Total pengeluaran operasional
              </TableCell>
              <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(total)}</TableCell>
            </TableRow>
          </TableFooter>
        </Table>
      </Card>
    </>
  )
}
