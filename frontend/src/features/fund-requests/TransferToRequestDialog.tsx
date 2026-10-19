import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { FormField } from '@/components/FormField'
import { SelectField } from '@/components/SelectField'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { formatRupiah, toDateInput } from '@/lib/format'
import { useCashAccounts } from '@/lib/queries'
import type { FundRequest } from '@/types'

const schema = z.object({
  date: z.string().min(1, 'Tanggal wajib diisi'),
  from_cash_account_id: z.string().min(1, 'Pilih kas asal'),
  amount: z.coerce.number<string>().positive('Jumlah harus lebih dari 0'),
  reference: z.string().max(100).optional(),
})
type FormValues = z.input<typeof schema>

interface Props {
  open: boolean
  onOpenChange: (o: boolean) => void
  request: FundRequest
  onSaved: (fresh: FundRequest) => void
}

/** Bendahara mencatat transfer dana untuk pengajuan (mutasi antar kas ke kas pengajuan). */
export function TransferToRequestDialog({ open, onOpenChange, request, onSaved }: Props) {
  const { data: cashAccounts = [] } = useCashAccounts({ activeOnly: true })
  const { register, control, handleSubmit, reset, setError, formState } = useForm<FormValues, unknown, z.output<typeof schema>>({
    resolver: zodResolver(schema),
  })
  const errors = formState.errors
  const sources = cashAccounts.filter((c) => c.id !== request.cash_account_id)

  useEffect(() => {
    if (!open) return
    const bank = sources.find((c) => c.type === 'bank') ?? sources[0]
    reset({
      date: toDateInput(new Date()),
      from_cash_account_id: bank ? String(bank.id) : '',
      amount: request.summary.remaining_transfer ? String(request.summary.remaining_transfer) : '',
      reference: '',
    })
    // Hanya di-reset saat dialog dibuka.
  }, [open]) // eslint-disable-line react-hooks/exhaustive-deps

  const mutation = useMutation({
    mutationFn: async (v: z.output<typeof schema>) => (await api.post<{ data: FundRequest }>(`/fund-requests/${request.id}/transfers`, v)).data.data,
    onSuccess: (fresh) => {
      toast.success('Transfer dicatat')
      onSaved(fresh)
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
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Catat Transfer ke {request.cash_account?.name}</DialogTitle>
          <DialogDescription>
            Saran transfer {formatRupiah(request.summary.suggested_transfer)}, sudah ditransfer {formatRupiah(request.summary.transferred)}.
          </DialogDescription>
        </DialogHeader>
        <form id="request-transfer-form" onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid gap-4">
          <FormField label="Dari kas / rekening" error={errors.from_cash_account_id?.message}>
            <Controller
              control={control}
              name="from_cash_account_id"
              render={({ field }) => (
                <SelectField value={field.value ?? ''} onChange={field.onChange} options={sources.map((c) => ({ value: String(c.id), label: c.name }))} />
              )}
            />
          </FormField>
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Tanggal transfer" htmlFor="rt-date" error={errors.date?.message}>
              <Input id="rt-date" type="date" {...register('date')} />
            </FormField>
            <FormField label="Jumlah (Rp)" htmlFor="rt-amount" error={errors.amount?.message}>
              <Input id="rt-amount" type="number" min={0} step="any" {...register('amount')} />
            </FormField>
          </div>
          <FormField label="No. Referensi" htmlFor="rt-ref" error={errors.reference?.message}>
            <Input id="rt-ref" placeholder="Opsional, mis. no. bukti transfer" {...register('reference')} />
          </FormField>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Batal
          </Button>
          <Button type="submit" form="request-transfer-form" disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="animate-spin" />}
            Simpan
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
