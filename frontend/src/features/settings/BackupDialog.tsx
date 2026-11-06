import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { DatabaseBackup, Download, KeyRound, Loader2, RotateCcw, Trash2, Upload } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { api, downloadFile, errorMessage, tokenStorage } from '@/lib/api'
import { formatDate } from '@/lib/format'

interface BackupFile {
  name: string
  size: number
  time: number
}

interface BackupPanel {
  backups: BackupFile[]
  database_size: number
  upload_limit: number
}

const formatBytes = (bytes: number) => {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toLocaleString('id-ID', { maximumFractionDigits: 1 })} KB`
  return `${(bytes / 1024 ** 2).toLocaleString('id-ID', { maximumFractionDigits: 1 })} MB`
}

/** Form rahasia Backup & Restore database (About → Alt+F7). PIN dulu, lalu panel. */
export function BackupDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const [unlocked, setUnlocked] = useState(false)
  const lock = useCallback(() => setUnlocked(false), [])

  return (
    <Dialog
      open={open}
      onOpenChange={(o) => {
        onOpenChange(o)
        if (!o) setUnlocked(false) // PIN diminta lagi setiap form dibuka.
      }}
    >
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <DatabaseBackup className="size-5" /> Backup & Restore Database
          </DialogTitle>
          <DialogDescription>Hanya database. Lampiran (foto nota, bukti transfer) tidak ikut.</DialogDescription>
        </DialogHeader>
        {unlocked ? <Panel onLocked={lock} /> : <PinForm onUnlocked={() => setUnlocked(true)} />}
      </DialogContent>
    </Dialog>
  )
}

function PinForm({ onUnlocked }: { onUnlocked: () => void }) {
  const [pin, setPin] = useState('')
  const unlock = useMutation({
    mutationFn: () => api.post('/backup/unlock', { pin }),
    onSuccess: onUnlocked,
    onError: (err) => toast.error(errorMessage(err)),
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    unlock.mutate()
  }

  return (
    <form onSubmit={submit} className="grid gap-3">
      <Label htmlFor="backup-pin">PIN</Label>
      <Input id="backup-pin" type="password" autoFocus autoComplete="off" value={pin} onChange={(e) => setPin(e.target.value)} />
      <Button type="submit" disabled={!pin || unlock.isPending}>
        {unlock.isPending ? <Loader2 className="animate-spin" /> : <KeyRound />} Buka
      </Button>
    </form>
  )
}

function Panel({ onLocked }: { onLocked: () => void }) {
  const queryClient = useQueryClient()
  const [file, setFile] = useState<File | null>(null)
  const [target, setTarget] = useState<string | null>(null) // nama file riwayat yang akan di-restore
  const [confirmText, setConfirmText] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: ['backup'],
    queryFn: async () => (await api.get<BackupPanel>('/backup')).data,
    retry: false,
  })
  // Sesi PIN habis / ditolak → kembali ke form PIN.
  useEffect(() => {
    if (!error) return
    toast.error(errorMessage(error))
    queryClient.removeQueries({ queryKey: ['backup'] })
    onLocked()
  }, [error, onLocked, queryClient])
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['backup'] })

  const download = (name: string) => downloadFile(`/backup/${encodeURIComponent(name)}/download`, name).catch((err) => toast.error(errorMessage(err)))

  const create = useMutation({
    mutationFn: async () => (await api.post<{ name: string }>('/backup')).data,
    onSuccess: async ({ name }) => {
      toast.success(`Backup dibuat: ${name}`)
      refresh()
      await download(name)
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const remove = useMutation({
    mutationFn: (name: string) => api.delete(`/backup/${encodeURIComponent(name)}`),
    onSuccess: () => {
      toast.success('File backup dihapus.')
      refresh()
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const restore = useMutation({
    mutationFn: async () => {
      const body = new FormData()
      body.append('konfirmasi', confirmText)
      if (target) body.append('name', target)
      else if (file) body.append('file', file)
      return (await api.post<{ message: string }>('/backup/restore', body)).data
    },
    onSuccess: (res) => {
      // Tabel token ikut ditimpa: semua sesi harus login ulang.
      toast.success(res.message, { duration: 6000 })
      setTimeout(() => {
        tokenStorage.clear()
        window.location.assign(`${import.meta.env.BASE_URL}login`)
      }, 4000)
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const source = target ?? file?.name ?? null
  const tooBig = !!file && !!data?.upload_limit && file.size > data.upload_limit

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
        <span className="text-muted-foreground">
          Ukuran database: <b className="text-foreground">{data ? formatBytes(data.database_size) : '-'}</b> · Batas upload:{' '}
          <b className="text-foreground">{data ? formatBytes(data.upload_limit) : '-'}</b>
        </span>
        <Button onClick={() => create.mutate()} disabled={create.isPending || restore.isPending}>
          {create.isPending ? <Loader2 className="animate-spin" /> : <Download />} Buat & Download Backup
        </Button>
      </div>

      <div className="grid gap-1">
        <h3 className="text-sm font-semibold">Riwayat backup di server</h3>
        <div className="max-h-56 overflow-y-auto rounded-lg border">
          {isLoading && <p className="p-3 text-sm text-muted-foreground">Memuat...</p>}
          {data?.backups.length === 0 && <p className="p-3 text-sm text-muted-foreground">Belum ada backup.</p>}
          {data?.backups.map((b) => (
            <div key={b.name} className="flex items-center justify-between gap-2 border-b p-2 last:border-b-0">
              <div className="min-w-0">
                <p className="truncate font-mono text-xs">{b.name}</p>
                <p className="text-xs text-muted-foreground">
                  {formatDate(new Date(b.time * 1000).toISOString(), 'd MMM yyyy HH:mm')} · {formatBytes(b.size)}
                </p>
              </div>
              <div className="flex shrink-0 gap-1">
                <Button size="icon-sm" variant="ghost" aria-label="Download" onClick={() => download(b.name)}>
                  <Download />
                </Button>
                <Button
                  size="icon-sm"
                  variant="ghost"
                  aria-label="Restore dari file ini"
                  onClick={() => {
                    setTarget(b.name)
                    setFile(null)
                  }}
                >
                  <RotateCcw />
                </Button>
                <Button size="icon-sm" variant="ghost" aria-label="Hapus" disabled={remove.isPending} onClick={() => remove.mutate(b.name)}>
                  <Trash2 className="text-destructive" />
                </Button>
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="grid gap-2 rounded-lg border border-destructive/40 p-3">
        <h3 className="text-sm font-semibold text-destructive">Restore (menimpa seluruh database)</h3>
        <p className="text-xs text-muted-foreground">
          Pilih file dari riwayat (ikon <RotateCcw className="inline size-3" />) atau upload file .sql / .sql.gz. Backup pengaman "_pre-restore" dibuat
          otomatis sebelum restore. Setelah selesai semua pengguna perlu login ulang.
        </p>
        <Input
          type="file"
          accept=".sql,.gz"
          onChange={(e) => {
            setFile(e.target.files?.[0] ?? null)
            setTarget(null)
          }}
        />
        {source && (
          <p className="text-xs">
            Sumber: <b className="font-mono">{source}</b>
            {tooBig && <span className="text-destructive"> — melebihi batas upload server; salin file ke folder backups lalu restore dari riwayat.</span>}
          </p>
        )}
        <Label htmlFor="backup-confirm" className="text-xs">
          Ketik <b>RESTORE</b> untuk konfirmasi
        </Label>
        <div className="flex gap-2">
          <Input id="backup-confirm" value={confirmText} onChange={(e) => setConfirmText(e.target.value)} autoComplete="off" />
          <Button variant="destructive" disabled={!source || tooBig || confirmText !== 'RESTORE' || restore.isPending} onClick={() => restore.mutate()}>
            {restore.isPending ? <Loader2 className="animate-spin" /> : <Upload />} Restore
          </Button>
        </div>
      </div>
    </div>
  )
}
