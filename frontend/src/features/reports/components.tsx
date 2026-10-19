import { useState, type ReactNode } from 'react'
import { toast } from 'sonner'
import { FileSpreadsheet, Loader2, Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { SelectField } from '@/components/SelectField'
import { downloadFile, errorMessage } from '@/lib/api'
import { formatRupiah } from '@/lib/format'
import { useCashAccounts } from '@/lib/queries'
import { cn } from '@/lib/utils'
import { ALL } from './utils'


/** Bar filter + tombol Export Excel & Cetak (disembunyikan saat print). */
export function ReportToolbar({ children, exportName, exportParams, filename }: { children: ReactNode; exportName: string; exportParams: Record<string, unknown>; filename: string }) {
  const [exporting, setExporting] = useState(false)

  const exportExcel = async () => {
    setExporting(true)
    try {
      await downloadFile(`/reports/${exportName}/export`, filename, exportParams)
    } catch (err) {
      toast.error(errorMessage(err, 'Gagal mengekspor.'))
    } finally {
      setExporting(false)
    }
  }

  return (
    <Card className="print:hidden">
      <CardContent className="flex flex-wrap items-end gap-3">
        {children}
        <div className="ml-auto flex gap-2">
          <Button variant="outline" onClick={exportExcel} disabled={exporting}>
            {exporting ? <Loader2 className="animate-spin" /> : <FileSpreadsheet />} Excel
          </Button>
          <Button variant="outline" onClick={() => window.print()}>
            <Printer /> Cetak / PDF
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

export function Field({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
  return (
    <div className={cn('grid gap-1', className)}>
      <span className="text-xs text-muted-foreground">{label}</span>
      {children}
    </div>
  )
}

export function DateInput({ value, onChange, type = 'date' }: { value: string; onChange: (v: string) => void; type?: 'date' | 'month' }) {
  return <Input type={type} value={value} onChange={(e) => e.target.value && onChange(e.target.value)} className="w-40" />
}

export function CashAccountFilter({ value, onChange, allowAll = true, onlyCash = false }: { value: string; onChange: (v: string) => void; allowAll?: boolean; onlyCash?: boolean }) {
  const { data: cashAccounts = [] } = useCashAccounts()
  const options = cashAccounts.filter((c) => !onlyCash || c.type === 'cash').map((c) => ({ value: String(c.id), label: c.name }))

  return (
    <Field label="Kas / Rekening" className="min-w-48">
      <SelectField
        value={value}
        onChange={(v) => onChange(v === ALL ? '' : v)}
        placeholder="Semua kas"
        options={allowAll ? [{ value: ALL, label: 'Semua kas' }, ...options] : options}
      />
    </Field>
  )
}

export function SummaryCard({ label, value, hint, tone }: { label: string; value: number; hint?: string; tone?: 'positive' | 'negative' }) {
  return (
    <Card>
      <CardHeader className="pb-1">
        <CardDescription>{label}</CardDescription>
        <CardTitle className={cn('text-xl tabular-nums', tone === 'negative' && 'text-destructive', tone === 'positive' && 'text-success')}>
          {formatRupiah(value)}
        </CardTitle>
      </CardHeader>
      {hint && <CardContent className="text-xs text-muted-foreground">{hint}</CardContent>}
    </Card>
  )
}

/** Judul laporan yang hanya muncul saat dicetak. */
export function PrintTitle({ title, subtitle }: { title: string; subtitle: string }) {
  return (
    <div className="hidden print:block">
      <h2 className="text-lg font-semibold">GKT Sinai — {title}</h2>
      <p className="text-sm">{subtitle}</p>
    </div>
  )
}
