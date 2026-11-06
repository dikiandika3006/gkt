import { lazy, type ComponentType, type LazyExoticComponent } from 'react'
import {
  BarChart3,
  BookMarked,
  BookOpen,
  CalendarRange,
  ClipboardList,
  FileText,
  HandCoins,
  HandHelping,
  Landmark,
  ListTree,
  Lock,
  Scale,
  Target,
  TrendingUp,
  UserRound,
} from 'lucide-react'
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
  {
    slug: 'perbandingan',
    label: 'Perbandingan Tahunan',
    description: 'Penerimaan & pengeluaran tahun ini dibanding tahun lalu, per bulan dan per kelompok',
    section: 'Analisa',
    icon: TrendingUp,
    module: 'reports',
    page: lazy(() => import('./ComparisonPage')),
  },
  {
    slug: 'tren-persembahan',
    label: 'Tren Persembahan',
    description: 'Persembahan per minggu per bagian (ibadah, syukur, perpuluhan, lain) dari Rekap Persembahan',
    section: 'Analisa',
    icon: HandCoins,
    module: 'reports',
    page: lazy(() => import('./OfferingTrendPage')),
  },
  {
    slug: 'pemberi',
    label: 'Rekap per Pemberi',
    description: 'Persembahan syukur & perpuluhan per nama jemaat per bulan — data pribadi',
    section: 'Analisa',
    icon: UserRound,
    module: 'report_donors',
    page: lazy(() => import('./DonorsPage')),
  },
  {
    slug: 'lpj-tahunan',
    label: 'LPJ Tahunan',
    description: 'Laporan pertanggungjawaban keuangan setahun untuk Rapat Jemaat, siap cetak',
    section: 'Tahunan',
    icon: BookMarked,
    module: 'reports',
    page: lazy(() => import('./AnnualPage')),
  },
]

export const reportPath = (r: ReportEntry) => `/reports/${r.slug}`
