import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { SelectField } from '@/components/SelectField'
import { cn } from '@/lib/utils'

interface PaginationProps {
  page: number
  pageSize: number
  total: number
  onPageChange: (page: number) => void
  onPageSizeChange?: (size: number) => void
  pageSizeOptions?: number[]
  /** Satuan data, mis. "kategori". */
  itemLabel?: string
  className?: string
}

/** Nomor halaman yang ditampilkan, dengan 'gap' untuk elipsis. Contoh: 1 … 4 5 6 … 12 */
function pageItems(page: number, last: number): (number | 'gap')[] {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1)
  const items: (number | 'gap')[] = [1]
  const start = Math.max(2, page - 1)
  const end = Math.min(last - 1, page + 1)
  if (start > 2) items.push('gap')
  for (let i = start; i <= end; i++) items.push(i)
  if (end < last - 1) items.push('gap')
  items.push(last)
  return items
}

export function Pagination({
  page,
  pageSize,
  total,
  onPageChange,
  onPageSizeChange,
  pageSizeOptions = [10, 15, 25, 50],
  itemLabel = 'data',
  className,
}: PaginationProps) {
  const last = Math.max(1, Math.ceil(total / pageSize))
  const from = total === 0 ? 0 : (page - 1) * pageSize + 1
  const to = Math.min(page * pageSize, total)

  return (
    <div className={cn('flex flex-col gap-3 text-sm sm:flex-row sm:items-center sm:justify-between', className)}>
      <div className="flex items-center gap-3 text-muted-foreground">
        <span>
          <span className="font-medium text-foreground tabular-nums">
            {from}–{to}
          </span>{' '}
          dari <span className="font-medium text-foreground tabular-nums">{total}</span> {itemLabel}
        </span>
        {onPageSizeChange && (
          <div className="flex items-center gap-2">
            <span className="hidden sm:inline">Tampil</span>
            <SelectField
              className="h-8 w-[4.5rem]"
              value={String(pageSize)}
              onChange={(v) => onPageSizeChange(Number(v))}
              options={pageSizeOptions.map((n) => ({ value: String(n), label: String(n) }))}
            />
          </div>
        )}
      </div>

      {last > 1 && (
        <nav aria-label="Halaman" className="flex items-center gap-1 self-end sm:self-auto">
          <Button variant="outline" size="icon-sm" aria-label="Sebelumnya" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>
            <ChevronLeft />
          </Button>
          <div className="hidden items-center gap-1 sm:flex">
            {pageItems(page, last).map((item, i) =>
              item === 'gap' ? (
                <span key={`gap-${i}`} className="px-1 text-muted-foreground">
                  …
                </span>
              ) : (
                <Button
                  key={item}
                  size="icon-sm"
                  variant={item === page ? 'default' : 'ghost'}
                  aria-current={item === page ? 'page' : undefined}
                  className="tabular-nums"
                  onClick={() => onPageChange(item)}
                >
                  {item}
                </Button>
              ),
            )}
          </div>
          <span className="px-2 text-muted-foreground tabular-nums sm:hidden">
            {page} / {last}
          </span>
          <Button variant="outline" size="icon-sm" aria-label="Berikutnya" disabled={page >= last} onClick={() => onPageChange(page + 1)}>
            <ChevronRight />
          </Button>
        </nav>
      )}
    </div>
  )
}
