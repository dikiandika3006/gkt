import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import {
  AlertTriangle,
  ArrowDownRight,
  ArrowRight,
  ArrowUpRight,
  CalendarCheck,
  CheckCircle2,
  Landmark,
  Scale,
  ShieldAlert,
  ShieldCheck,
  Wallet,
} from 'lucide-react'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { PageHeader } from '@/components/PageHeader'
import { useAuth } from '@/features/auth/AuthContext'
import { InsightPanel } from '@/features/insights/InsightPanel'
import { api } from '@/lib/api'
import { formatCompact, formatDate, formatRupiah } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { DashboardData } from '@/types'
import { Meter } from './Meter'
import { SecretariatDashboard } from './SecretariatDashboard'
import { StatTile } from './StatTile'
import { deltaTone, percentChange, resilienceLevel } from './utils'

// Slot kategorikal 1 & 2 dari palette dataviz tervalidasi (lolos uji CVD).
const SERIES_COLORS = { income: '#2a78d6', expense: '#eb6834' }

const RESILIENCE = {
  kritis: { label: 'Kritis', icon: ShieldAlert, text: 'text-destructive', meter: 'critical' },
  waspada: { label: 'Waspada', icon: AlertTriangle, text: 'text-warning', meter: 'warning' },
  aman: { label: 'Aman', icon: ShieldCheck, text: 'text-success', meter: 'good' },
} as const

/** Isi dashboard mengikuti hak akses: keuangan gereja lengkap, atau khusus Kas Sekretariat. */
export default function DashboardPage() {
  const { can } = useAuth()
  if (can('dashboard')) return <ChurchDashboard />
  return <SecretariatDashboard />
}

function ChurchDashboard() {
  const [range, setRange] = useState<6 | 12>(12)
  const { data, isLoading } = useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => (await api.get<DashboardData>('/dashboard')).data,
  })

  const trend = (data?.trend ?? []).map((t) => ({ ...t, net: t.income - t.expense, label: formatDate(`${t.month}-01`, 'MMM yy') }))
  const previous = trend.at(-2)
  const against = previous ? formatDate(`${previous.month}-01`, 'MMM yyyy') : ''
  const chartData = trend.slice(-range)

  return (
    <>
      <PageHeader
        title="Dashboard"
        description={data ? `Ringkasan keuangan gereja · ${formatDate(data.period.start_date, 'MMMM yyyy')}` : 'Ringkasan kondisi keuangan gereja'}
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {isLoading || !data ? (
          Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-40 rounded-xl" />)
        ) : (
          <>
            <StatTile
              label="Total Saldo"
              value={data.total_balance}
              icon={Landmark}
              to="/cash-accounts"
              valueClass={data.total_balance < 0 ? 'text-destructive' : undefined}
              delta={(() => {
                const opening = data.total_balance - data.month.change
                const change = percentChange(data.total_balance, opening)
                return { change, tone: deltaTone(change, true), against: 'awal bulan' }
              })()}
            />
            <StatTile
              label="Pemasukan Operasional"
              value={data.month.income}
              icon={ArrowUpRight}
              to="/transactions"
              delta={previous ? { change: percentChange(data.month.income, previous.income), tone: deltaTone(percentChange(data.month.income, previous.income), true), against } : undefined}
              spark={trend.map((t) => ({ label: t.label, value: t.income }))}
            />
            <StatTile
              label="Pengeluaran Operasional"
              value={data.month.expense}
              icon={ArrowDownRight}
              to="/transactions"
              delta={
                previous ? { change: percentChange(data.month.expense, previous.expense), tone: deltaTone(percentChange(data.month.expense, previous.expense), false), against } : undefined
              }
              spark={trend.map((t) => ({ label: t.label, value: t.expense }))}
            />
            <StatTile
              label="Selisih Bulan Ini"
              value={data.month.net}
              icon={Scale}
              to="/reports"
              valueClass={data.month.net < 0 ? 'text-destructive' : 'text-success'}
              badge={
                <Badge variant="outline" className={cn('gap-1', data.month.net < 0 ? 'border-destructive/40 text-destructive' : 'border-success/40 text-success')}>
                  {data.month.net < 0 ? <ArrowDownRight className="size-3" /> : <ArrowUpRight className="size-3" />}
                  {data.month.net < 0 ? 'Defisit' : 'Surplus'}
                </Badge>
              }
              spark={trend.map((t) => ({ label: t.label, value: t.net }))}
            />
          </>
        )}
      </div>

      <InsightPanel />

      {data && (
        <div className="grid gap-4 md:grid-cols-2">
          <ResilienceCard resilience={data.resilience} />
          <RecurringCard recurring={data.recurring} />
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-2">
            <div>
              <CardTitle>Pemasukan vs Pengeluaran Operasional</CardTitle>
              <CardDescription>
                {range} bulan terakhir · <span className="inline-block size-2 rounded-full bg-destructive align-middle" /> bulan defisit
              </CardDescription>
            </div>
            <div className="flex rounded-lg border p-0.5" role="group" aria-label="Rentang grafik">
              {([6, 12] as const).map((r) => (
                <Button key={r} size="xs" variant={range === r ? 'secondary' : 'ghost'} aria-pressed={range === r} onClick={() => setRange(r)}>
                  {r} bln
                </Button>
              ))}
            </div>
          </CardHeader>
          <CardContent className="h-80">
            {isLoading ? (
              <Skeleton className="h-full" />
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={chartData} barGap={2} margin={{ left: 8, right: 8 }}>
                  <CartesianGrid vertical={false} stroke="var(--border)" />
                  <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={12} stroke="var(--muted-foreground)" tick={<DeficitTick data={chartData} />} interval={0} />
                  <YAxis tickFormatter={formatCompact} tickLine={false} axisLine={false} fontSize={12} width={48} stroke="var(--muted-foreground)" />
                  <Tooltip cursor={{ fill: 'var(--muted)' }} content={(p) => <TrendTooltip active={p.active} payload={p.payload} />} />
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
            <CardDescription>Posisi saat ini · klik untuk buku kas</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-2">
            {data?.balances.map((b) => {
              const share = data.total_balance > 0 ? Math.max(0, b.balance) / data.total_balance : 0
              return (
                <Link key={b.id} to="/reports" className="group grid gap-1.5 rounded-lg p-2 transition hover:bg-muted">
                  <div className="flex items-center justify-between gap-2">
                    <div className="flex min-w-0 items-center gap-2">
                      <Wallet className="size-4 shrink-0 text-muted-foreground" />
                      <span className="truncate text-sm font-medium">{b.name}</span>
                    </div>
                    <span className={cn('flex items-center gap-1 text-sm font-semibold', b.balance < 0 && 'text-destructive')}>
                      {b.balance < 0 && (
                        <Badge variant="outline" className="border-destructive/40 px-1 text-[10px] text-destructive">
                          Minus
                        </Badge>
                      )}
                      {formatRupiah(b.balance)}
                    </span>
                  </div>
                  <div className="h-1.5 overflow-hidden rounded-full bg-primary/10">
                    <div className="h-full rounded-full bg-primary transition-[width] duration-700" style={{ width: `${share * 100}%` }} />
                  </div>
                </Link>
              )
            })}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Transaksi Terbaru</CardTitle>
          <Button variant="ghost" size="sm" render={<Link to="/transactions" />}>
            Lihat semua <ArrowRight />
          </Button>
        </CardHeader>
        <CardContent className="grid gap-1">
          {data?.recent_transactions.length === 0 && <p className="text-sm text-muted-foreground">Belum ada transaksi.</p>}
          {data?.recent_transactions.map((t) => {
            const income = t.type === 'income'
            return (
              <div key={t.id} className="flex items-center justify-between gap-3 rounded-lg p-2 transition hover:bg-muted">
                <div className="flex min-w-0 items-center gap-3">
                  <span className={cn('flex size-8 shrink-0 items-center justify-center rounded-full', income ? 'bg-success/10 text-success' : 'bg-destructive/10 text-destructive')}>
                    {income ? <ArrowUpRight className="size-4" /> : <ArrowDownRight className="size-4" />}
                  </span>
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium">{t.description}</p>
                    <p className="truncate text-xs text-muted-foreground">
                      {formatDate(t.date)} · {t.category?.name}
                      {t.counterparty && ` · ${t.counterparty}`}
                    </p>
                  </div>
                </div>
                <span className={cn('shrink-0 text-sm font-semibold tabular-nums', income ? 'text-success' : 'text-destructive')}>
                  {income ? '+' : '−'} {formatRupiah(t.amount)}
                </span>
              </div>
            )
          })}
        </CardContent>
      </Card>
    </>
  )
}

function ResilienceCard({ resilience }: { resilience: DashboardData['resilience'] }) {
  const level = RESILIENCE[resilienceLevel(resilience.months)]
  const Icon = level.icon

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between pb-2">
        <CardDescription>Ketahanan Kas</CardDescription>
        <Badge variant="outline" className={cn('gap-1', level.text)}>
          <Icon className="size-3" /> {level.label}
        </Badge>
      </CardHeader>
      <CardContent className="grid gap-2">
        <div className={cn('text-2xl font-semibold', level.text)}>
          {resilience.months === null ? '-' : `${resilience.months.toLocaleString('id-ID')} bulan`}
        </div>
        <Meter value={(resilience.months ?? 6) / 6} level={level.meter} label="Ketahanan kas, skala 6 bulan" />
        <div className="flex justify-between text-[10px] text-muted-foreground">
          <span>0</span>
          <span>1 bln</span>
          <span>3 bln (aman)</span>
          <span>6 bln</span>
        </div>
        <p className="text-xs text-muted-foreground">
          Saldo cukup untuk sekian bulan pengeluaran operasional (rata-rata {formatRupiah(resilience.average_expense)}/bulan, 6 bulan terakhir).
        </p>
      </CardContent>
    </Card>
  )
}

function RecurringCard({ recurring }: { recurring: DashboardData['recurring'] }) {
  const short = recurring.shortfall > 0
  const coverage = recurring.remaining > 0 ? recurring.bank_balance / recurring.remaining : 1

  return (
    <Link to="/recurring" className="group block rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
      <Card className={cn('h-full transition duration-200 group-hover:-translate-y-0.5 group-hover:shadow-md', short && 'border-destructive/50')}>
        <CardHeader className="flex flex-row items-center justify-between pb-2">
          <CardDescription className="flex items-center gap-1.5">
            <CalendarCheck className="size-3.5" /> Sisa Biaya Rutin Bulan Ini
          </CardDescription>
          <Badge variant="outline" className={cn('gap-1', short ? 'border-destructive/40 text-destructive' : 'border-success/40 text-success')}>
            {short ? <AlertTriangle className="size-3" /> : <CheckCircle2 className="size-3" />}
            {short ? `Kurang ${formatRupiah(recurring.shortfall)}` : 'Cukup'}
          </Badge>
        </CardHeader>
        <CardContent className="grid gap-2">
          <div className="text-2xl font-semibold">{formatRupiah(recurring.remaining)}</div>
          <Meter value={coverage} level={short ? 'critical' : 'good'} label="Saldo rekening dibanding sisa biaya rutin" />
          <p className="flex items-center justify-between text-xs text-muted-foreground">
            <span>Saldo rekening {formatRupiah(recurring.bank_balance)}</span>
            <span className="flex items-center gap-1 text-foreground transition group-hover:translate-x-0.5">
              Rincian <ArrowRight className="size-3" />
            </span>
          </p>
        </CardContent>
      </Card>
    </Link>
  )
}

type TrendPoint = { label: string; income: number; expense: number; net: number }

/** Tooltip grafik: pemasukan, pengeluaran, dan selisih (merah jika defisit). */
function TrendTooltip({ active, payload }: { active?: boolean; payload?: ReadonlyArray<{ payload?: unknown }> }) {
  const point = payload?.[0]?.payload as TrendPoint | undefined
  if (!active || !point) return null

  return (
    <div className="grid gap-1 rounded-lg border bg-popover px-3 py-2 text-xs shadow-md">
      <span className="font-medium">{point.label}</span>
      <Row color={SERIES_COLORS.income} label="Pemasukan" value={point.income} />
      <Row color={SERIES_COLORS.expense} label="Pengeluaran" value={point.expense} />
      <div className={cn('mt-1 flex justify-between gap-4 border-t pt-1 font-medium', point.net < 0 ? 'text-destructive' : 'text-success')}>
        <span>{point.net < 0 ? 'Defisit' : 'Surplus'}</span>
        <span className="tabular-nums">{formatRupiah(point.net)}</span>
      </div>
    </div>
  )
}

function Row({ color, label, value }: { color: string; label: string; value: number }) {
  return (
    <div className="flex justify-between gap-4">
      <span className="flex items-center gap-1.5 text-muted-foreground">
        <span className="size-2 rounded-full" style={{ background: color }} />
        {label}
      </span>
      <span className="tabular-nums">{formatRupiah(value)}</span>
    </div>
  )
}

/** Label sumbu X + titik merah di bawah bulan yang defisit. */
function DeficitTick({ x, y, payload, data }: { x?: number; y?: number; payload?: { value: string; index: number }; data: TrendPoint[] }) {
  if (x === undefined || y === undefined || !payload) return null
  const deficit = (data[payload.index]?.net ?? 0) < 0

  return (
    <g transform={`translate(${x},${y})`}>
      <text dy={12} textAnchor="middle" fontSize={12} fill="var(--muted-foreground)">
        {payload.value}
      </text>
      {deficit && <circle cy={22} r={3} fill="var(--destructive)" />}
    </g>
  )
}
