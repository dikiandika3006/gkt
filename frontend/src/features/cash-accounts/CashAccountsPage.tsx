import { Fragment, useEffect, useState } from 'react'
import { Controller, useFieldArray, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ChevronDown, ChevronRight, Loader2, Pencil, Plus, Trash2, X } from 'lucide-react'
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

const pocketSchema = z.object({
  id: z.number().optional(),
  name: z.string().trim().min(1, 'Nama pocket wajib diisi').max(100),
  opening_balance: z.coerce.number<string>().min(0, 'Tidak boleh negatif'),
  is_active: z.boolean(),
})

const schema = z
  .object({
    code: z.string().trim().min(1, 'Kode wajib diisi').max(20),
    name: z.string().trim().min(1, 'Nama wajib diisi').max(255),
    type: z.enum(['cash', 'bank']),
    bank_name: z.string().optional(),
    account_number: z.string().optional(),
    opening_balance: z.coerce.number<string>().min(0, 'Tidak boleh negatif'),
    is_active: z.boolean(),
    has_pockets: z.boolean(),
    pockets: z.array(pocketSchema),
  })
  .refine((v) => !v.has_pockets || v.pockets.length > 0, { path: ['pockets'], message: 'Tambahkan minimal satu pocket' })
type FormValues = z.input<typeof schema>
type FormOutput = z.output<typeof schema>

export default function CashAccountsPage() {
  const canWrite = useAuth().can('cash_accounts', 'manage')
  const queryClient = useQueryClient()
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<CashAccount | null>(null)
  const [deleting, setDeleting] = useState<CashAccount | null>(null)
  const [expanded, setExpanded] = useState<Set<number>>(new Set())
  const { data: accounts = [], isLoading } = useCashAccounts()
  const pager = useClientPagination(accounts)
  const colSpan = canWrite ? 7 : 6

  const toggle = (id: number) =>
    setExpanded((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

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
              <TableHead className="text-right">Saldo Saat Ini</TableHead>
              <TableHead>Status</TableHead>
              {canWrite && <TableHead className="w-20 pr-4" />}
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && (
              <TableRow>
                <TableCell colSpan={colSpan} className="py-8 text-center text-muted-foreground">
                  Memuat...
                </TableCell>
              </TableRow>
            )}
            {pager.rows.map((a) => {
              const open = expanded.has(a.id)
              return (
                <Fragment key={a.id}>
                  <TableRow>
                    <TableCell className="pl-4 font-mono text-xs">{a.code}</TableCell>
                    <TableCell className="font-medium">
                      {a.has_pockets ? (
                        <button type="button" className="inline-flex items-center gap-1 text-left hover:underline" onClick={() => toggle(a.id)} aria-expanded={open}>
                          {open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                          {a.name}
                          <Badge variant="outline" className="ml-1">
                            {a.pockets?.length ?? 0} pocket
                          </Badge>
                        </button>
                      ) : (
                        a.name
                      )}
                    </TableCell>
                    <TableCell>{a.type_label}</TableCell>
                    <TableCell>{a.type === 'bank' ? `${a.bank_name ?? '-'} · ${a.account_number ?? '-'}` : '-'}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatRupiah(a.current_balance ?? a.opening_balance)}</TableCell>
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
                  {open &&
                    a.pockets?.map((p) => (
                      <TableRow key={`p-${p.id}`} className="bg-muted/30">
                        <TableCell />
                        <TableCell colSpan={3} className="pl-8 text-sm">
                          {p.name}
                        </TableCell>
                        <TableCell className={`text-right text-sm tabular-nums ${(p.current_balance ?? 0) < 0 ? 'text-destructive' : ''}`}>
                          {formatRupiah(p.current_balance ?? p.opening_balance)}
                        </TableCell>
                        <TableCell>
                          {!p.is_active && <Badge variant="outline">Nonaktif</Badge>}
                        </TableCell>
                        {canWrite && <TableCell />}
                      </TableRow>
                    ))}
                </Fragment>
              )
            })}
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
  const pockets = useFieldArray({ control, name: 'pockets' })
  const errors = formState.errors
  const type = watch('type')
  const hasPockets = watch('has_pockets')
  const pocketValues = watch('pockets')
  const pocketTotal = (pocketValues ?? []).reduce((sum, p) => sum + (Number(p.opening_balance) || 0), 0)

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
            has_pockets: account.has_pockets,
            pockets: (account.pockets ?? []).map((p) => ({ id: p.id, name: p.name, opening_balance: String(p.opening_balance), is_active: p.is_active })),
          }
        : { code: '', name: '', type: 'cash', bank_name: '', account_number: '', opening_balance: '0', is_active: true, has_pockets: false, pockets: [] },
    )
  }, [open, account, reset])

  const mutation = useMutation({
    mutationFn: (values: FormOutput) => {
      const payload = values.has_pockets ? values : { ...values, pockets: undefined }
      return account ? api.put(`/cash-accounts/${account.id}`, payload) : api.post('/cash-accounts', payload)
    },
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

  const pocketsError = errors.pockets?.message ?? errors.pockets?.root?.message
  const addPocket = () => pockets.append({ name: '', opening_balance: '0', is_active: true })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
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
            {hasPockets ? (
              <Input id="opening_balance" value={formatRupiah(pocketTotal)} readOnly disabled title="Jumlah saldo awal semua pocket" />
            ) : (
              <Input id="opening_balance" type="number" min={0} step="any" {...register('opening_balance')} aria-invalid={!!errors.opening_balance} />
            )}
            {hasPockets && <p className="text-xs text-muted-foreground">Jumlah saldo awal semua pocket</p>}
          </FormField>

          <div className="flex flex-wrap gap-x-8 gap-y-3 sm:col-span-2">
            <label className="flex cursor-pointer items-start gap-2">
              <input type="checkbox" className="mt-0.5 size-4 accent-primary" {...register('is_active')} />
              <span className="grid">
                <span className="text-sm font-medium whitespace-nowrap">Aktif</span>
                <span className="text-xs text-muted-foreground">Bisa dipilih di transaksi</span>
              </span>
            </label>
            <label className="flex cursor-pointer items-start gap-2">
              <input type="checkbox" className="mt-0.5 size-4 accent-primary" {...register('has_pockets')} />
              <span className="grid">
                <span className="text-sm font-medium whitespace-nowrap">Pakai Pocket</span>
                <span className="text-xs text-muted-foreground">Saldo dibagi ke beberapa pocket</span>
              </span>
            </label>
          </div>
          {errors.has_pockets?.message && <p className="text-sm text-destructive sm:col-span-2">{errors.has_pockets.message}</p>}

          {hasPockets && (
            <div className="grid gap-3 rounded-lg border p-3 sm:col-span-2">
              <div className="flex items-start justify-between gap-3">
                <div className="grid gap-0.5">
                  <p className="text-sm font-medium">Daftar Pocket</p>
                  <p className="text-xs text-muted-foreground">
                    Setiap transaksi di rekening ini wajib memilih pocket. Pocket yang sudah dipakai transaksi tidak bisa dihapus, cukup dinonaktifkan.
                  </p>
                </div>
                <Button type="button" size="sm" variant="outline" className="shrink-0" onClick={addPocket}>
                  <Plus /> Pocket
                </Button>
              </div>

              {pockets.fields.length === 0 ? (
                <button
                  type="button"
                  onClick={addPocket}
                  className="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground transition hover:bg-muted"
                >
                  Belum ada pocket. Klik <b>+ Pocket</b> untuk menambah.
                </button>
              ) : (
                <div className="grid gap-2">
                  <div className="hidden grid-cols-[1fr_10rem_4rem_2rem] gap-2 text-xs font-medium text-muted-foreground sm:grid">
                    <span>Nama pocket</span>
                    <span>Saldo awal (Rp)</span>
                    <span className="text-center">Aktif</span>
                    <span />
                  </div>
                  {pockets.fields.map((f, i) => (
                    <div key={f.id} className="grid grid-cols-[1fr_auto_auto] items-start gap-2 sm:grid-cols-[1fr_10rem_4rem_2rem]">
                      <div className="col-span-3 sm:col-span-1">
                        <Input placeholder="Nama pocket" {...register(`pockets.${i}.name`)} aria-invalid={!!errors.pockets?.[i]?.name} />
                        {errors.pockets?.[i]?.name?.message && <p className="mt-1 text-xs text-destructive">{errors.pockets[i]?.name?.message}</p>}
                      </div>
                      <div>
                        <Input
                          type="number"
                          min={0}
                          step="any"
                          placeholder="Saldo awal"
                          {...register(`pockets.${i}.opening_balance`)}
                          aria-invalid={!!errors.pockets?.[i]?.opening_balance}
                        />
                        {errors.pockets?.[i]?.opening_balance?.message && (
                          <p className="mt-1 text-xs text-destructive">{errors.pockets[i]?.opening_balance?.message}</p>
                        )}
                      </div>
                      <label className="flex h-9 items-center justify-center gap-1.5 text-xs">
                        <input type="checkbox" className="size-4 accent-primary" {...register(`pockets.${i}.is_active`)} />
                        <span className="sm:sr-only">Aktif</span>
                      </label>
                      <Button type="button" size="icon-sm" variant="ghost" aria-label="Hapus pocket" className="mt-1" onClick={() => pockets.remove(i)}>
                        <X />
                      </Button>
                    </div>
                  ))}
                  <div className="flex justify-between border-t pt-2 text-sm">
                    <span className="text-muted-foreground">Total saldo awal</span>
                    <span className="font-medium tabular-nums">{formatRupiah(pocketTotal)}</span>
                  </div>
                </div>
              )}
              {pocketsError && <p className="text-sm text-destructive">{pocketsError}</p>}
            </div>
          )}
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
