import { useEffect, useMemo, useState } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  ArrowDownLeft,
  ArrowLeftRight,
  ArrowUpRight,
  CircleOff,
  FolderTree,
  Loader2,
  Lock,
  Pencil,
  Plus,
  Search,
  SearchX,
  Trash2,
  X,
  type LucideIcon,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { FormField } from '@/components/FormField'
import { PageHeader } from '@/components/PageHeader'
import { Pagination } from '@/components/Pagination'
import { SelectField } from '@/components/SelectField'
import { useAuth } from '@/features/auth/AuthContext'
import { useClientPagination } from '@/hooks/use-client-pagination'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { useCategories } from '@/lib/queries'
import { cn } from '@/lib/utils'
import type { Category, CategoryNature, CategoryType } from '@/types'

const TYPE_STYLE: Record<CategoryType, { label: string; icon: LucideIcon; badge: string; tile: string }> = {
  income: {
    label: 'Pemasukan',
    icon: ArrowDownLeft,
    badge: 'bg-success/12 text-success border-success/25',
    tile: 'bg-success/12 text-success',
  },
  expense: {
    label: 'Pengeluaran',
    icon: ArrowUpRight,
    badge: 'bg-destructive/10 text-destructive border-destructive/25',
    tile: 'bg-destructive/10 text-destructive',
  },
  both: {
    label: 'Dua Arah',
    icon: ArrowLeftRight,
    badge: 'bg-warning/15 text-warning border-warning/30',
    tile: 'bg-warning/15 text-warning',
  },
}

const NATURE_STYLE: Record<CategoryNature, string> = {
  operational: 'bg-muted text-muted-foreground border-border',
  titipan: 'bg-info/10 text-info border-info/25',
  pinjaman: 'bg-chart-4/12 text-chart-4 border-chart-4/30',
  transfer: 'bg-secondary text-secondary-foreground border-primary/20',
}

const NATURE_OPTIONS = [
  { value: 'operational', label: 'Operasional' },
  { value: 'titipan', label: 'Titipan' },
  { value: 'pinjaman', label: 'Pinjaman' },
  { value: 'transfer', label: 'Transfer (sistem)' },
]

const TYPE_OPTIONS = (Object.keys(TYPE_STYLE) as CategoryType[]).map((t) => ({ value: t, label: TYPE_STYLE[t].label }))

type QuickFilter = 'all' | CategoryType | 'inactive'

const isSystem = (c: Category) => c.nature === 'transfer'

export default function CategoriesPage() {
  const canWrite = useAuth().can('categories', 'manage')
  const queryClient = useQueryClient()
  const { data: categories = [], isLoading } = useCategories()

  const [quick, setQuickState] = useState<QuickFilter>('all')
  const [search, setSearchState] = useState('')
  const [group, setGroupState] = useState('')
  const [nature, setNatureState] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Category | null>(null)
  const [deleting, setDeleting] = useState<Category | null>(null)

  const groups = useMemo(() => [...new Set(categories.map((c) => c.group))].sort(), [categories])

  const counts = useMemo(
    () => ({
      all: categories.length,
      income: categories.filter((c) => c.type === 'income').length,
      expense: categories.filter((c) => c.type === 'expense').length,
      both: categories.filter((c) => c.type === 'both').length,
      inactive: categories.filter((c) => !c.is_active).length,
    }),
    [categories],
  )

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return categories.filter((c) => {
      if (quick === 'inactive' ? c.is_active : quick !== 'all' && c.type !== quick) return false
      if (group && c.group !== group) return false
      if (nature && c.nature !== nature) return false
      if (q && !`${c.code} ${c.name} ${c.description ?? ''}`.toLowerCase().includes(q)) return false
      return true
    })
  }, [categories, quick, search, group, nature])

  const { rows, page, pageSize, total, setPage, setPageSize } = useClientPagination(filtered)

  // Setiap filter berubah, kembali ke halaman 1.
  const resetPage =
    <T,>(set: (v: T) => void) =>
    (v: T) => {
      set(v)
      setPage(1)
    }
  const setQuick = resetPage(setQuickState)
  const setSearch = resetPage(setSearchState)
  const setGroup = resetPage(setGroupState)
  const setNature = resetPage(setNatureState)

  const hasFilter = quick !== 'all' || search !== '' || group !== '' || nature !== ''
  const resetFilters = () => {
    setQuick('all')
    setSearch('')
    setGroup('')
    setNature('')
  }

  const remove = useMutation({
    mutationFn: (c: Category) => api.delete(`/categories/${c.id}`),
    onSuccess: () => {
      toast.success('Kategori dihapus')
      queryClient.invalidateQueries({ queryKey: ['categories'] })
      setDeleting(null)
    },
    onError: (err) => {
      toast.error(errorMessage(err))
      setDeleting(null)
    },
  })

  const openForm = (c: Category | null) => {
    setEditing(c)
    setFormOpen(true)
  }

  const actions = (c: Category) =>
    isSystem(c) ? (
      <Tooltip>
        <TooltipTrigger render={<span className="inline-flex items-center gap-1 text-xs text-muted-foreground" />}>
          <Lock className="size-3.5" /> Sistem
        </TooltipTrigger>
        <TooltipContent>Kategori sistem untuk mutasi antar kas, tidak bisa diubah</TooltipContent>
      </Tooltip>
    ) : (
      <div className="flex justify-end gap-1">
        <Button size="icon-sm" variant="ghost" aria-label="Ubah" className="hover:bg-primary/10 hover:text-primary" onClick={() => openForm(c)}>
          <Pencil />
        </Button>
        {(c.transactions_count ?? 0) > 0 ? (
          <Tooltip>
            <TooltipTrigger
              render={<Button size="icon-sm" variant="ghost" aria-label="Tidak bisa dihapus" className="cursor-not-allowed opacity-40" />}
            >
              <Trash2 />
            </TooltipTrigger>
            <TooltipContent>Sudah dipakai transaksi — nonaktifkan saja</TooltipContent>
          </Tooltip>
        ) : (
          <Button
            size="icon-sm"
            variant="ghost"
            aria-label="Hapus"
            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
            onClick={() => setDeleting(c)}
          >
            <Trash2 />
          </Button>
        )}
      </div>
    )

  return (
    <>
      <PageHeader
        title="Kategori"
        description="Pos pemasukan & pengeluaran (bagan akun)"
        actions={
          canWrite && (
            <Button onClick={() => openForm(null)}>
              <Plus /> Tambah Kategori
            </Button>
          )
        }
      />

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <StatTile label="Semua" value={counts.all} icon={FolderTree} tone="bg-primary/10 text-primary" active={quick === 'all'} onClick={() => setQuick('all')} />
        {(['income', 'expense', 'both'] as const).map((t) => (
          <StatTile
            key={t}
            label={TYPE_STYLE[t].label}
            value={counts[t]}
            icon={TYPE_STYLE[t].icon}
            tone={TYPE_STYLE[t].tile}
            active={quick === t}
            onClick={() => setQuick(quick === t ? 'all' : t)}
          />
        ))}
        <StatTile
          label="Nonaktif"
          value={counts.inactive}
          icon={CircleOff}
          tone="bg-muted text-muted-foreground"
          active={quick === 'inactive'}
          onClick={() => setQuick(quick === 'inactive' ? 'all' : 'inactive')}
        />
      </div>

      <Card className="gap-0 py-0">
        <div className="flex flex-col gap-2 border-b p-3 sm:flex-row sm:items-center">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input placeholder="Cari kode atau nama kategori..." value={search} onChange={(e) => setSearch(e.target.value)} className="pr-8 pl-8" />
            {search && (
              <button
                type="button"
                aria-label="Hapus pencarian"
                onClick={() => setSearch('')}
                className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
              >
                <X className="size-3.5" />
              </button>
            )}
          </div>
          <div className="grid grid-cols-2 gap-2 sm:flex">
            <SelectField
              className="sm:w-44"
              value={group}
              onChange={setGroup}
              placeholder="Semua kelompok"
              options={[{ value: '', label: 'Semua kelompok' }, ...groups.map((g) => ({ value: g, label: g }))]}
            />
            <SelectField
              className="sm:w-40"
              value={nature}
              onChange={setNature}
              placeholder="Semua sifat"
              options={[{ value: '', label: 'Semua sifat' }, ...NATURE_OPTIONS]}
            />
          </div>
          {hasFilter && (
            <Button variant="ghost" size="sm" onClick={resetFilters} className="text-muted-foreground">
              <X /> Reset
            </Button>
          )}
        </div>

        {/* Desktop: tabel */}
        <div className="hidden sm:block">
          <Table>
            <TableHeader>
              <TableRow className="bg-muted/50 hover:bg-muted/50">
                <TableHead className="pl-4">Kode</TableHead>
                <TableHead>Nama</TableHead>
                <TableHead>Kelompok</TableHead>
                <TableHead>Jenis</TableHead>
                <TableHead>Sifat</TableHead>
                <TableHead className="text-right">Dipakai</TableHead>
                <TableHead>Status</TableHead>
                {canWrite && <TableHead className="w-24 pr-4" />}
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading &&
                Array.from({ length: 6 }, (_, i) => (
                  <TableRow key={i}>
                    <TableCell colSpan={canWrite ? 8 : 7} className="px-4">
                      <Skeleton className="h-6 w-full" />
                    </TableCell>
                  </TableRow>
                ))}
              {rows.map((c) => (
                <TableRow key={c.id} className={cn('transition-colors hover:bg-accent/60', !c.is_active && 'opacity-55')}>
                  <TableCell className="pl-4">
                    <span className="rounded-md bg-muted px-1.5 py-0.5 font-mono text-xs">{c.code}</span>
                  </TableCell>
                  <TableCell className="max-w-72 whitespace-normal">
                    <div className="font-medium">{c.name}</div>
                    {c.description && <div className="text-xs text-muted-foreground">{c.description}</div>}
                  </TableCell>
                  <TableCell className="text-muted-foreground">{c.group}</TableCell>
                  <TableCell>
                    <TypeBadge type={c.type} />
                  </TableCell>
                  <TableCell>
                    <Badge variant="outline" className={NATURE_STYLE[c.nature]}>
                      {c.nature_label}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    {c.transactions_count ? <span className="font-medium">{c.transactions_count}</span> : <span className="text-muted-foreground">–</span>}
                  </TableCell>
                  <TableCell>
                    <StatusDot active={c.is_active} />
                  </TableCell>
                  {canWrite && <TableCell className="pr-4 text-right">{actions(c)}</TableCell>}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>

        {/* HP: daftar kartu */}
        <div className="divide-y sm:hidden">
          {isLoading && Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="m-3 h-16" />)}
          {rows.map((c) => (
            <div key={c.id} className={cn('flex gap-3 p-3', !c.is_active && 'opacity-55')}>
              <div className={cn('flex size-9 shrink-0 items-center justify-center rounded-lg', TYPE_STYLE[c.type].tile)}>
                {(() => {
                  const Icon = TYPE_STYLE[c.type].icon
                  return <Icon className="size-4" />
                })()}
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <div className="truncate font-medium">{c.name}</div>
                    <div className="text-xs text-muted-foreground">
                      <span className="font-mono">{c.code}</span> · {c.group}
                    </div>
                  </div>
                  {canWrite && <div className="shrink-0">{actions(c)}</div>}
                </div>
                <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                  <TypeBadge type={c.type} />
                  {c.nature !== 'operational' && (
                    <Badge variant="outline" className={NATURE_STYLE[c.nature]}>
                      {c.nature_label}
                    </Badge>
                  )}
                  <StatusDot active={c.is_active} />
                  {!!c.transactions_count && <span className="text-xs text-muted-foreground">{c.transactions_count} transaksi</span>}
                </div>
              </div>
            </div>
          ))}
        </div>

        {!isLoading && filtered.length === 0 && (
          <div className="flex flex-col items-center gap-2 px-4 py-12 text-center">
            <div className="flex size-12 items-center justify-center rounded-full bg-muted">
              <SearchX className="size-6 text-muted-foreground" />
            </div>
            <p className="font-medium">Tidak ada kategori yang cocok</p>
            <p className="text-sm text-muted-foreground">Coba ubah kata kunci atau filter.</p>
            {hasFilter && (
              <Button variant="outline" size="sm" onClick={resetFilters} className="mt-1">
                Reset filter
              </Button>
            )}
          </div>
        )}

        {filtered.length > 0 && (
          <Pagination
            className="border-t p-3"
            page={page}
            pageSize={pageSize}
            total={total}
            onPageChange={setPage}
            onPageSizeChange={setPageSize}
            itemLabel="kategori"
          />
        )}
      </Card>

      <CategoryFormDialog open={formOpen} onOpenChange={setFormOpen} category={editing} groups={groups} />
      <ConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Hapus kategori?"
        description={`Kategori "${deleting?.name ?? ''}" akan dihapus permanen.`}
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting)}
      />
    </>
  )
}

function StatTile({
  label,
  value,
  icon: Icon,
  tone,
  active,
  onClick,
}: {
  label: string
  value: number
  icon: LucideIcon
  tone: string
  active: boolean
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        'flex items-center gap-3 rounded-xl border bg-card p-3 text-left shadow-xs transition-all hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none',
        active && 'border-primary/50 ring-2 ring-primary/30',
      )}
    >
      <div className={cn('flex size-10 shrink-0 items-center justify-center rounded-lg', tone)}>
        <Icon className="size-5" />
      </div>
      <div className="min-w-0">
        <div className="truncate text-xs text-muted-foreground">{label}</div>
        <div className="text-xl font-semibold tabular-nums">{value}</div>
      </div>
    </button>
  )
}

function TypeBadge({ type }: { type: CategoryType }) {
  const s = TYPE_STYLE[type]
  return (
    <Badge variant="outline" className={s.badge}>
      <s.icon /> {s.label}
    </Badge>
  )
}

function StatusDot({ active }: { active: boolean }) {
  return (
    <span className={cn('inline-flex items-center gap-1.5 text-xs', active ? 'text-success' : 'text-muted-foreground')}>
      <span className={cn('size-2 rounded-full', active ? 'bg-success' : 'bg-muted-foreground/50')} />
      {active ? 'Aktif' : 'Nonaktif'}
    </span>
  )
}

const schema = z.object({
  name: z.string().trim().min(1, 'Nama wajib diisi').max(255),
  group: z.string().trim().min(1, 'Kelompok wajib diisi').max(100),
  type: z.enum(['income', 'expense', 'both']),
  nature: z.enum(['operational', 'titipan', 'pinjaman', 'transfer']),
  description: z.string().optional(),
  is_active: z.boolean(),
})
type FormValues = z.infer<typeof schema>

function CategoryFormDialog({
  open,
  onOpenChange,
  category,
  groups,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  category: Category | null
  groups: string[]
}) {
  const queryClient = useQueryClient()
  const { register, control, handleSubmit, reset, setError, formState } = useForm<FormValues>({ resolver: zodResolver(schema) })
  const errors = formState.errors
  const used = (category?.transactions_count ?? 0) > 0

  // Kategori yang sudah dipakai hanya boleh tetap atau berubah ke "dua arah" (aturan backend).
  const typeOptions = used && category ? TYPE_OPTIONS.filter((o) => o.value === category.type || o.value === 'both') : TYPE_OPTIONS

  // Pratinjau kode otomatis (hanya saat tambah). Kode final tetap ditentukan server saat simpan.
  const [group, type, nature] = useWatch({ control, name: ['group', 'type', 'nature'] })
  const [previewParams, setPreviewParams] = useState<{ group: string; type: string; nature: string } | null>(null)
  useEffect(() => {
    const timer = setTimeout(() => setPreviewParams(group?.trim() ? { group: group.trim(), type, nature } : null), 300)
    return () => clearTimeout(timer)
  }, [group, type, nature])
  const preview = useQuery({
    queryKey: ['categories', 'next-code', previewParams],
    queryFn: async () => (await api.get<{ code: string }>('/categories/next-code', { params: previewParams })).data.code,
    enabled: open && !category && !!previewParams,
  })

  useEffect(() => {
    if (!open) return
    reset(
      category
        ? {
            name: category.name,
            group: category.group,
            type: category.type,
            nature: category.nature,
            description: category.description ?? '',
            is_active: category.is_active,
          }
        : { name: '', group: '', type: 'expense', nature: 'operational', description: '', is_active: true },
    )
  }, [open, category, reset])

  const mutation = useMutation({
    mutationFn: async (values: FormValues) =>
      (category ? await api.put<{ data: Category }>(`/categories/${category.id}`, values) : await api.post<{ data: Category }>('/categories', values)).data
        .data,
    onSuccess: (saved) => {
      toast.success(category ? 'Kategori disimpan' : `Kategori ditambahkan dengan kode ${saved.code}`)
      queryClient.invalidateQueries({ queryKey: ['categories'] })
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
          <DialogTitle>{category ? 'Ubah Kategori' : 'Tambah Kategori'}</DialogTitle>
        </DialogHeader>
        <form id="category-form" onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid gap-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Kode">
              <div className="flex h-8 items-center gap-2 rounded-lg border border-dashed bg-muted/50 px-2.5 text-sm">
                {category ? (
                  <>
                    <Lock className="size-3.5 text-muted-foreground" />
                    <span className="font-mono">{category.code}</span>
                    <span className="ml-auto text-xs text-muted-foreground">tidak bisa diubah</span>
                  </>
                ) : preview.data ? (
                  <>
                    <span className="font-mono font-medium text-primary">{preview.data}</span>
                    <span className="ml-auto text-xs text-muted-foreground">otomatis</span>
                  </>
                ) : (
                  <span className="text-xs text-muted-foreground">{preview.isFetching ? 'Menghitung kode...' : 'Otomatis setelah kelompok diisi'}</span>
                )}
              </div>
            </FormField>
            <FormField label="Kelompok" htmlFor="group" error={errors.group?.message}>
              <Input id="group" list="category-groups" placeholder="mis. Pengeluaran" {...register('group')} aria-invalid={!!errors.group} />
              <datalist id="category-groups">
                {groups.map((g) => (
                  <option key={g} value={g} />
                ))}
              </datalist>
            </FormField>
          </div>
          <FormField label="Nama" htmlFor="name" error={errors.name?.message}>
            <Input id="name" {...register('name')} aria-invalid={!!errors.name} />
          </FormField>
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Jenis" error={errors.type?.message}>
              <Controller
                control={control}
                name="type"
                render={({ field }) => <SelectField value={field.value ?? ''} onChange={field.onChange} options={typeOptions} />}
              />
              {used && <p className="text-xs text-muted-foreground">Sudah dipakai transaksi — hanya bisa diubah ke Dua Arah.</p>}
            </FormField>
            <FormField label="Sifat" error={errors.nature?.message}>
              <Controller
                control={control}
                name="nature"
                render={({ field }) => (
                  <SelectField value={field.value ?? ''} onChange={field.onChange} options={NATURE_OPTIONS.filter((o) => o.value !== 'transfer')} />
                )}
              />
            </FormField>
          </div>
          <FormField label="Deskripsi" htmlFor="description">
            <Input id="description" {...register('description')} />
          </FormField>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" className="size-4 accent-primary" {...register('is_active')} /> Aktif
          </label>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Batal
          </Button>
          <Button type="submit" form="category-form" disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="animate-spin" />}
            Simpan
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
