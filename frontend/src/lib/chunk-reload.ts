const KEY = 'gkt_chunk_reload_at'
const GUARD_MS = 30_000

/** Error karena file JS halaman (chunk) tidak bisa dimuat — biasanya tab lama setelah deploy baru. */
export function isChunkLoadError(error: unknown): boolean {
  const message = error instanceof Error ? `${error.name} ${error.message}` : String(error)
  return /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module|ChunkLoadError|Loading chunk .* failed|is not a valid JavaScript MIME type/i.test(message)
}

/**
 * Muat ulang halaman sekali untuk mengambil versi terbaru.
 * Mengembalikan false bila baru saja reload (mencegah loop) — pemanggil sebaiknya menampilkan pesan error.
 */
export function reloadOnceForNewVersion(): boolean {
  try {
    const last = Number(sessionStorage.getItem(KEY) ?? 0)
    if (Date.now() - last < GUARD_MS) return false
    sessionStorage.setItem(KEY, String(Date.now()))
  } catch {
    // sessionStorage tidak tersedia (mode privat tertentu): tetap reload sekali.
  }
  window.location.reload()
  return true
}
