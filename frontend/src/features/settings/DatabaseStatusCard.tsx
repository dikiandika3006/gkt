import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { toast } from 'sonner'
import { DatabaseZap, Loader2, RefreshCw } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { api, errorMessage } from '@/lib/api'
import type { SystemStatus, SystemUpdateResult } from '@/types'

/** Status database & tombol Update Database (sama seperti post-deploy.sh: backup → migrate → refresh cache). */
export function DatabaseStatusCard() {
  const queryClient = useQueryClient()
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [output, setOutput] = useState<string | null>(null)

  const status = useQuery({
    queryKey: ['system-status'],
    queryFn: async () => (await api.get<SystemStatus>('/system/status')).data,
  })

  const update = useMutation({
    mutationFn: async () => (await api.post<SystemUpdateResult>('/system/update')).data,
    onSuccess: (res) => {
      toast.success(res.backup ? `${res.message} Backup: ${res.backup}` : res.message)
      setOutput(res.migrate_output || null)
      queryClient.setQueryData(['system-status'], res.status)
      setConfirmOpen(false)
    },
    onError: (err) => {
      toast.error(errorMessage(err))
      const data = err instanceof AxiosError ? (err.response?.data as { migrate_output?: string } | undefined) : undefined
      setOutput(data?.migrate_output || null)
      setConfirmOpen(false)
      status.refetch()
    },
  })

  const s = status.data
  const short = (sha: string | null) => (sha ? sha.slice(0, 7) : '-')

  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
        <div className="grid gap-1">
          <CardTitle className="flex items-center gap-2">
            <DatabaseZap className="size-4" /> Status Database
            {s && (
              <Badge variant="outline" className={s.needs_update ? 'border-warning/50 text-warning' : 'border-success/50 text-success'}>
                {s.needs_update ? 'Perlu update' : 'Terbaru'}
              </Badge>
            )}
          </CardTitle>
          <CardDescription>Setelah deploy, jalankan Update Database agar tabel & menu baru aktif. Backup otomatis dibuat dulu.</CardDescription>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" size="icon" aria-label="Muat ulang status" onClick={() => status.refetch()} disabled={status.isFetching}>
            <RefreshCw className={status.isFetching ? 'animate-spin' : ''} />
          </Button>
          <Button variant={s?.needs_update ? 'default' : 'outline'} disabled={!s || update.isPending} onClick={() => setConfirmOpen(true)}>
            {update.isPending ? <Loader2 className="animate-spin" /> : <DatabaseZap />} Update Database
          </Button>
        </div>
      </CardHeader>
      <CardContent className="grid gap-3 text-sm">
        {status.isLoading && <p className="text-muted-foreground">Memuat...</p>}
        {status.isError && <p className="text-destructive">{errorMessage(status.error)}</p>}
        {s && (
          <>
            <dl className="grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
              <Row label="Koneksi">
                {s.database.ok ? (
                  <span className="text-success">Terhubung</span>
                ) : (
                  <span className="text-destructive">{s.database.error ?? 'Gagal'}</span>
                )}{' '}
                <span className="text-muted-foreground">
                  ({s.database.driver} · {s.database.name})
                </span>
              </Row>
              <Row label="Migrasi tertunda">{s.pending_migrations.length === 0 ? 'Tidak ada' : `${s.pending_migrations.length} migrasi`}</Row>
              <Row label="Versi deploy">
                <span className="font-mono">{short(s.deployed_commit)}</span>
              </Row>
              <Row label="Terakhir diterapkan">
                <span className="font-mono">{short(s.last_applied_commit)}</span>
              </Row>
              <Row label="Cache route">{s.routes_cached ? 'Aktif' : 'Tidak'}</Row>
              <Row label="Cache konfigurasi">{s.config_cached ? 'Aktif' : 'Tidak'}</Row>
            </dl>
            {s.pending_migrations.length > 0 && (
              <ul className="grid gap-0.5 rounded-md border bg-muted/40 p-2 font-mono text-xs">
                {s.pending_migrations.map((m) => (
                  <li key={m} className="truncate">
                    {m}
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
        {output && <pre className="max-h-48 overflow-auto rounded-md border bg-muted/40 p-2 text-xs whitespace-pre-wrap">{output}</pre>}
      </CardContent>

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title="Update database sekarang?"
        description="Backup database dibuat otomatis dulu (masuk riwayat Backup), lalu migrasi dijalankan dan cache route/konfigurasi diperbarui. Jangan tutup halaman sampai selesai."
        confirmLabel="Update"
        loading={update.isPending}
        onConfirm={() => update.mutate()}
      />
    </Card>
  )
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-3 border-b border-dashed py-1 sm:block sm:border-0 sm:py-0">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="text-right sm:text-left">{children}</dd>
    </div>
  )
}
