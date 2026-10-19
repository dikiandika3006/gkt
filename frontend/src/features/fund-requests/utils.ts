/** Warna angka bertanda: negatif = merah (kas menalangi / kurang). */
export const signedClass = (value: number) => (value < 0 ? 'text-destructive' : '')
