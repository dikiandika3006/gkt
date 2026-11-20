import { useEffect, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { FileSpreadsheet, Loader2, Pencil, Plus, ShieldAlert, Trash2 } from 'lucide-react'
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
import { useAuth } from '@/features/auth/AuthContext'
import { useClientPagination } from '@/hooks/use-client-pagination'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { useTitheMembers } from '@/lib/queries'
import type { TitheMember } from '@/types'

const schema = z.object({
  code: z.coerce.number<string>({ error: 'Nomor harus berupa angka' }).int('Nomor harus bilangan bulat').min(1, 'Nomor minimal 1'),
  name: z.string().trim().min(1, 'Nama wajib diisi').max(255),
  alias: z
    .string()
    .trim()
    .max(20, 'Alias maksimal 20 karakter')
    .refine((v) => !v.includes('/'), 'Alias tidak boleh mengandung tanda "/"'),
  is_active: z.boolean(),
})
type FormValues = z.input<typeof schema>
type FormOutput = z.output<typeof schema>

/** Label seperti di backend: kode 3 digit + "/" + alias. */
function formatLabel(code: number | string, alias: string) {
  const n = String(code ?? '').trim()
  if (!n) return '-'
  const number = n.padStart(3, '0')
  return alias.trim() ? `${number}/${alias.trim()}` : number
}

/** Master nomor perpuluhan jemaat. Label (001/SVN) muncul sebagai saran nama di Mutasi & Rekap Persembahan. */
export default function TitheMembersPage() {
  const canWrite = useAuth().can('tithe_members', 'manage')
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<TitheMember | null>(null)
  const [deleting, setDeleting] = useState<TitheMember | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)
  const { data: members = [], isLoading } = useTitheMembers()

  const q = search.trim().toLowerCase()
  const filtered = q
    ? members.filter((m) => m.label.toLowerCase().includes(q) || m.name.toLowerCase().includes(q) || String(m.code) === q.replace(/^0+/, ''))
    : members
  const pager = useClientPagination(filtered, 25)
  const colSpan = canWrite ? 5 : 4

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['tithe-members'] })
    queryClient.invalidateQueries({ queryKey: ['counterparties'] })
  }

  const remove = useMutation({
    mutationFn: (m: TitheMember) => api.delete(`/tithe-members/${m.id}`),
    onSuccess: () => {
      toast.success('Nomor perpuluhan dihapus')
      refresh()
      setDeleting(null)
    },
    onError: (err) => {
      toast.error(errorMessage(err))
      setDeleting(null)
    },
  })

  const importFile = useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData()
      form.append('file', file)
      return (await api.post<{ message: string }>('/tithe-members/import', form)).data
    },
    onSuccess: (res) => {
      toast.success(res.message)
      refresh()
    },
    onError: (err) => toast.error(errorMessage(err)),
    onSettled: () => {
      if (fileRef.current) fileRef.current.value = ''
    },
  })

  return (
    <>
      <PageHeader
        title="Nomor Perpuluhan"
        description="Master nomor & alias perpuluhan jemaat. Label (mis. 001/SVN) muncul sebagai saran nama di Mutasi & Rekap Persembahan."
        actions={
          canWrite && (
            <div className="flex flex-wrap gap-2">
              <input
                ref={fileRef}
                type="file"
                accept=".xlsx,.xls,.csv"
                className="hidden"
                onChange={(e) => {
                  const file = e.target.files?.[0]
                  if (file) importFile.mutate(file)
                }}
              />
              <Button variant="outline" disabled={importFile.isPending} onClick={() => fileRef.current?.click()}>
                {importFile.isPending ? <Loader2 className="animate-spin" /> : <FileSpreadsheet />} Import Excel
              </Button>
              <Button
                onClick={() => {
                  setEditing(null)
                  setFormOpen(true)
                }}
              >
                <Plus /> Tambah
              </Button>
            </div>
          )
        }
      />

      <div className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 p-3 text-sm">
        <ShieldAlert className="mt-0.5 size-4 shrink-0 text-warning" />
        <p>
          Berisi nama lengkap jemaat (data pribadi). Pengguna Mutasi/Rekap tanpa hak modul ini hanya melihat label nomor/alias. Import Excel membaca kolom{' '}
          <b>Nama</b>, <b>Kode</b>, <b>Alias</b> (sheet "Master No Perpuluhan"); nomor yang sudah ada diperbarui, nomor tanpa nama dilewati.
        </p>
      </div>

      <div className="flex items-center gap-2">
        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari nomor, alias, atau nama" className="max-w-xs" />
        <span className="text-sm text-muted-foreground">{filtered.length} nomor</span>
      </div>

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Label</TableHead>
              <TableHead>Nama</TableHead>
              <TableHead>Alias</TableHead>
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
            {!isLoading && filtered.length === 0 && (
              <TableRow>
                <TableCell colSpan={colSpan} className="py-8 text-center text-muted-foreground">
                  {members.length === 0 ? 'Belum ada data. Tambah manual atau Import Excel.' : 'Tidak ada yang cocok.'}
                </TableCell>
              </TableRow>
            )}
            {pager.rows.map((m) => (
              <TableRow key={m.id}>
                <TableCell className="pl-4 font-mono text-sm font-medium">{m.label}</TableCell>
                <TableCell>{m.name}</TableCell>
                <TableCell className="text-muted-foreground">{m.alias ?? '-'}</TableCell>
                <TableCell>
                  <Badge variant={m.is_active ? 'secondary' : 'outline'}>{m.is_active ? 'Aktif' : 'Nonaktif'}</Badge>
                </TableCell>
                {canWrite && (
                  <TableCell className="pr-4">
                    <div className="flex justify-end gap-1">
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label="Ubah"
                        onClick={() => {
                          setEditing(m)
                          setFormOpen(true)
                        }}
                      >
                        <Pencil />
                      </Button>
                      <Button size="icon-sm" variant="ghost" aria-label="Hapus" onClick={() => setDeleting(m)}>
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
            itemLabel="nomor"
          />
        )}
      </Card>

      <TitheMemberFormDialog open={formOpen} onOpenChange={setFormOpen} member={editing} onSaved={refresh} />
      <ConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Hapus nomor perpuluhan?"
        description={`"${deleting?.label ?? ''} — ${deleting?.name ?? ''}" akan dihapus dari master. Nama di mutasi/rekap yang sudah tercatat tidak berubah.`}
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting)}
      />
    </>
  )
}

function TitheMemberFormDialog({
  open,
  onOpenChange,
  member,
  onSaved,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  member: TitheMember | null
  onSaved: () => void
}) {
  const { register, handleSubmit, reset, setError, watch, formState } = useForm<FormValues, unknown, FormOutput>({ resolver: zodResolver(schema) })
  const errors = formState.errors
  const preview = formatLabel(watch('code') as string, watch('alias') ?? '')

  useEffect(() => {
    if (!open) return
    reset(
      member
        ? { code: String(member.code), name: member.name, alias: member.alias ?? '', is_active: member.is_active }
        : { code: '', name: '', alias: '', is_active: true },
    )
  }, [open, member, reset])

  const mutation = useMutation({
    mutationFn: (values: FormOutput) => (member ? api.put(`/tithe-members/${member.id}`, values) : api.post('/tithe-members', values)),
    onSuccess: () => {
      toast.success('Nomor perpuluhan disimpan')
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
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{member ? 'Ubah Nomor Perpuluhan' : 'Tambah Nomor Perpuluhan'}</DialogTitle>
        </DialogHeader>
        <form id="tithe-member-form" onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid gap-4 sm:grid-cols-2">
          <FormField label="Nomor" htmlFor="code" error={errors.code?.message}>
            <Input id="code" type="number" min={1} step={1} inputMode="numeric" placeholder="mis. 1" {...register('code')} aria-invalid={!!errors.code} />
          </FormField>
          <FormField label="Alias" htmlFor="alias" error={errors.alias?.message}>
            <Input id="alias" placeholder="mis. SVN" {...register('alias')} aria-invalid={!!errors.alias} />
          </FormField>
          <div className="sm:col-span-2">
            <FormField label="Nama" htmlFor="name" error={errors.name?.message}>
              <Input id="name" {...register('name')} aria-invalid={!!errors.name} />
            </FormField>
          </div>
          <p className="text-sm text-muted-foreground sm:col-span-2">
            Tampil di mutasi/rekap sebagai: <span className="font-mono font-medium text-foreground">{preview}</span>
          </p>
          <label className="flex cursor-pointer items-start gap-2 sm:col-span-2">
            <input type="checkbox" className="mt-0.5 size-4 accent-primary" {...register('is_active')} />
            <span className="grid">
              <span className="text-sm font-medium">Aktif</span>
              <span className="text-xs text-muted-foreground">Muncul sebagai saran nama</span>
            </span>
          </label>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Batal
          </Button>
          <Button type="submit" form="tithe-member-form" disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="animate-spin" />} Simpan
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
