/** Warna angka bertanda: negatif = merah (kas menalangi / kurang). */
export const signedClass = (value: number) => (value < 0 ? 'text-destructive' : '')

/** Kode kategori sementara untuk nota LPJ yang belum dikategorikan (sama dengan backend). */
export const UNCATEGORIZED_CODE = 'LPJ-BELUM'
