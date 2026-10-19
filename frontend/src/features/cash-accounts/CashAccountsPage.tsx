import { useEffect, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Pencil, Plus, Trash2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { FormField } from '@/components/FormField'
import { PageHeader } from '@/components/PageHeader'
import { Pagination } from '@/components/Pagination'
import { SelectField } from '@/components/SelectField'
import { useAuth } from '@/features/auth/AuthContext'
import { useClientPagination } from '@/hooks/use-client-pagination'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { formatRupiah } from '@/lib/format'
import { useCashAccounts } from '@/lib/queries'
import type { CashAccount } from '@/types'

const schema = z.object({
  code: z.string().trim().min(1, 'Kode wajib diisi').max(20),
  name: z.string().trim().min(1, 'Nama wajib diisi').max(255),
  type: z.enum(['cash', 'bank']),
  bank_name: z.string().optional(),
  account_number: z.string().optional(),
  opening_balance: z.coerce.number<string>().min(0, 'Tidak boleh negatif'),
  is_active: z.boolean(),
})
type FormValues = z.input<typeof schema>
type FormOutput = z.output<typeof schema>

export default function CashAccountsPage() {
  const { canWrite } = useAuth()
  const queryClient = useQueryClient()
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<CashAccount | null>(null)
  const [deleting, setDeleting] = useState<CashAccount | null>(null)
  const { data: accounts = [], isLoading } = useCashAccounts()
  const pager = useClientPagination(accounts)

  const remove = useMutation({
    mutationFn: (a: CashAccount) => api.delete(`/cash-accounts/${a.id}`),
    onSuccess: () => {
      toast.success('Kas/rekening dihapus')
      queryClient.invalidateQueries({ queryKey: ['cash-accounts'] })
      setDeleting(null)
    },
    onError: (err) => {
      toast.error(errorMessage(err))
      setDeleting(null)
    },
  })

  return (
    <>
      <PageHeader
        title="Kas & Bank"
        description="Daftar kas tunai dan rekening bank gereja"
        actions={
          canWrite && (
            <Button
              onClick={() => {
                setEditing(null)
                setFormOpen(true)
              }}
            >
              <Plus /> Tambah Kas/Rekening
            </Button>
          )
        }
      />

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Kode</TableHead>
              <TableHead>Nama</TableHead>
              <TableHead>Jenis</TableHead>
              <TableHead>Bank / No. Rekening</TableHead>
              <TableHead className="text-right">Saldo Awal</TableHead>
              <TableHead>Status</TableHead>
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
            {pager.rows.map((a) => (
              <TableRow key={a.id}>
                <TableCell className="pl-4 font-mono text-xs">{a.code}</TableCell>
                <TableCell className="font-medium">{a.name}</TableCell>
                <TableCell>{a.type_label}</TableCell>
                <TableCell>{a.type === 'bank' ? `${a.bank_name ?? '-'} · ${a.account_number ?? '-'}` : '-'}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(a.opening_balance)}</TableCell>
                <TableCell>
                  <Badge variant={a.is_active ? 'secondary' : 'outline'}>{a.is_active ? 'Aktif' : 'Nonaktif'}</Badge>
                </TableCell>
                {canWrite && (
                  <TableCell className="pr-4">
                    <div className="flex justify-end gap-1">
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label="Ubah"
                        onClick={() => {
                          setEditing(a)
                          setFormOpen(true)
                        }}
                      >
                        <Pencil />
                      </Button>
                      <Button size="icon-sm" variant="ghost" aria-label="Hapus" onClick={() => setDeleting(a)}>
                        <Trash2 className="text-destructive" />
                      </Button>
                    </div>
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
        {pager.total > 0 && (
          <Pagination
            className="border-t p-3"
            page={pager.page}
            pageSize={pager.pageSize}
            total={pager.total}
            onPageChange={pager.setPage}
            onPageSizeChange={pager.setPageSize}
            itemLabel="kas/rekening"
          />
        )}
      </Card>

      <CashAccountFormDialog open={formOpen} onOpenChange={setFormOpen} account={editing} />
      <ConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Hapus kas/rekening?"
        description={`"${deleting?.name ?? ''}" akan dihapus. Kas yang sudah dipakai transaksi tidak bisa dihapus.`}
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting)}
      />
    </>
  )
}

function CashAccountFormDialog({ open, onOpenChange, account }: { open: boolean; onOpenChange: (o: boolean) => void; account: CashAccount | null }) {
  const queryClient = useQueryClient()
  const { register, control, handleSubmit, reset, setError, watch, formState } = useForm<FormValues, unknown, FormOutput>({
    resolver: zodResolver(schema),
  })
  const errors = formState.errors
  const type = watch('type')

  useEffect(() => {
    if (!open) return
    reset(
      account
        ? {
            code: account.code,
            name: account.name,
            type: account.type,
            bank_name: account.bank_name ?? '',
            account_number: account.account_number ?? '',
            opening_balance: String(account.opening_balance),
            is_active: account.is_active,
          }
        : { code: '', name: '', type: 'cash', bank_name: '', account_number: '', opening_balance: '0', is_active: true },
    )
  }, [open, account, reset])

  const mutation = useMutation({
    mutationFn: (values: FormOutput) => (account ? api.put(`/cash-accounts/${account.id}`, values) : api.post('/cash-accounts', values)),
    onSuccess: () => {
      toast.success('Kas/rekening disimpan')
      queryClient.invalidateQueries({ queryKey: ['cash-accounts'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      onOpenChange(false)
    },
    onError: (err) => {
      const fe = fieldErrors(err)
      Object.entries(fe).forEach(([f, message]) => setError(f as keyof FormValues, { message }))
      if (Object.keys(fe).length === 0) toast.error(errorMessage(err))
    },
  })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{account ? 'Ubah Kas/Rekening' : 'Tambah Kas/Rekening'}</DialogTitle>
        </DialogHeader>
        <form id="cash-account-form" onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid gap-4 sm:grid-cols-2">
          <FormField label="Kode" htmlFor="code" error={errors.code?.message}>
            <Input id="code" placeholder="mis. KAS-01" {...register('code')} aria-invalid={!!errors.code} />
          </FormField>
          <FormField label="Jenis" error={errors.type?.message}>
            <Controller
              control={control}
              name="type"
              render={({ field }) => (
                <SelectField
                  value={field.value ?? ''}
                  onChange={field.onChange}
                  options={[
                    { value: 'cash', label: 'Kas Tunai' },
                    { value: 'bank', label: 'Rekening Bank' },
                  ]}
                />
              )}
            />
          </FormField>
          <div className="sm:col-span-2">
            <FormField label="Nama" htmlFor="name" error={errors.name?.message}>
              <Input id="name" {...register('name')} aria-invalid={!!errors.name} />
            </FormField>
          </div>
          {type === 'bank' && (
            <>
              <FormField label="Nama Bank" htmlFor="bank_name" error={errors.bank_name?.message}>
                <Input id="bank_name" {...register('bank_name')} aria-invalid={!!errors.bank_name} />
              </FormField>
              <FormField label="No. Rekening" htmlFor="account_number" error={errors.account_number?.message}>
                <Input id="account_number" {...register('account_number')} />
              </FormField>
            </>
          )}
          <FormField label="Saldo Awal (Rp)" htmlFor="opening_balance" error={errors.opening_balance?.message}>
            <Input id="opening_balance" type="number" min={0} step="any" {...register('opening_balance')} aria-invalid={!!errors.opening_balance} />
          </FormField>
          <label className="flex items-center gap-2 self-end pb-2 text-sm">
            <input type="checkbox" className="size-4 accent-primary" {...register('is_active')} /> Aktif
          </label>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Batal
          </Button>
          <Button type="submit" form="cash-account-form" disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="animate-spin" />}
            Simpan
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
