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
import { useAccessibleCashAccounts, useCategories, useCounterparties, useRecurringExpenses } from '@/lib/queries'
import { cn } from '@/lib/utils'
import type { BankImportPreview, BankImportRow, BankImportStatus, Category, ImportColumnRole, ImportDetection } from '@/types'

interface EditLine {
  uid: number
  category_id: string
  amount: string
  counterparty: string
  description: string
  recurring_expense_id: number | null
  recurring_period: string | null
}

interface EditRow extends Omit<BankImportRow, 'lines' | 'pocket_id'> {
  include: boolean
  pocket_id: string
  lines: EditLine[]
}

/** Koreksi deteksi kolom dari layar; dikirim ulang ke preview. */
interface DetectionOverride {
  sheet?: string
  header_row?: number
  mapping?: Partial<Record<ImportColumnRole, number | null>>
  direction?: 'bank' | 'book'
}

const ROLE_LABELS: Record<ImportColumnRole, string> = {
  date: 'Tanggal',
  description: 'Keterangan',
  debit: 'Debet',
  credit: 'Kredit',
  amount: 'Nominal (satu kolom)',
  direction: 'Jenis (DB/CR, masuk/keluar)',
  balance: 'Saldo',
  pocket: 'Pocket',
  name: 'Nama',
}
const ROLES = Object.keys(ROLE_LABELS) as ImportColumnRole[]
const IGNORE = '__ignore'

/** Pemetaan yang pernah dikoreksi user, diingat per susunan judul kolom (hanya di browser ini). */
const signatureOf = (d: ImportDetection) => `import-mapping:${d.columns.map((c) => c.header.trim().toLowerCase()).join('|')}`
function loadSaved(d: ImportDetection): DetectionOverride | null {
  try {
    const raw = localStorage.getItem(signatureOf(d))
    return raw ? (JSON.parse(raw) as DetectionOverride) : null
  } catch {
    return null
  }
}
function saveOverride(d: ImportDetection, o: DetectionOverride) {
  try {
    localStorage.setItem(signatureOf(d), JSON.stringify({ mapping: o.mapping, direction: o.direction }))
  } catch {
    // penyimpanan browser tidak tersedia — abaikan
  }
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
const isRowValid = (row: EditRow, needsPocket: boolean) =>
  row.lines.length > 0 &&
  row.lines.every((l) => l.category_id !== '' && Number(l.amount) > 0) &&
  cents(lineTotal(row)) === cents(row.amount) &&
  (!needsPocket || row.pocket_id !== '')

function toEditRows(preview: BankImportPreview): EditRow[] {
  return preview.rows.map((r) => ({
    ...r,
    include: r.status === 'new',
    pocket_id: r.pocket_id ? String(r.pocket_id) : '',
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
  const [override, setOverride] = useState<DetectionOverride>({})
  const [confirmNegative, setConfirmNegative] = useState(false)

  const { data: cashAccounts = [] } = useAccessibleCashAccounts({ activeOnly: true })
  const { data: categories = [] } = useCategories({ activeOnly: true, excludeTransfer: true })
  const { data: recurring = [] } = useRecurringExpenses()
  const { data: names = [] } = useCounterparties('')

  const read = useMutation({
    mutationFn: async ({ accountId, custom }: { accountId: string; custom: DetectionOverride }) => {
      const form = new FormData()
      form.append('file', file as File)
      if (accountId) form.append('cash_account_id', accountId)
      if (custom.sheet) form.append('sheet', custom.sheet)
      if (custom.header_row) form.append('header_row', String(custom.header_row))
      if (custom.direction) form.append('direction', custom.direction)
      for (const [role, index] of Object.entries(custom.mapping ?? {})) {
        if (index != null) form.append(`mapping[${role}]`, String(index))
      }
      return { data: (await api.post<BankImportPreview>('/transactions/import/preview', form)).data, custom }
    },
    onSuccess: ({ data, custom }) => {
      // Format yang pernah dikoreksi → pakai pemetaan tersimpan sekali secara otomatis.
      const saved = data.detection && Object.keys(custom).length === 0 ? loadSaved(data.detection) : null
      if (saved) {
        setOverride(saved)
        read.mutate({ accountId: data.cash_account_id ? String(data.cash_account_id) : cashAccountId, custom: saved })
        return
      }
      setPreview(data)
      setRows(toEditRows(data))
      setConfirmNegative(false)
      if (data.cash_account_id) setCashAccountId(String(data.cash_account_id))
      setView('all')
      if (!data.cash_account_id) toast.warning('Rekening tidak dikenali. Pilih kas tujuan.')
      else if (data.rows.length === 0) toast.warning('Belum ada baris terbaca. Atur kolom di panel "Kolom terdeteksi".')
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const readFile = (accountId = cashAccountId, custom = override) => read.mutate({ accountId, custom })
  const applyOverride = (next: DetectionOverride) => {
    setOverride(next)
    if (preview?.detection) saveOverride(preview.detection, next)
    readFile(cashAccountId, next)
  }

  const needsPocket = !!preview?.has_pockets
  const selected = rows.filter((r) => r.include)
  const invalidCount = selected.filter((r) => !isRowValid(r, needsPocket)).length
  const transactionCount = selected.reduce((s, r) => s + r.lines.length, 0)

  // Proyeksi saldo per pocket setelah baris terpilih disimpan.
  const pocketProjection = (preview?.pockets ?? []).map((p) => {
    const change = selected.filter((r) => r.pocket_id === String(p.id)).reduce((s, r) => s + (r.type === 'income' ? r.amount : -r.amount), 0)
    return { ...p, change, after: p.current_balance + change }
  })
  const negativePockets = pocketProjection.filter((p) => p.after < -0.005)

  const save = useMutation({
    mutationFn: () =>
      api.post<{ message: string; count: number }>('/transactions/import', {
        cash_account_id: Number(cashAccountId),
        confirm_negative: confirmNegative,
        rows: selected.map((r) => ({
          key: r.key,
          date: r.date,
          type: r.type,
          amount: r.amount,
          description: r.description,
          pocket_id: r.pocket_id ? Number(r.pocket_id) : null,
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
      for (const key of ['transactions', 'dashboard', 'reports', 'recurring-status', 'counterparties', 'cash-accounts']) {
        queryClient.invalidateQueries({ queryKey: [key] })
      }
      navigate('/transactions')
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const updateRow = (key: string, fn: (row: EditRow) => EditRow) => setRows((rs) => rs.map((r) => (r.key === key ? fn(r) : r)))

  const visible = rows.filter((r) =>
    view === 'todo' ? r.include && !isRowValid(r, needsPocket) : view === 'duplicate' ? r.status !== 'new' : true,
  )
  const pocketOptions = (preview?.pockets ?? []).filter((p) => p.is_active).map((p) => ({ value: String(p.id), label: p.name }))

  // Proyeksi saldo aplikasi setelah import vs saldo akhir bank.
  const signedSelected = selected.reduce((s, r) => s + (r.type === 'income' ? r.amount : -r.amount), 0)
  const projected = preview?.summary.app_until_last_date != null ? preview.summary.app_until_last_date + signedSelected : null
  const closingDiff = projected != null && preview?.summary.bank_closing != null ? preview.summary.bank_closing - projected : null

  return (
    <>
      <PageHeader
        title="Import Mutasi"
        description="Unggah mutasi rekening (CSV KlikBCA, atau CSV/Excel format bebas), periksa kolom & kategori, lalu simpan sebagai transaksi"
        actions={
          <Button variant="outline" render={<Link to="/transactions" />}>
            <ArrowLeft /> Kembali
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-wrap items-end gap-3">
          <div className="grid min-w-64 flex-1 gap-1.5">
            <span className="text-sm font-medium">File CSV / Excel</span>
            <Input
              type="file"
              accept=".csv,.xlsx,.xls,text/csv"
              onChange={(e) => {
                setFile(e.target.files?.[0] ?? null)
                setPreview(null)
                setRows([])
                setOverride({})
              }}
            />
          </div>
          <div className="grid min-w-56 gap-1.5">
            <span className="text-sm font-medium">Kas / Rekening tujuan</span>
            <SelectField
              value={cashAccountId}
              onChange={(v) => {
                setCashAccountId(v)
                // Pocket & saldo tergantung rekening → baca ulang bila file sudah dibaca.
                if (preview && file) readFile(v)
              }}
              placeholder="Deteksi otomatis"
              options={cashAccounts.map((c) => ({ value: String(c.id), label: c.account_number ? `${c.name} · ${c.account_number}` : c.name }))}
            />
          </div>
          <Button disabled={!file || read.isPending} onClick={() => readFile()}>
            {read.isPending ? <Loader2 className="animate-spin" /> : <FileUp />} Baca File
          </Button>
        </CardContent>
      </Card>

      {preview && (
        <>
          {preview.detection && (
            <DetectionPanel key={JSON.stringify(preview.detection.mapping) + preview.detection.direction + preview.detection.header_row} detection={preview.detection} busy={read.isPending} onApply={applyOverride} />
          )}

          <div className="grid gap-4 md:grid-cols-3">
            <SummaryCard
              title="Rekening"
              value={preview.account_number ?? '-'}
              hint={[preview.account_name, preview.period].filter(Boolean).join(' · ')}
            />
            <SummaryCard
              title="Saldo awal bank vs aplikasi"
              value={preview.summary.bank_opening != null ? formatRupiah(preview.summary.bank_opening) : '-'}
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

          {needsPocket && (
            <Card className="gap-0 py-0">
              <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 border-b py-3">
                <div>
                  <CardTitle className="text-base">Saldo per Pocket</CardTitle>
                  <CardDescription>Pocket yang tidak yakin dibiarkan kosong — pilih di setiap baris sebelum disimpan.</CardDescription>
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-sm text-muted-foreground">Isi pocket kosong dengan</span>
                  <SelectField
                    value=""
                    className="w-44"
                    placeholder="Pilih pocket"
                    onChange={(v) => setRows((rs) => rs.map((r) => (r.include && r.pocket_id === '' ? { ...r, pocket_id: v } : r)))}
                    options={pocketOptions}
                  />
                </div>
              </CardHeader>
              <CardContent className="px-0">
                <table className="w-full text-sm">
                  <thead className="text-muted-foreground">
                    <tr className="border-b">
                      <th className="py-2 pl-4 text-left font-medium">Pocket</th>
                      <th className="py-2 text-right font-medium">Saldo sekarang</th>
                      <th className="py-2 text-right font-medium">Dari import</th>
                      <th className="py-2 pr-4 text-right font-medium">Saldo setelah import</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pocketProjection.map((p) => (
                      <tr key={p.id} className="border-b last:border-0">
                        <td className="py-2 pl-4">{p.name}</td>
                        <td className="py-2 text-right tabular-nums">{formatRupiah(p.current_balance)}</td>
                        <td className="py-2 text-right tabular-nums">{p.change ? formatRupiah(p.change) : '-'}</td>
                        <td className={cn('py-2 pr-4 text-right tabular-nums', p.after < -0.005 && 'font-medium text-destructive')}>{formatRupiah(p.after)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {negativePockets.length > 0 && (
                  <label className="flex items-start gap-2 border-t px-4 py-3 text-sm text-destructive">
                    <input type="checkbox" className="mt-0.5 size-4 accent-primary" checked={confirmNegative} onChange={(e) => setConfirmNegative(e.target.checked)} />
                    Saldo pocket {negativePockets.map((p) => p.name).join(', ')} akan minus. Saya akan menutupnya dengan mutasi antar pocket.
                  </label>
                )}
              </CardContent>
            </Card>
          )}

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
                pocketOptions={needsPocket ? pocketOptions : null}
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
            <Button
              disabled={selected.length === 0 || invalidCount > 0 || !cashAccountId || (negativePockets.length > 0 && !confirmNegative) || save.isPending}
              onClick={() => save.mutate()}
            >
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

/** Panel koreksi hasil deteksi kolom: sheet, baris judul, peran tiap kolom, arah Debet/Kredit. */
function DetectionPanel({ detection, busy, onApply }: { detection: ImportDetection; busy: boolean; onApply: (o: DetectionOverride) => void }) {
  const [open, setOpen] = useState(false)
  const [headerRow, setHeaderRow] = useState(String(detection.header_row))
  const [mapping, setMapping] = useState(detection.mapping)
  const [direction, setDirection] = useState(detection.direction)

  const roleOfColumn = (index: number) => ROLES.find((r) => mapping[r] === index) ?? IGNORE
  const setColumnRole = (index: number, role: string) =>
    setMapping((m) => {
      const next = { ...m }
      for (const r of ROLES) if (next[r] === index) next[r] = null
      if (role !== IGNORE) next[role as ImportColumnRole] = index
      return next
    })
  const usesDebitCredit = mapping.debit != null || mapping.credit != null
  const summary = ROLES.filter((r) => detection.mapping[r] != null)
    .map((r) => `${ROLE_LABELS[r]} = ${detection.columns[detection.mapping[r]!]?.letter ?? '?'}`)
    .join(' · ')

  return (
    <Card className="gap-2 py-3">
      <div className="flex flex-wrap items-center justify-between gap-2 px-4">
        <div className="min-w-0 text-sm">
          <span className="font-medium">Kolom terdeteksi</span>
          <span className="text-muted-foreground">
            {' '}
            · sheet "{detection.sheet}" · judul di baris {detection.header_row}
            {usesDebitCredit && ` · ${detection.direction === 'bank' ? 'Debet = keluar, Kredit = masuk' : 'Debit = masuk, Kredit = keluar'}`}
          </span>
          <p className="truncate text-xs text-muted-foreground">{summary || 'Belum ada kolom yang dikenali.'}</p>
        </div>
        <Button variant="outline" size="sm" onClick={() => setOpen((o) => !o)}>
          {open ? 'Tutup' : 'Atur kolom'}
        </Button>
      </div>

      {open && (
        <CardContent className="grid gap-3 px-4">
          <div className="flex flex-wrap items-end gap-3">
            {detection.sheets.length > 1 && (
              <FormField label="Sheet">
                <SelectField value={detection.sheet} onChange={(v) => onApply({ sheet: v })} options={detection.sheets.map((s) => ({ value: s, label: s }))} className="w-48" />
              </FormField>
            )}
            <FormField label="Baris judul kolom">
              <Input type="number" min={1} value={headerRow} onChange={(e) => setHeaderRow(e.target.value)} className="w-28" />
            </FormField>
            {usesDebitCredit && (
              <FormField label="Arti kolom Debet/Kredit">
                <SelectField
                  value={direction}
                  onChange={(v) => setDirection(v as 'bank' | 'book')}
                  className="w-72"
                  options={[
                    { value: 'bank', label: 'Rekening koran: Debet keluar, Kredit masuk' },
                    { value: 'book', label: 'Buku kas: Debit masuk, Kredit keluar' },
                  ]}
                />
              </FormField>
            )}
            {detection.direction_detected !== direction && usesDebitCredit && (
              <span className="pb-2 text-xs text-muted-foreground">Deteksi otomatis dari saldo: {detection.direction_detected === 'bank' ? 'rekening koran' : 'buku kas'}</span>
            )}
          </div>

          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {detection.columns.map((c) => (
              <div key={c.index} className="grid gap-1 rounded-lg border p-2">
                <div className="flex items-baseline justify-between gap-2 text-xs">
                  <span className="font-medium">
                    {c.letter}. {c.header || <em className="text-muted-foreground">(tanpa judul)</em>}
                  </span>
                </div>
                <p className="truncate text-xs text-muted-foreground" title={c.samples.join(' | ')}>
                  {c.samples.length ? c.samples.join(' | ') : '—'}
                </p>
                <SelectField
                  value={roleOfColumn(c.index)}
                  onChange={(v) => setColumnRole(c.index, v)}
                  options={[{ value: IGNORE, label: 'Abaikan' }, ...ROLES.map((r) => ({ value: r, label: ROLE_LABELS[r] }))]}
                />
              </div>
            ))}
          </div>

          <div className="flex justify-end gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setHeaderRow(String(detection.header_row))
                setMapping(detection.mapping)
                setDirection(detection.direction)
              }}
            >
              Batal ubah
            </Button>
            <Button
              size="sm"
              disabled={busy || mapping.date == null || (mapping.debit == null && mapping.credit == null && mapping.amount == null)}
              onClick={() => onApply({ sheet: detection.sheet, header_row: Number(headerRow) || detection.header_row, mapping, direction })}
            >
              {busy && <Loader2 className="animate-spin" />} Terapkan & baca ulang
            </Button>
          </div>
        </CardContent>
      )}
    </Card>
  )
}

interface ImportRowCardProps {
  row: EditRow
  /** null = rekening tanpa pocket. */
  pocketOptions: { value: string; label: string }[] | null
  categories: Category[]
  recurringName: (id: number) => string | undefined
  onChange: (fn: (row: EditRow) => EditRow) => void
}

function ImportRowCard({ row, pocketOptions, categories, recurringName, onChange }: ImportRowCardProps) {
  const options = useMemo(
    () =>
      categories
        .filter((c) => c.type === row.type || c.type === 'both')
        .map((c) => ({ value: String(c.id), label: `${c.code} · ${c.name}` })),
    [categories, row.type],
  )
  const remaining = (cents(row.amount) - cents(lineTotal(row))) / 100
  const valid = isRowValid(row, pocketOptions !== null)
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
          {pocketOptions && (
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm font-medium">Pocket</span>
              <SelectField
                value={row.pocket_id}
                onChange={(v) => onChange((r) => ({ ...r, pocket_id: v }))}
                placeholder={row.pocket ? `"${row.pocket}" tidak dikenali — pilih` : 'Pilih pocket'}
                invalid={row.pocket_id === ''}
                className="w-56"
                options={pocketOptions}
              />
            </div>
          )}
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
