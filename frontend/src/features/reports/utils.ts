/** Nilai select "semua". */
export const ALL = '__all'

export const toneOf = (v: number): 'negative' | 'positive' => (v < 0 ? 'negative' : 'positive')
