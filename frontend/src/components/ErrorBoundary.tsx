import { Component, type ErrorInfo, type ReactNode } from 'react'
import { AlertTriangle, LayoutDashboard, RotateCw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { isChunkLoadError, reloadOnceForNewVersion } from '@/lib/chunk-reload'

interface Props {
  children: ReactNode
  /** Ganti nilainya (mis. pathname) untuk mereset boundary saat pindah halaman. */
  resetKey?: string
}

interface State {
  error: Error | null
  resetKey?: string
}

/** Menangkap error render agar layar tidak kosong; error muat-chunk dicoba reload sekali ke versi terbaru. */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null, resetKey: this.props.resetKey }

  static getDerivedStateFromError(error: Error): Partial<State> {
    return { error }
  }

  static getDerivedStateFromProps(props: Props, state: State): Partial<State> | null {
    return props.resetKey !== state.resetKey ? { error: null, resetKey: props.resetKey } : null
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    if (isChunkLoadError(error) && reloadOnceForNewVersion()) return
    console.error(error, info.componentStack)
  }

  render() {
    const { error } = this.state
    if (!error) return this.props.children

    const chunk = isChunkLoadError(error)

    return (
      <div className="flex justify-center p-4">
        <Card className="w-full max-w-md">
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <AlertTriangle className="size-5 text-destructive" />
              {chunk ? 'Versi aplikasi sudah diperbarui' : 'Terjadi kesalahan saat menampilkan halaman'}
            </CardTitle>
            <CardDescription>
              {chunk
                ? 'Halaman ini memakai versi lama. Muat ulang untuk mengambil versi terbaru.'
                : 'Coba muat ulang halaman. Jika masih terjadi, hubungi administrator.'}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-3">
            {import.meta.env.DEV && <pre className="max-h-40 overflow-auto rounded bg-muted p-2 text-xs whitespace-pre-wrap">{error.message}</pre>}
            <div className="flex flex-wrap gap-2">
              <Button onClick={() => window.location.reload()}>
                <RotateCw /> Muat ulang
              </Button>
              <Button variant="outline" onClick={() => window.location.assign(import.meta.env.BASE_URL)}>
                <LayoutDashboard /> Ke Dashboard
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    )
  }
}
