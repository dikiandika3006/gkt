import { Combobox } from '@base-ui/react/combobox'
import { CheckIcon, ChevronDownIcon, XIcon } from 'lucide-react'
import type { SelectOption } from '@/components/SelectField'
import { cn } from '@/lib/utils'

interface SearchableSelectProps {
  id?: string
  value: string
  onChange: (value: string) => void
  options: SelectOption[]
  placeholder?: string
  className?: string
  disabled?: boolean
  invalid?: boolean
  /** Tampilkan tombol × untuk mengosongkan pilihan. */
  clearable?: boolean
  emptyText?: string
}

/**
 * Seperti SelectField, tetapi bisa diketik untuk mencari (cocok untuk daftar panjang, mis. kategori).
 * Pencarian mencocokkan label, jadi kode maupun nama sama-sama bisa dicari.
 */
export function SearchableSelect({
  id,
  value,
  onChange,
  options,
  placeholder = 'Ketik untuk mencari...',
  className,
  disabled,
  invalid,
  clearable,
  emptyText = 'Tidak ditemukan',
}: SearchableSelectProps) {
  const selected = options.find((o) => o.value === value) ?? null

  return (
    <Combobox.Root
      items={options}
      value={selected}
      onValueChange={(o) => onChange((o as SelectOption | null)?.value ?? '')}
      itemToStringLabel={(o: SelectOption) => o.label}
      isItemEqualToValue={(a: SelectOption, b: SelectOption) => a.value === b.value}
      disabled={disabled}
      autoHighlight
    >
      <div className={cn('relative w-full', className)}>
        <Combobox.Input
          id={id}
          placeholder={placeholder}
          aria-invalid={invalid || undefined}
          className="h-8 w-full min-w-0 rounded-lg border border-input bg-transparent py-1 pr-14 pl-2.5 text-sm transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 dark:bg-input/30 dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40"
        />
        <div className="absolute inset-y-0 right-1 flex items-center gap-0.5">
          {clearable && selected && (
            <Combobox.Clear
              aria-label="Kosongkan"
              className="flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
            >
              <XIcon className="size-3.5" />
            </Combobox.Clear>
          )}
          <Combobox.Trigger
            aria-label="Buka pilihan"
            className="flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-muted"
          >
            <ChevronDownIcon className="size-4" />
          </Combobox.Trigger>
        </div>
      </div>

      <Combobox.Portal>
        <Combobox.Positioner sideOffset={4} className="isolate z-50">
          <Combobox.Popup className="max-h-[min(var(--available-height),20rem)] w-(--anchor-width) min-w-48 origin-(--transform-origin) overflow-y-auto rounded-lg bg-popover p-1 text-popover-foreground shadow-md ring-1 ring-foreground/10 duration-100 data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95">
            <Combobox.Empty className="px-2 py-3 text-center text-sm text-muted-foreground empty:hidden">{emptyText}</Combobox.Empty>
            <Combobox.List>
              {(o: SelectOption) => (
                <Combobox.Item
                  key={o.value}
                  value={o}
                  className="relative flex w-full cursor-default items-center rounded-md py-1.5 pr-8 pl-2 text-sm outline-hidden select-none data-highlighted:bg-accent data-highlighted:text-accent-foreground"
                >
                  {o.label}
                  <Combobox.ItemIndicator className="pointer-events-none absolute right-2 flex size-4 items-center justify-center">
                    <CheckIcon className="size-4" />
                  </Combobox.ItemIndicator>
                </Combobox.Item>
              )}
            </Combobox.List>
          </Combobox.Popup>
        </Combobox.Positioner>
      </Combobox.Portal>
    </Combobox.Root>
  )
}
