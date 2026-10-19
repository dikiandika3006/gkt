import { useRef } from 'react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { FileText, Loader2, Paperclip, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { api, downloadFile, errorMessage } from '@/lib/api'
import type { Attachment } from '@/types'

interface Props {
  attachableType: 'transaction' | 'fund_report_item'
  attachableId: number
  attachments: Attachment[]
  canEdit: boolean
  onChanged: () => void
}

/** Daftar lampiran bukti (nota/kwitansi) + unggah & hapus. File dibuka lewat API ber-auth. */
export function AttachmentList({ attachableType, attachableId, attachments, canEdit, onChanged }: Props) {
  const input = useRef<HTMLInputElement>(null)

  const upload = useMutation({
    mutationFn: (file: File) => {
      const form = new FormData()
      form.append('attachable_type', attachableType)
      form.append('attachable_id', String(attachableId))
      form.append('file', file)
      return api.post('/attachments', form)
    },
    onSuccess: () => {
      toast.success('Lampiran diunggah')
      onChanged()
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const remove = useMutation({
    mutationFn: (a: Attachment) => api.delete(`/attachments/${a.id}`),
    onSuccess: () => {
      toast.success('Lampiran dihapus')
      onChanged()
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const open = (a: Attachment) =>
    downloadFile(`/attachments/${a.id}`, a.original_name, undefined, true).catch((err) => toast.error(errorMessage(err)))

  return (
    <div className="flex flex-wrap items-center gap-2">
      {attachments.map((a) => (
        <span key={a.id} className="inline-flex items-center gap-1 rounded-md border bg-muted/40 py-0.5 pr-0.5 pl-2 text-xs">
          <button type="button" className="inline-flex max-w-40 items-center gap-1 truncate hover:underline" onClick={() => open(a)}>
            <FileText className="size-3 shrink-0" />
            <span className="truncate">{a.original_name}</span>
          </button>
          {canEdit && (
            <Button
              type="button"
              size="icon-xs"
              variant="ghost"
              aria-label={`Hapus ${a.original_name}`}
              disabled={remove.isPending}
              onClick={() => remove.mutate(a)}
            >
              <Trash2 className="text-destructive" />
            </Button>
          )}
        </span>
      ))}
      {attachments.length === 0 && !canEdit && <span className="text-xs text-muted-foreground">Tidak ada lampiran</span>}
      {canEdit && (
        <>
          <input
            ref={input}
            type="file"
            accept="image/jpeg,image/png,image/webp,application/pdf"
            className="hidden"
            onChange={(e) => {
              const file = e.target.files?.[0]
              if (file) upload.mutate(file)
              e.target.value = ''
            }}
          />
          <Button type="button" size="xs" variant="outline" disabled={upload.isPending} onClick={() => input.current?.click()}>
            {upload.isPending ? <Loader2 className="animate-spin" /> : <Paperclip />}
            Lampirkan nota
          </Button>
        </>
      )}
    </div>
  )
}
