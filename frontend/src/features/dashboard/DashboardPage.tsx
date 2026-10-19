import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { ArrowDownRight, ArrowUpRight, CalendarCheck, Landmark, Scale, ShieldCheck } from 'lucide-react'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Badge } from '@/components/ui/badge'
import { PageHeader } from '@/components/PageHeader'
import { api } from '@/lib/api'
import { formatCompact, formatDate, formatRupiah } from '@/lib/format'
import type { DashboardData } from '@/types'

// Slot kategorikal 1 & 2 dari palette dataviz tervalidasi (lolos uji CVD).
const SERIES_COLORS = { income: '#2a78d6', expense: '#eb6834' }

export default function DashboardPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => (await api.get<DashboardData>('/dashboard')).data,
  })

  const stats = data
    ? [
        { label: 'Total Saldo', value: data.total_balance, icon: Landmark, hint: 'Semua kas & rekening' },
        { label: 'Pemasukan Operasional Bulan Ini', value: data.month.income, icon: ArrowUpRight, hint: formatDate(data.period.start_date, 'MMMM yyyy') },
        { label: 'Pengeluaran Operasional Bulan Ini', value: data.month.expense, icon: ArrowDownRight, hint: formatDate(data.period.start_date, 'MMMM yyyy') },
        { label: 'Selisih Bulan Ini', value: data.month.net, icon: Scale, hint: data.month.net >= 0 ? 'Surplus' : 'Defisit' },
      ]
    : []

  const trend = data?.trend.map((t) => ({ ...t, label: formatDate(`${t.month}-01`, 'MMM yy') })) ?? []

  return (
    <>
      <PageHeader title="Dashboard" description="Ringkasan kondisi keuangan gereja" />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {isLoading
          ? Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-28 rounded-xl" />)
          : stats.map((s) => (
              <Card key={s.label}>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardDescription>{s.label}</CardDescription>
                  <s.icon className="size-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-semibold tabular-nums">{formatRupiah(s.value)}</div>
                  <p className="text-xs text-muted-foreground">{s.hint}</p>
                </CardContent>
              </Card>
            ))}
      </div>

      {data && (
        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardDescription>Ketahanan Kas</CardDescription>
              <ShieldCheck className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-semibold tabular-nums">
                {data.resilience.months === null ? '-' : `${data.resilience.months.toLocaleString('id-ID')} bulan`}
              </div>
              <p className="text-xs text-muted-foreground">
                Saldo cukup untuk sekian bulan pengeluaran rutin (rata-rata {formatRupiah(data.resilience.average_expense)}/bulan, 6 bulan terakhir)
              </p>
            </CardContent>
          </Card>
          <Card className={data.recurring.shortfall > 0 ? 'border-destructive/50' : ''}>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardDescription>Sisa Biaya Rutin Bulan Ini</CardDescription>
              <CalendarCheck className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-semibold tabular-nums">{formatRupiah(data.recurring.remaining)}</div>
              <p className={`text-xs ${data.recurring.shortfall > 0 ? 'text-destructive' : 'text-muted-foreground'}`}>
                {data.recurring.shortfall > 0
                  ? `Saldo rekening kurang ${formatRupiah(data.recurring.shortfall)}`
                  : `Saldo rekening ${formatRupiah(data.recurring.bank_balance)} — cukup`}{' '}
                ·{' '}
                <Link to="/recurring" className="underline">
                  lihat rincian
                </Link>
              </p>
            </CardContent>
          </Card>
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Pemasukan vs Pengeluaran</CardTitle>
            <CardDescription>12 bulan terakhir</CardDescription>
          </CardHeader>
          <CardContent className="h-80">
            {isLoading ? (
              <Skeleton className="h-full" />
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={trend} barGap={2} margin={{ left: 8, right: 8 }}>
                  <CartesianGrid vertical={false} stroke="var(--border)" />
                  <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={12} stroke="var(--muted-foreground)" />
                  <YAxis tickFormatter={formatCompact} tickLine={false} axisLine={false} fontSize={12} width={48} stroke="var(--muted-foreground)" />
                  <Tooltip
                    cursor={{ fill: 'var(--muted)' }}
                    formatter={(v) => formatRupiah(Number(v))}
                    contentStyle={{ borderRadius: 8, borderColor: 'var(--border)', fontSize: 12 }}
                  />
                  <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
                  <Bar dataKey="income" name="Pemasukan" fill={SERIES_COLORS.income} radius={[4, 4, 0, 0]} maxBarSize={18} />
                  <Bar dataKey="expense" name="Pengeluaran" fill={SERIES_COLORS.expense} radius={[4, 4, 0, 0]} maxBarSize={18} />
                </BarChart>
              </ResponsiveContainer>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Saldo per Kas</CardTitle>
            <CardDescription>Posisi saat ini</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-3">
            {data?.balances.map((b) => (
              <div key={b.id} className="flex items-center justify-between gap-2">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{b.name}</p>
                  <p className="text-xs text-muted-foreground">{b.code}</p>
                </div>
                <span className="text-sm font-semibold tabular-nums">{formatRupiah(b.balance)}</span>
              </div>
            ))}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Transaksi Terbaru</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3">
          {data?.recent_transactions.length === 0 && <p className="text-sm text-muted-foreground">Belum ada transaksi.</p>}
          {data?.recent_transactions.map((t) => (
            <div key={t.id} className="flex items-center justify-between gap-3">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">{t.description}</p>
                <p className="text-xs text-muted-foreground">
                  {formatDate(t.date)} · {t.category?.name}
                </p>
              </div>
              <Badge variant={t.type === 'income' ? 'secondary' : 'outline'} className="tabular-nums">
                {t.type === 'income' ? '+' : '−'} {formatRupiah(t.amount)}
              </Badge>
            </div>
          ))}
        </CardContent>
      </Card>
    </>
  )
}
