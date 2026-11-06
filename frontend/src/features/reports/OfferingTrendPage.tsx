import { useState } from 'react'
import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { startOfYear } from 'date-fns'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { formatCompact, formatDate, formatRupiah, toDateInput } from '@/lib/format'
import type { OfferingTrend } from '@/types'
import { DateInput, Field, PrintTitle, ReportToolbar, SummaryCard } from './components'

const COLORS = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)']

/** Tren persembahan mingguan dari Rekap Persembahan, per bagian (ibadah, syukur, perpuluhan, lain). */
export default function OfferingTrendPage() {
  const [params, setParams] = useState(() => ({ start_date: toDateInput(startOfYear(new Date())), end_date: toDateInput(new Date()) }))

  const { data } = useQuery({
    queryKey: ['reports', 'offering-trend', params],
    queryFn: async () => (await api.get<OfferingTrend>('/reports/offering-trend', { params })).data,
  })

  const sections = data?.sections ?? []
  const weeks = data?.weeks ?? []
  const chart = weeks.map((w) => ({ label: formatDate(w.date, 'd MMM'), ...Object.fromEntries(sections.map((s) => [s.label, Number(w[s.key])])) }))
  const best = weeks.reduce<(typeof weeks)[number] | null>((b, w) => (!b || w.total > b.total ? w : b), null)
  const num = 'text-right tabular-nums whitespace-nowrap'

  return (
    <>
      <ReportToolbar exportName="offering-trend" exportParams={params} filename={`tren-persembahan-${params.start_date}-${params.end_date}.xlsx`}>
        <Field label="Dari">
          <DateInput value={params.start_date} onChange={(v) => setParams((p) => ({ ...p, start_date: v }))} />
        </Field>
        <Field label="Sampai">
          <DateInput value={params.end_date} onChange={(v) => setParams((p) => ({ ...p, end_date: v }))} />
        </Field>
      </ReportToolbar>

      <PrintTitle title="Tren Persembahan" subtitle={`${formatDate(params.start_date)} s/d ${formatDate(params.end_date)}`} />

      {data && weeks.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Belum ada rekap persembahan pada periode ini. Data diambil dari menu{' '}
            <Link to="/offerings" className="underline underline-offset-4">
              Rekap Persembahan
            </Link>
            .
          </CardContent>
        </Card>
      ) : (
        data && (
          <>
            <div className="grid gap-4 sm:grid-cols-3">
              <SummaryCard label={`Total ${weeks.length} minggu`} value={data.totals.total} />
              <SummaryCard label="Rata-rata per minggu" value={data.average} />
              <SummaryCard label="Minggu tertinggi" value={best?.total ?? 0} hint={best ? formatDate(best.date, 'd MMMM yyyy') : undefined} />
            </div>

            <Card className="print:hidden">
              <CardHeader>
                <CardTitle>Persembahan per minggu</CardTitle>
              </CardHeader>
              <CardContent className="h-72">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={chart}>
                    <CartesianGrid vertical={false} stroke="var(--border)" />
                    <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={12} stroke="var(--muted-foreground)" />
                    <YAxis tickFormatter={formatCompact} tickLine={false} axisLine={false} fontSize={12} width={48} stroke="var(--muted-foreground)" />
                    <Tooltip formatter={(v) => formatRupiah(Number(v))} cursor={{ fill: 'var(--muted)' }} contentStyle={{ borderRadius: 8, borderColor: 'var(--border)', fontSize: 12 }} />
                    <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
                    {sections.map((s, i) => (
                      <Bar key={s.key} dataKey={s.label} stackId="a" fill={COLORS[i % COLORS.length]} maxBarSize={28} />
                    ))}
                  </BarChart>
                </ResponsiveContainer>
              </CardContent>
            </Card>

            <Card>
              <CardContent className="px-0">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="pl-4">Tanggal ibadah</TableHead>
                      {sections.map((s) => (
                        <TableHead key={s.key} className="text-right">
                          {s.label}
                        </TableHead>
                      ))}
                      <TableHead className="pr-4 text-right">Total</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {weeks.map((w) => (
                      <TableRow key={w.date}>
                        <TableCell className="pl-4">{formatDate(w.date, 'd MMM yyyy')}</TableCell>
                        {sections.map((s) => (
                          <TableCell key={s.key} className={num}>
                            {formatRupiah(Number(w[s.key]))}
                          </TableCell>
                        ))}
                        <TableCell className={`${num} pr-4 font-medium`}>{formatRupiah(w.total)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                  <TableFooter>
                    <TableRow>
                      <TableCell className="pl-4">Jumlah</TableCell>
                      {sections.map((s) => (
                        <TableCell key={s.key} className={num}>
                          {formatRupiah(data.totals[s.key])}
                        </TableCell>
                      ))}
                      <TableCell className={`${num} pr-4`}>{formatRupiah(data.totals.total)}</TableCell>
                    </TableRow>
                  </TableFooter>
                </Table>
              </CardContent>
            </Card>
          </>
        )
      )}
    </>
  )
}
