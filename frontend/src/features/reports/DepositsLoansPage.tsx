import { Fragment, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { startOfYear } from 'date-fns'
import { ChevronDown, ChevronRight } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { formatDate, formatRupiah, toDateInput } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { DepositLoanCategory } from '@/types'
import { DateInput, Field, PrintTitle, ReportToolbar } from './components'

/** Kartu titipan & pinjaman per nama: saldo awal, masuk, keluar, sisa + rincian transaksi. */
export default function DepositsLoansPage() {
  const [params, setParams] = useState(() => ({ start_date: toDateInput(startOfYear(new Date())), end_date: toDateInput(new Date()) }))
  const [open, setOpen] = useState<Set<string>>(new Set())
  const toggle = (key: string) =>
    setOpen((s) => {
      const next = new Set(s)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })

  const { data } = useQuery({
    queryKey: ['reports', 'deposits-loans', params],
    queryFn: async () => (await api.get<{ categories: DepositLoanCategory[] }>('/reports/deposits-loans', { params })).data,
  })

  const num = 'text-right tabular-nums whitespace-nowrap'
  const categories = data?.categories ?? []

  return (
    <>
      <ReportToolbar exportName="deposits-loans" exportParams={params} filename={`titipan-pinjaman-${params.start_date}-${params.end_date}.xlsx`}>
        <Field label="Dari">
          <DateInput value={params.start_date} onChange={(v) => setParams((p) => ({ ...p, start_date: v }))} />
        </Field>
        <Field label="Sampai">
          <DateInput value={params.end_date} onChange={(v) => setParams((p) => ({ ...p, end_date: v }))} />
        </Field>
        <p className="self-center text-xs text-muted-foreground">Kelompok per kolom Nama di transaksi. Sisa positif = masih dipegang gereja.</p>
      </ReportToolbar>

      <PrintTitle title="Titipan & Pinjaman" subtitle={`${formatDate(params.start_date)} s/d ${formatDate(params.end_date)}`} />

      {data && categories.length === 0 && (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">Tidak ada transaksi titipan atau pinjaman.</CardContent>
        </Card>
      )}

      {categories.map((cat) => (
        <Card key={cat.category_id}>
          <CardHeader className="flex flex-row items-center gap-2">
            <CardTitle>{cat.name}</CardTitle>
            <Badge variant="outline">{cat.nature === 'titipan' ? 'Titipan' : 'Pinjaman'}</Badge>
            <span className="ml-auto text-sm text-muted-foreground">
              Sisa <b className={cn('tabular-nums', cat.closing !== 0 && 'text-foreground')}>{formatRupiah(cat.closing)}</b>
            </span>
          </CardHeader>
          <CardContent className="px-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-4">Nama</TableHead>
                  <TableHead className="text-right">Saldo awal</TableHead>
                  <TableHead className="text-right">Masuk</TableHead>
                  <TableHead className="text-right">Keluar</TableHead>
                  <TableHead className="pr-4 text-right">Sisa</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {cat.people.map((p) => {
                  const key = `${cat.category_id}|${p.name}`
                  const expanded = open.has(key)
                  return (
                    <Fragment key={key}>
                      <TableRow className="cursor-pointer" onClick={() => toggle(key)}>
                        <TableCell className="pl-4 font-medium">
                          <span className="inline-flex items-center gap-1">
                            {p.transactions.length > 0 && (expanded ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4 print:hidden" />)}
                            {p.name}
                          </span>
                        </TableCell>
                        <TableCell className={num}>{formatRupiah(p.opening)}</TableCell>
                        <TableCell className={num}>{formatRupiah(p.income)}</TableCell>
                        <TableCell className={num}>{formatRupiah(p.expense)}</TableCell>
                        <TableCell className={cn(num, 'pr-4 font-medium')}>{formatRupiah(p.closing)}</TableCell>
                      </TableRow>
                      {expanded &&
                        p.transactions.map((t) => (
                          <TableRow key={t.id} className="text-xs text-muted-foreground">
                            <TableCell className="pl-10">
                              {formatDate(t.date)} · {t.description}
                              <span className="block">
                                {t.number} · {t.cash_account}
                              </span>
                            </TableCell>
                            <TableCell />
                            <TableCell className={num}>{t.income ? formatRupiah(t.income) : ''}</TableCell>
                            <TableCell className={num}>{t.expense ? formatRupiah(t.expense) : ''}</TableCell>
                            <TableCell className="pr-4" />
                          </TableRow>
                        ))}
                    </Fragment>
                  )
                })}
                <TableRow className="border-t-2 font-semibold">
                  <TableCell className="pl-4">Jumlah</TableCell>
                  <TableCell className={num}>{formatRupiah(cat.opening)}</TableCell>
                  <TableCell className={num}>{formatRupiah(cat.income)}</TableCell>
                  <TableCell className={num}>{formatRupiah(cat.expense)}</TableCell>
                  <TableCell className={cn(num, 'pr-4')}>{formatRupiah(cat.closing)}</TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      ))}
    </>
  )
}
