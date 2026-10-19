import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { FormField } from '@/components/FormField'
import { SearchableSelect } from '@/components/SearchableSelect'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { useCategories } from '@/lib/queries'
import type { RecurringExpense } from '@/types'

const schema = z.object({
  name: z.string().trim().min(1, 'Nama wajib diisi').max(255),
  category_id: z.string().min(1, 'Kategori wajib dipilih'),
  counterparty: z.string().max(255).optional(),
  bank_info: z.string().max(255).optional(),
  amount: z.coerce.number<string>().positive('Nominal harus lebih dari 0'),
  sort_order: z.coerce.number<string>().int().min(0),
  is_active: z.boolean(),
})
type FormValues = z.input<typeof schema>

export function RecurringExpenseFormDialog({ open, onOpenChange, item }: { open: boolean; onOpenChange: (o: boolean) => void; item: RecurringExpense | null }) {
  const queryClient = useQueryClient()
  const { data: categories = [] } = useCategories({ type: 'expense', activeOnly: true, excludeTransfer: true })
  const { register, control, handleSubmit, reset, setError, formState } = useForm<FormValues, unknown, z.output<typeof schema>>({
    resolver: zodResolver(schema),
  })
  const errors = formState.errors

  useEffect(() => {
    if (!open) return
    reset({
      name: item?.name ?? '',
      category_id: item ? String(item.category_id) : '',
      counterparty: item?.counterparty ?? '',
      bank_info: item?.bank_info ?? '',
      amount: item ? String(item.amount) : '',
      sort_order: String(item?.sort_order ?? 0),
      is_active: item?.is_active ?? true,
    })
  }, [open, item, reset])

  const mutation = useMutation({
    mutationFn: (v: z.output<typeof schema>) => (item ? api.put(`/recurring-expenses/${item.id}`, v) : api.post('/recurring-expenses', v)),
    onSuccess: () => {
      toast.success('Biaya rutin disimpan')
      queryClient.invalidateQueries({ queryKey: ['recurring-expenses'] })
      queryClient.invalidateQueries({ queryKey: ['recurring-status'] })
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
          <DialogTitle>{item ? 'Ubah Biaya Rutin' : 'Tambah Biaya Rutin'}</DialogTitle>
        </DialogHeader>
        <form id="recurring-form" onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <FormField label="Nama" htmlFor="re-name" error={errors.name?.message}>
              <Input id="re-name" placeholder="mis. Tunjangan HT Lz. ..." {...register('name')} />
            </FormField>
          </div>
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
          <FormField label="Anggaran / bulan (Rp)" htmlFor="re-amount" error={errors.amount?.message}>
            <Input id="re-amount" type="number" min={0} step="any" {...register('amount')} />
          </FormField>
          <FormField label="Penerima" htmlFor="re-cp" error={errors.counterparty?.message}>
            <Input id="re-cp" {...register('counterparty')} />
          </FormField>
          <FormField label="Rekening tujuan" htmlFor="re-bank" error={errors.bank_info?.message}>
            <Input id="re-bank" placeholder="Opsional" {...register('bank_info')} />
          </FormField>
          <FormField label="Urutan" htmlFor="re-sort" error={errors.sort_order?.message}>
            <Input id="re-sort" type="number" min={0} {...register('sort_order')} />
          </FormField>
          <label className="flex items-center gap-2 self-end pb-2 text-sm">
            <input type="checkbox" className="size-4 accent-primary" {...register('is_active')} /> Aktif
          </label>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Batal
          </Button>
          <Button type="submit" form="recurring-form" disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="animate-spin" />}
            Simpan
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
