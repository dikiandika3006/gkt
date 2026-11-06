import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ShieldAlert } from 'lucide-react'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { formatRupiah } from '@/lib/format'
import type { DonorRecap } from '@/types'
import { Field, PrintTitle, ReportToolbar, YearInput } from './components'

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']

/** Persembahan syukur & perpuluhan per nama per bulan (data pribadi — modul hak akses tersendiri). */
export default function DonorsPage() {
  const [year, setYear] = useState(() => new Date().getFullYear())
  const [search, setSearch] = useState('')

  const { data } = useQuery({
    queryKey: ['reports', 'donors', year],
    queryFn: async () => (await api.get<DonorRecap>('/reports/donors', { params: { year } })).data,
  })

  const rows = (data?.rows ?? []).filter((r) => r.name.toLowerCase().includes(search.trim().toLowerCase()))
  const num = 'text-right tabular-nums whitespace-nowrap text-xs'
  const amount = (v: number) => (v ? formatRupiah(v) : '')

  return (
    <>
      <ReportToolbar exportName="donors" exportParams={{ year }} filename={`rekap-pemberi-${year}.xlsx`}>
        <Field label="Tahun">
          <YearInput value={year} onChange={setYear} />
        </Field>
        <Field label="Cari nama">
          <Input value={search} placeholder="Nama / kode jemaat" onChange={(e) => setSearch(e.target.value)} className="w-56" />
        </Field>
      </ReportToolbar>

      <div className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 p-3 text-sm print:hidden">
        <ShieldAlert className="mt-0.5 size-4 shrink-0 text-warning" />
        <p>Berisi data pribadi jemaat. Jangan dibagikan atau diumumkan. Data diambil dari nama di Rekap Persembahan bagian Syukur & Perpuluhan.</p>
      </div>

      <PrintTitle title={`Rekap per Pemberi ${year}`} subtitle="RAHASIA — data pribadi jemaat" />

      <Card>
        <CardContent className="px-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="sticky left-0 bg-card pl-4">Nama</TableHead>
                {MONTHS.map((m) => (
                  <TableHead key={m} className="text-right text-xs">
                    {m}
                  </TableHead>
                ))}
                <TableHead className="text-right">Perpuluhan</TableHead>
                <TableHead className="text-right">Syukur</TableHead>
                <TableHead className="pr-4 text-right">Total</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data && rows.length === 0 && (
                <TableRow>
                  <TableCell colSpan={16} className="py-8 text-center text-muted-foreground">
                    Tidak ada data.
                  </TableCell>
                </TableRow>
              )}
              {rows.map((r) => (
                <TableRow key={r.name}>
                  <TableCell className="sticky left-0 bg-card pl-4 font-medium whitespace-nowrap">{r.name}</TableCell>
                  {Object.values(r.months).map((v, i) => (
                    <TableCell key={i} className={num}>
                      {amount(v)}
                    </TableCell>
                  ))}
                  <TableCell className={num}>{amount(r.perpuluhan)}</TableCell>
                  <TableCell className={num}>{amount(r.syukur)}</TableCell>
                  <TableCell className={`${num} pr-4 font-medium`}>{formatRupiah(r.total)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
            {data && (
              <TableFooter>
                <TableRow>
                  <TableCell className="pl-4" colSpan={13}>
                    Jumlah ({data.totals.donors} nama)
                  </TableCell>
                  <TableCell className={num}>{formatRupiah(data.totals.perpuluhan)}</TableCell>
                  <TableCell className={num}>{formatRupiah(data.totals.syukur)}</TableCell>
                  <TableCell className={`${num} pr-4`}>{formatRupiah(data.totals.total)}</TableCell>
                </TableRow>
              </TableFooter>
            )}
          </Table>
        </CardContent>
      </Card>
    </>
  )
}
