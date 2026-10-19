import axios, { AxiosError } from 'axios'

const TOKEN_KEY = 'gkt_token'

export const tokenStorage = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (token: string) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = tokenStorage.get()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response?.status === 401 && tokenStorage.get()) {
      tokenStorage.clear()
      window.location.assign(`${import.meta.env.BASE_URL}login`)
    }
    return Promise.reject(error)
  },
)

/** Unduh file dari endpoint ber-auth (ekspor Excel, lampiran) lalu simpan / buka di tab baru. */
export async function downloadFile(url: string, filename: string, params?: Record<string, unknown>, openInTab = false) {
  const res = await api.get<Blob>(url, { params, responseType: 'blob' })
  const href = URL.createObjectURL(res.data)
  if (openInTab) {
    window.open(href, '_blank', 'noopener')
  } else {
    const a = document.createElement('a')
    a.href = href
    a.download = filename
    a.click()
  }
  setTimeout(() => URL.revokeObjectURL(href), 60_000)
}

type LaravelError = { message?: string; errors?: Record<string, string[]> }

/** Ambil pesan error yang ramah dari respons Laravel. */
export function errorMessage(error: unknown, fallback = 'Terjadi kesalahan.'): string {
  if (error instanceof AxiosError) {
    const data = error.response?.data as LaravelError | undefined
    const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined
    return firstFieldError ?? data?.message ?? fallback
  }
  return fallback
}

/** Error validasi per field (422) untuk ditampilkan di form. */
export function fieldErrors(error: unknown): Record<string, string> {
  if (error instanceof AxiosError && error.response?.status === 422) {
    const errors = (error.response.data as LaravelError).errors ?? {}
    return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]]))
  }
  return {}
}
