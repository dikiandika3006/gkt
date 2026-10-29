import { useState } from 'react'
import { Link } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeft, Loader2, Plus, Save, Trash2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { PageHeader } from '@/components/PageHeader'
import { useAuth } from '@/features/auth/AuthContext'
import { api, errorMessage } from '@/lib/api'
import { useCashAccounts } from '@/lib/queries'
import type { OfferingFundSetting, OfferingSettings } from '@/types'

const WEEKS = [1, 2, 3, 4, 5] as const
const IN_SYSTEM = 'system'

/** Rujukan pos untuk jadwal: id untuk pos lama, key sementara untuk pos baru. */
const refOf = (f: OfferingFundSetting) => String(f.id ?? f.key)

/** Master Pos Persembahan: rekening tiap pos, jadwal Minggu ke-1..5, daftar kebaktian. */
export default function OfferingSettingsPage() {
  const { data, dataUpdatedAt } = useQuery({
    queryKey: ['offering-settings'],
    queryFn: async () => (await api.get<OfferingSettings>('/offering-settings')).data,
  })

  if (!data) return <p className="text-muted-foreground">Memuat...</p>

  // Editor dibuat ulang setiap data server berubah (mis. setelah simpan).
  return <SettingsEditor key={dataUpdatedAt} initial={data} />
}

function SettingsEditor({ initial }: { initial: OfferingSettings }) {
  const queryClient = useQueryClient()
  const canManage = useAuth().can('offerings', 'manage')
  const { data: cashAccounts = [] } = useCashAccounts({ activeOnly: true })
  const [funds, setFunds] = useState<OfferingFundSetting[]>(initial.funds)
  const [schedule, setSchedule] = useState<Record<string, string>>(() =>
    Object.fromEntries(WEEKS.map((w) => [String(w), initial.schedule[String(w)] ? String(initial.schedule[String(w)]) : ''])),
  )
  const [services, setServices] = useState<string[]>(initial.services)
  const [errors, setErrors] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: async () =>
      (
        await api.put<OfferingSettings>('/offering-settings', {
          funds: funds.map((f) => ({ ...f, name: f.name.trim() })),
          schedule: Object.fromEntries(WEEKS.map((w) => [w, schedule[String(w)] || null])),
          services: services.map((s) => s.trim()).filter(Boolean),
        })
      ).data,
    onSuccess: (fresh) => {
      toast.success('Pengaturan pos persembahan disimpan')
      setErrors(null)
      queryClient.setQueryData(['offering-settings'], fresh)
      queryClient.invalidateQueries({ queryKey: ['offering-reports', 'template'] })
    },
    onError: (err) => {
      setErrors(errorMessage(err))
      toast.error(errorMessage(err))
    },
  })

  const update = (i: number, patch: Partial<OfferingFundSetting>) => setFunds((all) => all.map((f, j) => (j === i ? { ...f, ...patch } : f)))
  const removeFund = (i: number) => {
    const ref = refOf(funds[i])
    setFunds((all) => all.filter((_, j) => j !== i))
    setSchedule((s) => Object.fromEntries(Object.entries(s).map(([w, v]) => [w, v === ref ? '' : v])))
  }

  return (
    <>
      <PageHeader
        title="Pos Persembahan"
        description="Kantong khusus tiap ibadah ditentukan dari hari Minggu ke-berapa di bulan itu (tgl 1–7 = Minggu 1, 8–14 = Minggu 2, dst.)."
        actions={
          <Button variant="outline" render={<Link to="/offerings" />}>
            <ArrowLeft /> Rekap Persembahan
          </Button>
        }
      />

      <div className="grid items-start gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle className="text-base">Daftar Pos & Rekening</CardTitle>
            <CardDescription>Persembahan Syukur, QRIS, Syukur per nama & Perpuluhan selalu ke Rekening Utama — tidak perlu diatur di sini.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-3">
            {funds.map((f, i) => {
              const inSystem = f.cash_account_id !== null
              return (
                <div key={refOf(f)} className="grid gap-2 rounded-lg border p-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <Input className="w-44 font-medium" value={f.name} disabled={!canManage} onChange={(e) => update(i, { name: e.target.value })} aria-label="Nama pos" />
                    <select
                      className="h-9 rounded-md border bg-background px-2 text-sm"
                      disabled={!canManage}
                      value={inSystem ? String(f.cash_account_id) : IN_SYSTEM}
                      onChange={(e) => update(i, { cash_account_id: e.target.value === IN_SYSTEM ? null : Number(e.target.value) })}
                      aria-label="Rekening tujuan"
                    >
                      {cashAccounts.map((c) => (
                        <option key={c.id} value={String(c.id)}>
                          {c.name}
                          {c.account_number ? ` · ${c.bank_name ?? ''} ${c.account_number}` : ''}
                        </option>
                      ))}
                      <option value={IN_SYSTEM}>Rekening lain (isi no rek)</option>
                    </select>
                    <label className="flex items-center gap-1.5 text-sm">
                      <input type="checkbox" className="size-4 accent-primary" checked={f.is_active} disabled={!canManage} onChange={(e) => update(i, { is_active: e.target.checked })} /> Aktif
                    </label>
                    {f.used && <Badge variant="outline">Sudah dipakai</Badge>}
                    {canManage && !f.used && (
                      <Button type="button" size="icon-sm" variant="ghost" className="ml-auto" aria-label={`Hapus ${f.name}`} onClick={() => removeFund(i)}>
                        <Trash2 className="text-destructive" />
                      </Button>
                    )}
                  </div>
                  {!inSystem && (
                    <div className="grid gap-2 sm:grid-cols-3">
                      <Input placeholder="Bank (mis. BRI)" value={f.bank_name ?? ''} disabled={!canManage} onChange={(e) => update(i, { bank_name: e.target.value })} aria-label="Bank" />
                      <Input placeholder="No rekening" value={f.account_number ?? ''} disabled={!canManage} onChange={(e) => update(i, { account_number: e.target.value })} aria-label="No rekening" />
                      <Input placeholder="Atas nama" value={f.account_holder ?? ''} disabled={!canManage} onChange={(e) => update(i, { account_holder: e.target.value })} aria-label="Atas nama" />
                    </div>
                  )}
                </div>
              )
            })}
            {canManage && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="justify-self-start"
                onClick={() =>
                  setFunds((all) => [
                    ...all,
                    { key: `baru-${Date.now()}`, name: '', cash_account_id: null, bank_name: '', account_number: '', account_holder: '', is_active: true },
                  ])
                }
              >
                <Plus /> Tambah pos
              </Button>
            )}
          </CardContent>
        </Card>

        <div className="grid gap-4">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Jadwal per Minggu</CardTitle>
              <CardDescription>Pos kantong khusus untuk hari Minggu ke-N.</CardDescription>
            </CardHeader>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-4">Minggu</TableHead>
                  <TableHead className="pr-4">Pos</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {WEEKS.map((w) => (
                  <TableRow key={w}>
                    <TableCell className="pl-4 font-medium">
                      Minggu {w}
                      <div className="text-xs font-normal text-muted-foreground">
                        tgl {(w - 1) * 7 + 1}–{w === 5 ? 31 : w * 7}
                      </div>
                    </TableCell>
                    <TableCell className="pr-4">
                      <select
                        className="h-9 w-full rounded-md border bg-background px-2 text-sm"
                        disabled={!canManage}
                        value={schedule[String(w)] ?? ''}
                        onChange={(e) => setSchedule((s) => ({ ...s, [String(w)]: e.target.value }))}
                        aria-label={`Pos Minggu ${w}`}
                      >
                        <option value="">— Tidak ada —</option>
                        {funds
                          .filter((f) => f.name.trim())
                          .map((f) => (
                            <option key={refOf(f)} value={refOf(f)}>
                              {f.name}
                            </option>
                          ))}
                      </select>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Kebaktian</CardTitle>
              <CardDescription>Baris Persembahan Ibadah dibuat per kebaktian (mis. Persembahan Syukur KU I, Diakonia KU I).</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-2">
              {services.map((s, i) => (
                <div key={i} className="flex items-center gap-2">
                  <Input value={s} disabled={!canManage} onChange={(e) => setServices((all) => all.map((x, j) => (j === i ? e.target.value : x)))} aria-label={`Kebaktian ${i + 1}`} />
                  {canManage && services.length > 1 && (
                    <Button type="button" size="icon-sm" variant="ghost" aria-label={`Hapus ${s}`} onClick={() => setServices((all) => all.filter((_, j) => j !== i))}>
                      <Trash2 className="text-destructive" />
                    </Button>
                  )}
                </div>
              ))}
              {canManage && (
                <Button type="button" variant="outline" size="sm" className="justify-self-start" onClick={() => setServices((all) => [...all, ''])}>
                  <Plus /> Tambah kebaktian
                </Button>
              )}
            </CardContent>
          </Card>
        </div>
      </div>

      {canManage && (
        <div className="flex items-center justify-end gap-3">
          {errors && <p className="text-sm text-destructive">{errors}</p>}
          <Button size="lg" disabled={save.isPending} onClick={() => save.mutate()}>
            {save.isPending ? <Loader2 className="animate-spin" /> : <Save />} Simpan Pengaturan
          </Button>
        </div>
      )}
    </>
  )
}
