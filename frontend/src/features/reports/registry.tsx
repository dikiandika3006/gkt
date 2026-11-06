import { lazy, type ComponentType, type LazyExoticComponent } from 'react'
import { BarChart3, BookOpen, CalendarRange, ClipboardList, FileText, HandHelping, Landmark, ListTree, Lock, Scale, Target } from 'lucide-react'
import type { LayoutDashboard } from 'lucide-react'
import type { Module } from '@/types'

export type ReportSection = 'Bulanan' | 'Dana' | 'Analisa' | 'Tahunan'

export interface ReportEntry {
  /** Path di bawah /reports/. */
  slug: string
  label: string
  description: string
  section: ReportSection
  icon: typeof LayoutDashboard
  module: Module
  page: LazyExoticComponent<ComponentType>
}

const named = <K extends string>(loader: () => Promise<Record<K, ComponentType>>, name: K) =>
  lazy(() => loader().then((m) => ({ default: m[name] })))

export const REPORT_SECTIONS: ReportSection[] = ['Bulanan', 'Dana', 'Analisa', 'Tahunan']

/** Daftar laporan: dipakai bersama oleh sidebar (grup Laporan) dan routing. */
export const REPORTS: ReportEntry[] = [
  {
    slug: 'bulanan',
    label: 'Laporan Bulanan',
    description: 'Laporan keuangan satu bulan siap cetak untuk majelis / warta, lengkap dengan tanda tangan',
    section: 'Bulanan',
    icon: FileText,
    module: 'reports',
    page: lazy(() => import('./MonthlyStatementPage')),
  },
  {
    slug: 'rekap-bulanan',
    label: 'Rekap Bulanan',
    description: 'Ringkasan per bulan dan matriks kelompok/kategori × bulan',
    section: 'Bulanan',
    icon: CalendarRange,
    module: 'reports',
    page: named(() => import('./MonthlyTab'), 'MonthlyTab'),
  },
  {
    slug: 'buku-kas',
    label: 'Buku Kas',
    description: 'Daftar transaksi harian dengan saldo berjalan',
    section: 'Bulanan',
    icon: BookOpen,
    module: 'reports',
    page: named(() => import('./CashFlowTab'), 'CashFlowTab'),
  },
  {
    slug: 'rekonsiliasi',
    label: 'Rekonsiliasi Bank',
    description: 'Cocokkan saldo aplikasi dengan rekening koran / hitung fisik kas di akhir bulan',
    section: 'Bulanan',
    icon: Landmark,
    module: 'reports',
    page: lazy(() => import('./ReconciliationPage')),
  },
  {
    slug: 'tutup-buku',
    label: 'Tutup Buku',
    description: 'Kunci bulan yang laporannya sudah diumumkan agar transaksinya tidak berubah',
    section: 'Bulanan',
    icon: Lock,
    module: 'reports',
    page: lazy(() => import('./PeriodLocksPage')),
  },
  {
    slug: 'posisi-dana',
    label: 'Posisi Dana',
    description: 'Dari total uang di kas & bank, berapa yang terikat (dana khusus, titipan, pinjaman) dan berapa yang bebas dipakai',
    section: 'Dana',
    icon: Scale,
    module: 'reports',
    page: lazy(() => import('./FundPositionPage')),
  },
  {
    slug: 'titipan-pinjaman',
    label: 'Titipan & Pinjaman',
    description: 'Kartu titipan & pinjaman per nama: masuk, disalurkan/dikembalikan, sisa',
    section: 'Dana',
    icon: HandHelping,
    module: 'reports',
    page: lazy(() => import('./DepositsLoansPage')),
  },
  {
    slug: 'sekretariat',
    label: 'Kas Sekretariat',
    description: 'Pemakaian dana Kas Sekretariat per pengajuan, kategori & nota',
    section: 'Dana',
    icon: ClipboardList,
    module: 'report_secretariat',
    page: named(() => import('./SecretariatTab'), 'SecretariatTab'),
  },
  {
    slug: 'ringkasan',
    label: 'Ringkasan Periode',
    description: 'Pemasukan & pengeluaran per kategori untuk rentang tanggal bebas',
    section: 'Analisa',
    icon: ListTree,
    module: 'reports',
    page: named(() => import('./SummaryTab'), 'SummaryTab'),
  },
  {
    slug: 'realisasi-anggaran',
    label: 'Realisasi Anggaran',
    description: 'Anggaran vs realisasi per kategori, bulan ini dan kumulatif sejak Januari',
    section: 'Analisa',
    icon: Target,
    module: 'reports',
    page: lazy(() => import('./BudgetReportPage')),
  },
  {
    slug: 'analisa-kategori',
    label: 'Analisa Kategori',
    description: 'Peringkat pengeluaran operasional per kategori',
    section: 'Analisa',
    icon: BarChart3,
    module: 'reports',
    page: named(() => import('./CategoryRankingTab'), 'CategoryRankingTab'),
  },
]

export const reportPath = (r: ReportEntry) => `/reports/${r.slug}`
