import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { formatDate, formatRupiah, toDateInput } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { FundPosition } from '@/types'
import { DateInput, Field, PrintTitle, ReportToolbar, SummaryCard } from './components'

function Line({ label, value, bold, minus, hint }: { label: string; value: number; bold?: boolean; minus?: boolean; hint?: string }) {
  return (
    <TableRow className={cn(bold && 'border-t-2 font-semibold')}>
      <TableCell className={cn('pl-4', minus && 'pl-8')}>
        {label}
        {hint && <span className="block text-xs font-normal text-muted-foreground">{hint}</span>}
      </TableCell>
      <TableCell className="pr-4 text-right tabular-nums">{minus && value !== 0 ? `(${formatRupiah(value)})` : formatRupiah(value)}</TableCell>
    </TableRow>
  )
}

/** Posisi dana: dari total uang di kas & bank, berapa yang benar-benar bebas dipakai untuk operasional. */
export default function FundPositionPage() {
  const [date, setDate] = useState(() => toDateInput(new Date()))

  const { data } = useQuery({
    queryKey: ['reports', 'fund-position', date],
    queryFn: async () => (await api.get<FundPosition>('/reports/fund-position', { params: { date } })).data,
  })

  return (
    <>
      <ReportToolbar exportName="fund-position" exportParams={{ date }} filename={`posisi-dana-${date}.xlsx`}>
        <Field label="Per tanggal">
          <DateInput value={date} onChange={setDate} />
        </Field>
      </ReportToolbar>

      <PrintTitle title="Posisi Dana" subtitle={`Per ${formatDate(date, 'd MMMM yyyy')}`} />

      {data && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <SummaryCard label="Total uang di kas & bank" value={data.total} />
            <SummaryCard label="Dana khusus (pocket)" value={data.special_funds} hint="Sudah terikat untuk tujuan tertentu" />
            <SummaryCard label="Titipan & utang pinjaman" value={data.deposits + data.loan_debt} hint="Uang pihak lain yang dipegang gereja" />
            <SummaryCard label="Dana bebas (operasional)" value={data.free_funds} tone={data.free_funds < 0 ? 'negative' : 'positive'} hint="Yang benar-benar bisa dipakai" />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>Perhitungan dana bebas</CardTitle>
                <CardDescription>Per {formatDate(data.date, 'd MMMM yyyy')}</CardDescription>
              </CardHeader>
              <CardContent className="px-0">
                <Table>
                  <TableBody>
                    <Line label="Total uang di semua kas & bank" value={data.total} />
                    <Line label="Dana khusus (rekening ber-pocket)" value={data.special_funds} minus />
                    <Line label="Titipan belum disalurkan" value={data.deposits} minus />
                    <Line label="Utang pinjaman (belum dikembalikan)" value={data.loan_debt} minus />
                    <Line label="Dana bebas (operasional)" value={data.free_funds} bold />
                    {data.loan_receivable > 0 && (
                      <Line label="Piutang: uang gereja yang dipinjamkan" value={data.loan_receivable} hint="Tidak ada di kas; menambah dana bila sudah kembali" />
                    )}
                  </TableBody>
                </Table>
                {data.non_operational.length > 0 && (
                  <div className="mt-2 grid gap-1 px-4 text-xs text-muted-foreground">
                    <p className="font-medium">Rincian titipan & pinjaman (kas umum):</p>
                    {data.non_operational.map((r) => (
                      <p key={r.code} className="flex justify-between gap-4">
                        <span>{r.name}</span>
                        <span className="tabular-nums">{formatRupiah(r.net)}</span>
                      </p>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>

            <div className="grid content-start gap-4">
              <Card>
                <CardHeader>
                  <CardTitle>Saldo per kas & bank</CardTitle>
                </CardHeader>
                <CardContent className="px-0">
                  <Table>
                    <TableBody>
                      {data.accounts.map((a) => (
                        <TableRow key={a.id}>
                          <TableCell className="pl-4">
                            {a.name}
                            {a.has_pockets && <span className="block text-xs text-muted-foreground">Dana khusus</span>}
                          </TableCell>
                          <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(a.balance)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </CardContent>
              </Card>

              {data.pockets.map((acc) => (
                <Card key={acc.id}>
                  <CardHeader>
                    <CardTitle>Dana khusus · {acc.name}</CardTitle>
                  </CardHeader>
                  <CardContent className="px-0">
                    <Table>
                      <TableBody>
                        {acc.pockets.map((p) => (
                          <TableRow key={p.id}>
                            <TableCell className="pl-4">{p.name}</TableCell>
                            <TableCell className={cn('pr-4 text-right tabular-nums', p.balance < 0 && 'text-destructive')}>{formatRupiah(p.balance)}</TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </CardContent>
                </Card>
              ))}
            </div>
          </div>
        </>
      )}
    </>
  )
}
