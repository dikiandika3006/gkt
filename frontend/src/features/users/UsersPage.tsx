import { useEffect, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Pencil, Plus, Trash2 } from 'lucide-react'
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
import type { User } from '@/types'

const ROLE_OPTIONS = [
  { value: 'admin', label: 'Administrator' },
  { value: 'bendahara', label: 'Bendahara' },
  { value: 'sekretariat', label: 'Sekretariat (pengajuan & LPJ)' },
  { value: 'viewer', label: 'Viewer (hanya lihat)' },
]

const schema = z.object({
  name: z.string().trim().min(1, 'Nama wajib diisi'),
  username: z
    .string()
    .trim()
    .min(1, 'Username wajib diisi')
    .max(50, 'Maksimal 50 karakter')
    .regex(/^[A-Za-z0-9_-]+$/, 'Hanya huruf, angka, - dan _'),
  email: z.union([z.email('Email tidak valid'), z.literal('')]),
  password: z.string().optional(),
  role: z.enum(['admin', 'bendahara', 'sekretariat', 'viewer']),
  is_active: z.boolean(),
})
type FormValues = z.infer<typeof schema>

export default function UsersPage() {
  const { user: me } = useAuth()
  const queryClient = useQueryClient()
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<User | null>(null)
  const [deleting, setDeleting] = useState<User | null>(null)

  const { data: users = [], isLoading } = useQuery({
    queryKey: ['users'],
    queryFn: async () => (await api.get<{ data: User[] }>('/users')).data.data,
  })
  const pager = useClientPagination(users)

  const remove = useMutation({
    mutationFn: (u: User) => api.delete(`/users/${u.id}`),
    onSuccess: () => {
      toast.success('Pengguna dihapus')
      queryClient.invalidateQueries({ queryKey: ['users'] })
      setDeleting(null)
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  return (
    <>
      <PageHeader
        title="Pengguna"
        description="Akun yang dapat mengakses aplikasi"
        actions={
          <Button
            onClick={() => {
              setEditing(null)
              setFormOpen(true)
            }}
          >
            <Plus /> Tambah Pengguna
          </Button>
        }
      />

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Nama</TableHead>
              <TableHead>Username</TableHead>
              <TableHead>Email</TableHead>
              <TableHead>Role</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="w-20 pr-4" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && (
              <TableRow>
                <TableCell colSpan={6}className="py-8 text-center text-muted-foreground">
                  Memuat...
                </TableCell>
              </TableRow>
            )}
            {pager.rows.map((u) => (
              <TableRow key={u.id}>
                <TableCell className="pl-4 font-medium">{u.name}</TableCell>
                <TableCell>{u.username}</TableCell>
                <TableCell className="text-muted-foreground">{u.email ?? '-'}</TableCell>
                <TableCell>{u.role_label}</TableCell>
                <TableCell>
                  <Badge variant={u.is_active ? 'secondary' : 'outline'}>{u.is_active ? 'Aktif' : 'Nonaktif'}</Badge>
                </TableCell>
                <TableCell className="pr-4">
                  <div className="flex justify-end gap-1">
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      aria-label="Ubah"
                      onClick={() => {
                        setEditing(u)
                        setFormOpen(true)
                      }}
                    >
                      <Pencil />
                    </Button>
                    {u.id !== me?.id && (
                      <Button size="icon-sm" variant="ghost" aria-label="Hapus" onClick={() => setDeleting(u)}>
                        <Trash2 className="text-destructive" />
                      </Button>
                    )}
                  </div>
                </TableCell>
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
            itemLabel="pengguna"
          />
        )}
      </Card>

      <UserFormDialog open={formOpen} onOpenChange={setFormOpen} user={editing} />
      <ConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Hapus pengguna?"
        description={`Akun ${deleting?.username ?? ''} akan dihapus.`}
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting)}
      />
    </>
  )
}

function UserFormDialog({ open, onOpenChange, user }: { open: boolean; onOpenChange: (o: boolean) => void; user: User | null }) {
  const queryClient = useQueryClient()
  const { register, control, handleSubmit, reset, setError, formState } = useForm<FormValues>({ resolver: zodResolver(schema) })
  const errors = formState.errors

  useEffect(() => {
    if (!open) return
    reset(
      user
        ? { name: user.name, username: user.username, email: user.email ?? '', password: '', role: user.role, is_active: user.is_active }
        : { name: '', username: '', email: '', password: '', role: 'bendahara', is_active: true },
    )
  }, [open, user, reset])

  const mutation = useMutation({
    mutationFn: (values: FormValues) => (user ? api.put(`/users/${user.id}`, values) : api.post('/users', values)),
    onSuccess: () => {
      toast.success('Pengguna disimpan')
      queryClient.invalidateQueries({ queryKey: ['users'] })
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
          <DialogTitle>{user ? 'Ubah Pengguna' : 'Tambah Pengguna'}</DialogTitle>
        </DialogHeader>
        <form id="user-form" onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid gap-4">
          <FormField label="Nama" htmlFor="name" error={errors.name?.message}>
            <Input id="name" {...register('name')} aria-invalid={!!errors.name} />
          </FormField>
          <FormField label="Username" htmlFor="username" error={errors.username?.message}>
            <Input id="username" autoCapitalize="none" autoComplete="off" {...register('username')} aria-invalid={!!errors.username} />
          </FormField>
          <FormField label="Email (opsional)" htmlFor="email" error={errors.email?.message}>
            <Input id="email" type="email" {...register('email')} aria-invalid={!!errors.email} />
          </FormField>
          <FormField label={user ? 'Password (kosongkan jika tidak diubah)' : 'Password'} htmlFor="password" error={errors.password?.message}>
            <Input id="password" type="password" autoComplete="new-password" {...register('password')} aria-invalid={!!errors.password} />
          </FormField>
          <FormField label="Role" error={errors.role?.message}>
            <Controller
              control={control}
              name="role"
              render={({ field }) => <SelectField value={field.value ?? ''} onChange={field.onChange} options={ROLE_OPTIONS} />}
            />
          </FormField>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" className="size-4 accent-primary" {...register('is_active')} /> Aktif
          </label>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Batal
          </Button>
          <Button type="submit" form="user-form" disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="animate-spin" />}
            Simpan
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
