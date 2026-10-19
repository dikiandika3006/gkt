import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Card } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { PageHeader } from '@/components/PageHeader'
import { Pagination } from '@/components/Pagination'
import { SelectField } from '@/components/SelectField'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/format'
import type { ActivityLog, Paginated } from '@/types'

const ALL = '__all'
const SUBJECTS = [
  { value: 'Transaction', label: 'Transaksi' },
  { value: 'FundRequest', label: 'Pengajuan Dana' },
  { value: 'FundReport', label: 'LPJ' },
  { value: 'FundReportItem', label: 'Nota LPJ' },
  { value: 'RecurringExpense', label: 'Biaya Rutin' },
  { value: 'Category', label: 'Kategori' },
  { value: 'CashAccount', label: 'Kas & Bank' },
]
const ACTION: Record<ActivityLog['action'], { label: string; variant: 'secondary' | 'outline' | 'destructive' }> = {
  created: { label: 'Dibuat', variant: 'secondary' },
  updated: { label: 'Diubah', variant: 'outline' },
  deleted: { label: 'Dihapus', variant: 'destructive' },
}
const HIDDEN_FIELDS = new Set(['id', 'created_at', 'updated_at', 'deleted_at', 'created_by'])

/** Ringkas perubahan field: "amount: 100000 → 150000". */
function describe(log: ActivityLog): string {
  const source = log.action === 'updated' ? log.after : (log.after ?? log.before)
  if (!source) return ''
  return Object.keys(source)
    .filter((k) => !HIDDEN_FIELDS.has(k))
    .map((k) => (log.action === 'updated' ? `${k}: ${String(log.before?.[k] ?? '-')} → ${String(log.after?.[k] ?? '-')}` : `${k}: ${String(source[k] ?? '-')}`))
    .join(' · ')
}

export default function ActivityLogsPage() {
  const [subject, setSubject] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)

  const { data, isLoading } = useQuery({
    queryKey: ['activity-logs', subject, page, perPage],
    queryFn: async () =>
      (await api.get<Paginated<ActivityLog>>('/activity-logs', { params: { subject_type: subject || undefined, page, per_page: perPage } })).data,
    placeholderData: keepPreviousData,
  })

  return (
    <>
      <PageHeader title="Log Aktivitas" description="Jejak audit: siapa mengubah data keuangan apa dan kapan" />

      <div className="w-60">
        <SelectField
          value={subject}
          onChange={(v) => {
            setSubject(v === ALL ? '' : v)
            setPage(1)
          }}
          placeholder="Semua data"
          options={[{ value: ALL, label: 'Semua data' }, ...SUBJECTS]}
        />
      </div>

      <Card className="py-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Waktu</TableHead>
              <TableHead>Pengguna</TableHead>
              <TableHead>Aksi</TableHead>
              <TableHead>Data</TableHead>
              <TableHead className="pr-4">Perubahan</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && (
              <TableRow>
                <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                  Memuat...
                </TableCell>
              </TableRow>
            )}
            {data?.data.map((log) => (
              <TableRow key={log.id}>
                <TableCell className="pl-4 whitespace-nowrap">{formatDate(log.created_at, 'd MMM yyyy HH:mm')}</TableCell>
                <TableCell>{log.user?.name ?? 'Sistem'}</TableCell>
                <TableCell>
                  <Badge variant={ACTION[log.action].variant}>{ACTION[log.action].label}</Badge>
                </TableCell>
                <TableCell className="whitespace-nowrap">
                  {SUBJECTS.find((s) => s.value === log.subject_type)?.label ?? log.subject_type} #{log.subject_id}
                </TableCell>
                <TableCell className="max-w-xl truncate pr-4 text-xs text-muted-foreground" title={describe(log)}>
                  {describe(log)}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
        {data && data.meta.total > 0 && (
          <Pagination
            className="border-t p-3"
            page={data.meta.current_page}
            pageSize={data.meta.per_page}
            total={data.meta.total}
            onPageChange={setPage}
            onPageSizeChange={(size) => {
              setPerPage(size)
              setPage(1)
            }}
            itemLabel="aktivitas"
          />
        )}
      </Card>
    </>
  )
}
