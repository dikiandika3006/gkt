import type { NameSuggestion } from '@/types'

/** Daftar saran untuk <Input list={id}>: nilai = label (001/SVN / nama), keterangan = nama lengkap jemaat bila ada. */
export function NameDatalist({ id, items }: { id: string; items: NameSuggestion[] }) {
  return (
    <datalist id={id}>
      {items.map((n) => (
        <option key={n.value} value={n.value}>
          {n.hint ?? undefined}
        </option>
      ))}
    </datalist>
  )
}
