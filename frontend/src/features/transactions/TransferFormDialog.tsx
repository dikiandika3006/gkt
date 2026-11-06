import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { FormField } from '@/components/FormField'
import { SelectField } from '@/components/SelectField'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { formatRupiah, toDateInput } from '@/lib/format'
import { useAccessibleCashAccounts } from '@/lib/queries'
import type { CashAccount, Transaction } from '@/types'

const schema = z
  .object({
    date: z.string().min(1, 'Tanggal wajib diisi'),
    from_cash_account_id: z.string().min(1, 'Pilih kas asal'),
    to_cash_account_id: z.string().min(1, 'Pilih kas tujuan'),
    from_pocket_id: z.string().optional(),
    to_pocket_id: z.string().optional(),
    amount: z.coerce.number<string>().positive('Jumlah harus lebih dari 0'),
    description: z.string().trim().min(1, 'Keterangan wajib diisi').max(255),
    reference: z.string().max(100).optional(),
  })
  // Kas boleh sama asalkan pocket-nya berbeda (mutasi antar pocket).
  .refine((v) => v.from_cash_account_id !== v.to_cash_account_id || (!!v.from_pocket_id && v.from_pocket_id !== v.to_pocket_id), {
    path: ['to_cash_account_id'],
    message: 'Kas/pocket tujuan harus berbeda',
  })

type FormValues = z.input<typeof schema>

interface Props {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Salah satu sisi mutasi yang akan diubah; kosong = mutasi baru. */
  leg?: Transaction | null
}

function pocketOptions(account: CashAccount | undefined) {
  return (account?.pockets ?? [])
    .filter((p) => p.is_active)
    .map((p) => ({ value: String(p.id), label: p.current_balance === null ? p.name : `${p.name} (saldo ${formatRupiah(p.current_balance)})` }))
}

/** Mutasi antar kas / antar pocket: tidak dihitung sebagai pemasukan/pengeluaran gereja. */
export function TransferFormDialog({ open, onOpenChange, leg }: Props) {
  const queryClient = useQueryClient()
  const { data: cashAccounts = [] } = useAccessibleCashAccounts({ activeOnly: true })
  const { register, control, handleSubmit, reset, setError, setValue, watch, formState } = useForm<FormValues, unknown, z.output<typeof schema>>({
    resolver: zodResolver(schema),
  })
  const errors = formState.errors
  const fromAccount = cashAccounts.find((c) => String(c.id) === watch('from_cash_account_id'))
  const toAccount = cashAccounts.find((c) => String(c.id) === watch('to_cash_account_id'))

  useEffect(() => {
    if (!open) return
    reset({
      date: leg?.date ?? toDateInput(new Date()),
      from_cash_account_id: leg && leg.type === 'expense' ? String(leg.cash_account_id) : '',
      to_cash_account_id: leg && leg.type === 'income' ? String(leg.cash_account_id) : '',
      from_pocket_id: leg && leg.type === 'expense' && leg.pocket_id ? String(leg.pocket_id) : '',
      to_pocket_id: leg && leg.type === 'income' && leg.pocket_id ? String(leg.pocket_id) : '',
      amount: leg ? String(leg.amount) : '',
      description: leg?.description ?? '',
      reference: leg?.reference ?? '',
    })
  }, [open, leg, reset])

  const mutation = useMutation({
    mutationFn: async (v: z.output<typeof schema>) => {
      if (leg) {
        await api.put(`/transfers/${leg.transfer_group}`, { date: v.date, amount: v.amount, description: v.description, reference: v.reference })
      } else {
        await api.post('/transfers', {
          ...v,
          from_pocket_id: v.from_pocket_id ? Number(v.from_pocket_id) : null,
          to_pocket_id: v.to_pocket_id ? Number(v.to_pocket_id) : null,
        })
      }
    },
    onSuccess: () => {
      toast.success('Mutasi antar kas disimpan')
      ;['transactions', 'dashboard', 'reports', 'fund-requests', 'cash-accounts'].forEach((key) => queryClient.invalidateQueries({ queryKey: [key] }))
      onOpenChange(false)
    },
    onError: (err) => {
      const fe = fieldErrors(err)
      Object.entries(fe).forEach(([f, message]) => setError(f as keyof FormValues, { message }))
      if (Object.keys(fe).length === 0) toast.error(errorMessage(err))
    },
  })

  const accountOptions = cashAccounts.map((c) => ({ value: String(c.id), label: c.name }))

  const submit = handleSubmit((v) => {
    if (!leg) {
      let missing = false
      if (fromAccount?.has_pockets && !v.from_pocket_id) {
        setError('from_pocket_id', { message: 'Pocket asal wajib dipilih' })
        missing = true
      }
      if (toAccount?.has_pockets && !v.to_pocket_id) {
        setError('to_pocket_id', { message: 'Pocket tujuan wajib dipilih' })
        missing = true
      }
      if (missing) return
    }
    mutation.mutate(v)
  })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{leg ? 'Ubah Mutasi Antar Kas' : 'Mutasi Antar Kas'}</DialogTitle>
          <DialogDescription>
            Pindah dana antar kas milik gereja, mis. Rekening Gereja → Kas Sekretariat, atau antar pocket dalam satu rekening. Tidak dihitung sebagai pengeluaran.
          </DialogDescription>
        </DialogHeader>
        <form id="transfer-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
          <FormField label="Dari kas" error={errors.from_cash_account_id?.message}>
            <Controller
              control={control}
              name="from_cash_account_id"
              render={({ field }) => (
                <SelectField
                  value={field.value ?? ''}
                  onChange={(v) => {
                    field.onChange(v)
                    setValue('from_pocket_id', '')
                  }}
                  options={accountOptions}
                  disabled={!!leg}
                />
              )}
            />
          </FormField>
          <FormField label="Ke kas" error={errors.to_cash_account_id?.message}>
            <Controller
              control={control}
              name="to_cash_account_id"
              render={({ field }) => (
                <SelectField
                  value={field.value ?? ''}
                  onChange={(v) => {
                    field.onChange(v)
                    setValue('to_pocket_id', '')
                  }}
                  options={accountOptions}
                  disabled={!!leg}
                />
              )}
            />
          </FormField>
          {!leg && (fromAccount?.has_pockets || toAccount?.has_pockets) && (
            <>
              <FormField label="Dari pocket" error={errors.from_pocket_id?.message}>
                {fromAccount?.has_pockets ? (
                  <Controller
                    control={control}
                    name="from_pocket_id"
                    render={({ field }) => <SelectField value={field.value ?? ''} onChange={field.onChange} placeholder="Pilih pocket" options={pocketOptions(fromAccount)} />}
                  />
                ) : (
                  <Input value="-" disabled />
                )}
              </FormField>
              <FormField label="Ke pocket" error={errors.to_pocket_id?.message}>
                {toAccount?.has_pockets ? (
                  <Controller
                    control={control}
                    name="to_pocket_id"
                    render={({ field }) => <SelectField value={field.value ?? ''} onChange={field.onChange} placeholder="Pilih pocket" options={pocketOptions(toAccount)} />}
                  />
                ) : (
                  <Input value="-" disabled />
                )}
              </FormField>
            </>
          )}
          <FormField label="Tanggal" htmlFor="tr-date" error={errors.date?.message}>
            <Input id="tr-date" type="date" {...register('date')} />
          </FormField>
          <FormField label="Jumlah (Rp)" htmlFor="tr-amount" error={errors.amount?.message}>
            <Input id="tr-amount" type="number" min={0} step="any" {...register('amount')} />
          </FormField>
          <div className="sm:col-span-2">
            <FormField label="Keterangan" htmlFor="tr-desc" error={errors.description?.message}>
              <Input id="tr-desc" {...register('description')} />
            </FormField>
          </div>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Batal
          </Button>
          <Button type="submit" form="transfer-form" disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="animate-spin" />}
            Simpan
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
