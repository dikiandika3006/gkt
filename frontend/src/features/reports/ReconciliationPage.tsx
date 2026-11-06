import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { format, subMonths } from 'date-fns'
import { toast } from 'sonner'
import { Check, Loader2, Pencil, Trash2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAuth } from '@/features/auth/AuthContext'
import { api, errorMessage } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { ReconciliationRow } from '@/types'
import { DateInput, Field, PrintTitle } from './components'

/** Rekonsiliasi per akhir bulan: saldo aplikasi vs saldo rekening koran / hitung fisik kas. */
export default function ReconciliationPage() {
  const { hasRole } = useAuth()
  const canEdit = hasRole('admin', 'bendahara')
  const queryClient = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()
  const [lastMonth] = useState(() => format(subMonths(new Date(), 1), 'yyyy-MM'))
  const month = searchParams.get('month') ?? lastMonth
  const setMonth = (m: string) => setSearchParams({ month: m }, { replace: true })
  const [editing, setEditing] = useState<ReconciliationRow | null>(null)
  const [form, setForm] = useState({ statement_balance: '', notes: '' })

  const { data, isLoading } = useQuery({
    queryKey: ['bank-reconciliations', month],
    queryFn: async () => (await api.get<{ month: string; accounts: ReconciliationRow[] }>('/bank-reconciliations', { params: { month } })).data,
  })

  const done = (message: string) => {
    toast.success(message)
    setEditing(null)
    queryClient.invalidateQueries({ queryKey: ['bank-reconciliations'] })
    queryClient.invalidateQueries({ queryKey: ['period-locks'] })
  }
  const save = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ message: string }>('/bank-reconciliations', {
          cash_account_id: editing?.cash_account_id,
          month,
          statement_balance: form.statement_balance,
          notes: form.notes || null,
        })
      ).data,
    onSuccess: (res) => done(res.message),
    onError: (err) => toast.error(errorMessage(err)),
  })
  const remove = useMutation({
    mutationFn: async (row: ReconciliationRow) => (await api.delete<{ message: string }>(`/bank-reconciliations/${row.id}`)).data,
    onSuccess: (res) => done(res.message),
    onError: (err) => toast.error(errorMessage(err)),
  })

  const monthEnd = formatDate(format(new Date(Number(month.slice(0, 4)), Number(month.slice(5, 7)), 0), 'yyyy-MM-dd'), 'd MMMM yyyy')
  const accounts = data?.accounts ?? []
  const filled = accounts.filter((a) => a.statement_balance != null)
  const matched = filled.filter((a) => a.difference === 0)

  return (
    <>
      <Card className="print:hidden">
        <CardContent className="flex flex-wrap items-end gap-3">
          <Field label="Bulan">
            <DateInput type="month" value={month} onChange={setMonth} />
          </Field>
          <p className="self-center text-sm text-muted-foreground">
            Saldo per {monthEnd}: {matched.length} cocok, {filled.length - matched.length} selisih, {accounts.length - filled.length} belum diisi.
          </p>
          <Button variant="outline" className="ml-auto" onClick={() => window.print()}>
            Cetak / PDF
          </Button>
        </CardContent>
      </Card>

      <PrintTitle title="Rekonsiliasi Kas & Bank" subtitle={`Saldo per ${monthEnd}`} />

      <Card>
        <CardHeader>
          <CardTitle>Saldo per {monthEnd}</CardTitle>
          <CardDescription>
            Isi saldo menurut rekening koran (bank) atau hitung fisik (kas tunai). Selisih ≠ 0 berarti ada transaksi yang belum/salah dicatat — cek di{' '}
            <Link to="/reports/buku-kas" className="underline underline-offset-4">
              Buku Kas
            </Link>
            .
          </CardDescription>
        </CardHeader>
        <CardContent className="px-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-4">Kas / Rekening</TableHead>
                <TableHead className="text-right">Saldo aplikasi</TableHead>
                <TableHead className="text-right">Rekening koran / fisik</TableHead>
                <TableHead className="text-right">Selisih</TableHead>
                <TableHead>Status</TableHead>
                {canEdit && <TableHead className="pr-4 text-right print:hidden">Aksi</TableHead>}
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading && (
                <TableRow>
                  <TableCell colSpan={6} className="py-8 text-center">
                    <Loader2 className="mx-auto animate-spin text-muted-foreground" />
                  </TableCell>
                </TableRow>
              )}
              {accounts.map((a) => (
                <TableRow key={a.cash_account_id}>
                  <TableCell className="pl-4">
                    <div className="font-medium">{a.name}</div>
                    <div className="text-xs text-muted-foreground">
                      {a.type_label}
                      {a.account_number && ` · ${a.account_number}`}
                    </div>
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{formatRupiah(a.app_balance)}</TableCell>
                  <TableCell className="text-right tabular-nums">{a.statement_balance == null ? '—' : formatRupiah(a.statement_balance)}</TableCell>
                  <TableCell className={cn('text-right tabular-nums', a.difference && 'font-medium text-destructive')}>
                    {a.difference == null ? '—' : formatRupiah(a.difference)}
                  </TableCell>
                  <TableCell>
                    {a.statement_balance == null ? (
                      <Badge variant="outline">Belum</Badge>
                    ) : a.difference === 0 ? (
                      <Badge variant="secondary">
                        <Check /> Cocok
                      </Badge>
                    ) : (
                      <Badge variant="destructive">Selisih</Badge>
                    )}
                    {(a.notes || a.reconciled_by) && (
                      <div className="mt-0.5 text-xs text-muted-foreground">
                        {a.notes}
                        {a.reconciled_by && ` (${a.reconciled_by})`}
                      </div>
                    )}
                  </TableCell>
                  {canEdit && (
                    <TableCell className="pr-4 print:hidden">
                      <div className="flex justify-end gap-1">
                        <Button
                          size="icon-sm"
                          variant="ghost"
                          aria-label="Isi saldo"
                          onClick={() => {
                            setForm({ statement_balance: a.statement_balance == null ? '' : String(a.statement_balance), notes: a.notes ?? '' })
                            setEditing(a)
                          }}
                        >
                          <Pencil />
                        </Button>
                        {a.id && (
                          <Button size="icon-sm" variant="ghost" aria-label="Hapus" disabled={remove.isPending} onClick={() => remove.mutate(a)}>
                            <Trash2 className="text-destructive" />
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  )}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Dialog open={!!editing} onOpenChange={(o) => !o && setEditing(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editing?.name}</DialogTitle>
            <DialogDescription>
              Saldo aplikasi per {monthEnd}: <b>{editing && formatRupiah(editing.app_balance)}</b>
            </DialogDescription>
          </DialogHeader>
          <form
            className="grid gap-4"
            onSubmit={(e) => {
              e.preventDefault()
              save.mutate()
            }}
          >
            <div className="grid gap-2">
              <Label htmlFor="statement">{editing?.type === 'bank' ? 'Saldo menurut rekening koran' : 'Saldo hitung fisik'}</Label>
              <Input
                id="statement"
                type="number"
                step="0.01"
                required
                value={form.statement_balance}
                onChange={(e) => setForm((f) => ({ ...f, statement_balance: e.target.value }))}
              />
              {editing && form.statement_balance !== '' && (
                <p className="text-xs text-muted-foreground">Selisih: {formatRupiah(Number(form.statement_balance) - editing.app_balance)}</p>
              )}
            </div>
            <div className="grid gap-2">
              <Label htmlFor="recon-notes">Catatan (opsional)</Label>
              <Input id="recon-notes" maxLength={255} value={form.notes} placeholder="Mis. biaya admin 31 Okt belum dicatat" onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} />
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                Batal
              </Button>
              <Button type="submit" disabled={save.isPending}>
                {save.isPending && <Loader2 className="animate-spin" />} Simpan
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  )
}
