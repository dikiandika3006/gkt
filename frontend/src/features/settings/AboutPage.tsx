import { ChevronRight } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { PageHeader } from '@/components/PageHeader'
import { formatDate } from '@/lib/format'
import { APP_NAME, CHANGELOG, RULES } from './about'

/** Setting → About: info aplikasi, aturan sistem, dan riwayat perubahan. */
export default function AboutPage() {
  const latest = CHANGELOG[0]

  return (
    <>
      <PageHeader title="About" description="Info aplikasi, aturan yang berlaku, dan riwayat perubahan." />

      <div className="grid max-w-4xl gap-4">
        <div className="grid gap-4 sm:grid-cols-3">
          <Info label="Aplikasi" value={APP_NAME} />
          <Info label="Pembaruan terakhir" value={latest ? formatDate(latest.date) : '-'} />
          <Info label="Jumlah pembaruan" value={`${CHANGELOG.length} catatan`} />
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Aturan Sistem</CardTitle>
            <CardDescription>Aturan yang berlaku di aplikasi. Klik judul untuk membuka detail.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-1">
            {Object.entries(RULES).map(([title, rules]) => (
              <details key={title} className="group rounded-lg border px-3 py-2">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-2 text-sm font-medium">
                  <span className="flex items-center gap-1.5">
                    <ChevronRight className="size-4 transition group-open:rotate-90" />
                    {title}
                  </span>
                  <span className="text-xs font-normal text-muted-foreground">{rules.length} aturan</span>
                </summary>
                <ul className="mt-2 ml-6 list-disc space-y-1 text-sm text-muted-foreground">
                  {rules.map((r) => (
                    <li key={r}>{r}</li>
                  ))}
                </ul>
              </details>
            ))}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Riwayat Perubahan</CardTitle>
            <CardDescription>Terbaru di paling atas.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4">
            {CHANGELOG.map((entry, i) => (
              <div key={`${entry.date}-${entry.title}`} className="grid gap-1 border-l-2 pl-3">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                  <span className="font-semibold">{formatDate(entry.date)}</span>
                  <span>· {entry.title}</span>
                  {i === 0 && <Badge variant="secondary">Terbaru</Badge>}
                </div>
                <ul className="ml-4 list-disc space-y-0.5 text-sm text-muted-foreground">
                  {entry.changes.map((c) => (
                    <li key={c}>{c}</li>
                  ))}
                </ul>
              </div>
            ))}
          </CardContent>
        </Card>
      </div>
    </>
  )
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <Card className="gap-1 py-4">
      <CardContent className="grid gap-1">
        <span className="text-xs text-muted-foreground">{label}</span>
        <span className="font-semibold">{value}</span>
      </CardContent>
    </Card>
  )
}
