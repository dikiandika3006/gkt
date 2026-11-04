const MAX_SIDE = 2000
const QUALITY = 0.82

/**
 * Perkecil foto (mis. dari kamera HP, 4–12 MB) menjadi JPEG ±300–800 KB sebelum diunggah.
 * PDF dan gambar yang tidak bisa dibaca browser (mis. HEIC di Chrome) dikembalikan apa adanya.
 */
export async function compressImage(file: File): Promise<File> {
  if (!file.type.startsWith('image/') || file.type === 'image/gif') return file

  let bitmap: ImageBitmap
  try {
    bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
  } catch {
    return file
  }

  const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height))
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(bitmap.width * scale)
  canvas.height = Math.round(bitmap.height * scale)
  const ctx = canvas.getContext('2d')
  if (!ctx) return file

  // Latar putih agar gambar transparan (PNG) tidak jadi hitam di JPEG.
  ctx.fillStyle = '#fff'
  ctx.fillRect(0, 0, canvas.width, canvas.height)
  ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
  bitmap.close()

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY))
  if (!blob || (blob.size >= file.size && file.type === 'image/jpeg')) return file

  const name = file.name.replace(/\.[^.]+$/, '') || 'foto'
  return new File([blob], `${name}.jpg`, { type: 'image/jpeg', lastModified: Date.now() })
}
