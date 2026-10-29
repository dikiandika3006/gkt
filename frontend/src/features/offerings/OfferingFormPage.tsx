import { useEffect, useId, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { Controller, useFieldArray, useForm, type Control, type UseFormRegister } from 'react-hook-form'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeft, ArrowRight, CalendarDays, Info, Loader2, Plus, Save, Settings2, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { FormField } from '@/components/FormField'
import { MoneyInput } from '@/components/MoneyInput'
import { PageHeader } from '@/components/PageHeader'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { formatRupiah } from '@/lib/format'
import { useCounterparties } from '@/lib/queries'
import type { OfferingFundOption, OfferingReport, OfferingSection, OfferingTemplate } from '@/types'
import { SECTIONS, weekPeriod } from './utils'

/** Baris tetap Persembahan Ibadah (dari template minggu itu): hanya jumlah yang diisi. */
interface IbadahValues {
  label: string
  offering_fund_id: number | null
  account: string
  amount: string
}

/** Baris bebas: syukur, perpuluhan, lain-lain, pengeluaran. */
interface LineValues {
  section: Exclude<OfferingSection, 'ibadah'>
  label: string
  name: string
  amount: string
  /** Pos untuk baris Lain-lain ('' = keterangan bebas → Rekening Utama). */
  offering_fund_id: string
}

interface FormValues {
  service_date: string
  period_start: string
  period_end: string
  notes: string
  ibadah: IbadahValues[]
  lines: LineValues[]
}

const emptyLine = (section: LineValues['section']): LineValues => ({ section, label: '', name: '', amount: '', offering_fund_id: '' })

/** Hari Minggu terakhir (atau hari ini bila Minggu) dalam format yyyy-MM-dd. */
function lastSunday(): string {
  const d = new Date()
  d.setDate(d.getDate() - d.getDay())
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/**
 * Pindahkan jumlah yang sudah diisi ke baris template baru:
 * baris biasa dicocokkan per label, baris pos dicocokkan per urutan kebaktian (pindah ke pos baru).
 */
function mergeIbadah(template: OfferingTemplate['ibadah_lines'], current: IbadahValues[]): { rows: IbadahValues[]; movedFund: boolean } {
  const byLabel = new Map(current.filter((c) => !c.offering_fund_id).map((c) => [c.label, c.amount]))
  const fundAmounts = current.filter((c) => c.offering_fund_id).map((c) => c.amount)
  const oldFundId = current.find((c) => c.offering_fund_id)?.offering_fund_id ?? null
  let fundIndex = 0
  const rows = template.map((t) => ({
    ...t,
    amount: t.offering_fund_id ? (fundAmounts[fundIndex++] ?? '') : (byLabel.get(t.label) ?? ''),
  }))
  const newFundId = template.find((t) => t.offering_fund_id)?.offering_fund_id ?? null
  return { rows, movedFund: !!oldFundId && oldFundId !== newFundId && fundAmounts.some((a) => Number(a) > 0) }
}

/** Form rekap persembahan mingguan. Rekening tujuan ditentukan otomatis oleh Master Pos Persembahan. */
export default function OfferingFormPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({})
  const [serviceDate, setServiceDate] = useState<string | null>(id ? null : lastSunday())
  // Saat ubah rekap, baris Ibadah tersimpan dipakai apa adanya sampai tanggal ibadah diganti.
  const regenerate = useRef(!id)

  const existing = useQuery({
    queryKey: ['offering-reports', 'detail', id],
    queryFn: async () => (await api.get<{ data: OfferingReport }>(`/offering-reports/${id}`)).data.data,
    enabled: !!id,
  })
  const template = useQuery({
    queryKey: ['offering-reports', 'template', serviceDate],
    queryFn: async () => (await api.get<OfferingTemplate>('/offering-reports/template', { params: { service_date: serviceDate } })).data,
    enabled: !!serviceDate,
    placeholderData: keepPreviousData,
  })

  const { register, control, handleSubmit, reset, watch, setValue, getValues } = useForm<FormValues>({
    defaultValues: { service_date: '', period_start: '', period_end: '', notes: '', ibadah: [], lines: [] },
  })
  const ibadah = useFieldArray({ control, name: 'ibadah' })
  const lineArray = useFieldArray({ control, name: 'lines' })
  const values = watch()
  const tpl = template.data

  // Muat rekap yang diubah.
  useEffect(() => {
    const r = existing.data
    if (!r) return
    reset({
      service_date: r.service_date,
      period_start: r.period_start,
      period_end: r.period_end,
      notes: r.notes ?? '',
      ibadah: (r.lines ?? [])
        .filter((l) => l.section === 'ibadah')
        .map((l) => ({
          label: l.label ?? '',
          offering_fund_id: l.offering_fund_id ?? null,
          account: l.destination_note ?? [l.destination?.name, l.destination?.bank_name, l.destination?.account_number].filter(Boolean).join(' '),
          amount: String(l.amount),
        })),
      lines: (r.lines ?? [])
        .filter((l) => l.section !== 'ibadah')
        .map((l) => ({
          section: l.section as LineValues['section'],
          label: l.label ?? '',
          name: l.name ?? '',
          amount: String(l.amount),
          offering_fund_id: l.offering_fund_id ? String(l.offering_fund_id) : '',
        })),
    })
    setServiceDate(r.service_date)
  }, [existing.data, reset])

  // Rekap baru / tanggal diganti: susun ulang baris Ibadah dari template minggu itu.
  useEffect(() => {
    if (!tpl || !regenerate.current || tpl.service_date !== serviceDate) return
    const { rows, movedFund } = mergeIbadah(tpl.ibadah_lines, getValues('ibadah'))
    ibadah.replace(rows)
    if (movedFund) toast.info(`Jumlah kantong khusus dipindah ke pos minggu ini: ${tpl.fund?.name ?? '-'}.`)
    if (!id && getValues('lines').length === 0) {
      const p = weekPeriod(tpl.service_date)
      setValue('service_date', tpl.service_date)
      setValue('period_start', p.start)
      setValue('period_end', p.end)
      lineArray.replace([emptyLine('syukur'), emptyLine('perpuluhan')])
    }
    // ibadah.replace / lineArray.replace stabil dari react-hook-form.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tpl, serviceDate])

  const mutation = useMutation({
    mutationFn: async (v: FormValues) => {
      const payload = {
        service_date: v.service_date,
        period_start: v.period_start,
        period_end: v.period_end,
        notes: v.notes || null,
        // Baris tanpa jumlah diabaikan.
        lines: [
          ...v.ibadah.filter((l) => Number(l.amount) > 0).map((l) => ({ section: 'ibadah', label: l.label, offering_fund_id: l.offering_fund_id, amount: Number(l.amount) })),
          ...v.lines
            .filter((l) => Number(l.amount) > 0)
            .map((l) => ({
              section: l.section,
              label: l.label || null,
              name: l.name || null,
              amount: Number(l.amount),
              offering_fund_id: l.section === 'lainnya' && l.offering_fund_id ? Number(l.offering_fund_id) : null,
            })),
        ],
      }
      return (await (id ? api.put<{ data: OfferingReport }>(`/offering-reports/${id}`, payload) : api.post<{ data: OfferingReport }>('/offering-reports', payload))).data.data
    },
    onSuccess: (saved) => {
      toast.success('Rekap persembahan disimpan')
      queryClient.invalidateQueries({ queryKey: ['offering-reports'] })
      navigate(`/offerings/${saved.id}`)
    },
    onError: (err) => {
      const fe = fieldErrors(err)
      setServerErrors(fe)
      toast.error(Object.keys(fe).length ? 'Periksa kembali isian yang ditandai.' : errorMessage(err))
    },
  })

  const ibadahTotal = (values.ibadah ?? []).reduce((s, l) => s + (Number(l.amount) || 0), 0)
  const sectionTotal = (key: OfferingSection) =>
    key === 'ibadah' ? ibadahTotal : (values.lines ?? []).filter((l) => l.section === key).reduce((s, l) => s + (Number(l.amount) || 0), 0)
  const totalOffering = SECTIONS.filter((s) => s.key !== 'pengeluaran').reduce((sum, s) => sum + sectionTotal(s.key), 0)

  // Error server memakai indeks baris yang terkirim (ibadah dulu, baris kosong dibuang) → petakan ulang ke baris form.
  const sentIbadah = (values.ibadah ?? []).filter((l) => Number(l.amount) > 0).length
  const sentIndex = new Map<number, number>()
  ;(values.lines ?? []).forEach((l, i) => Number(l.amount) > 0 && sentIndex.set(i, sentIbadah + sentIndex.size))
  const lineError = (i: number, field: string) => {
    const s = sentIndex.get(i)
    return s === undefined ? undefined : serverErrors[`lines.${s}.${field}`]
  }

  if ((id && existing.isLoading) || (!tpl && template.isLoading)) return <p className="text-muted-foreground">Memuat...</p>

  const renderLines = (key: LineValues['section']) => {
    const meta = SECTIONS.find((s) => s.key === key)!
    const rows = lineArray.fields.map((f, i) => ({ f, i })).filter(({ i }) => values.lines?.[i]?.section === key)
    const toMain = key === 'syukur' || key === 'perpuluhan'
    return (
      <Card key={key} className="gap-3">
        <CardHeader className="flex flex-row items-start justify-between">
          <div>
            <CardTitle className="text-base">{meta.title}</CardTitle>
            {toMain && tpl && (
              <CardDescription className="flex items-center gap-1 text-xs">
                <ArrowRight className="size-3" /> {tpl.main_account}
              </CardDescription>
            )}
            {key === 'pengeluaran' && <CardDescription className="text-xs">Catatan saja, tidak mengurangi setoran.</CardDescription>}
          </div>
          <span className="text-sm font-semibold">{formatRupiah(sectionTotal(key))}</span>
        </CardHeader>
        <CardContent className="grid gap-2">
          {rows.map(({ f, i }) => (
            <LineRow
              key={f.id}
              index={i}
              section={key}
              register={register}
              control={control}
              fundId={values.lines?.[i]?.offering_fund_id ?? ''}
              onFund={(fundId, name) => {
                setValue(`lines.${i}.offering_fund_id`, fundId)
                if (fundId) setValue(`lines.${i}.label`, name)
              }}
              funds={tpl?.funds ?? []}
              mainAccount={tpl?.main_account ?? 'Rekening Utama'}
              onRemove={() => lineArray.remove(i)}
              errors={{ label: lineError(i, 'label'), name: lineError(i, 'name'), amount: lineError(i, 'amount') }}
            />
          ))}
          <Button type="button" variant="ghost" size="sm" className="justify-start" onClick={() => lineArray.append(emptyLine(key))}>
            <Plus /> Tambah baris
          </Button>
        </CardContent>
      </Card>
    )
  }

  return (
    <>
      <PageHeader
        title={id ? 'Ubah Rekap Persembahan' : 'Rekap Persembahan Baru'}
        description="Isi sesuai hitungan hari Minggu. Rekening tujuan otomatis dari Master Pos Persembahan. Baris tanpa jumlah tidak disimpan."
        actions={
          <>
            <Button variant="outline" render={<Link to="/offerings/settings" />}>
              <Settings2 /> Pos Persembahan
            </Button>
            <Button variant="outline" render={<Link to={id ? `/offerings/${id}` : '/offerings'} />}>
              <ArrowLeft /> Kembali
            </Button>
          </>
        }
      />

      <form onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid gap-4">
        <Card>
          <CardContent className="grid gap-4 sm:grid-cols-4">
            <FormField label="Tanggal ibadah (Minggu)" htmlFor="service_date" error={serverErrors.service_date}>
              <Input
                id="service_date"
                type="date"
                {...register('service_date', {
                  onChange: (e) => {
                    if (!e.target.value) return
                    const p = weekPeriod(e.target.value)
                    setValue('period_start', p.start)
                    setValue('period_end', p.end)
                    regenerate.current = true
                    setServiceDate(e.target.value)
                  },
                })}
              />
            </FormField>
            <FormField label="Periode warta dari" htmlFor="period_start" error={serverErrors.period_start}>
              <Input id="period_start" type="date" {...register('period_start')} />
            </FormField>
            <FormField label="Sampai" htmlFor="period_end" error={serverErrors.period_end}>
              <Input id="period_end" type="date" {...register('period_end')} />
            </FormField>
            <FormField label="Catatan" htmlFor="notes">
              <Input id="notes" placeholder="Opsional" {...register('notes')} />
            </FormField>
          </CardContent>
        </Card>

        <div className="grid items-start gap-4 lg:grid-cols-2">
          <div className="grid gap-4">
            <Card className="gap-3">
              <CardHeader className="flex flex-row items-start justify-between">
                <div>
                  <CardTitle className="text-base">Persembahan Ibadah</CardTitle>
                  {tpl && (
                    <CardDescription className="flex flex-wrap items-center gap-1 text-xs">
                      <CalendarDays className="size-3" /> Minggu ke-{tpl.week_of_month} · Pos: <span className="font-medium text-foreground">{tpl.fund?.name ?? 'belum diatur'}</span>
                    </CardDescription>
                  )}
                </div>
                <span className="text-sm font-semibold">{formatRupiah(ibadahTotal)}</span>
              </CardHeader>
              <CardContent className="grid gap-2">
                {ibadah.fields.map((f, i) => (
                  <div key={f.id} className="grid gap-1 rounded-lg border p-2">
                    <div className="flex items-center gap-2">
                      <span className="min-w-0 flex-1 truncate text-sm font-medium">{values.ibadah?.[i]?.label}</span>
                      <Controller
                        control={control}
                        name={`ibadah.${i}.amount`}
                        render={({ field }) => (
                          <MoneyInput
                            className="w-36 text-right"
                            placeholder="Jumlah"
                            aria-label={`Jumlah ${values.ibadah?.[i]?.label ?? ''}`}
                            value={field.value}
                            onChange={field.onChange}
                            onBlur={field.onBlur}
                          />
                        )}
                      />
                    </div>
                    <p className="flex items-center gap-1 text-xs text-muted-foreground">
                      <ArrowRight className="size-3" /> {values.ibadah?.[i]?.account}
                    </p>
                  </div>
                ))}
                {tpl && !tpl.fund && (
                  <p className="flex items-center gap-1 text-xs text-warning">
                    <Info className="size-3" /> Jadwal Minggu ke-{tpl.week_of_month} belum punya pos. Atur di Pos Persembahan.
                  </p>
                )}
              </CardContent>
            </Card>
            {renderLines('syukur')}
            {renderLines('perpuluhan')}
          </div>
          <div className="grid gap-4">
            {renderLines('lainnya')}
            {renderLines('pengeluaran')}
          </div>
        </div>

        {serverErrors.lines && <p className="text-sm text-destructive">{serverErrors.lines}</p>}

        <Card className="sticky bottom-4 z-10 border-primary/30 shadow-lg">
          <CardContent className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-xs text-muted-foreground">Total Persembahan</p>
              <p className="text-2xl font-semibold">{formatRupiah(totalOffering)}</p>
              {sectionTotal('pengeluaran') > 0 && (
                <p className="text-xs text-muted-foreground">Pengeluaran (catatan): {formatRupiah(sectionTotal('pengeluaran'))}</p>
              )}
            </div>
            <Button type="submit" size="lg" disabled={mutation.isPending}>
              {mutation.isPending ? <Loader2 className="animate-spin" /> : <Save />}
              Simpan Rekap
            </Button>
          </CardContent>
        </Card>
      </form>
    </>
  )
}

interface LineRowProps {
  index: number
  section: LineValues['section']
  register: UseFormRegister<FormValues>
  control: Control<FormValues>
  fundId: string
  onFund: (fundId: string, name: string) => void
  funds: OfferingFundOption[]
  mainAccount: string
  onRemove: () => void
  errors: { label?: string; name?: string; amount?: string }
}

function LineRow({ index, section, register, control, fundId, onFund, funds, mainAccount, onRemove, errors }: LineRowProps) {
  const listId = useId()
  const [search, setSearch] = useState('')
  const { data: names = [] } = useCounterparties(search.length >= 2 ? search : '')
  const byName = section === 'syukur' || section === 'perpuluhan'
  const isOther = section === 'lainnya'
  const fund = funds.find((f) => String(f.id) === fundId)

  return (
    <div className="grid gap-1 rounded-lg border p-2">
      <div className="flex flex-wrap items-start gap-2">
        {isOther && (
          <select
            className="h-9 min-w-32 flex-1 rounded-md border bg-background px-2 text-sm"
            value={fundId}
            onChange={(e) => onFund(e.target.value, funds.find((f) => String(f.id) === e.target.value)?.name ?? '')}
            aria-label="Pos persembahan"
          >
            {funds.map((f) => (
              <option key={f.id} value={String(f.id)}>
                {f.name}
              </option>
            ))}
            <option value="">Lainnya (isi sendiri)</option>
          </select>
        )}
        {!byName && (!isOther || !fundId) && (
          <div className="min-w-36 flex-[2]">
            <Input placeholder="Keterangan" aria-label="Keterangan" aria-invalid={!!errors.label} {...register(`lines.${index}.label`)} />
            {errors.label && <p className="text-xs text-destructive">{errors.label}</p>}
          </div>
        )}
        {section !== 'pengeluaran' && (
          <div className="min-w-28 flex-[2]">
            <Input
              placeholder={byName ? 'Nama / kode' : 'Nama / kode (opsional)'}
              aria-label="Nama atau kode"
              list={listId}
              autoComplete="off"
              aria-invalid={!!errors.name}
              {...register(`lines.${index}.name`, { onChange: (e) => setSearch(e.target.value) })}
            />
            <datalist id={listId}>
              {names.map((n) => (
                <option key={n} value={n} />
              ))}
            </datalist>
            {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
          </div>
        )}
        <div className="w-32">
          <Controller
            control={control}
            name={`lines.${index}.amount`}
            render={({ field }) => (
              <MoneyInput
                className="text-right"
                placeholder="Jumlah"
                aria-label="Jumlah"
                aria-invalid={!!errors.amount}
                value={field.value}
                onChange={field.onChange}
                onBlur={field.onBlur}
              />
            )}
          />
          {errors.amount && <p className="text-xs text-destructive">{errors.amount}</p>}
        </div>
        <Button type="button" size="icon" variant="ghost" aria-label="Hapus baris" onClick={onRemove}>
          <Trash2 className="text-destructive" />
        </Button>
      </div>
      {isOther && (
        <p className="flex items-center gap-1 text-xs text-muted-foreground">
          <ArrowRight className="size-3" /> {fund ? fund.account : mainAccount}
        </p>
      )}
    </div>
  )
}
