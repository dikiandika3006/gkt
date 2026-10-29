import { useState } from 'react'
import { useNavigate } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { PageHeader } from '@/components/PageHeader'
import { Pagination } from '@/components/Pagination'
import { SelectField } from '@/components/SelectField'
import { useAuth } from '@/features/auth/AuthContext'
import { useClientPagination } from '@/hooks/use-client-pagination'
import { api } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import { useCashAccounts, useFundAccountSummary } from '@/lib/queries'
import type { FundRequest } from '@/types'
import { FundRequestFormDialog } from './FundRequestFormDialog'
import { StatusBadge } from './shared'
import { signedClass } from './utils'

export default function FundRequestsPage() {
  const canManageFunds = useAuth().can('fund_requests', 'manage')
  const navigate = useNavigate()
  const { data: cashAccounts = [] } = useCashAccounts()
  const [selected, setSelected] = useState('')
  const [year, setYear] = useState(() => String(new Date().getFullYear()))
  const [formOpen, setFormOpen] = useState(false)
  const [years] = useState(() => Array.from({ length: 5 }, (_, i) => String(new Date().getFullYear() + 1 - i)))

  // Default: Kas Sekretariat (kode KAS-SEK), jika tidak ada pakai kas tunai pertama.
  const fallback = cashAccounts.find((c) => c.code === 'KAS-SEK') ?? cashAccounts.find((c) => c.type === 'cash')
  const accountId = selected || (fallback ? String(fallback.id) : '')
  const account = cashAccounts.find((c) => String(c.id) === accountId)

  const requests = useQuery({
    queryKey: ['fund-requests', accountId, year],
    queryFn: async () => (await api.get<{ data: FundRequest[] }>('/fund-requests', { params: { cash_account_id: accountId, year } })).data.data,
    enabled: !!accountId,
  })
  const summary = useFundAccountSummary(accountId)
  const balance = summary.data?.find((a) => String(a.id) === accountId)?.balance
  const pager = useClientPagination(requests.data ?? [])

  return (
    <>
      <PageHeader
        title="Kas Sekretariat"
        description="Pengajuan dana operasional → transfer → LPJ per nota. Sisa/kekurangan otomatis dibawa ke pengajuan berikutnya."
        actions={
          canManageFunds &&
          accountId && (
            <Button onClick={() => setFormOpen(true)}>
              <Plus /> Buat Pengajuan
            </Button>
          )
        }
      />

      <div className="grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader className="pb-1">
            <CardDescription>Saldo {account?.name ?? 'kas'} saat ini</CardDescription>
            <CardTitle className={`text-2xl tabular-nums ${signedClass(balance ?? 0)}`}>{formatRupiah(balance ?? 0)}</CardTitle>
          </CardHeader>
          <CardContent className="text-xs text-muted-foreground">
            {(balance ?? 0) >= 0 ? 'Uang yang masih dipegang (belum di-LPJ-kan).' : 'Kas menalangi: pengeluaran nota melebihi dana yang ditransfer.'}
          </CardContent>
        </Card>
        <Card className="md:col-span-2">
          <CardContent className="flex flex-wrap items-end gap-3">
            <div className="grid min-w-56 gap-1">
              <span className="text-xs text-muted-foreground">Kas</span>
              <SelectField
                value={accountId}
                onChange={setSelected}
                options={cashAccounts.filter((c) => c.type === 'cash').map((c) => ({ value: String(c.id), label: c.name }))}
              />
            </div>
            <div className="grid w-32 gap-1">
              <span className="text-xs text-muted-foreground">Tahun</span>
              <SelectField value={year} onChange={setYear} options={years.map((y) => ({ value: y, label: y }))} />
            </div>
          </CardContent>
        </Card>
      </div>

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Periode</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">Sisa Dibawa</TableHead>
              <TableHead className="text-right">Pengajuan</TableHead>
              <TableHead className="text-right">Ditransfer</TableHead>
              <TableHead className="text-right">Realisasi LPJ</TableHead>
              <TableHead className="pr-4 text-right">Saldo Akhir</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {requests.isLoading && (
              <TableRow>
                <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                  Memuat...
                </TableCell>
              </TableRow>
            )}
            {requests.data?.length === 0 && (
              <TableRow>
                <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                  Belum ada pengajuan di tahun ini.
                </TableCell>
              </TableRow>
            )}
            {pager.rows.map((r) => (
              <TableRow key={r.id} className="cursor-pointer" onClick={() => navigate(`/fund-requests/${r.id}`)}>
                <TableCell className="pl-4">
                  <div className="font-medium">{formatDate(`${r.period}-01`, 'MMMM yyyy')}</div>
                  <div className="font-mono text-xs text-muted-foreground">{r.number}</div>
                </TableCell>
                <TableCell>
                  <StatusBadge status={r.summary.status} label={r.summary.status_label} />
                </TableCell>
                <TableCell className={`text-right tabular-nums ${signedClass(r.summary.carry_over)}`}>{formatRupiah(r.summary.carry_over)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(r.summary.requested)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(r.summary.transferred)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(r.summary.reported)}</TableCell>
                <TableCell className={`pr-4 text-right font-medium tabular-nums ${signedClass(r.summary.closing_balance)}`}>
                  {formatRupiah(r.summary.closing_balance)}
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
            itemLabel="pengajuan"
          />
        )}
      </Card>

      {accountId && (
        <FundRequestFormDialog
          open={formOpen}
          onOpenChange={setFormOpen}
          cashAccountId={Number(accountId)}
          onSaved={(r) => navigate(`/fund-requests/${r.id}`)}
        />
      )}
    </>
  )
}
