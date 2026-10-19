import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { addMonths, format, parseISO } from 'date-fns'
import { AlertTriangle, CheckCircle2, ChevronLeft, ChevronRight, Pencil, Plus, Settings2, Trash2, Wallet } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { PageHeader } from '@/components/PageHeader'
import { Pagination } from '@/components/Pagination'
import { useAuth } from '@/features/auth/AuthContext'
import { TransactionFormDialog, type TransactionDraft } from '@/features/transactions/TransactionFormDialog'
import { useClientPagination } from '@/hooks/use-client-pagination'
import { api, errorMessage } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import { useRecurringExpenses } from '@/lib/queries'
import type { RecurringExpense, RecurringStatus, RecurringStatusValue } from '@/types'
import { RecurringExpenseFormDialog } from './RecurringExpenseFormDialog'

const STATUS_BADGE: Record<RecurringStatusValue, { label: string; variant: 'outline' | 'secondary' | 'default' }> = {
  belum: { label: 'Belum dibayar', variant: 'outline' },
  sebagian: { label: 'Sebagian', variant: 'secondary' },
  lunas: { label: 'Lunas', variant: 'default' },
}

export default function RecurringExpensesPage() {
  const { canWrite } = useAuth()
  const [period, setPeriod] = useState(() => format(new Date(), 'yyyy-MM'))
  const [draft, setDraft] = useState<TransactionDraft | null>(null)

  const status = useQuery({
    queryKey: ['recurring-status', period],
    queryFn: async () => (await api.get<RecurringStatus>('/recurring-expenses/status', { params: { period } })).data,
  })

  const shift = (months: number) => setPeriod(format(addMonths(parseISO(`${period}-01`), months), 'yyyy-MM'))
  const data = status.data
  // Total di footer & kartu tetap dari data.totals (semua item), bukan halaman aktif.
  const pager = useClientPagination(data?.items ?? [])

  const pay = (item: RecurringStatus['items'][number]) =>
    setDraft({
      type: 'expense',
      category_id: String(item.category_id),
      amount: item.remaining || item.budget,
      description: item.name,
      counterparty: item.counterparty ?? '',
      recurring_expense_id: String(item.id),
      recurring_period: period,
    })

  return (
    <>
      <PageHeader
        title="Biaya Rutin"
        description="Anggaran biaya bulanan vs pembayaran yang sudah dilakukan. Pembayaran tetap dicatat sebagai transaksi biasa."
      />

      <Tabs defaultValue="status">
        <TabsList>
          <TabsTrigger value="status">Status Bulanan</TabsTrigger>
          <TabsTrigger value="items">
            <Settings2 /> Daftar Anggaran
          </TabsTrigger>
        </TabsList>

        <TabsContent value="status" className="grid gap-4">
          <div className="flex items-center gap-2">
            <Button variant="outline" size="icon-sm" aria-label="Bulan sebelumnya" onClick={() => shift(-1)}>
              <ChevronLeft />
            </Button>
            <Input type="month" value={period} onChange={(e) => e.target.value && setPeriod(e.target.value)} className="w-44" />
            <Button variant="outline" size="icon-sm" aria-label="Bulan berikutnya" onClick={() => shift(1)}>
              <ChevronRight />
            </Button>
          </div>

          {data && (
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard label="Total Anggaran" value={data.totals.budget} />
              <StatCard label="Sudah Dibayar" value={data.totals.paid} />
              <StatCard label="Sisa Kewajiban" value={data.totals.remaining} />
              <Card className={data.sufficiency.shortfall > 0 ? 'border-destructive/50' : ''}>
                <CardHeader className="pb-1">
                  <CardDescription className="flex items-center gap-1.5">
                    <Wallet className="size-3.5" /> Saldo Rekening Saat Ini
                  </CardDescription>
                  <CardTitle className="text-xl tabular-nums">{formatRupiah(data.sufficiency.bank_balance)}</CardTitle>
                </CardHeader>
                <CardContent className="text-xs">
                  {data.sufficiency.shortfall > 0 ? (
                    <span className="flex items-center gap-1 text-destructive">
                      <AlertTriangle className="size-3.5" /> Kurang {formatRupiah(data.sufficiency.shortfall)} untuk sisa biaya rutin
                    </span>
                  ) : (
                    <span className="flex items-center gap-1 text-muted-foreground">
                      <CheckCircle2 className="size-3.5" /> Cukup untuk sisa biaya rutin bulan ini
                    </span>
                  )}
                </CardContent>
              </Card>
            </div>
          )}

          <Card className="py-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-4">Biaya</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Anggaran</TableHead>
                  <TableHead className="text-right">Dibayar</TableHead>
                  <TableHead className="text-right">Sisa</TableHead>
                  <TableHead>Pembayaran</TableHead>
                  {canWrite && <TableHead className="pr-4" />}
                </TableRow>
              </TableHeader>
              <TableBody>
                {status.isLoading && (
                  <TableRow>
                    <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                      Memuat...
                    </TableCell>
                  </TableRow>
                )}
                {data?.items.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                      Belum ada anggaran biaya rutin. Tambahkan di tab Daftar Anggaran.
                    </TableCell>
                  </TableRow>
                )}
                {pager.rows.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell className="pl-4">
                      <div className="font-medium">{item.name}</div>
                      <div className="text-xs text-muted-foreground">
                        {[item.category?.name, item.counterparty, item.bank_info].filter(Boolean).join(' · ')}
                      </div>
                    </TableCell>
                    <TableCell>
                      <Badge variant={STATUS_BADGE[item.status].variant}>{STATUS_BADGE[item.status].label}</Badge>
                    </TableCell>
                    <TableCell className="text-right tabular-nums">{formatRupiah(item.budget)}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatRupiah(item.paid)}</TableCell>
                    <TableCell className="text-right tabular-nums">{item.remaining ? formatRupiah(item.remaining) : '-'}</TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                      {item.payments.map((p) => (
                        <div key={p.id}>
                          {formatDate(p.date, 'd MMM')} · {formatRupiah(p.amount)}
                        </div>
                      ))}
                    </TableCell>
                    {canWrite && (
                      <TableCell className="pr-4 text-right">
                        {item.status !== 'lunas' && (
                          <Button size="sm" variant="outline" onClick={() => pay(item)}>
                            Catat Pembayaran
                          </Button>
                        )}
                      </TableCell>
                    )}
                  </TableRow>
                ))}
              </TableBody>
              {data && data.items.length > 0 && (
                <TableFooter>
                  <TableRow>
                    <TableCell colSpan={2} className="pl-4">
                      Total
                    </TableCell>
                    <TableCell className="text-right tabular-nums">{formatRupiah(data.totals.budget)}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatRupiah(data.totals.paid)}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatRupiah(data.totals.remaining)}</TableCell>
                    <TableCell colSpan={canWrite ? 2 : 1} />
                  </TableRow>
                </TableFooter>
              )}
            </Table>
            {pager.total > 0 && (
              <Pagination
                className="border-t p-3"
                page={pager.page}
                pageSize={pager.pageSize}
                total={pager.total}
                onPageChange={pager.setPage}
                onPageSizeChange={pager.setPageSize}
                itemLabel="biaya"
              />
            )}
          </Card>
        </TabsContent>

        <TabsContent value="items">
          <RecurringItems canWrite={canWrite} />
        </TabsContent>
      </Tabs>

      <TransactionFormDialog open={!!draft} onOpenChange={(o) => !o && setDraft(null)} draft={draft} />
    </>
  )
}

function StatCard({ label, value }: { label: string; value: number }) {
  return (
    <Card>
      <CardHeader className="pb-1">
        <CardDescription>{label}</CardDescription>
        <CardTitle className="text-xl tabular-nums">{formatRupiah(value)}</CardTitle>
      </CardHeader>
    </Card>
  )
}

function RecurringItems({ canWrite }: { canWrite: boolean }) {
  const queryClient = useQueryClient()
  const { data: items = [], isLoading } = useRecurringExpenses()
  const pager = useClientPagination(items)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<RecurringExpense | null>(null)
  const [deleting, setDeleting] = useState<RecurringExpense | null>(null)

  const remove = useMutation({
    mutationFn: (r: RecurringExpense) => api.delete(`/recurring-expenses/${r.id}`),
    onSuccess: () => {
      toast.success('Biaya rutin dihapus')
      queryClient.invalidateQueries({ queryKey: ['recurring-expenses'] })
      queryClient.invalidateQueries({ queryKey: ['recurring-status'] })
    },
    onError: (err) => toast.error(errorMessage(err)),
    onSettled: () => setDeleting(null),
  })

  return (
    <div className="grid gap-4">
      {canWrite && (
        <div>
          <Button
            onClick={() => {
              setEditing(null)
              setFormOpen(true)
            }}
          >
            <Plus /> Tambah Biaya Rutin
          </Button>
        </div>
      )}
      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Nama</TableHead>
              <TableHead>Kategori</TableHead>
              <TableHead>Penerima / Rekening</TableHead>
              <TableHead className="text-right">Anggaran / Bulan</TableHead>
              <TableHead>Status</TableHead>
              {canWrite && <TableHead className="w-20 pr-4" />}
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && (
              <TableRow>
                <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                  Memuat...
                </TableCell>
              </TableRow>
            )}
            {pager.rows.map((r) => (
              <TableRow key={r.id}>
                <TableCell className="pl-4 font-medium">{r.name}</TableCell>
                <TableCell>{r.category?.name}</TableCell>
                <TableCell className="text-sm">
                  <div>{r.counterparty}</div>
                  <div className="text-xs text-muted-foreground">{r.bank_info}</div>
                </TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(r.amount)}</TableCell>
                <TableCell>
                  <Badge variant={r.is_active ? 'secondary' : 'outline'}>{r.is_active ? 'Aktif' : 'Nonaktif'}</Badge>
                </TableCell>
                {canWrite && (
                  <TableCell className="pr-4">
                    <div className="flex justify-end gap-1">
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label="Ubah"
                        onClick={() => {
                          setEditing(r)
                          setFormOpen(true)
                        }}
                      >
                        <Pencil />
                      </Button>
                      <Button size="icon-sm" variant="ghost" aria-label="Hapus" onClick={() => setDeleting(r)}>
                        <Trash2 className="text-destructive" />
                      </Button>
                    </div>
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
          {items.length > 0 && (
            <TableFooter>
              <TableRow>
                <TableCell colSpan={3} className="pl-4">
                  Total anggaran aktif / bulan
                </TableCell>
                <TableCell className="text-right tabular-nums">
                  {formatRupiah(items.filter((i) => i.is_active).reduce((sum, i) => sum + i.amount, 0))}
                </TableCell>
                <TableCell colSpan={canWrite ? 2 : 1} />
              </TableRow>
            </TableFooter>
          )}
        </Table>
        {pager.total > 0 && (
          <Pagination
            className="border-t p-3"
            page={pager.page}
            pageSize={pager.pageSize}
            total={pager.total}
            onPageChange={pager.setPage}
            onPageSizeChange={pager.setPageSize}
            itemLabel="biaya rutin"
          />
        )}
      </Card>

      <RecurringExpenseFormDialog open={formOpen} onOpenChange={setFormOpen} item={editing} />
      <ConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Hapus biaya rutin?"
        description={`"${deleting?.name ?? ''}" akan dihapus. Item yang sudah punya riwayat pembayaran tidak bisa dihapus, nonaktifkan saja.`}
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting)}
      />
    </div>
  )
}
