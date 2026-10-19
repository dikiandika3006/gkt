import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { AlertTriangle, ArrowRight, CheckCircle2, Info, Lightbulb, OctagonAlert, Sparkles, type LucideIcon } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import { formatRupiah } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { Insight, InsightSeverity } from '@/types'

const STYLE: Record<InsightSeverity, { icon: LucideIcon; card: string; iconBox: string; label: string }> = {
  danger: { icon: OctagonAlert, card: 'border-destructive/40 bg-destructive/5', iconBox: 'bg-destructive/15 text-destructive', label: 'Perlu tindakan' },
  warning: { icon: AlertTriangle, card: 'border-warning/40 bg-warning/5', iconBox: 'bg-warning/15 text-warning', label: 'Perhatian' },
  info: { icon: Info, card: 'border-info/30 bg-info/5', iconBox: 'bg-info/15 text-info', label: 'Info' },
  success: { icon: CheckCircle2, card: 'border-success/30 bg-success/5', iconBox: 'bg-success/15 text-success', label: 'Baik' },
}

/** Panel "Insight Keuangan" di Dashboard: hasil mesin analisis lokal (GET /insights). */
export function InsightPanel() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['insights'],
    queryFn: async () => (await api.get<{ data: Insight[] }>('/insights')).data.data,
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Sparkles className="size-4 text-primary" /> Insight Keuangan
        </CardTitle>
        <CardDescription>Analisis otomatis dari data transaksi, anggaran & biaya rutin</CardDescription>
      </CardHeader>
      <CardContent>
        {isLoading && (
          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {Array.from({ length: 3 }, (_, i) => (
              <Skeleton key={i} className="h-32 rounded-xl" />
            ))}
          </div>
        )}
        {isError && <p className="text-sm text-muted-foreground">Insight belum bisa dimuat. Coba muat ulang halaman.</p>}
        {data && data.length === 0 && (
          <p className="flex items-center gap-2 text-sm text-muted-foreground">
            <CheckCircle2 className="size-4 text-success" /> Semua aman — tidak ada hal yang perlu diperhatikan.
          </p>
        )}
        {data && data.length > 0 && (
          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {data.map((insight) => (
              <InsightCard key={insight.key} insight={insight} />
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function InsightCard({ insight }: { insight: Insight }) {
  const style = STYLE[insight.severity]
  const Icon = style.icon

  return (
    <div className={cn('flex flex-col gap-2 rounded-xl border p-3', style.card)}>
      <div className="flex items-start gap-3">
        <div className={cn('flex size-9 shrink-0 items-center justify-center rounded-lg', style.iconBox)}>
          <Icon className="size-4.5" />
        </div>
        <div className="min-w-0">
          <p className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">{style.label}</p>
          <p className="leading-snug font-semibold">{insight.title}</p>
        </div>
      </div>
      <p className="text-sm text-muted-foreground">{insight.message}</p>

      {insight.items.length > 0 && (
        <ul className="grid gap-1 text-sm">
          {insight.items.map((item) => (
            <li key={item.label} className="flex items-center justify-between gap-2">
              <span className="truncate">{item.label}</span>
              <span className="shrink-0 tabular-nums">
                {formatRupiah(item.value)}
                {item.note && <span className="ml-1.5 text-xs text-muted-foreground">{item.note}</span>}
              </span>
            </li>
          ))}
        </ul>
      )}

      {insight.recommendation && (
        <p className="flex gap-1.5 rounded-lg bg-background/70 p-2 text-xs">
          <Lightbulb className="mt-px size-3.5 shrink-0 text-warning" />
          <span>{insight.recommendation}</span>
        </p>
      )}

      {insight.link && (
        <Link to={insight.link} className="mt-auto inline-flex items-center gap-1 self-start text-xs font-medium text-primary hover:underline">
          Lihat detail <ArrowRight className="size-3" />
        </Link>
      )}
    </div>
  )
}
