import { useState } from 'react'
import { Link } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { History, Loader2, Save } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { PageHeader } from '@/components/PageHeader'
import { SelectField } from '@/components/SelectField'
import { api, errorMessage } from '@/lib/api'
import { useCashAccounts } from '@/lib/queries'
import type { AppSettings } from '@/types'

const ALL = 'all'

/** Menu Setting (admin): pengaturan umum aplikasi. Riwayat perubahan tercatat di Log Aktivitas. */
export default function SettingsPage() {
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: async () => (await api.get<AppSettings>('/settings')).data,
  })
  const { data: accounts = [] } = useCashAccounts()
  // null = belum ada perubahan, pakai data dari server.
  const [edit, setEdit] = useState<string | null>(null)
  const saved = data?.dashboard_cash_account_id ? String(data.dashboard_cash_account_id) : ALL
  const value = edit ?? saved

  const save = useMutation({
    mutationFn: async () =>
      (await api.put<AppSettings>('/settings', { dashboard_cash_account_id: value === ALL ? null : Number(value) })).data,
    onSuccess: (res) => {
      toast.success('Setting disimpan.')
      queryClient.setQueryData(['settings'], res)
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      setEdit(null)
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const options = [
    { value: ALL, label: 'Semua kas & bank' },
    ...accounts.map((a) => ({ value: String(a.id), label: `${a.name} · ${a.type_label}${a.is_active ? '' : ' (nonaktif)'}` })),
  ]

  return (
    <>
      <PageHeader
        title="Setting"
        description="Pengaturan umum aplikasi. Berlaku untuk semua pengguna."
        actions={
          <>
            <Button variant="outline" render={<Link to="/activity-logs" />}>
              <History /> Riwayat perubahan
            </Button>
            <Button onClick={() => save.mutate()} disabled={!data || value === saved || save.isPending}>
              {save.isPending ? <Loader2 className="animate-spin" /> : <Save />} Simpan
            </Button>
          </>
        }
      />

      <Card className="max-w-2xl">
        <CardHeader>
          <CardTitle>Dashboard</CardTitle>
          <CardDescription>Tampilan kartu saldo di halaman Dashboard.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-2">
          <Label htmlFor="dashboard-account">Rekening di Dashboard</Label>
          {isLoading ? (
            <Skeleton className="h-9" />
          ) : (
            <SelectField id="dashboard-account" value={value} onChange={(v) => setEdit(v || ALL)} options={options} />
          )}
          <p className="text-xs text-muted-foreground">
            <b>Semua</b>: Total Saldo dan Saldo per Kas menampilkan seluruh kas & bank. Pilih satu kas/bank agar hanya saldo kas itu yang tampil.
            Pemasukan, pengeluaran, grafik, dan Ketahanan Kas tetap dihitung dari semua kas.
          </p>
        </CardContent>
      </Card>
    </>
  )
}
