import { useEffect, useState } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CopyPlus, Loader2, Pencil, PiggyBank, Plus, Target, Trash2, TrendingDown, Wallet, type LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { FormField } from '@/components/FormField'
import { PageHeader } from '@/components/PageHeader'
import { Pagination } from '@/components/Pagination'
import { SearchableSelect } from '@/components/SearchableSelect'
import { SelectField } from '@/components/SelectField'
import { useAuth } from '@/features/auth/AuthContext'
import { useClientPagination } from '@/hooks/use-client-pagination'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { formatRupiah } from '@/lib/format'
import { useCategories } from '@/lib/queries'
import { cn } from '@/lib/utils'
import type { BudgetReport, BudgetRow, BudgetStatus } from '@/types'

const STATUS: Record<BudgetStatus, { label: string; badge: string; bar: string }> = {
  aman: { label: 'Aman', badge: 'bg-success/12 text-success border-success/25', bar: 'bg-success' },
  waspada: { label: 'Waspada', badge: 'bg-warning/15 text-warning border-warning/30', bar: 'bg-warning' },
  lewat: { label: 'Lewat anggaran', badge: 'bg-destructive/10 text-destructive border-destructive/25', bar: 'bg-destructive' },
  tercapai: { label: 'Tercapai', badge: 'bg-success/12 text-success border-success/25', bar: 'bg-success' },
  tertinggal: { label: 'Tertinggal', badge: 'bg-warning/15 text-warning border-warning/30', bar: 'bg-warning' },
}

const thisYear = new Date().getFullYear()
const YEAR_OPTIONS = [thisYear + 1, thisYear, thisYear - 1, thisYear - 2].map((y) => ({ value: String(y), label: String(y) }))

export default function BudgetsPage() {
  const { canWrite } = useAuth()
  const queryClient = useQueryClient()
  const [year, setYear] = useState(thisYear)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<BudgetRow | null>(null)
  const [deleting, setDeleting] = useState<BudgetRow | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['budgets', year],
    queryFn: async () => (await api.get<BudgetReport>('/budgets', { params: { year } })).data,
  })
  const pager = useClientPagination(data?.rows ?? [])

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['budgets'] })
    queryClient.invalidateQueries({ queryKey: ['insights'] })
  }

  const remove = useMutation({
    mutationFn: (b: BudgetRow) => api.delete(`/budgets/${b.id}`),
    onSuccess: () => {
      toast.success('Anggaran dihapus')
      invalidate()
    },
    onError: (err) => toast.error(errorMessage(err)),
    onSettled: () => setDeleting(null),
  })

  const copy = useMutation({
    mutationFn: async () => (await api.post<{ message: string }>('/budgets/copy', { from_year: year - 1, to_year: year })).data,
    onSuccess: (res) => {
      toast.success(res.message)
      invalidate()
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const totals = data?.totals
  const expensePct = totals && totals.expense_budget > 0 ? (totals.expense_actual / totals.expense_budget) * 100 : 0
  const incomePct = totals && totals.income_budget > 0 ? (totals.income_actual / totals.income_budget) * 100 : 0

  return (
    <>
      <PageHeader
        title="Anggaran"
        description="Rencana anggaran tahunan per pos (RAPB) dan realisasinya"
        actions={
          <>
            <SelectField className="w-28" value={String(year)} onChange={(v) => setYear(Number(v))} options={YEAR_OPTIONS} />
            {canWrite && (
              <>
                <Button variant="outline" disabled={copy.isPending} onClick={() => copy.mutate()}>
                  {copy.isPending ? <Loader2 className="animate-spin" /> : <CopyPlus />} Salin dari {year - 1}
                </Button>
                <Button
                  onClick={() => {
                    setEditing(null)
                    setFormOpen(true)
                  }}
                >
                  <Plus /> Tambah Anggaran
                </Button>
              </>
            )}
          </>
        }
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat icon={Wallet} tone="bg-primary/10 text-primary" label="Anggaran pengeluaran" value={totals?.expense_budget} />
        <Stat
          icon={TrendingDown}
          tone="bg-destructive/10 text-destructive"
          label="Realisasi pengeluaran"
          value={totals?.expense_actual}
          note={`${expensePct.toLocaleString('id-ID', { maximumFractionDigits: 1 })}% terpakai`}
        />
        <Stat icon={PiggyBank} tone="bg-success/12 text-success" label="Sisa anggaran" value={totals ? totals.expense_budget - totals.expense_actual : undefined} />
        <Stat
          icon={Target}
          tone="bg-warning/15 text-warning"
          label="Target penerimaan"
          value={totals?.income_budget}
          note={`${incomePct.toLocaleString('id-ID', { maximumFractionDigits: 1 })}% tercapai`}
        />
      </div>

      {data && data.elapsed > 0 && data.elapsed < 1 && (
        <p className="text-sm text-muted-foreground">
          Tahun {year} sudah berjalan <span className="font-medium text-foreground">{Math.round(data.elapsed * 100)}%</span>. Garis tipis di bar
          menunjukkan posisi wajar realisasi saat ini.
        </p>
      )}

      <Card className="gap-0 py-0">
        <Table>
          <TableHeader>
            <TableRow className="bg-muted/50 hover:bg-muted/50">
              <TableHead className="pl-4">Pos</TableHead>
              <TableHead>Jenis</TableHead>
              <TableHead className="text-right">Anggaran</TableHead>
              <TableHead className="text-right">Realisasi</TableHead>
              <TableHead className="w-48">Progres</TableHead>
              <TableHead className="text-right">Sisa</TableHead>
              {canWrite && <TableHead className="w-20 pr-4" />}
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading &&
              Array.from({ length: 5 }, (_, i) => (
                <TableRow key={i}>
                  <TableCell colSpan={7} className="px-4">
                    <Skeleton className="h-6 w-full" />
                  </TableCell>
                </TableRow>
              ))}
            {data?.rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={7} className="py-10 text-center text-muted-foreground">
                  Belum ada anggaran untuk {year}.{canWrite && ' Tambahkan per pos, atau salin dari tahun lalu.'}
                </TableCell>
              </TableRow>
            )}
            {pager.rows.map((b) => (
              <TableRow key={b.id} className="hover:bg-accent/60">
                <TableCell className="pl-4">
                  <div className="font-medium">{b.category?.name}</div>
                  <div className="text-xs text-muted-foreground">
                    <span className="font-mono">{b.category?.code}</span> · {b.category?.group}
                  </div>
                </TableCell>
                <TableCell>
                  <Badge variant="outline" className={b.type === 'income' ? 'border-success/25 text-success' : 'border-destructive/25 text-destructive'}>
                    {b.type_label}
                  </Badge>
                </TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(b.amount)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(b.actual)}</TableCell>
                <TableCell>
                  <ProgressBar row={b} />
                </TableCell>
                <TableCell className={cn('text-right tabular-nums', b.remaining < 0 && b.type === 'expense' && 'font-medium text-destructive')}>
                  {formatRupiah(b.remaining)}
                </TableCell>
                {canWrite && (
                  <TableCell className="pr-4">
                    <div className="flex justify-end gap-1">
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label="Ubah"
                        className="hover:bg-primary/10 hover:text-primary"
                        onClick={() => {
                          setEditing(b)
                          setFormOpen(true)
                        }}
                      >
                        <Pencil />
                      </Button>
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label="Hapus"
                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                        onClick={() => setDeleting(b)}
                      >
                        <Trash2 />
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
            itemLabel="pos anggaran"
          />
        )}
      </Card>

      <BudgetFormDialog open={formOpen} onOpenChange={setFormOpen} budget={editing} year={year} onSaved={invalidate} />
      <ConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Hapus anggaran?"
        description={`Anggaran "${deleting?.category?.name ?? ''}" tahun ${year} akan dihapus. Transaksi tidak terpengaruh.`}
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting)}
      />
    </>
  )
}

function Stat({ icon: Icon, tone, label, value, note }: { icon: LucideIcon; tone: string; label: string; value?: number; note?: string }) {
  return (
    <div className="flex items-center gap-3 rounded-xl border bg-card p-3 shadow-xs">
      <div className={cn('flex size-10 shrink-0 items-center justify-center rounded-lg', tone)}>
        <Icon className="size-5" />
      </div>
      <div className="min-w-0">
        <div className="truncate text-xs text-muted-foreground">{label}</div>
        {value === undefined ? <Skeleton className="mt-1 h-6 w-24" /> : <div className="truncate font-semibold tabular-nums">{formatRupiah(value)}</div>}
        {note && <div className="text-xs text-muted-foreground">{note}</div>}
      </div>
    </div>
  )
}

/** Bar realisasi; garis tipis = posisi wajar menurut porsi tahun yang sudah berjalan. */
function ProgressBar({ row }: { row: BudgetRow }) {
  const pct = Math.min(100, row.percentage)
  const expected = row.amount > 0 ? Math.min(100, (row.expected_to_date / row.amount) * 100) : 0
  const style = STATUS[row.status]

  return (
    <div className="grid gap-1">
      <div className="relative h-2 overflow-hidden rounded-full bg-muted">
        <div className={cn('h-full rounded-full transition-all', style.bar)} style={{ width: `${pct}%` }} />
        {expected > 0 && expected < 100 && <div className="absolute inset-y-0 w-0.5 bg-foreground/40" style={{ left: `${expected}%` }} />}
      </div>
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs tabular-nums">{row.percentage.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%</span>
        <Badge variant="outline" className={cn('h-4.5 px-1.5 text-[10px]', style.badge)}>
          {style.label}
        </Badge>
      </div>
    </div>
  )
}

const schema = z.object({
  category_id: z.string().min(1, 'Pilih kategori'),
  type: z.enum(['income', 'expense']),
  amount: z.coerce.number<string>().gt(0, 'Harus lebih dari 0'),
  notes: z.string().optional(),
})
type FormValues = z.input<typeof schema>
type FormOutput = z.output<typeof schema>

function BudgetFormDialog({
  open,
  onOpenChange,
  budget,
  year,
  onSaved,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  budget: BudgetRow | null
  year: number
  onSaved: () => void
}) {
  const { data: categories = [] } = useCategories({ excludeTransfer: true })
  const { register, control, handleSubmit, reset, setError, setValue, formState } = useForm<FormValues, unknown, FormOutput>({
    resolver: zodResolver(schema),
  })
  const errors = formState.errors
  const categoryId = useWatch({ control, name: 'category_id' })
  const category = categories.find((c) => String(c.id) === categoryId)

  useEffect(() => {
    if (!open) return
    reset(
      budget
        ? { category_id: String(budget.category_id), type: budget.type, amount: String(budget.amount), notes: budget.notes ?? '' }
        : { category_id: '', type: 'expense', amount: '', notes: '' },
    )
  }, [open, budget, reset])

  // Kategori satu arah: jenis anggaran mengikuti kategori.
  useEffect(() => {
    if (category && category.type !== 'both') setValue('type', category.type)
  }, [category, setValue])

  const mutation = useMutation({
    mutationFn: (values: FormOutput) => {
      const payload = { ...values, year }
      return budget ? api.put(`/budgets/${budget.id}`, payload) : api.post('/budgets', payload)
    },
    onSuccess: () => {
      toast.success('Anggaran disimpan')
      onSaved()
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
          <DialogTitle>
            {budget ? 'Ubah' : 'Tambah'} Anggaran {year}
          </DialogTitle>
        </DialogHeader>
        <form id="budget-form" onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid gap-4">
          <FormField label="Pos / kategori" error={errors.category_id?.message}>
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
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Jenis" error={errors.type?.message}>
              <Controller
                control={control}
                name="type"
                render={({ field }) => (
                  <SelectField
                    value={field.value ?? ''}
                    onChange={field.onChange}
                    disabled={!!category && category.type !== 'both'}
                    options={[
                      { value: 'expense', label: 'Pengeluaran' },
                      { value: 'income', label: 'Target penerimaan' },
                    ]}
                  />
                )}
              />
            </FormField>
            <FormField label="Anggaran setahun (Rp)" htmlFor="amount" error={errors.amount?.message}>
              <Input id="amount" type="number" inputMode="numeric" min={0} step="any" {...register('amount')} aria-invalid={!!errors.amount} />
            </FormField>
          </div>
          <FormField label="Catatan" htmlFor="notes">
            <Input id="notes" placeholder="mis. sesuai Program Jemaat 2027" {...register('notes')} />
          </FormField>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Batal
          </Button>
          <Button type="submit" form="budget-form" disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="animate-spin" />}
            Simpan
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
