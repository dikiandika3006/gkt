import { useState } from 'react'
import { Link } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ChevronLeft, ChevronRight, Lock, LockOpen } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { useAuth } from '@/features/auth/AuthContext'
import { api, errorMessage } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import type { PeriodLockMonth } from '@/types'

/** Tutup buku per bulan: admin & bendahara menutup, hanya admin yang bisa membuka kembali. */
export default function PeriodLocksPage() {
  const { hasRole } = useAuth()
  const queryClient = useQueryClient()
  const [year, setYear] = useState(() => new Date().getFullYear())
  const [closing, setClosing] = useState<PeriodLockMonth | null>(null)
  const [notes, setNotes] = useState('')
  const [opening, setOpening] = useState<PeriodLockMonth | null>(null)
  const canClose = hasRole('admin', 'bendahara')
  const canOpen = hasRole('admin')

  const { data } = useQuery({
    queryKey: ['period-locks', year],
    queryFn: async () => (await api.get<{ year: number; months: PeriodLockMonth[] }>('/period-locks', { params: { year } })).data,
  })

  const refresh = () => {
    for (const key of ['period-locks', 'transactions', 'reports']) queryClient.invalidateQueries({ queryKey: [key] })
  }
  const lock = useMutation({
    mutationFn: async (m: PeriodLockMonth) => (await api.post<{ message: string }>('/period-locks', { month: m.month, notes: notes || null })).data,
    onSuccess: (res) => {
      toast.success(res.message)
      setClosing(null)
      refresh()
    },
    onError: (err) => toast.error(errorMessage(err)),
  })
  const unlock = useMutation({
    mutationFn: async (m: PeriodLockMonth) => (await api.delete<{ message: string }>(`/period-locks/${m.month}`)).data,
    onSuccess: (res) => {
      toast.success(res.message)
      setOpening(null)
      refresh()
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  return (
    <>
      <Card>
        <CardHeader className="flex flex-row items-start justify-between gap-4">
          <div className="grid gap-1">
            <CardTitle>Tutup Buku {year}</CardTitle>
            <CardDescription>
              Bulan yang sudah ditutup tidak bisa ditambah, diubah, atau dihapus transaksinya (termasuk import, LPJ, dan rekap persembahan). Lakukan setelah laporan
              bulanan diumumkan dan rekonsiliasi bank cocok.
            </CardDescription>
          </div>
          <div className="flex shrink-0 items-center gap-1">
            <Button size="icon-sm" variant="outline" aria-label="Tahun sebelumnya" onClick={() => setYear((y) => y - 1)}>
              <ChevronLeft />
            </Button>
            <span className="w-12 text-center text-sm font-medium tabular-nums">{year}</span>
            <Button size="icon-sm" variant="outline" aria-label="Tahun berikutnya" onClick={() => setYear((y) => y + 1)}>
              <ChevronRight />
            </Button>
          </div>
        </CardHeader>
        <CardContent className="px-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-4">Bulan</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Transaksi</TableHead>
                <TableHead className="text-right">Saldo akhir kas umum</TableHead>
                <TableHead className="text-right">Rekonsiliasi</TableHead>
                <TableHead className="pr-4 text-right">Aksi</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data?.months.map((m) => (
                <TableRow key={m.month} className={m.is_future ? 'text-muted-foreground' : undefined}>
                  <TableCell className="pl-4 font-medium">{m.label}</TableCell>
                  <TableCell>
                    {m.locked ? (
                      <div className="grid gap-0.5">
                        <Badge variant="secondary" className="w-fit">
                          <Lock /> Ditutup
                        </Badge>
                        <span className="text-xs text-muted-foreground">
                          {m.locked_by ?? '—'}
                          {m.locked_at && `, ${formatDate(m.locked_at.slice(0, 10))}`}
                          {m.notes && ` · ${m.notes}`}
                        </span>
                      </div>
                    ) : m.is_future ? (
                      <span className="text-xs">Belum berjalan</span>
                    ) : (
                      <Badge variant="outline">Terbuka</Badge>
                    )}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{m.transactions || '—'}</TableCell>
                  <TableCell className="text-right tabular-nums">{m.closing_balance == null ? '—' : formatRupiah(m.closing_balance)}</TableCell>
                  <TableCell className="text-right">
                    {m.is_future ? (
                      '—'
                    ) : (
                      <Link to={`/reports/rekonsiliasi?month=${m.month}`} className="text-xs underline-offset-4 hover:underline">
                        {m.reconciled_accounts ? `${m.reconciled_accounts} kas` : 'Belum'}
                      </Link>
                    )}
                  </TableCell>
                  <TableCell className="pr-4 text-right">
                    {m.locked
                      ? canOpen && (
                          <Button size="sm" variant="outline" onClick={() => setOpening(m)}>
                            <LockOpen /> Buka
                          </Button>
                        )
                      : canClose &&
                        !m.is_future && (
                          <Button
                            size="sm"
                            onClick={() => {
                              setNotes('')
                              setClosing(m)
                            }}
                          >
                            <Lock /> Tutup buku
                          </Button>
                        )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Dialog open={!!closing} onOpenChange={(o) => !o && setClosing(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Tutup buku {closing?.label}?</DialogTitle>
            <DialogDescription>
              Setelah ditutup, transaksi bulan ini tidak bisa ditambah, diubah, atau dihapus. Hanya admin yang bisa membukanya kembali.
            </DialogDescription>
          </DialogHeader>
          <div className="grid gap-2">
            <Label htmlFor="lock-notes">Catatan (opsional)</Label>
            <Input id="lock-notes" value={notes} maxLength={255} placeholder="Mis. sudah diumumkan di warta 2 Nov" onChange={(e) => setNotes(e.target.value)} />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setClosing(null)}>
              Batal
            </Button>
            <Button disabled={lock.isPending} onClick={() => closing && lock.mutate(closing)}>
              <Lock /> Tutup buku
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={!!opening}
        onOpenChange={(o) => !o && setOpening(null)}
        title={`Buka kembali ${opening?.label ?? ''}?`}
        description="Transaksi bulan ini akan bisa diubah lagi. Laporan yang sudah diumumkan bisa berubah — tutup kembali setelah koreksi."
        confirmLabel="Buka kembali"
        loading={unlock.isPending}
        onConfirm={() => opening && unlock.mutate(opening)}
      />
    </>
  )
}
