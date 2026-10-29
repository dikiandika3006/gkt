import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeft, ArrowRightLeft, Loader2, Pencil, Printer, Save, Trash2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { PageHeader } from '@/components/PageHeader'
import { useAuth } from '@/features/auth/AuthContext'
import { api, errorMessage } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { OfferingCandidate, OfferingReport } from '@/types'
import { STATUS } from './utils'
import { WartaSheet } from './WartaSheet'

export default function OfferingDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canWrite = useAuth().can('offerings', 'manage')
  const [deleteOpen, setDeleteOpen] = useState(false)

  const key = ['offering-reports', 'detail', id]
  const { data: report, isLoading } = useQuery({
    queryKey: key,
    queryFn: async () => (await api.get<{ data: OfferingReport }>(`/offering-reports/${id}`)).data.data,
  })

  const remove = useMutation({
    mutationFn: () => api.delete(`/offering-reports/${id}`),
    onSuccess: () => {
      toast.success('Rekap dihapus')
      queryClient.invalidateQueries({ queryKey: ['offering-reports'] })
      navigate('/offerings', { replace: true })
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  if (isLoading || !report) return <p className="text-muted-foreground">{isLoading ? 'Memuat...' : 'Rekap tidak ditemukan.'}</p>

  const status = STATUS[report.summary.status]

  return (
    <>
      <div className="print:hidden">
        <PageHeader
          title={`Rekap ${formatDate(report.period_start, 'd MMM')} – ${formatDate(report.period_end, 'd MMM yyyy')}`}
          description={`Ibadah ${formatDate(report.service_date, 'EEEE, d MMMM yyyy')}${report.creator ? ` · dicatat oleh ${report.creator.name}` : ''}`}
          actions={
            <>
              <Button variant="outline" render={<Link to="/offerings" />}>
                <ArrowLeft /> Kembali
              </Button>
              {canWrite && (
                <>
                  <Button variant="outline" render={<Link to={`/offerings/${report.id}/edit`} />}>
                    <Pencil /> Ubah
                  </Button>
                  <Button variant="outline" onClick={() => setDeleteOpen(true)}>
                    <Trash2 className="text-destructive" /> Hapus
                  </Button>
                </>
              )}
            </>
          }
        />
      </div>

      <Tabs defaultValue="warta">
        <TabsList className="print:hidden">
          <TabsTrigger value="warta">Laporan Warta</TabsTrigger>
          <TabsTrigger value="crosscheck">
            Crosscheck Mutasi
            <Badge variant="outline" className={cn('ml-1', status.className)}>
              {status.label}
            </Badge>
          </TabsTrigger>
        </TabsList>

        <TabsContent value="warta" className="grid gap-3">
          <div className="flex items-center justify-between gap-2 print:hidden">
            <p className="text-sm text-muted-foreground">Screenshot area putih di bawah untuk ditempel di warta jemaat.</p>
            <Button variant="outline" size="sm" onClick={() => window.print()}>
              <Printer /> Cetak
            </Button>
          </div>
          <div className="overflow-x-auto rounded-xl bg-muted/40 p-4 print:overflow-visible print:bg-transparent print:p-0">
            <WartaSheet report={report} />
          </div>
        </TabsContent>

        <TabsContent value="crosscheck" className="grid gap-4">
          <Crosscheck report={report} canWrite={canWrite} onSaved={(fresh) => {
            queryClient.setQueryData(key, fresh)
            queryClient.invalidateQueries({ queryKey: ['offering-reports'] })
          }} />
        </TabsContent>
      </Tabs>

      <ConfirmDialog
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        title="Hapus rekap persembahan?"
        description="Rekap dan penautan mutasinya akan dihapus. Transaksi mutasi tetap ada."
        loading={remove.isPending}
        onConfirm={() => remove.mutate()}
      />
    </>
  )
}

function Crosscheck({ report, canWrite, onSaved }: { report: OfferingReport; canWrite: boolean; onSaved: (fresh: OfferingReport) => void }) {
  const [range, setRange] = useState<{ start_date?: string; end_date?: string }>({})
  const [selected, setSelected] = useState<Set<number> | null>(null)

  const candidates = useQuery({
    queryKey: ['offering-reports', 'candidates', report.id, range],
    queryFn: async () =>
      (
        await api.get<{ start_date: string; end_date: string; data: OfferingCandidate[] }>(`/offering-reports/${report.id}/candidates`, {
          params: range,
        })
      ).data,
  })

  const rows = candidates.data?.data ?? []
  // Sebelum user mencentang apa pun, pilihan = mutasi yang sudah tertaut.
  const chosen = selected ?? new Set((report.transactions ?? []).map((t) => t.id))
  const chosenTotal = rows.filter((r) => chosen.has(r.id)).reduce((sum, r) => sum + r.amount, 0)
  const s = report.summary
  const liveDiff = chosenTotal - s.expected_deposit
  const template = useQuery({
    queryKey: ['offering-reports', 'template'],
    queryFn: async () => (await api.get<{ default_cash_account_id: number | null }>('/offering-reports/template')).data,
  })
  const churchId = template.data?.default_cash_account_id ?? null
  const elsewhere = (report.lines ?? []).filter((l) => l.section !== 'pengeluaran' && l.destination_cash_account_id !== churchId)

  const save = useMutation({
    mutationFn: async () =>
      (await api.put<{ data: OfferingReport }>(`/offering-reports/${report.id}/transactions`, { transaction_ids: [...chosen] })).data.data,
    onSuccess: (fresh) => {
      toast.success('Hasil crosscheck disimpan')
      setSelected(null)
      onSaved(fresh)
      candidates.refetch()
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const toggle = (id: number) => {
    const next = new Set(chosen)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    setSelected(next)
  }

  const status = STATUS[s.status]

  return (
    <>
      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <CardHeader>
            <CardDescription>Seharusnya masuk Rekening Gereja</CardDescription>
            <CardTitle className="text-xl">{formatRupiah(s.expected_deposit)}</CardTitle>
          </CardHeader>
          {s.other_destination_total > 0 && (
            <CardContent className="text-xs text-muted-foreground">
              Tidak termasuk {formatRupiah(s.other_destination_total)} yang ditransfer ke rekening lain.
            </CardContent>
          )}
        </Card>
        <Card>
          <CardHeader>
            <CardDescription>Mutasi masuk yang dicocokkan</CardDescription>
            <CardTitle className="text-xl">{formatRupiah(chosenTotal)}</CardTitle>
          </CardHeader>
          <CardContent className="text-xs text-muted-foreground">{chosen.size} mutasi dipilih</CardContent>
        </Card>
        <Card className={cn(s.status === 'kurang' && 'border-destructive/50', s.status === 'cocok' && 'border-success/50')}>
          <CardHeader>
            <CardDescription className="flex items-center justify-between">
              Selisih
              <Badge variant="outline" className={status.className}>
                {selected ? 'Belum disimpan' : status.label}
              </Badge>
            </CardDescription>
            <CardTitle className={cn('text-xl', Math.abs(liveDiff) >= 0.01 && (liveDiff < 0 ? 'text-destructive' : 'text-warning'), Math.abs(liveDiff) < 0.01 && chosen.size > 0 && 'text-success')}>
              {liveDiff > 0 ? '+' : ''}
              {formatRupiah(liveDiff)}
            </CardTitle>
          </CardHeader>
          <CardContent className="text-xs text-muted-foreground">
            {chosen.size === 0 ? 'Centang mutasi setoran dari bendahara 2 (termasuk QRIS).' : liveDiff < 0 ? 'Masih ada yang belum masuk ke rekening.' : liveDiff > 0 ? 'Mutasi yang dipilih melebihi rekap.' : 'Rekap sudah cocok dengan mutasi.'}
          </CardContent>
        </Card>
      </div>

      {elsewhere.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <ArrowRightLeft className="size-4" /> Ditransfer ke rekening lain (tidak dicocokkan)
            </CardTitle>
          </CardHeader>
          <CardContent className="grid gap-1 text-sm">
            {elsewhere.map((l, i) => (
              <div key={i} className="flex justify-between gap-2">
                <span>
                  {[l.label, l.name].filter(Boolean).join(' · ')} <span className="text-muted-foreground">→ {l.destination_note ?? l.destination?.name}</span>
                </span>
                <span className="tabular-nums">{formatRupiah(l.amount)}</span>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      <Card className="gap-0 pb-0">
        <CardHeader className="flex flex-row flex-wrap items-end justify-between gap-3 pb-3">
          <div>
            <CardTitle className="text-base">Mutasi masuk Rekening Gereja</CardTitle>
            <CardDescription>Default Senin–Sabtu setelah ibadah. Mutasi yang sudah dipakai rekap lain tidak ditampilkan.</CardDescription>
          </div>
          <div className="flex flex-wrap items-end gap-2">
            <Input
              type="date"
              className="w-40"
              aria-label="Dari tanggal"
              value={range.start_date ?? candidates.data?.start_date ?? ''}
              onChange={(e) => e.target.value && setRange((r) => ({ ...r, start_date: e.target.value, end_date: r.end_date ?? candidates.data?.end_date }))}
            />
            <Input
              type="date"
              className="w-40"
              aria-label="Sampai tanggal"
              value={range.end_date ?? candidates.data?.end_date ?? ''}
              onChange={(e) => e.target.value && setRange((r) => ({ ...r, end_date: e.target.value, start_date: r.start_date ?? candidates.data?.start_date }))}
            />
            {canWrite && (
              <Button disabled={!selected || save.isPending} onClick={() => save.mutate()}>
                {save.isPending ? <Loader2 className="animate-spin" /> : <Save />} Simpan
              </Button>
            )}
          </div>
        </CardHeader>
        <Table>
          <TableHeader>
            <TableRow>
              {canWrite && <TableHead className="w-10 pl-4" />}
              <TableHead className={canWrite ? '' : 'pl-4'}>Tanggal</TableHead>
              <TableHead>Keterangan bank</TableHead>
              <TableHead>Kategori</TableHead>
              <TableHead className="pr-4 text-right">Jumlah</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {candidates.isLoading && (
              <TableRow>
                <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                  Memuat...
                </TableCell>
              </TableRow>
            )}
            {!candidates.isLoading && rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                  Belum ada mutasi masuk pada rentang ini. Import mutasi bank dulu di menu Mutasi.
                </TableCell>
              </TableRow>
            )}
            {rows.map((r) => (
              <TableRow key={r.id} className={cn(chosen.has(r.id) && 'bg-success/5', canWrite && 'cursor-pointer')} onClick={() => canWrite && toggle(r.id)}>
                {canWrite && (
                  <TableCell className="pl-4">
                    <input type="checkbox" className="size-4 accent-primary" checked={chosen.has(r.id)} onChange={() => toggle(r.id)} onClick={(e) => e.stopPropagation()} aria-label={`Pilih ${r.number}`} />
                  </TableCell>
                )}
                <TableCell className={cn('whitespace-nowrap', !canWrite && 'pl-4')}>{formatDate(r.date, 'EEE, d MMM')}</TableCell>
                <TableCell className="max-w-96">
                  <div className="truncate">{r.bank_description ?? r.description}</div>
                  {r.counterparty && <div className="truncate text-xs text-muted-foreground">{r.counterparty}</div>}
                </TableCell>
                <TableCell className="text-muted-foreground">{r.category}</TableCell>
                <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(r.amount)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
          {rows.length > 0 && (
            <TableFooter>
              <TableRow>
                <TableCell colSpan={canWrite ? 4 : 3} className="pl-4">
                  Total dipilih
                </TableCell>
                <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(chosenTotal)}</TableCell>
              </TableRow>
            </TableFooter>
          )}
        </Table>
      </Card>
    </>
  )
}
