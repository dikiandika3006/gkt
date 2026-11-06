import { useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { History, Loader2, Save } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { PageHeader } from '@/components/PageHeader'
import { SelectField } from '@/components/SelectField'
import { api, errorMessage } from '@/lib/api'
import { useCashAccounts } from '@/lib/queries'
import type { AppSettings } from '@/types'

const NONE = 'none'

type Key = keyof AppSettings
type Draft = Record<Key, string>

/** Setting teks (kop & penandatangan laporan); sisanya berupa id kas. */
const TEXT_KEYS: Key[] = ['report_church_name', 'report_signer_left_title', 'report_signer_left_name', 'report_signer_right_title', 'report_signer_right_name']
const isText = (k: string) => TEXT_KEYS.includes(k as Key)

const toDraft = (s: AppSettings): Draft =>
  Object.fromEntries(Object.entries(s).map(([k, v]) => [k, isText(k) ? String(v ?? '') : v ? String(v) : NONE])) as Draft

/** Menu Setting (admin): pengaturan umum aplikasi. Riwayat perubahan tercatat di Log Aktivitas. */
export default function SettingsPage() {
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: async () => (await api.get<AppSettings>('/settings')).data,
  })
  const { data: accounts = [] } = useCashAccounts()
  // null = belum ada perubahan, pakai data dari server.
  const [edits, setEdits] = useState<Draft | null>(null)
  const saved = data ? toDraft(data) : null
  const draft = edits ?? saved
  const dirty = !!draft && !!saved && JSON.stringify(draft) !== JSON.stringify(saved)

  const save = useMutation({
    mutationFn: async () => {
      const payload = Object.fromEntries(
        Object.entries(draft ?? {}).map(([k, v]) => [k, isText(k) ? v.trim() || null : v === NONE ? null : Number(v)]),
      )
      return (await api.put<AppSettings>('/settings', payload)).data
    },
    onSuccess: (res) => {
      toast.success('Setting disimpan.')
      queryClient.setQueryData(['settings'], res)
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['reports'] })
      setEdits(null)
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const accountOptions = (noneLabel: string) => [
    { value: NONE, label: noneLabel },
    ...accounts.map((a) => ({ value: String(a.id), label: `${a.name} · ${a.type_label}${a.is_active ? '' : ' (nonaktif)'}` })),
  ]

  const field = (key: Key, label: string, noneLabel: string, help: ReactNode) => (
    <div className="grid gap-2">
      <Label htmlFor={key}>{label}</Label>
      {isLoading || !draft ? (
        <Skeleton className="h-9" />
      ) : (
        <SelectField id={key} value={draft[key]} onChange={(v) => setEdits({ ...draft, [key]: v || NONE })} options={accountOptions(noneLabel)} />
      )}
      <p className="text-xs text-muted-foreground">{help}</p>
    </div>
  )

  const textField = (key: Key, label: string, placeholder: string) => (
    <div className="grid gap-2">
      <Label htmlFor={key}>{label}</Label>
      {isLoading || !draft ? (
        <Skeleton className="h-9" />
      ) : (
        <Input id={key} value={draft[key]} placeholder={placeholder} maxLength={100} onChange={(e) => setEdits({ ...draft, [key]: e.target.value })} />
      )}
    </div>
  )

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
            <Button onClick={() => save.mutate()} disabled={!dirty || save.isPending}>
              {save.isPending ? <Loader2 className="animate-spin" /> : <Save />} Simpan
            </Button>
          </>
        }
      />

      <div className="grid max-w-2xl gap-4">
        <Card>
          <CardHeader>
            <CardTitle>Dashboard</CardTitle>
            <CardDescription>Tampilan kartu saldo di halaman Dashboard.</CardDescription>
          </CardHeader>
          <CardContent>
            {field(
              'dashboard_cash_account_id',
              'Rekening di Dashboard',
              'Semua kas & bank',
              <>
                <b>Semua</b>: Total Saldo dan Saldo per Kas menampilkan seluruh kas & bank. Pilih satu kas/bank agar hanya saldo kas itu yang tampil.
                Pemasukan, pengeluaran, grafik, dan Ketahanan Kas tetap dihitung dari semua kas.
              </>,
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Mutasi</CardTitle>
            <CardDescription>Kas yang langsung terpilih saat membuka menu Mutasi. Pengguna tetap bisa memilih kas lain yang boleh diakses.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4">
            {field(
              'mutation_cash_account_id',
              'Kas default',
              'Otomatis (kas pertama yang boleh diakses)',
              'Mis. Rekening Utama. Dipakai bila role pengguna punya akses ke kas ini (menu Hak Akses → Akses Kas di Mutasi).',
            )}
            {field(
              'mutation_fallback_cash_account_id',
              'Kas default bila tidak punya akses',
              'Otomatis (kas pertama yang boleh diakses)',
              'Mis. Kas Sekretariat. Dipakai bila pengguna tidak punya akses ke kas default di atas.',
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Laporan Cetak</CardTitle>
            <CardDescription>Kop dan kolom tanda tangan di Laporan Bulanan (dan laporan cetak lain). Kosongkan nama bila ingin ditulis tangan.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">{textField('report_church_name', 'Nama gereja di kop', 'GKT Sinai')}</div>
            {textField('report_signer_left_title', 'Penandatangan kiri — jabatan', 'Bendahara')}
            {textField('report_signer_left_name', 'Penandatangan kiri — nama', 'Nama bendahara')}
            {textField('report_signer_right_title', 'Penandatangan kanan — jabatan', 'Ketua Majelis')}
            {textField('report_signer_right_name', 'Penandatangan kanan — nama', 'Nama ketua majelis')}
          </CardContent>
        </Card>
      </div>
    </>
  )
}
