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
import { SearchableSelect } from '@/components/SearchableSelect'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { formatRupiah, toDateInput } from '@/lib/format'
import { useCategories } from '@/lib/queries'
import type { FundReportItem, FundRequest } from '@/types'

const schema = z.object({
  date: z.string().min(1, 'Tanggal wajib diisi'),
  category_id: z.string().min(1, 'Kategori wajib dipilih'),
  description: z.string().trim().min(1, 'Uraian wajib diisi').max(255),
  qty: z.coerce.number<string>().positive('Harus > 0'),
  unit: z.string().max(30).optional(),
  unit_price: z.coerce.number<string>().positive('Harus > 0'),
})
type FormValues = z.input<typeof schema>

interface Props {
  open: boolean
  onOpenChange: (o: boolean) => void
  request: FundRequest
  item: FundReportItem | null
  onSaved: (fresh: FundRequest) => void
}

/** Satu nota LPJ. Setelah tersimpan, foto nota dilampirkan dari tabel LPJ. */
export function ReportItemFormDialog({ open, onOpenChange, request, item, onSaved }: Props) {
  const { data: categories = [] } = useCategories({ type: 'expense', activeOnly: true, excludeTransfer: true })
  const { register, control, handleSubmit, reset, watch, setError, formState } = useForm<FormValues, unknown, z.output<typeof schema>>({
    resolver: zodResolver(schema),
  })
  const errors = formState.errors
  const total = (Number(watch('qty')) || 0) * (Number(watch('unit_price')) || 0)
  const operational = categories.filter((c) => c.nature === 'operational')

  useEffect(() => {
    if (!open) return
    reset(
      item
        ? {
            date: item.date,
            category_id: String(item.category_id),
            description: item.description,
            qty: String(item.qty),
            unit: item.unit ?? '',
            unit_price: String(item.unit_price),
          }
        : { date: toDateInput(new Date()), category_id: '', description: '', qty: '1', unit: '', unit_price: '' },
    )
  }, [open, item, reset])

  const mutation = useMutation({
    mutationFn: async (v: z.output<typeof schema>) =>
      (
        await (item
          ? api.put<{ data: FundRequest }>(`/fund-report-items/${item.id}`, v)
          : api.post<{ data: FundRequest }>(`/fund-requests/${request.id}/report/items`, v))
      ).data.data,
    onSuccess: (fresh) => {
      toast.success(item ? 'Nota diperbarui' : 'Nota ditambahkan. Lampirkan foto nota di tabel LPJ.')
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
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{item ? 'Ubah Nota' : 'Tambah Nota LPJ'}</DialogTitle>
          <DialogDescription>Tanggal sesuai nota. Nota ini otomatis tercatat sebagai pengeluaran dari {request.cash_account?.name}.</DialogDescription>
        </DialogHeader>
        <form id="report-item-form" onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid gap-4 sm:grid-cols-2">
          <FormField label="Tanggal nota" htmlFor="ri-date" error={errors.date?.message}>
            <Input id="ri-date" type="date" {...register('date')} />
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
                  options={operational.map((c) => ({ value: String(c.id), label: `${c.code} · ${c.name}` }))}
                />
              )}
            />
          </FormField>
          <div className="sm:col-span-2">
            <FormField label="Uraian" htmlFor="ri-desc" error={errors.description?.message}>
              <Input id="ri-desc" placeholder="mis. Kertas HVS A4 2 rim" {...register('description')} />
            </FormField>
          </div>
          <FormField label="Qty" htmlFor="ri-qty" error={errors.qty?.message}>
            <Input id="ri-qty" type="number" min={0} step="any" {...register('qty')} />
          </FormField>
          <FormField label="Satuan" htmlFor="ri-unit" error={errors.unit?.message}>
            <Input id="ri-unit" placeholder="rim, pcs, paket..." {...register('unit')} />
          </FormField>
          <FormField label="Harga satuan (Rp)" htmlFor="ri-price" error={errors.unit_price?.message}>
            <Input id="ri-price" type="number" min={0} step="any" {...register('unit_price')} />
          </FormField>
          <div className="grid content-end pb-2 text-sm">
            Jumlah: <span className="text-lg font-semibold tabular-nums">{formatRupiah(total)}</span>
          </div>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Batal
          </Button>
          <Button type="submit" form="report-item-form" disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="animate-spin" />}
            Simpan
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
