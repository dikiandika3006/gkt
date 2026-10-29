import type { ComponentProps } from 'react'
import { Input } from '@/components/ui/input'

const group = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 })

interface MoneyInputProps extends Omit<ComponentProps<typeof Input>, 'value' | 'onChange' | 'type'> {
  /** Angka polos tanpa pemisah, mis. "1229000" ('' = kosong). */
  value: string
  onChange: (value: string) => void
}

/** Input nominal rupiah: tampil "1.229.000" saat diketik, nilai yang disimpan tetap angka polos. */
export function MoneyInput({ value, onChange, ...props }: MoneyInputProps) {
  // Nilai lama bisa berisi desimal (mis. "1229000.00") — ambil bagian bulatnya saja.
  const digits = String(value ?? '').split('.')[0].replace(/\D/g, '')

  return (
    <Input
      {...props}
      type="text"
      inputMode="numeric"
      autoComplete="off"
      value={digits ? group.format(Number(digits)) : ''}
      onChange={(e) => onChange(e.target.value.replace(/\D/g, '').replace(/^0+(?=\d)/, ''))}
    />
  )
}
