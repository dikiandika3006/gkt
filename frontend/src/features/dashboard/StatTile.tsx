import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { ArrowDownRight, ArrowUpRight, Minus, type LucideIcon } from 'lucide-react'
import { Line, LineChart, ResponsiveContainer, Tooltip, YAxis } from 'recharts'
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card'
import { formatRupiah } from '@/lib/format'
import { cn } from '@/lib/utils'
import { TONE_TEXT, type Tone } from './utils'

interface Props {
  label: string
  value: number
  icon: LucideIcon
  to: string
  /** Kelas warna untuk angka utama (mis. merah saat defisit). */
  valueClass?: string
  /** Label status pendamping warna, mis. "Defisit" — warna tidak pernah berdiri sendiri. */
  badge?: ReactNode
  delta?: { change: number | null; tone: Tone; against: string }
  spark?: { label: string; value: number }[]
}

/** Kartu statistik: label · nilai · perubahan vs pembanding · sparkline 12 bulan. Seluruh kartu bisa diklik. */
export function StatTile({ label, value, icon: Icon, to, valueClass, badge, delta, spark }: Props) {
  const DeltaIcon = !delta || delta.tone === 'neutral' || delta.change === null ? Minus : delta.change > 0 ? ArrowUpRight : ArrowDownRight

  return (
    <Link to={to} className="group block rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
      <Card className="h-full gap-2 transition duration-200 group-hover:-translate-y-0.5 group-hover:shadow-md">
        <CardHeader className="flex flex-row items-center justify-between pb-0">
          <CardDescription>{label}</CardDescription>
          <span className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary transition group-hover:bg-primary group-hover:text-primary-foreground">
            <Icon className="size-4" />
          </span>
        </CardHeader>
        <CardContent className="grid gap-1">
          <div className="flex flex-wrap items-baseline gap-2">
            <span className={cn('text-2xl font-semibold', valueClass)}>{formatRupiah(value)}</span>
            {badge}
          </div>
          {delta && (
            <p className={cn('flex items-center gap-1 text-xs', TONE_TEXT[delta.tone])}>
              <DeltaIcon className="size-3.5" />
              {delta.change === null ? 'Belum ada pembanding' : `${Math.abs(delta.change).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`}
              <span className="text-muted-foreground">vs {delta.against}</span>
            </p>
          )}
          {spark && spark.length > 1 && (
            <div className="-mx-1 h-10">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={spark} margin={{ top: 4, right: 4, bottom: 4, left: 4 }}>
                  <YAxis hide domain={['dataMin', 'dataMax']} />
                  <Tooltip
                    cursor={{ stroke: 'var(--border)' }}
                    formatter={(v) => formatRupiah(Number(v))}
                    labelFormatter={(_, payload) => payload?.[0]?.payload?.label ?? ''}
                    contentStyle={{ borderRadius: 8, borderColor: 'var(--border)', fontSize: 11, padding: '4px 8px' }}
                  />
                  <Line
                    type="monotone"
                    dataKey="value"
                    name={label}
                    stroke="var(--muted-foreground)"
                    strokeWidth={2}
                    isAnimationActive={false}
                    dot={(p: { cx?: number; cy?: number; index?: number }) =>
                      p.index === spark.length - 1 && p.cx !== undefined && p.cy !== undefined ? (
                        <circle key="last" cx={p.cx} cy={p.cy} r={4} fill="var(--primary)" stroke="var(--card)" strokeWidth={2} />
                      ) : (
                        <g key={p.index} />
                      )
                    }
                    activeDot={{ r: 4, fill: 'var(--primary)', stroke: 'var(--card)', strokeWidth: 2 }}
                  />
                </LineChart>
              </ResponsiveContainer>
            </div>
          )}
        </CardContent>
      </Card>
    </Link>
  )
}
