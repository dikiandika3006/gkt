import { format, parseISO } from 'date-fns'
import { id } from 'date-fns/locale'

const rupiah = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
})

export const formatRupiah = (value: number) => rupiah.format(value)

export const formatCompact = (value: number) =>
  new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(value)

export const formatDate = (value: string, pattern = 'd MMM yyyy') =>
  format(parseISO(value), pattern, { locale: id })

export const toDateInput = (date: Date) => format(date, 'yyyy-MM-dd')
