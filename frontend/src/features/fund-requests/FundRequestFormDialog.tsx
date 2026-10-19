import { useEffect } from 'react'
import { useFieldArray, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { format } from 'date-fns'
import { Loader2, Plus, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { FormField } from '@/components/FormField'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { formatRupiah, toDateInput } from '@/lib/format'
import type { FundRequest } from '@/types'

const schema = z.object({
  period: z.string().regex(/^\d{4}-\d{2}$/, 'Pilih bulan'),
  request_date: z.string().min(1, 'Tanggal wajib diisi'),
  notes: z.string().max(2000).optional(),
  items: z
    .array(
      z.object({
        description: z.string().trim().min(1, 'Uraian wajib diisi').max(255),
        amount: z.coerce.number<string>().positive('Harus > 0'),
      }),
    )
    .min(1, 'Isi minimal satu rincian'),
})
type FormValues = z.input<typeof schema>

interface Props {
  open: boolean
  onOpenChange: (o: boolean) => void
  cashAccountId: number
  request?: FundRequest | null
  onSaved?: (r: FundRequest) => void
}

/** Pengajuan dana: rencana kebutuhan bulan ini (per item). */
export function FundRequestFormDialog({ open, onOpenChange, cashAccountId, request, onSaved }: Props) {
  const queryClient = useQueryClient()
  const { register, control, handleSubmit, reset, watch, setError, formState } = useForm<FormValues, unknown, z.output<typeof schema>>({
    resolver: zodResolver(schema),
  })
  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const errors = formState.errors
  const total = (watch('items') ?? []).reduce((sum, i) => sum + (Number(i.amount) || 0), 0)

  useEffect(() => {
    if (!open) return
    reset(
      request
        ? {
            period: request.period,
            request_date: request.request_date,
            notes: request.notes ?? '',
            items: (request.items ?? []).map((i) => ({ description: i.description, amount: String(i.amount) })),
          }
        : { period: format(new Date(), 'yyyy-MM'), request_date: toDateInput(new Date()), notes: '', items: [{ description: '', amount: '' }] },
    )
  }, [open, request, reset])

  const mutation = useMutation({
    mutationFn: async (v: z.output<typeof schema>) =>
      (
        await (request
          ? api.put<{ data: FundRequest }>(`/fund-requests/${request.id}`, v)
          : api.post<{ data: FundRequest }>('/fund-requests', { ...v, cash_account_id: cashAccountId }))
      ).data.data,
    onSuccess: (saved) => {
      toast.success('Pengajuan disimpan')
      queryClient.invalidateQueries({ queryKey: ['fund-requests'] })
      onOpenChange(false)
      onSaved?.(saved)
    },
    onError: (err) => {
      const fe = fieldErrors(err)
      Object.entries(fe).forEach(([f, message]) => setError(f as keyof FormValues, { message }))
      if (Object.keys(fe).length === 0) toast.error(errorMessage(err))
    },
  })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{request ? `Ubah Pengajuan ${request.number}` : 'Pengajuan Dana Operasional'}</DialogTitle>
        </DialogHeader>
        <form id="fund-request-form" onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid gap-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Untuk bulan" htmlFor="fr-period" error={errors.period?.message}>
              <Input id="fr-period" type="month" {...register('period')} />
            </FormField>
            <FormField label="Tanggal pengajuan" htmlFor="fr-date" error={errors.request_date?.message}>
              <Input id="fr-date" type="date" {...register('request_date')} />
            </FormField>
          </div>

          <div className="grid gap-2">
            <span className="text-sm font-medium">Rincian rencana kebutuhan</span>
            {fields.map((f, i) => (
              <div key={f.id} className="grid grid-cols-[1fr_9rem_auto] items-start gap-2">
                <div>
                  <Input placeholder="Uraian, mis. ATK, konsumsi rapat" {...register(`items.${i}.description`)} aria-invalid={!!errors.items?.[i]?.description} />
                  {errors.items?.[i]?.description && <p className="text-xs text-destructive">{errors.items[i]?.description?.message}</p>}
                </div>
                <div>
                  <Input type="number" min={0} step="any" placeholder="Rp" {...register(`items.${i}.amount`)} aria-invalid={!!errors.items?.[i]?.amount} />
                  {errors.items?.[i]?.amount && <p className="text-xs text-destructive">{errors.items[i]?.amount?.message}</p>}
                </div>
                <Button type="button" size="icon" variant="ghost" aria-label="Hapus baris" disabled={fields.length === 1} onClick={() => remove(i)}>
                  <Trash2 className="text-destructive" />
                </Button>
              </div>
            ))}
            {errors.items?.message && <p className="text-xs text-destructive">{errors.items.message}</p>}
            <div className="flex items-center justify-between">
              <Button type="button" size="sm" variant="outline" onClick={() => append({ description: '', amount: '' })}>
                <Plus /> Tambah baris
              </Button>
              <span className="text-sm">
                Total: <span className="font-semibold tabular-nums">{formatRupiah(total)}</span>
              </span>
            </div>
          </div>

          <FormField label="Catatan" htmlFor="fr-notes">
            <Input id="fr-notes" placeholder="Opsional" {...register('notes')} />
          </FormField>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Batal
          </Button>
          <Button type="submit" form="fund-request-form" disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="animate-spin" />}
            Simpan
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
