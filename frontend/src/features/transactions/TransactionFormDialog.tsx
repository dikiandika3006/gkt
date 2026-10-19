import { useEffect, useId } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { AttachmentList } from '@/components/AttachmentList'
import { FormField } from '@/components/FormField'
import { SearchableSelect } from '@/components/SearchableSelect'
import { SelectField } from '@/components/SelectField'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { toDateInput } from '@/lib/format'
import { useCashAccounts, useCategories, useCounterparties, useRecurringExpenses } from '@/lib/queries'
import type { Transaction } from '@/types'

const NONE = '__none'

const schema = z
  .object({
    date: z.string().min(1, 'Tanggal wajib diisi'),
    type: z.enum(['income', 'expense']),
    category_id: z.string().min(1, 'Kategori wajib dipilih'),
    cash_account_id: z.string().min(1, 'Kas/rekening wajib dipilih'),
    amount: z.coerce.number<string>().positive('Jumlah harus lebih dari 0'),
    description: z.string().trim().min(1, 'Keterangan wajib diisi').max(255),
    counterparty: z.string().max(255).optional(),
    reference: z.string().max(100).optional(),
    notes: z.string().max(2000).optional(),
    recurring_expense_id: z.string().optional(),
    recurring_period: z.string().optional(),
  })
  .refine((v) => !v.recurring_expense_id || !!v.recurring_period, { path: ['recurring_period'], message: 'Pilih bulan anggaran' })

type FormValues = z.input<typeof schema>
type Output = z.output<typeof schema>

/** Nilai awal opsional, mis. dari tombol "Catat Pembayaran" biaya rutin. */
export type TransactionDraft = Partial<Omit<FormValues, 'amount'>> & { amount?: number }

interface Props {
  open: boolean
  onOpenChange: (open: boolean) => void
  transaction?: Transaction | null
  draft?: TransactionDraft | null
}

export function TransactionFormDialog({ open, onOpenChange, transaction, draft }: Props) {
  const queryClient = useQueryClient()
  const listId = useId()
  const { register, control, handleSubmit, reset, watch, setValue, setError, formState } = useForm<FormValues, unknown, Output>({
    resolver: zodResolver(schema),
  })

  const type = watch('type')
  const counterparty = watch('counterparty') ?? ''
  const recurringId = watch('recurring_expense_id')
  const { data: categories = [] } = useCategories({ type, activeOnly: true, excludeTransfer: true })
  const { data: cashAccounts = [] } = useCashAccounts({ activeOnly: true })
  const { data: recurring = [] } = useRecurringExpenses()
  const { data: names = [] } = useCounterparties(counterparty.length >= 2 ? counterparty : '')

  // Lampiran hanya bisa dikelola setelah transaksi tersimpan.
  const detail = useQuery({
    queryKey: ['transactions', 'detail', transaction?.id],
    queryFn: async () => (await api.get<{ data: Transaction }>(`/transactions/${transaction!.id}`)).data.data,
    enabled: open && !!transaction,
  })

  useEffect(() => {
    if (!open) return
    reset(
      transaction
        ? {
            date: transaction.date,
            type: transaction.type,
            category_id: String(transaction.category_id),
            cash_account_id: String(transaction.cash_account_id),
            amount: String(transaction.amount),
            description: transaction.description,
            counterparty: transaction.counterparty ?? '',
            reference: transaction.reference ?? '',
            notes: transaction.notes ?? '',
            recurring_expense_id: transaction.recurring_expense_id ? String(transaction.recurring_expense_id) : '',
            recurring_period: transaction.recurring_period ?? '',
          }
        : {
            date: toDateInput(new Date()),
            type: 'income',
            category_id: '',
            cash_account_id: '',
            description: '',
            counterparty: '',
            reference: '',
            notes: '',
            recurring_expense_id: '',
            recurring_period: '',
            ...draft,
            amount: draft?.amount ? String(draft.amount) : '',
          },
    )
  }, [open, transaction, draft, reset])

  const mutation = useMutation({
    mutationFn: (values: Output) => {
      const payload = {
        ...values,
        recurring_expense_id: values.type === 'expense' && values.recurring_expense_id ? Number(values.recurring_expense_id) : null,
        recurring_period: values.type === 'expense' && values.recurring_expense_id ? values.recurring_period : null,
      }
      return transaction ? api.put(`/transactions/${transaction.id}`, payload) : api.post('/transactions', payload)
    },
    onSuccess: () => {
      toast.success(transaction ? 'Transaksi diperbarui' : 'Transaksi disimpan')
      queryClient.invalidateQueries({ queryKey: ['transactions'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['reports'] })
      queryClient.invalidateQueries({ queryKey: ['recurring-status'] })
      onOpenChange(false)
    },
    onError: (err) => {
      const errors = fieldErrors(err)
      Object.entries(errors).forEach(([field, message]) => setError(field as keyof FormValues, { message }))
      if (Object.keys(errors).length === 0) toast.error(errorMessage(err))
    },
  })

  const errors = formState.errors

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{transaction ? `Ubah Transaksi ${transaction.number}` : 'Tambah Transaksi'}</DialogTitle>
        </DialogHeader>

        <form id="transaction-form" onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid gap-4 sm:grid-cols-2">
          <FormField label="Jenis" error={errors.type?.message}>
            <Controller
              control={control}
              name="type"
              render={({ field }) => (
                <SelectField
                  value={field.value ?? ''}
                  onChange={(v) => {
                    field.onChange(v)
                    setValue('category_id', '')
                  }}
                  options={[
                    { value: 'income', label: 'Pemasukan' },
                    { value: 'expense', label: 'Pengeluaran' },
                  ]}
                />
              )}
            />
          </FormField>
          <FormField label="Tanggal" htmlFor="date" error={errors.date?.message}>
            <Input id="date" type="date" {...register('date')} aria-invalid={!!errors.date} />
          </FormField>
          <FormField label="Kategori" error={errors.category_id?.message}>
            <Controller
              control={control}
              name="category_id"
              render={({ field }) => (
                <SearchableSelect
                  value={field.value ?? ''}
                  onChange={field.onChange}
                  invalid={!!errors.category_id}
                  placeholder="Ketik kategori..."
                  emptyText="Kategori tidak ditemukan"
                  options={categories.map((c) => ({ value: String(c.id), label: `${c.code} · ${c.name}` }))}
                />
              )}
            />
          </FormField>
          <FormField label="Kas / Rekening" error={errors.cash_account_id?.message}>
            <Controller
              control={control}
              name="cash_account_id"
              render={({ field }) => (
                <SelectField
                  value={field.value ?? ''}
                  onChange={field.onChange}
                  invalid={!!errors.cash_account_id}
                  options={cashAccounts.map((c) => ({ value: String(c.id), label: c.name }))}
                />
              )}
            />
          </FormField>
          <FormField label="Jumlah (Rp)" htmlFor="amount" error={errors.amount?.message}>
            <Input id="amount" type="number" min={0} step="any" inputMode="decimal" {...register('amount')} aria-invalid={!!errors.amount} />
          </FormField>
          <FormField label="Nama (jemaat / penerima)" htmlFor="counterparty" error={errors.counterparty?.message}>
            <Input id="counterparty" list={listId} autoComplete="off" placeholder="Opsional" {...register('counterparty')} />
            <datalist id={listId}>
              {names.map((n) => (
                <option key={n} value={n} />
              ))}
            </datalist>
          </FormField>
          <div className="sm:col-span-2">
            <FormField label="Keterangan" htmlFor="description" error={errors.description?.message}>
              <Input id="description" {...register('description')} aria-invalid={!!errors.description} />
            </FormField>
          </div>
          <FormField label="No. Referensi" htmlFor="reference" error={errors.reference?.message}>
            <Input id="reference" placeholder="Opsional, mis. no. kwitansi" {...register('reference')} />
          </FormField>
          <FormField label="Catatan" htmlFor="notes" error={errors.notes?.message}>
            <Input id="notes" placeholder="Opsional" {...register('notes')} />
          </FormField>

          {type === 'expense' && (
            <>
              <FormField label="Pembayaran biaya rutin" error={errors.recurring_expense_id?.message}>
                <Controller
                  control={control}
                  name="recurring_expense_id"
                  render={({ field }) => (
                    <SelectField
                      value={field.value ?? ''}
                      onChange={(v) => {
                        const id = v === NONE ? '' : v
                        field.onChange(id)
                        if (id && !watch('recurring_period')) setValue('recurring_period', watch('date')?.slice(0, 7) ?? '')
                      }}
                      placeholder="Bukan biaya rutin"
                      options={[
                        { value: NONE, label: 'Bukan biaya rutin' },
                        ...recurring.filter((r) => r.is_active || String(r.id) === field.value).map((r) => ({ value: String(r.id), label: r.name })),
                      ]}
                    />
                  )}
                />
              </FormField>
              <FormField label="Untuk anggaran bulan" htmlFor="recurring_period" error={errors.recurring_period?.message}>
                <Input id="recurring_period" type="month" disabled={!recurringId} {...register('recurring_period')} />
              </FormField>
            </>
          )}

          {transaction && (
            <div className="grid gap-1.5 sm:col-span-2">
              <span className="text-sm font-medium">Lampiran</span>
              <AttachmentList
                attachableType="transaction"
                attachableId={transaction.id}
                attachments={detail.data?.attachments ?? []}
                canEdit
                onChanged={() => {
                  detail.refetch()
                  queryClient.invalidateQueries({ queryKey: ['transactions'] })
                }}
              />
            </div>
          )}
        </form>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Batal
          </Button>
          <Button type="submit" form="transaction-form" disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="animate-spin" />}
            Simpan
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
