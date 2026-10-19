import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { format, startOfYear } from 'date-fns'
import { Card, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { StatusBadge } from '@/features/fund-requests/shared'
import { api } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import { useCashAccounts } from '@/lib/queries'
import { cn } from '@/lib/utils'
import type { SecretariatReport } from '@/types'
import { CashAccountFilter, DateInput, Field, PrintTitle, ReportToolbar, SummaryCard } from './components'

const monthLabel = (m: string) => formatDate(`${m}-01`, 'MMM yy')

/** Laporan detail pemakaian dana Sekretariat: ringkasan pengajuan, per kategori, dan rincian nota. */
export function SecretariatTab() {
  const { data: cashAccounts = [] } = useCashAccounts()
  const [selected, setSelected] = useState('')
  const [range, setRange] = useState(() => ({ start_period: format(startOfYear(new Date()), 'yyyy-MM'), end_period: format(new Date(), 'yyyy-MM') }))

  const fallback = cashAccounts.find((c) => c.code === 'KAS-SEK') ?? cashAccounts.find((c) => c.type === 'cash')
  const accountId = selected || (fallback ? String(fallback.id) : '')
  const params = { ...range, cash_account_id: accountId }

  const { data } = useQuery({
    queryKey: ['reports', 'secretariat', params],
    queryFn: async () => (await api.get<SecretariatReport>('/reports/secretariat', { params })).data,
    enabled: !!accountId,
  })

  const totals = (data?.requests ?? []).reduce(
    (acc, r) => ({ requested: acc.requested + r.requested, transferred: acc.transferred + r.transferred }),
    { requested: 0, transferred: 0 },
  )
  const last = data?.requests.at(-1)

  return (
    <>
      <ReportToolbar exportName="secretariat" exportParams={params} filename={`laporan-sekretariat-${range.start_period}-${range.end_period}.xlsx`}>
        <CashAccountFilter value={accountId} onChange={setSelected} allowAll={false} onlyCash />
        <Field label="Dari bulan">
          <DateInput type="month" value={range.start_period} onChange={(v) => setRange((r) => ({ ...r, start_period: v }))} />
        </Field>
        <Field label="Sampai bulan">
          <DateInput type="month" value={range.end_period} onChange={(v) => setRange((r) => ({ ...r, end_period: v }))} />
        </Field>
      </ReportToolbar>

      <PrintTitle title="Laporan Pemakaian Dana Sekretariat" subtitle={`${range.start_period} s/d ${range.end_period}`} />

      {data && (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <SummaryCard label="Total Pengajuan" value={totals.requested} />
          <SummaryCard label="Total Ditransfer" value={totals.transferred} />
          <SummaryCard label="Total Realisasi LPJ" value={data.total_reported} />
          <SummaryCard label="Saldo Akhir Periode" value={last?.closing_balance ?? 0} tone={(last?.closing_balance ?? 0) < 0 ? 'negative' : undefined} hint="Sisa yang dibawa ke periode berikutnya" />
        </div>
      )}

      <Card className="gap-0 pb-0">
        <CardHeader className="pb-3">
          <CardTitle>Ringkasan per Pengajuan</CardTitle>
        </CardHeader>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Periode</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">Sisa Dibawa</TableHead>
              <TableHead className="text-right">Pengajuan</TableHead>
              <TableHead className="text-right">Ditransfer</TableHead>
              <TableHead className="text-right">Realisasi</TableHead>
              <TableHead className="text-right">Selisih</TableHead>
              <TableHead className="pr-4 text-right">Saldo Akhir</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {data?.requests.length === 0 && (
              <TableRow>
                <TableCell colSpan={8} className="py-6 text-center text-muted-foreground">
                  Belum ada pengajuan pada periode ini.
                </TableCell>
              </TableRow>
            )}
            {data?.requests.map((r) => (
              <TableRow key={r.id}>
                <TableCell className="pl-4">
                  <div className="font-medium">{formatDate(`${r.period}-01`, 'MMMM yyyy')}</div>
                  <div className="font-mono text-xs text-muted-foreground">{r.number}</div>
                </TableCell>
                <TableCell>
                  <StatusBadge status={r.status} label={r.status_label} />
                </TableCell>
                <TableCell className={cn('text-right tabular-nums', r.carry_over < 0 && 'text-destructive')}>{formatRupiah(r.carry_over)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(r.requested)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(r.transferred)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(r.reported)}</TableCell>
                <TableCell className={cn('text-right tabular-nums', r.difference < 0 && 'text-destructive')}>{formatRupiah(r.difference)}</TableCell>
                <TableCell className={cn('pr-4 text-right font-medium tabular-nums', r.closing_balance < 0 && 'text-destructive')}>
                  {formatRupiah(r.closing_balance)}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>

      {data && data.by_category.length > 0 && (
        <Card className="gap-0 pb-0">
          <CardHeader className="pb-3">
            <CardTitle>Pemakaian per Kategori</CardTitle>
          </CardHeader>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-4">Kategori</TableHead>
                  {data.periods.map((p) => (
                    <TableHead key={p} className="text-right">
                      {monthLabel(p)}
                    </TableHead>
                  ))}
                  <TableHead className="pr-4 text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.by_category.map((c) => (
                  <TableRow key={c.category_id}>
                    <TableCell className="pl-4">{c.category}</TableCell>
                    {data.periods.map((p) => (
                      <TableCell key={p} className="text-right tabular-nums">
                        {c.periods[p] ? formatRupiah(c.periods[p]) : '-'}
                      </TableCell>
                    ))}
                    <TableCell className="pr-4 text-right font-medium tabular-nums">{formatRupiah(c.total)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </Card>
      )}

      {data && data.items.length > 0 && (
        <Card className="gap-0 pb-0">
          <CardHeader className="pb-3">
            <CardTitle>Rincian Nota</CardTitle>
          </CardHeader>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-4">Tanggal</TableHead>
                <TableHead>Pengajuan</TableHead>
                <TableHead>Kategori</TableHead>
                <TableHead>Uraian</TableHead>
                <TableHead className="text-right">Qty × Harga</TableHead>
                <TableHead className="pr-4 text-right">Jumlah</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.items.map((i) => (
                <TableRow key={i.id}>
                  <TableCell className="pl-4 whitespace-nowrap">{formatDate(i.date)}</TableCell>
                  <TableCell className="font-mono text-xs">{i.request_number}</TableCell>
                  <TableCell>{i.category}</TableCell>
                  <TableCell>{i.description}</TableCell>
                  <TableCell className="text-right text-xs text-muted-foreground tabular-nums">
                    {i.qty} {i.unit ?? ''} × {formatRupiah(i.unit_price)}
                  </TableCell>
                  <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(i.amount)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
            <TableFooter>
              <TableRow>
                <TableCell colSpan={5} className="pl-4">
                  Total
                </TableCell>
                <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(data.total_reported)}</TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        </Card>
      )}
    </>
  )
}
