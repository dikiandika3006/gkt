import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, RotateCcw, Save } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { PageHeader } from '@/components/PageHeader'
import { SelectField } from '@/components/SelectField'
import { api, errorMessage } from '@/lib/api'
import { useCashAccounts } from '@/lib/queries'
import type { Module, PermissionLevel, PermissionMatrix } from '@/types'

const LEVEL_LABEL: Record<PermissionLevel, string> = { none: 'Tidak ada', view: 'Lihat', manage: 'Kelola' }

type Draft = Record<string, Record<Module, PermissionLevel>>
type CashDraft = Record<string, number[] | null>

/** Menu Hak Akses (admin): atur Tidak ada / Lihat / Kelola per role × modul. Admin selalu penuh. */
export default function PermissionsPage() {
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['permissions'],
    queryFn: async () => (await api.get<PermissionMatrix>('/permissions')).data,
  })
  // null = belum ada perubahan, pakai data dari server.
  const [edits, setEdits] = useState<Draft | null>(null)
  const draft: Draft = edits ?? ((data?.permissions ?? {}) as Draft)
  const setDraft = (fn: (d: Draft) => Draft) => setEdits(fn(draft))

  // Akses kas di Mutasi: role => daftar kas, null = semua kas (termasuk kas baru).
  const { data: cashAccounts = [] } = useCashAccounts()
  const [cashEdits, setCashEdits] = useState<CashDraft | null>(null)
  const cashDraft: CashDraft = cashEdits ?? data?.cash_accounts ?? {}
  const allIds = cashAccounts.map((c) => c.id)
  const toggleCash = (role: string, id: number, checked: boolean) => {
    const current = cashDraft[role] ?? allIds
    const next = checked ? [...new Set([...current, id])] : current.filter((x) => x !== id)
    setCashEdits({ ...cashDraft, [role]: allIds.every((x) => next.includes(x)) ? null : next.sort((a, b) => a - b) })
  }

  const save = useMutation({
    mutationFn: async () =>
      (await api.put<PermissionMatrix & { message: string }>('/permissions', { permissions: draft, cash_accounts: cashDraft })).data,
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.setQueryData(['permissions'], res)
      setEdits(null)
      setCashEdits(null)
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const dirty =
    !!data && (JSON.stringify(draft) !== JSON.stringify(data.permissions) || JSON.stringify(cashDraft) !== JSON.stringify(data.cash_accounts))

  const resetDefaults = () => {
    if (!data) return
    const next: Draft = {}
    for (const role of data.roles) {
      next[role.value] = Object.fromEntries(data.modules.map((m) => [m.key, m.defaults[role.value]])) as Record<Module, PermissionLevel>
    }
    setEdits(next)
    setCashEdits(Object.fromEntries(data.roles.map((r) => [r.value, null])))
  }

  return (
    <>
      <PageHeader
        title="Hak Akses"
        description="Atur menu & aksi yang boleh dipakai setiap role. Berlaku untuk semua pengguna dengan role tersebut; Administrator selalu punya akses penuh."
        actions={
          <>
            <Button variant="outline" onClick={resetDefaults} disabled={!data}>
              <RotateCcw /> Kembalikan default
            </Button>
            <Button onClick={() => save.mutate()} disabled={!dirty || save.isPending}>
              {save.isPending ? <Loader2 className="animate-spin" /> : <Save />} Simpan
            </Button>
          </>
        }
      />

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Modul</TableHead>
              <TableHead>Administrator</TableHead>
              {data?.roles.map((r) => (
                <TableHead key={r.value} className="min-w-36">
                  {r.label}
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && (
              <TableRow>
                <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                  Memuat...
                </TableCell>
              </TableRow>
            )}
            {data?.modules.map((m) => (
              <TableRow key={m.key}>
                <TableCell className="pl-4">
                  <div className="font-medium">{m.label}</div>
                  <div className="text-xs text-muted-foreground">{m.description}</div>
                </TableCell>
                <TableCell>
                  <Badge variant="secondary">Penuh</Badge>
                </TableCell>
                {data.roles.map((r) => (
                  <TableCell key={r.value}>
                    <SelectField
                      value={draft[r.value]?.[m.key] ?? 'none'}
                      onChange={(v) => setDraft((d) => ({ ...d, [r.value]: { ...d[r.value], [m.key]: v as PermissionLevel } }))}
                      options={m.levels.map((l) => ({ value: l, label: LEVEL_LABEL[l] }))}
                    />
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>

      <Card className="gap-0 py-0">
        <div className="border-b p-4">
          <h2 className="font-semibold">Akses Kas di Mutasi</h2>
          <p className="text-sm text-muted-foreground">
            Kas/rekening yang boleh dilihat & dipakai tiap role di menu Mutasi (termasuk Import CSV dan Mutasi Antar Kas). Semua dicentang = semua kas,
            termasuk kas baru. Dashboard & Laporan tidak terpengaruh.
          </p>
        </div>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Kas / Rekening</TableHead>
              <TableHead>Administrator</TableHead>
              {data?.roles.map((r) => (
                <TableHead key={r.value} className="min-w-36">
                  {r.label}
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {cashAccounts.map((c) => (
              <TableRow key={c.id}>
                <TableCell className="pl-4">
                  <div className="font-medium">{c.name}</div>
                  <div className="text-xs text-muted-foreground">
                    {c.type_label}
                    {!c.is_active && ' · nonaktif'}
                  </div>
                </TableCell>
                <TableCell>
                  <Badge variant="secondary">Semua</Badge>
                </TableCell>
                {data?.roles.map((r) => {
                  const ids = cashDraft[r.value]
                  const checked = ids === null || ids === undefined || ids.includes(c.id)
                  // Minimal satu kas per role.
                  const onlyOne = checked && (ids?.length === 1 || allIds.length === 1)
                  return (
                    <TableCell key={r.value}>
                      <input
                        type="checkbox"
                        className="size-4 accent-primary"
                        aria-label={`${r.label} boleh memakai ${c.name}`}
                        checked={checked}
                        disabled={onlyOne}
                        onChange={(e) => toggleCash(r.value, c.id, e.target.checked)}
                      />
                    </TableCell>
                  )
                })}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>

      <p className="text-xs text-muted-foreground">
        <strong>Lihat</strong> = boleh membuka menu & data. <strong>Kelola</strong> = juga boleh menambah, mengubah, menghapus. Pengguna yang sedang login
        melihat perubahan setelah memuat ulang halaman.
      </p>
    </>
  )
}
