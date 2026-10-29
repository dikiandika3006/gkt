import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { addMonths, format } from 'date-fns'
import { ChevronLeft, ChevronRight, Plus, Settings2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { PageHeader } from '@/components/PageHeader'
import { useAuth } from '@/features/auth/AuthContext'
import { api } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { OfferingHistory } from '@/types'
import { SECTIONS, STATUS } from './utils'

/** Riwayat rekap persembahan per bulan. */
export default function OfferingsPage() {
  const canWrite = useAuth().can('offerings', 'manage')
  const navigate = useNavigate()
  const [month, setMonth] = useState(() => new Date(new Date().getFullYear(), new Date().getMonth(), 1))

  const { data, isLoading } = useQuery({
    queryKey: ['offering-reports', format(month, 'yyyy-MM')],
    queryFn: async () =>
      (await api.get<OfferingHistory>('/offering-reports', { params: { year: month.getFullYear(), month: month.getMonth() + 1 } })).data,
    placeholderData: keepPreviousData,
  })
  const ms = data?.month_summary

  return (
    <>
      <PageHeader
        title="Rekap Persembahan"
        description="Rekap hitungan persembahan mingguan untuk warta jemaat, dicocokkan dengan mutasi masuk rekening."
        actions={
          <>
            <Button variant="outline" render={<Link to="/offerings/settings" />}>
              <Settings2 /> Pos Persembahan
            </Button>
            {canWrite && (
              <Button render={<Link to="/offerings/new" />}>
                <Plus /> Rekap Baru
              </Button>
            )}
          </>
        }
      />

      <div className="flex items-center gap-2">
        <Button variant="outline" size="icon-sm" aria-label="Bulan sebelumnya" onClick={() => setMonth((m) => addMonths(m, -1))}>
          <ChevronLeft />
        </Button>
        <span className="w-40 text-center font-medium">{formatDate(format(month, 'yyyy-MM-dd'), 'MMMM yyyy')}</span>
        <Button variant="outline" size="icon-sm" aria-label="Bulan berikutnya" onClick={() => setMonth((m) => addMonths(m, 1))}>
          <ChevronRight />
        </Button>
      </div>

      {ms && (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
          <Card className="xl:col-span-2">
            <CardHeader>
              <CardDescription>Total persembahan bulan ini · {ms.weeks} minggu</CardDescription>
              <CardTitle className="text-2xl">{formatRupiah(ms.total_offering)}</CardTitle>
            </CardHeader>
          </Card>
          {SECTIONS.filter((s) => s.key !== 'pengeluaran' && s.key !== 'lainnya').map((s) => (
            <Card key={s.key}>
              <CardHeader>
                <CardDescription>{s.title}</CardDescription>
                <CardTitle className="text-lg">{formatRupiah(ms.sections[s.key])}</CardTitle>
              </CardHeader>
            </Card>
          ))}
        </div>
      )}

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Periode</TableHead>
              <TableHead className="text-right">Ibadah</TableHead>
              <TableHead className="text-right">Syukur</TableHead>
              <TableHead className="text-right">Perpuluhan</TableHead>
              <TableHead className="text-right">Lain-lain</TableHead>
              <TableHead className="text-right">Total</TableHead>
              <TableHead className="pr-4">Crosscheck Mutasi</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && (
              <TableRow>
                <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                  Memuat...
                </TableCell>
              </TableRow>
            )}
            {data?.data.length === 0 && (
              <TableRow>
                <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                  Belum ada rekap di bulan ini.
                </TableCell>
              </TableRow>
            )}
            {data?.data.map((r) => {
              const status = STATUS[r.summary.status]
              return (
                <TableRow key={r.id} className="cursor-pointer" onClick={() => navigate(`/offerings/${r.id}`)}>
                  <TableCell className="pl-4">
                    <div className="font-medium">
                      {formatDate(r.period_start, 'd MMM')} – {formatDate(r.period_end, 'd MMM yyyy')}
                    </div>
                    <div className="text-xs text-muted-foreground">Ibadah {formatDate(r.service_date, 'EEEE, d MMM')}</div>
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{formatRupiah(r.summary.sections.ibadah)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatRupiah(r.summary.sections.syukur)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatRupiah(r.summary.sections.perpuluhan)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatRupiah(r.summary.sections.lainnya)}</TableCell>
                  <TableCell className="text-right font-semibold tabular-nums">{formatRupiah(r.summary.total_offering)}</TableCell>
                  <TableCell className="pr-4">
                    <Badge variant="outline" className={cn(status.className)}>
                      {status.label}
                      {(r.summary.status === 'kurang' || r.summary.status === 'lebih') && ` ${formatRupiah(Math.abs(r.summary.difference))}`}
                    </Badge>
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
          {ms && ms.weeks > 0 && (
            <TableFooter>
              <TableRow>
                <TableCell className="pl-4">Total bulan</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(ms.sections.ibadah)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(ms.sections.syukur)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(ms.sections.perpuluhan)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(ms.sections.lainnya)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(ms.total_offering)}</TableCell>
                <TableCell />
              </TableRow>
            </TableFooter>
          )}
        </Table>
      </Card>
    </>
  )
}
