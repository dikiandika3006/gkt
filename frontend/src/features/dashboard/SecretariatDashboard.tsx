import { Link } from 'react-router'
import { ArrowRight, ClipboardList, FileWarning, Wallet } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { PageHeader } from '@/components/PageHeader'
import { useAuth } from '@/features/auth/AuthContext'
import { signedClass } from '@/features/fund-requests/utils'
import { formatRupiah } from '@/lib/format'
import { useFundAccountSummary } from '@/lib/queries'

/** Dashboard untuk role tanpa akses keuangan gereja (mis. Sekretariat): hanya kas pengajuan dana. */
export function SecretariatDashboard() {
  const { user, can } = useAuth()

  if (!can('fund_requests')) {
    return (
      <>
        <PageHeader title="Dashboard" description={`Selamat datang, ${user?.name ?? ''}`} />
        <Card>
          <CardContent className="text-sm text-muted-foreground">
            Akun Anda belum memiliki akses ke modul apa pun. Hubungi administrator untuk mengatur Hak Akses.
          </CardContent>
        </Card>
      </>
    )
  }

  return <FundSummary />
}

function FundSummary() {
  const { user } = useAuth()
  const { data: accounts = [], isLoading } = useFundAccountSummary()

  return (
    <>
      <PageHeader
        title="Dashboard"
        description={`Selamat datang, ${user?.name ?? ''} · Ringkasan Kas Sekretariat`}
        actions={
          <Button render={<Link to="/fund-requests" />}>
            <ClipboardList /> Buka Kas Sekretariat
          </Button>
        }
      />

      {isLoading && <Skeleton className="h-32 rounded-xl" />}
      {!isLoading && accounts.length === 0 && (
        <Card>
          <CardContent className="text-sm text-muted-foreground">Belum ada pengajuan dana. Mulai dari menu Kas Sekretariat.</CardContent>
        </Card>
      )}

      {accounts.map((a) => (
        <div key={a.id} className="grid gap-4 md:grid-cols-3">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-1">
              <CardDescription>Saldo {a.name}</CardDescription>
              <Wallet className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className={`text-2xl font-semibold tabular-nums ${signedClass(a.balance)}`}>{formatRupiah(a.balance)}</div>
              <p className="text-xs text-muted-foreground">
                {a.balance >= 0 ? 'Uang yang masih dipegang (belum di-LPJ-kan).' : 'Kas menalangi: nota melebihi dana yang ditransfer.'}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-1">
              <CardDescription>Pengajuan aktif</CardDescription>
              <ClipboardList className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-semibold tabular-nums">{a.active_requests}</div>
              {a.latest ? (
                <Link to={`/fund-requests/${a.latest.id}`} className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                  Terakhir: {a.latest.number} ({a.latest.status_label}) <ArrowRight className="size-3" />
                </Link>
              ) : (
                <p className="text-xs text-muted-foreground">Belum ada pengajuan.</p>
              )}
            </CardContent>
          </Card>
          <Card className={a.pending_reports > 0 ? 'border-warning/50' : ''}>
            <CardHeader className="flex flex-row items-center justify-between pb-1">
              <CardDescription>LPJ belum selesai</CardDescription>
              <FileWarning className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-semibold tabular-nums">{a.pending_reports}</div>
              <p className="text-xs text-muted-foreground">
                {a.pending_reports > 0 ? 'Dana sudah ditransfer, nota belum lengkap dilaporkan.' : 'Semua dana yang ditransfer sudah di-LPJ-kan.'}
              </p>
            </CardContent>
          </Card>
        </div>
      ))}
    </>
  )
}
