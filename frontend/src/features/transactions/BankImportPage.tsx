import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeft, FileUp, Loader2, Plus, Repeat, Save, Split, Trash2, X } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { FormField } from '@/components/FormField'
import { PageHeader } from '@/components/PageHeader'
import { SearchableSelect } from '@/components/SearchableSelect'
import { SelectField } from '@/components/SelectField'
import { api, errorMessage } from '@/lib/api'
import { formatDate, formatRupiah } from '@/lib/format'
import { useCashAccounts, useCategories, useCounterparties, useRecurringExpenses } from '@/lib/queries'
import { cn } from '@/lib/utils'
import type { BankImportPreview, BankImportRow, BankImportStatus, Category } from '@/types'

interface EditLine {
  uid: number
  category_id: string
  amount: string
  counterparty: string
  description: string
  recurring_expense_id: number | null
  recurring_period: string | null
}

interface EditRow extends Omit<BankImportRow, 'lines'> {
  include: boolean
  lines: EditLine[]
}

type ViewFilter = 'all' | 'todo' | 'duplicate'

const STATUS: Record<BankImportStatus, { label: string; variant: 'secondary' | 'outline' | 'destructive' }> = {
  new: { label: 'Baru', variant: 'secondary' },
  possible_duplicate: { label: 'Mungkin duplikat', variant: 'destructive' },
  imported: { label: 'Sudah diimpor', variant: 'outline' },
}

let uidSeq = 0
const nextUid = () => ++uidSeq

const cents = (v: number) => Math.round(v * 100)
const lineTotal = (row: EditRow) => row.lines.reduce((s, l) => s + (Number(l.amount) || 0), 0)
const isRowValid = (row: EditRow) =>
  row.lines.length > 0 && row.lines.every((l) => l.category_id !== '' && Number(l.amount) > 0) && cents(lineTotal(row)) === cents(row.amount)

function toEditRows(preview: BankImportPreview): EditRow[] {
  return preview.rows.map((r) => ({
    ...r,
    include: r.status === 'new',
    lines: r.lines.map((l) => ({
      uid: nextUid(),
      category_id: l.category_id ? String(l.category_id) : '',
      amount: String(l.amount),
      counterparty: l.counterparty ?? '',
      description: l.description ?? '',
      recurring_expense_id: l.recurring_expense_id,
      recurring_period: l.recurring_period,
    })),
  }))
}

export default function BankImportPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [file, setFile] = useState<File | null>(null)
  const [cashAccountId, setCashAccountId] = useState('')
  const [preview, setPreview] = useState<BankImportPreview | null>(null)
  const [rows, setRows] = useState<EditRow[]>([])
  const [view, setView] = useState<ViewFilter>('all')

  const { data: cashAccounts = [] } = useCashAccounts({ activeOnly: true })
  const { data: categories = [] } = useCategories({ activeOnly: true, excludeTransfer: true })
  const { data: recurring = [] } = useRecurringExpenses()
  const { data: names = [] } = useCounterparties('')

  const read = useMutation({
    mutationFn: async () => {
      const form = new FormData()
      form.append('file', file as File)
      if (cashAccountId) form.append('cash_account_id', cashAccountId)
      return (await api.post<BankImportPreview>('/transactions/import/preview', form)).data
    },
    onSuccess: (data) => {
      setPreview(data)
      setRows(toEditRows(data))
      if (data.cash_account_id) setCashAccountId(String(data.cash_account_id))
      setView('all')
      if (!data.cash_account_id) toast.warning('Rekening tidak dikenali. Pilih kas tujuan lalu baca ulang file.')
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const selected = rows.filter((r) => r.include)
  const invalidCount = selected.filter((r) => !isRowValid(r)).length
  const transactionCount = selected.reduce((s, r) => s + r.lines.length, 0)

  const save = useMutation({
    mutationFn: () =>
      api.post<{ message: string; count: number }>('/transactions/import', {
        cash_account_id: Number(cashAccountId),
        rows: selected.map((r) => ({
          key: r.key,
          date: r.date,
          type: r.type,
          amount: r.amount,
          description: r.description,
          lines: r.lines.map((l) => ({
            category_id: Number(l.category_id),
            amount: Number(l.amount),
            counterparty: l.counterparty.trim() || null,
            description: l.description.trim() || null,
            recurring_expense_id: l.recurring_expense_id,
            recurring_period: l.recurring_period,
          })),
        })),
      }),
    onSuccess: (res) => {
      toast.success(res.data.message)
      for (const key of ['transactions', 'dashboard', 'reports', 'recurring-status', 'counterparties']) {
        queryClient.invalidateQueries({ queryKey: [key] })
      }
      navigate('/transactions')
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const updateRow = (key: string, fn: (row: EditRow) => EditRow) => setRows((rs) => rs.map((r) => (r.key === key ? fn(r) : r)))

  const visible = rows.filter((r) =>
    view === 'todo' ? r.include && !isRowValid(r) : view === 'duplicate' ? r.status !== 'new' : true,
  )

  // Proyeksi saldo aplikasi setelah import vs saldo akhir bank.
  const signedSelected = selected.reduce((s, r) => s + (r.type === 'income' ? r.amount : -r.amount), 0)
  const projected = preview?.summary.app_until_last_date != null ? preview.summary.app_until_last_date + signedSelected : null
  const closingDiff = projected != null && preview?.summary.bank_closing != null ? preview.summary.bank_closing - projected : null

  return (
    <>
      <PageHeader
        title="Import Mutasi Bank"
        description="Unggah CSV mutasi rekening (KlikBCA Bisnis), beri kategori, lalu simpan sebagai transaksi"
        actions={
          <Button variant="outline" render={<Link to="/transactions" />}>
            <ArrowLeft /> Kembali
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-wrap items-end gap-3">
          <div className="grid min-w-64 flex-1 gap-1.5">
            <span className="text-sm font-medium">File CSV</span>
            <Input
              type="file"
              accept=".csv,text/csv"
              onChange={(e) => {
                setFile(e.target.files?.[0] ?? null)
                setPreview(null)
                setRows([])
              }}
            />
          </div>
          <div className="grid min-w-56 gap-1.5">
            <span className="text-sm font-medium">Kas / Rekening tujuan</span>
            <SelectField
              value={cashAccountId}
              onChange={setCashAccountId}
              placeholder="Deteksi otomatis"
              options={cashAccounts.map((c) => ({ value: String(c.id), label: c.account_number ? `${c.name} · ${c.account_number}` : c.name }))}
            />
          </div>
          <Button disabled={!file || read.isPending} onClick={() => read.mutate()}>
            {read.isPending ? <Loader2 className="animate-spin" /> : <FileUp />} Baca File
          </Button>
        </CardContent>
      </Card>

      {preview && (
        <>
          <div className="grid gap-4 md:grid-cols-3">
            <SummaryCard
              title="Rekening"
              value={preview.account_number ?? '-'}
              hint={[preview.account_name, preview.period].filter(Boolean).join(' · ')}
            />
            <SummaryCard
              title="Saldo awal bank vs aplikasi"
              value={formatRupiah(preview.summary.bank_opening)}
              hint={preview.summary.app_opening != null ? `Aplikasi ${formatRupiah(preview.summary.app_opening)}` : 'Pilih kas tujuan'}
              diff={preview.summary.opening_difference}
            />
            <SummaryCard
              title="Saldo akhir bank vs aplikasi (setelah import)"
              value={preview.summary.bank_closing != null ? formatRupiah(preview.summary.bank_closing) : '-'}
              hint={projected != null ? `Aplikasi ${formatRupiah(projected)}` : 'Pilih kas tujuan'}
              diff={closingDiff}
            />
          </div>

          {preview.last_manual_date && (
            <p className="text-sm text-muted-foreground">
              Transaksi manual terakhir di kas ini: <strong>{formatDate(preview.last_manual_date)}</strong>. Baris pada/sebelum tanggal itu ditandai
              <em> mungkin duplikat</em> dan tidak dicentang — periksa sebelum ikut disimpan.
            </p>
          )}

          <div className="flex flex-wrap items-center justify-between gap-3">
            <Tabs value={view} onValueChange={(v) => setView(v as ViewFilter)}>
              <TabsList>
                <TabsTrigger value="all">Semua ({rows.length})</TabsTrigger>
                <TabsTrigger value="todo">Perlu diisi ({invalidCount})</TabsTrigger>
                <TabsTrigger value="duplicate">Duplikat / sudah ada ({rows.filter((r) => r.status !== 'new').length})</TabsTrigger>
              </TabsList>
            </Tabs>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" onClick={() => setRows((rs) => rs.map((r) => ({ ...r, include: r.status !== 'imported' })))}>
                Centang semua
              </Button>
              <Button variant="outline" size="sm" onClick={() => setRows((rs) => rs.map((r) => ({ ...r, include: false })))}>
                Hapus centang
              </Button>
            </div>
          </div>

          <datalist id="import-counterparties">
            {names.map((n) => (
              <option key={n} value={n} />
            ))}
          </datalist>

          <div className="grid gap-3">
            {visible.length === 0 && <p className="py-8 text-center text-sm text-muted-foreground">Tidak ada baris.</p>}
            {visible.map((row) => (
              <ImportRowCard
                key={row.key}
                row={row}
                categories={categories}
                recurringName={(id) => recurring.find((r) => r.id === id)?.name}
                onChange={(fn) => updateRow(row.key, fn)}
              />
            ))}
          </div>

          <div className="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center justify-between gap-3 border-t bg-background/95 px-4 py-3 backdrop-blur md:-mx-6 md:px-6">
            <span className="text-sm text-muted-foreground">
              {selected.length} baris dipilih → {transactionCount} transaksi
              {invalidCount > 0 && <span className="text-destructive"> · {invalidCount} baris belum lengkap</span>}
            </span>
            <Button disabled={selected.length === 0 || invalidCount > 0 || !cashAccountId || save.isPending} onClick={() => save.mutate()}>
              {save.isPending ? <Loader2 className="animate-spin" /> : <Save />} Simpan {transactionCount} Transaksi
            </Button>
          </div>
        </>
      )}
    </>
  )
}

function SummaryCard({ title, value, hint, diff }: { title: string; value: string; hint?: string; diff?: number | null }) {
  return (
    <Card>
      <CardHeader className="pb-1">
        <CardDescription>{title}</CardDescription>
        <CardTitle className="text-xl tabular-nums">{value}</CardTitle>
      </CardHeader>
      <CardContent className="text-xs text-muted-foreground">
        {hint}
        {diff != null && (
          <div className={cn('mt-1 font-medium', Math.abs(diff) < 0.01 ? 'text-emerald-600' : 'text-destructive')}>
            {Math.abs(diff) < 0.01 ? 'Cocok ✓' : `Selisih ${formatRupiah(diff)}`}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

interface ImportRowCardProps {
  row: EditRow
  categories: Category[]
  recurringName: (id: number) => string | undefined
  onChange: (fn: (row: EditRow) => EditRow) => void
}

function ImportRowCard({ row, categories, recurringName, onChange }: ImportRowCardProps) {
  const options = useMemo(
    () =>
      categories
        .filter((c) => c.type === row.type || c.type === 'both')
        .map((c) => ({ value: String(c.id), label: `${c.code} · ${c.name}` })),
    [categories, row.type],
  )
  const remaining = (cents(row.amount) - cents(lineTotal(row))) / 100
  const valid = isRowValid(row)
  const disabled = row.status === 'imported'

  const setLine = (uid: number, patch: Partial<EditLine>) =>
    onChange((r) => ({ ...r, lines: r.lines.map((l) => (l.uid === uid ? { ...l, ...patch } : l)) }))

  const split = () =>
    onChange((r) => {
      const last = r.lines[r.lines.length - 1]
      const rest = (cents(r.amount) - cents(lineTotal(r))) / 100
      return {
        ...r,
        lines: [
          ...r.lines,
          { uid: nextUid(), category_id: '', amount: rest > 0 ? String(rest) : '', counterparty: last?.counterparty ?? '', description: '', recurring_expense_id: null, recurring_period: null },
        ],
      }
    })

  return (
    <Card className={cn('gap-3 py-3', !row.include && 'opacity-60', row.include && !valid && 'ring-1 ring-destructive/50')}>
      <div className="flex flex-wrap items-start gap-3 px-4">
        <input
          type="checkbox"
          className="mt-1 size-4 accent-primary"
          checked={row.include}
          disabled={disabled}
          aria-label="Ikut disimpan"
          onChange={(e) => onChange((r) => ({ ...r, include: e.target.checked }))}
        />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-medium whitespace-nowrap">{formatDate(row.date)}</span>
            <Badge variant={row.type === 'income' ? 'secondary' : 'outline'} className="tabular-nums">
              {row.type === 'income' ? '+' : '−'} {formatRupiah(row.amount)}
            </Badge>
            <Badge variant={STATUS[row.status].variant}>{STATUS[row.status].label}</Badge>
            {row.name && <span className="text-sm text-muted-foreground">{row.name}</span>}
          </div>
          <p className="mt-0.5 truncate font-mono text-xs text-muted-foreground" title={row.description}>
            {row.description}
          </p>
          {row.status_reason && <p className="text-xs text-destructive">{row.status_reason}</p>}
        </div>
      </div>

      {row.include && (
        <CardContent className="grid gap-2 px-4">
          {row.lines.map((line, i) => (
            <div key={line.uid} className="grid gap-2 rounded-lg border p-2 md:grid-cols-[2fr_1.4fr_2fr_1fr_auto] md:items-end md:border-0 md:p-0">
              <FormField label={i === 0 ? 'Kategori' : ''}>
                <SearchableSelect
                  value={line.category_id}
                  onChange={(v) => setLine(line.uid, { category_id: v })}
                  options={options}
                  placeholder="Ketik kategori..."
                  emptyText="Kategori tidak ditemukan"
                  invalid={line.category_id === ''}
                />
              </FormField>
              <FormField label={i === 0 ? 'Nama' : ''}>
                <Input list="import-counterparties" value={line.counterparty} placeholder="Jemaat / penerima" onChange={(e) => setLine(line.uid, { counterparty: e.target.value })} />
              </FormField>
              <FormField label={i === 0 ? 'Keterangan' : ''}>
                <Input value={line.description} placeholder="Otomatis: kategori + nama" onChange={(e) => setLine(line.uid, { description: e.target.value })} />
              </FormField>
              <FormField label={i === 0 ? 'Nominal' : ''}>
                <Input
                  type="number"
                  min={0}
                  step="any"
                  inputMode="decimal"
                  value={line.amount}
                  aria-invalid={!(Number(line.amount) > 0) || undefined}
                  onChange={(e) => setLine(line.uid, { amount: e.target.value })}
                />
              </FormField>
              <Button
                variant="ghost"
                size="icon"
                aria-label="Hapus pecahan"
                disabled={row.lines.length === 1}
                onClick={() => onChange((r) => ({ ...r, lines: r.lines.filter((l) => l.uid !== line.uid) }))}
              >
                <Trash2 />
              </Button>
              {line.recurring_expense_id && (
                <div className="md:col-span-5">
                  <Badge variant="outline" className="gap-1">
                    <Repeat className="size-3" /> Biaya rutin: {recurringName(line.recurring_expense_id) ?? `#${line.recurring_expense_id}`} ({line.recurring_period})
                    <button type="button" aria-label="Lepas biaya rutin" onClick={() => setLine(line.uid, { recurring_expense_id: null, recurring_period: null })}>
                      <X className="size-3" />
                    </button>
                  </Badge>
                </div>
              )}
            </div>
          ))}
          <div className="flex flex-wrap items-center justify-between gap-2">
            <Button variant="outline" size="sm" onClick={split}>
              {row.lines.length === 1 ? <Split /> : <Plus />} Pecah ke kategori lain
            </Button>
            <span className={cn('text-xs tabular-nums', Math.abs(remaining) < 0.005 ? 'text-muted-foreground' : 'font-medium text-destructive')}>
              {Math.abs(remaining) < 0.005 ? 'Total pecahan sesuai' : `Sisa belum dibagi: ${formatRupiah(remaining)}`}
            </span>
          </div>
        </CardContent>
      )}
    </Card>
  )
}
