import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Link } from 'react-router'
import { ArrowLeftRight, ClipboardList, Download, Loader2, Paperclip, Pencil, Plus, Trash2, Upload } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { PageHeader } from '@/components/PageHeader'
import { Pagination } from '@/components/Pagination'
import { SearchableSelect } from '@/components/SearchableSelect'
import { SelectField } from '@/components/SelectField'
import { useAuth } from '@/features/auth/AuthContext'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import { useCashAccounts, useCategories } from '@/lib/queries'
import type { Paginated, Transaction } from '@/types'
import { TransactionFormDialog } from './TransactionFormDialog'
import { TransferFormDialog } from './TransferFormDialog'

const ALL = '__all'

export default function TransactionsPage() {
  const { canWrite } = useAuth()
  const queryClient = useQueryClient()
  const [filters, setFilters] = useState({ start_date: '', end_date: '', type: '', category_id: '', cash_account_id: '', search: '' })
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Transaction | null>(null)
  const [deleting, setDeleting] = useState<Transaction | null>(null)
  const [transferOpen, setTransferOpen] = useState(false)
  const [editingTransfer, setEditingTransfer] = useState<Transaction | null>(null)

  const { data: categories = [] } = useCategories()
  const { data: cashAccounts = [] } = useCashAccounts()

  const { data, isLoading } = useQuery({
    queryKey: ['transactions', filters, page, perPage],
    queryFn: async () =>
      (
        await api.get<Paginated<Transaction>>('/transactions', {
          params: { ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')), page, per_page: perPage },
        })
      ).data,
    placeholderData: keepPreviousData,
  })

  const remove = useMutation({
    // Mutasi antar kas dihapus berpasangan lewat endpoint transfer.
    mutationFn: (t: Transaction) => (t.transfer_group ? api.delete(`/transfers/${t.transfer_group}`) : api.delete(`/transactions/${t.id}`)),
    onSuccess: () => {
      toast.success('Transaksi dihapus')
      queryClient.invalidateQueries({ queryKey: ['transactions'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['fund-requests'] })
      setDeleting(null)
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const [exporting, setExporting] = useState(false)
  const onExport = async () => {
    setExporting(true)
    try {
      await downloadFile('/transactions/export', 'transaksi.xlsx', Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')))
    } catch (err) {
      toast.error(errorMessage(err))
    } finally {
      setExporting(false)
    }
  }

  const setFilter = (key: keyof typeof filters, value: string) => {
    setFilters((f) => ({ ...f, [key]: value === ALL ? '' : value }))
    setPage(1)
  }

  return (
    <>
      <PageHeader
        title="Transaksi Kas"
        description="Pencatatan pemasukan dan pengeluaran"
        actions={
          <>
            <Button variant="outline" disabled={exporting} onClick={onExport}>
              {exporting ? <Loader2 className="animate-spin" /> : <Download />} Export Excel
            </Button>
            {canWrite && (
            <>
              <Button variant="outline" render={<Link to="/transactions/import" />}>
                <Upload /> Import CSV
              </Button>
              <Button
                variant="outline"
                onClick={() => {
                  setEditingTransfer(null)
                  setTransferOpen(true)
                }}
              >
                <ArrowLeftRight /> Mutasi Antar Kas
              </Button>
              <Button
                onClick={() => {
                  setEditing(null)
                  setFormOpen(true)
                }}
              >
                <Plus /> Tambah Transaksi
              </Button>
            </>
            )}
          </>
        }
      />

      <Card>
        <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
          <Input placeholder="Cari keterangan / nama / nomor..." value={filters.search} onChange={(e) => setFilter('search', e.target.value)} className="lg:col-span-2" />
          <Input type="date" value={filters.start_date} onChange={(e) => setFilter('start_date', e.target.value)} aria-label="Dari tanggal" />
          <Input type="date" value={filters.end_date} onChange={(e) => setFilter('end_date', e.target.value)} aria-label="Sampai tanggal" />
          <SelectField
            value={filters.type}
            onChange={(v) => setFilter('type', v)}
            placeholder="Semua jenis"
            options={[
              { value: ALL, label: 'Semua jenis' },
              { value: 'income', label: 'Pemasukan' },
              { value: 'expense', label: 'Pengeluaran' },
            ]}
          />
          <SelectField
            value={filters.cash_account_id}
            onChange={(v) => setFilter('cash_account_id', v)}
            placeholder="Semua kas"
            options={[{ value: ALL, label: 'Semua kas' }, ...cashAccounts.map((c) => ({ value: String(c.id), label: c.name }))]}
          />
          <SearchableSelect
            value={filters.category_id}
            onChange={(v) => setFilter('category_id', v)}
            placeholder="Semua kategori (ketik untuk cari)"
            className="lg:col-span-2"
            clearable
            emptyText="Kategori tidak ditemukan"
            options={categories.map((c) => ({ value: String(c.id), label: `${c.code} · ${c.name}` }))}
          />
        </CardContent>
      </Card>

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Tanggal</TableHead>
              <TableHead>Nomor</TableHead>
              <TableHead>Keterangan</TableHead>
              <TableHead>Kategori</TableHead>
              <TableHead>Kas</TableHead>
              <TableHead className="text-right">Jumlah</TableHead>
              {canWrite && <TableHead className="w-20 pr-4" />}
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
                  Tidak ada transaksi.
                </TableCell>
              </TableRow>
            )}
            {data?.data.map((t) => (
              <TableRow key={t.id}>
                <TableCell className="pl-4 whitespace-nowrap">{formatDate(t.date)}</TableCell>
                <TableCell className="font-mono text-xs">{t.number}</TableCell>
                <TableCell className="max-w-72">
                  <div className="flex items-center gap-1.5">
                    <span className="truncate">{t.description}</span>
                    {!!t.attachments_count && <Paperclip className="size-3 shrink-0 text-muted-foreground" aria-label="Ada lampiran" />}
                  </div>
                  {t.counterparty && <div className="truncate text-xs text-muted-foreground">{t.counterparty}</div>}
                  {t.transfer_group && <Badge variant="outline" className="mt-0.5">Mutasi antar kas</Badge>}
                  {t.fund_report_item_id && <Badge variant="outline" className="mt-0.5">LPJ</Badge>}
                </TableCell>
                <TableCell>{t.category?.name}</TableCell>
                <TableCell>{t.cash_account?.name}</TableCell>
                <TableCell className="text-right tabular-nums">
                  <Badge variant={t.type === 'income' ? 'secondary' : 'outline'}>
                    {t.type === 'income' ? '+' : '−'} {formatRupiah(t.amount)}
                  </Badge>
                </TableCell>
                {canWrite && (
                  <TableCell className="pr-4">
                    {t.fund_report_item_id ? (
                      <div className="flex justify-end">
                        <Button size="icon-sm" variant="ghost" aria-label="Buka pengajuan" render={<Link to={`/fund-requests/${t.fund_request_id}`} />}>
                          <ClipboardList />
                        </Button>
                      </div>
                    ) : (
                      <div className="flex justify-end gap-1">
                        <Button
                          size="icon-sm"
                          variant="ghost"
                          aria-label="Ubah"
                          onClick={() => {
                            if (t.transfer_group) {
                              setEditingTransfer(t)
                              setTransferOpen(true)
                            } else {
                              setEditing(t)
                              setFormOpen(true)
                            }
                          }}
                        >
                          <Pencil />
                        </Button>
                        <Button size="icon-sm" variant="ghost" aria-label="Hapus" onClick={() => setDeleting(t)}>
                          <Trash2 className="text-destructive" />
                        </Button>
                      </div>
                    )}
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
        {data && data.meta.total > 0 && (
          <Pagination
            className="border-t p-3"
            page={data.meta.current_page}
            pageSize={data.meta.per_page}
            total={data.meta.total}
            onPageChange={setPage}
            onPageSizeChange={(size) => {
              setPerPage(size)
              setPage(1)
            }}
            itemLabel="transaksi"
          />
        )}
      </Card>

      <TransactionFormDialog open={formOpen} onOpenChange={setFormOpen} transaction={editing} />
      <TransferFormDialog open={transferOpen} onOpenChange={setTransferOpen} leg={editingTransfer} />
      <ConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Hapus transaksi?"
        description={
          deleting?.transfer_group
            ? 'Kedua sisi mutasi antar kas (kas asal & tujuan) akan dihapus.'
            : `Transaksi ${deleting?.number ?? ''} akan dihapus dari laporan.`
        }
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting)}
      />
    </>
  )
}
