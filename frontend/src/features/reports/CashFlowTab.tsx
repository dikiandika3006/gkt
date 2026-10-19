import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { endOfMonth, startOfMonth } from 'date-fns'
import { Card } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { formatDate, formatRupiah, toDateInput } from '@/lib/format'
import type { CashFlowReport } from '@/types'
import { CashAccountFilter, DateInput, Field, PrintTitle, ReportToolbar } from './components'

/** Buku kas dengan saldo berjalan (per kas atau gabungan). */
export function CashFlowTab() {
  const [params, setParams] = useState(() => ({
    start_date: toDateInput(startOfMonth(new Date())),
    end_date: toDateInput(endOfMonth(new Date())),
    cash_account_id: '',
  }))
  const query = { ...params, cash_account_id: params.cash_account_id || undefined }

  const { data } = useQuery({
    queryKey: ['reports', 'cash-flow', params],
    queryFn: async () => (await api.get<CashFlowReport>('/reports/cash-flow', { params: query })).data,
  })

  return (
    <>
      <ReportToolbar exportName="cash-flow" exportParams={query} filename={`buku-kas-${params.start_date}-${params.end_date}.xlsx`}>
        <Field label="Dari">
          <DateInput value={params.start_date} onChange={(v) => setParams((p) => ({ ...p, start_date: v }))} />
        </Field>
        <Field label="Sampai">
          <DateInput value={params.end_date} onChange={(v) => setParams((p) => ({ ...p, end_date: v }))} />
        </Field>
        <CashAccountFilter value={params.cash_account_id} onChange={(v) => setParams((p) => ({ ...p, cash_account_id: v }))} />
      </ReportToolbar>

      <PrintTitle title="Buku Kas" subtitle={`${formatDate(params.start_date)} – ${formatDate(params.end_date)}`} />

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Tanggal</TableHead>
              <TableHead>Nomor</TableHead>
              <TableHead>Keterangan</TableHead>
              <TableHead>Kategori</TableHead>
              <TableHead className="text-right">Masuk</TableHead>
              <TableHead className="text-right">Keluar</TableHead>
              <TableHead className="pr-4 text-right">Saldo</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow className="bg-muted/40">
              <TableCell colSpan={6} className="pl-4 font-medium">
                Saldo awal
              </TableCell>
              <TableCell className="pr-4 text-right font-medium tabular-nums">{formatRupiah(data?.opening_balance ?? 0)}</TableCell>
            </TableRow>
            {data?.rows.map((r) => (
              <TableRow key={r.id}>
                <TableCell className="pl-4 whitespace-nowrap">{formatDate(r.date)}</TableCell>
                <TableCell className="font-mono text-xs">{r.number}</TableCell>
                <TableCell className="max-w-72">
                  <div className="truncate">{r.description}</div>
                  {r.counterparty && <div className="truncate text-xs text-muted-foreground">{r.counterparty}</div>}
                </TableCell>
                <TableCell>{r.category}</TableCell>
                <TableCell className="text-right tabular-nums">{r.income ? formatRupiah(r.income) : '-'}</TableCell>
                <TableCell className="text-right tabular-nums">{r.expense ? formatRupiah(r.expense) : '-'}</TableCell>
                <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(r.balance)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
          <TableFooter>
            <TableRow>
              <TableCell colSpan={6} className="pl-4">
                Saldo akhir
              </TableCell>
              <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(data?.closing_balance ?? 0)}</TableCell>
            </TableRow>
          </TableFooter>
        </Table>
      </Card>
    </>
  )
}
