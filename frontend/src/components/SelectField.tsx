import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { cn } from '@/lib/utils'

export interface SelectOption {
  value: string
  label: string
}

interface SelectFieldProps {
  id?: string
  value: string
  onChange: (value: string) => void
  options: SelectOption[]
  placeholder?: string
  className?: string
  disabled?: boolean
  invalid?: boolean
}

/** Select sederhana berbasis string. Nilai '' = belum dipilih / semua. */
export function SelectField({ id, value, onChange, options, placeholder = 'Pilih...', className, disabled, invalid }: SelectFieldProps) {
  return (
    <Select
      items={options}
      value={value === '' ? null : value}
      onValueChange={(v) => onChange((v as string | null) ?? '')}
      disabled={disabled}
    >
      <SelectTrigger id={id} className={cn('w-full', className)} aria-invalid={invalid || undefined}>
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent>
        {options.map((o) => (
          <SelectItem key={o.value} value={o.value}>
            {o.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
