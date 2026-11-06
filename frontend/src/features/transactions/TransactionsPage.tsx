import { useMemo, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Link } from 'react-router'
import { ArrowLeftRight, ClipboardList, Download, Loader2, Paperclip, Pencil, Plus, Trash2, Upload } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { PageHeader } from '@/components/PageHeader'
import { Pagination } from '@/components/Pagination'
import { SearchableSelect } from '@/components/SearchableSelect'
import { SelectField } from '@/components/SelectField'
import { useAuth } from '@/features/auth/AuthContext'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import { useAccessibleCashAccounts, useCategories } from '@/lib/queries'
import type { Paginated, Transaction } from '@/types'
import { TransactionFormDialog } from './TransactionFormDialog'
import { TransferFormDialog } from './TransferFormDialog'

const ALL = '__all'

export default function TransactionsPage() {
  const { user, can } = useAuth()
  const canWrite = can('transactions', 'manage')
  const queryClient = useQueryClient()
  const [filters, setFilters] = useState({ start_date: '', end_date: '', type: '', category_id: '', search: '' })
  // Kas wajib dipilih; awalnya kas default user (Setting + akses kas), lalu kas pertama yang boleh.
  const [pickedAccount, setPickedAccount] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Transaction | null>(null)
  const [deleting, setDeleting] = useState<Transaction | null>(null)
  const [transferOpen, setTransferOpen] = useState(false)
  const [editingTransfer, setEditingTransfer] = useState<Transaction | null>(null)

  const { data: categories = [] } = useCategories()
  const { data: cashAccounts = [], isLoading: accountsLoading } = useAccessibleCashAccounts()
  const fallbackAccount = cashAccounts.find((c) => c.id === user?.default_cash_account_id) ?? cashAccounts.find((c) => c.is_active) ?? cashAccounts[0]
  const cashAccountId = pickedAccount || (fallbackAccount ? String(fallbackAccount.id) : '')
  // Transaksi baru otomatis memakai kas yang sedang dipilih. Di-memo agar form tidak ter-reset saat render ulang.
  const newDraft = useMemo(() => (cashAccountId ? { cash_account_id: cashAccountId } : null), [cashAccountId])
  const params = { ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')), cash_account_id: cashAccountId }

  const { data, isLoading } = useQuery({
    queryKey: ['transactions', params, page, perPage],
    queryFn: async () => (await api.get<Paginated<Transaction>>('/transactions', { params: { ...params, page, per_page: perPage } })).data,
    placeholderData: keepPreviousData,
    enabled: cashAccountId !== '',
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
      await downloadFile('/transactions/export', 'transaksi.xlsx', params)
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
        title="Mutasi"
        description="Pencatatan pemasukan dan pengeluaran"
        actions={
          <>
            <Button variant="outline" disabled={exporting || !cashAccountId} onClick={onExport}>
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

      <div className="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-3">
        <Label htmlFor="mutation-account" className="shrink-0">
          Kas / Rekening <span className="text-destructive">*</span>
        </Label>
        <SelectField
          id="mutation-account"
          value={cashAccountId}
          onChange={(v) => {
            if (!v) return // wajib dipilih, tidak bisa dikosongkan
            setPickedAccount(v)
            setPage(1)
          }}
          placeholder={accountsLoading ? 'Memuat kas...' : 'Pilih kas / rekening'}
          className="sm:w-80"
          options={cashAccounts.map((c) => ({ value: String(c.id), label: c.is_active ? c.name : `${c.name} (nonaktif)` }))}
        />
      </div>

      <Card>
        <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_2fr]">
          <Input placeholder="Cari keterangan / nama / nomor..." value={filters.search} onChange={(e) => setFilter('search', e.target.value)} className="sm:col-span-2 lg:col-span-1" />
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
          <SearchableSelect
            value={filters.category_id}
            onChange={(v) => setFilter('category_id', v)}
            placeholder="Semua kategori (ketik untuk cari)"
            className="sm:col-span-2 lg:col-span-1"
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
            {!accountsLoading && cashAccounts.length === 0 && (
              <TableRow>
                <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                  Belum ada kas yang bisa Anda akses. Hubungi admin (menu Hak Akses).
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

      <TransactionFormDialog open={formOpen} onOpenChange={setFormOpen} transaction={editing} draft={newDraft} />
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
