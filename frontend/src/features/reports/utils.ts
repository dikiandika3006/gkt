/** Nilai select "semua". */
export const ALL = '__all'

export const toneOf = (v: number): 'negative' | 'positive' => (v < 0 ? 'negative' : 'positive')

/** Persentase perubahan dibanding pembanding (teks), mis. "+12,5%". */
export const deltaLabel = (now: number, before: number) =>
  before === 0 ? '—' : `${now >= before ? '+' : ''}${(((now - before) / Math.abs(before)) * 100).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`
