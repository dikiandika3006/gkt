import { useState } from 'react'

/**
 * Paging di sisi browser untuk daftar yang API-nya mengembalikan array utuh.
 * Halaman dijaga tetap valid saat data berkurang (mis. habis menghapus/memfilter).
 */
export function useClientPagination<T>(items: T[], initialSize = 15) {
  const [page, setPage] = useState(1)
  const [pageSize, setPageSizeState] = useState(initialSize)

  const lastPage = Math.max(1, Math.ceil(items.length / pageSize))
  const currentPage = Math.min(page, lastPage)

  return {
    rows: items.slice((currentPage - 1) * pageSize, currentPage * pageSize),
    page: currentPage,
    pageSize,
    total: items.length,
    setPage,
    setPageSize: (size: number) => {
      setPageSizeState(size)
      setPage(1)
    },
  }
}
