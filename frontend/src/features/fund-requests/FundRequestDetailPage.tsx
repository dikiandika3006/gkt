import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeft, CheckCircle2, Info, Pencil, Plus, Send, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { AttachmentList } from '@/components/AttachmentList'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { PageHeader } from '@/components/PageHeader'
import { useAuth } from '@/features/auth/AuthContext'
import { api, errorMessage } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import type { FundReportItem, FundRequest } from '@/types'
import { FundRequestFormDialog } from './FundRequestFormDialog'
import { ReportItemFormDialog } from './ReportItemFormDialog'
import { StatusBadge } from './shared'
import { TransferToRequestDialog } from './TransferToRequestDialog'
import { signedClass } from './utils'

export default function FundRequestDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = useAuth()
  // Transfer dana = aksi bendahara (Mutasi); pengajuan & LPJ = Kas Sekretariat.
  const canWrite = can('transactions', 'manage')
  const canManageFunds = can('fund_requests', 'manage')
  const [editOpen, setEditOpen] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [transferOpen, setTransferOpen] = useState(false)
  const [itemOpen, setItemOpen] = useState(false)
  const [editingItem, setEditingItem] = useState<FundReportItem | null>(null)
  const [deletingItem, setDeletingItem] = useState<FundReportItem | null>(null)
  const [notes, setNotes] = useState<string | null>(null)

  const key = ['fund-requests', 'detail', id]
  const { data: request, isLoading } = useQuery({
    queryKey: key,
    queryFn: async () => (await api.get<{ data: FundRequest }>(`/fund-requests/${id}`)).data.data,
  })

  const refresh = (fresh?: FundRequest) => {
    if (fresh) queryClient.setQueryData(key, fresh)
    ;['fund-requests', 'transactions', 'dashboard', 'reports'].forEach((k) => queryClient.invalidateQueries({ queryKey: [k] }))
  }

  const removeRequest = useMutation({
    mutationFn: () => api.delete(`/fund-requests/${id}`),
    onSuccess: () => {
      toast.success('Pengajuan dihapus')
      refresh()
      navigate('/fund-requests', { replace: true })
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const removeItem = useMutation({
    mutationFn: async (item: FundReportItem) => (await api.delete<{ data: FundRequest }>(`/fund-report-items/${item.id}`)).data.data,
    onSuccess: (fresh) => {
      toast.success('Nota dihapus')
      refresh(fresh)
    },
    onError: (err) => toast.error(errorMessage(err)),
    onSettled: () => setDeletingItem(null),
  })

  const removeTransfer = useMutation({
    mutationFn: (group: string) => api.delete(`/transfers/${group}`),
    onSuccess: () => {
      toast.success('Transfer dihapus')
      queryClient.invalidateQueries({ queryKey: key })
      refresh()
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const saveReport = useMutation({
    mutationFn: async (submitted: boolean) =>
      (await api.put<{ data: FundRequest }>(`/fund-requests/${id}/report`, { notes: notes ?? request?.report?.notes ?? '', submitted })).data.data,
    onSuccess: (fresh, submitted) => {
      toast.success(submitted ? 'LPJ ditandai lengkap' : 'LPJ disimpan')
      setNotes(null)
      refresh(fresh)
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  if (isLoading || !request) return <p className="text-muted-foreground">{isLoading ? 'Memuat...' : 'Pengajuan tidak ditemukan.'}</p>

  const s = request.summary
  const items = request.report?.items ?? []
  const submitted = !!request.report?.submitted_at

  return (
    <>
      <PageHeader
        title={`Pengajuan ${formatDate(`${request.period}-01`, 'MMMM yyyy')}`}
        description={`${request.number} · ${request.cash_account?.name} · diajukan ${formatDate(request.request_date)}${request.creator ? ` oleh ${request.creator.name}` : ''}`}
        actions={
          <>
            <Button variant="outline" render={<Link to="/fund-requests" />}>
              <ArrowLeft /> Kembali
            </Button>
            {canManageFunds && (
              <>
                <Button variant="outline" onClick={() => setEditOpen(true)}>
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

      <div className="flex items-center gap-2">
        <StatusBadge status={s.status} label={s.status_label} />
        {request.notes && <span className="text-sm text-muted-foreground">{request.notes}</span>}
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Stat label="Sisa dibawa dari bulan lalu" value={s.carry_over} hint={s.carry_over < 0 ? 'Kurang (kas menalangi)' : 'Sisa dana'} signed />
        <Stat label="Pengajuan bulan ini" value={s.requested} hint={`Saran transfer ${formatRupiah(s.suggested_transfer)}`} />
        <Stat label="Sudah ditransfer" value={s.transferred} hint={s.remaining_transfer > 0 ? `Kurang ${formatRupiah(s.remaining_transfer)} dari saran` : 'Sesuai saran'} />
        <Stat
          label="Realisasi LPJ"
          value={s.reported}
          hint={`Saldo akhir ${formatRupiah(s.closing_balance)} → dibawa ke bulan berikutnya`}
          highlight={s.closing_balance}
        />
      </div>

      <Card className="border-dashed bg-muted/30">
        <CardContent className="flex gap-2 text-xs text-muted-foreground">
          <Info className="mt-0.5 size-4 shrink-0" />
          <p>
            Saran transfer = pengajuan − sisa dibawa. Transfer dicatat sebagai mutasi antar kas (bukan pengeluaran). Setiap nota LPJ otomatis menjadi
            pengeluaran gereja dari {request.cash_account?.name} sesuai kategori & tanggal nota. Selisih (transfer − realisasi) otomatis menjadi sisa yang
            dibawa ke pengajuan berikutnya.
          </p>
        </CardContent>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card className="gap-0 pb-0">
          <CardHeader className="pb-3">
            <CardTitle>Rencana Kebutuhan</CardTitle>
          </CardHeader>
          <Table>
            <TableBody>
              {(request.items ?? []).map((i) => (
                <TableRow key={i.id}>
                  <TableCell className="pl-4">{i.description}</TableCell>
                  <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(i.amount)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
            <TableFooter>
              <TableRow>
                <TableCell className="pl-4">Total</TableCell>
                <TableCell className="pr-4 text-right tabular-nums">{formatRupiah(s.requested)}</TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        </Card>

        <Card className="gap-0 pb-0">
          <CardHeader className="flex flex-row items-center justify-between pb-3">
            <CardTitle>Transfer dari Bendahara</CardTitle>
            {canWrite && (
              <Button size="sm" onClick={() => setTransferOpen(true)}>
                <Send /> Catat Transfer
              </Button>
            )}
          </CardHeader>
          <Table>
            <TableBody>
              {(request.transfers ?? []).length === 0 && (
                <TableRow>
                  <TableCell className="py-6 text-center text-muted-foreground">Belum ada transfer.</TableCell>
                </TableRow>
              )}
              {(request.transfers ?? []).map((t) => (
                <TableRow key={t.transfer_group}>
                  <TableCell className="pl-4">
                    <div>{formatDate(t.date)}</div>
                    <div className="font-mono text-xs text-muted-foreground">{t.number}</div>
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{formatRupiah(t.amount)}</TableCell>
                  {canWrite && (
                    <TableCell className="w-10 pr-4">
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label="Hapus transfer"
                        disabled={removeTransfer.isPending}
                        onClick={() => removeTransfer.mutate(t.transfer_group)}
                      >
                        <Trash2 className="text-destructive" />
                      </Button>
                    </TableCell>
                  )}
                </TableRow>
              ))}
            </TableBody>
            <TableFooter>
              <TableRow>
                <TableCell className="pl-4">Total</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(s.transferred)}</TableCell>
                {canWrite && <TableCell />}
              </TableRow>
            </TableFooter>
          </Table>
        </Card>
      </div>

      <Card className="gap-0 pb-0">
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 pb-3">
          <div>
            <CardTitle>LPJ (Laporan Pertanggungjawaban)</CardTitle>
            <CardDescription>Satu baris per nota. Lampirkan foto/scan nota.</CardDescription>
          </div>
          {canManageFunds && (
            <Button
              size="sm"
              onClick={() => {
                setEditingItem(null)
                setItemOpen(true)
              }}
            >
              <Plus /> Tambah Nota
            </Button>
          )}
        </CardHeader>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Tanggal</TableHead>
              <TableHead>Uraian</TableHead>
              <TableHead>Kategori</TableHead>
              <TableHead className="text-right">Qty × Harga</TableHead>
              <TableHead className="text-right">Jumlah</TableHead>
              <TableHead>Nota</TableHead>
              {canManageFunds && <TableHead className="w-20 pr-4" />}
            </TableRow>
          </TableHeader>
          <TableBody>
            {items.length === 0 && (
              <TableRow>
                <TableCell colSpan={7} className="py-6 text-center text-muted-foreground">
                  Belum ada nota.
                </TableCell>
              </TableRow>
            )}
            {items.map((item) => (
              <TableRow key={item.id}>
                <TableCell className="pl-4 whitespace-nowrap">{formatDate(item.date)}</TableCell>
                <TableCell>
                  <div>{item.description}</div>
                  {item.transaction_number && <div className="font-mono text-xs text-muted-foreground">{item.transaction_number}</div>}
                </TableCell>
                <TableCell>{item.category?.name}</TableCell>
                <TableCell className="text-right text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                  {item.qty} {item.unit ?? ''} × {formatRupiah(item.unit_price)}
                </TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(item.amount)}</TableCell>
                <TableCell>
                  <AttachmentList
                    attachableType="fund_report_item"
                    attachableId={item.id}
                    attachments={item.attachments ?? []}
                    canEdit={canManageFunds}
                    onChanged={() => queryClient.invalidateQueries({ queryKey: key })}
                  />
                </TableCell>
                {canManageFunds && (
                  <TableCell className="pr-4">
                    <div className="flex justify-end gap-1">
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label="Ubah nota"
                        onClick={() => {
                          setEditingItem(item)
                          setItemOpen(true)
                        }}
                      >
                        <Pencil />
                      </Button>
                      <Button size="icon-sm" variant="ghost" aria-label="Hapus nota" onClick={() => setDeletingItem(item)}>
                        <Trash2 className="text-destructive" />
                      </Button>
                    </div>
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
          <TableFooter>
            <TableRow>
              <TableCell colSpan={4} className="pl-4">
                Total realisasi · selisih terhadap transfer:{' '}
                <span className={signedClass(s.difference)}>
                  {formatRupiah(s.difference)} {s.difference >= 0 ? '(sisa)' : '(kurang)'}
                </span>
              </TableCell>
              <TableCell className="text-right tabular-nums">{formatRupiah(s.reported)}</TableCell>
              <TableCell colSpan={canManageFunds ? 2 : 1} />
            </TableRow>
          </TableFooter>
        </Table>
        <CardContent className="flex flex-wrap items-end gap-3 border-t py-4">
          <div className="grid min-w-64 flex-1 gap-1">
            <span className="text-xs text-muted-foreground">Catatan LPJ</span>
            <Input
              value={notes ?? request.report?.notes ?? ''}
              onChange={(e) => setNotes(e.target.value)}
              disabled={!canManageFunds}
              placeholder="Opsional"
            />
          </div>
          {canManageFunds && (
            <>
              <Button variant="outline" disabled={saveReport.isPending} onClick={() => saveReport.mutate(submitted)}>
                Simpan catatan
              </Button>
              <Button
                variant={submitted ? 'outline' : 'default'}
                disabled={saveReport.isPending}
                onClick={() => saveReport.mutate(!submitted)}
              >
                <CheckCircle2 /> {submitted ? 'Batalkan tanda lengkap' : 'Tandai LPJ lengkap'}
              </Button>
            </>
          )}
          {submitted && <span className="text-xs text-muted-foreground">Lengkap sejak {formatDate(request.report!.submitted_at!.slice(0, 10))}</span>}
        </CardContent>
      </Card>

      <FundRequestFormDialog
        open={editOpen}
        onOpenChange={setEditOpen}
        cashAccountId={request.cash_account_id}
        request={request}
        onSaved={(fresh) => refresh(fresh)}
      />
      <TransferToRequestDialog open={transferOpen} onOpenChange={setTransferOpen} request={request} onSaved={refresh} />
      <ReportItemFormDialog open={itemOpen} onOpenChange={setItemOpen} request={request} item={editingItem} onSaved={refresh} />
      <ConfirmDialog
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        title="Hapus pengajuan?"
        description="Pengajuan beserta semua transfer dan nota LPJ-nya (termasuk transaksinya) akan dihapus."
        loading={removeRequest.isPending}
        onConfirm={() => removeRequest.mutate()}
      />
      <ConfirmDialog
        open={!!deletingItem}
        onOpenChange={(o) => !o && setDeletingItem(null)}
        title="Hapus nota?"
        description={`Nota "${deletingItem?.description ?? ''}" beserta transaksi pengeluarannya akan dihapus.`}
        loading={removeItem.isPending}
        onConfirm={() => deletingItem && removeItem.mutate(deletingItem)}
      />
    </>
  )
}

function Stat({ label, value, hint, signed, highlight }: { label: string; value: number; hint?: string; signed?: boolean; highlight?: number }) {
  return (
    <Card>
      <CardHeader className="pb-1">
        <CardDescription>{label}</CardDescription>
        <CardTitle className={`text-xl tabular-nums ${signed ? signedClass(value) : ''}`}>{formatRupiah(value)}</CardTitle>
      </CardHeader>
      {hint && (
        <CardContent className={`text-xs ${highlight !== undefined && highlight < 0 ? 'text-destructive' : 'text-muted-foreground'}`}>{hint}</CardContent>
      )}
    </Card>
  )
}
