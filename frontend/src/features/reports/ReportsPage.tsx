import { Navigate } from 'react-router'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { PageHeader } from '@/components/PageHeader'
import { useAuth } from '@/features/auth/AuthContext'
import type { Module } from '@/types'
import { CashFlowTab } from './CashFlowTab'
import { CategoryRankingTab } from './CategoryRankingTab'
import { MonthlyTab } from './MonthlyTab'
import { SecretariatTab } from './SecretariatTab'
import { SummaryTab } from './SummaryTab'

const TABS: { value: string; label: string; module: Module; content: () => React.ReactNode }[] = [
  { value: 'monthly', label: 'Rekap Bulanan', module: 'reports', content: () => <MonthlyTab /> },
  { value: 'summary', label: 'Ringkasan Periode', module: 'reports', content: () => <SummaryTab /> },
  { value: 'cash-flow', label: 'Buku Kas', module: 'reports', content: () => <CashFlowTab /> },
  { value: 'categories', label: 'Analisa Kategori', module: 'reports', content: () => <CategoryRankingTab /> },
  { value: 'secretariat', label: 'Sekretariat', module: 'report_secretariat', content: () => <SecretariatTab /> },
]

export default function ReportsPage() {
  const { can } = useAuth()
  const tabs = TABS.filter((t) => can(t.module))

  if (tabs.length === 0) return <Navigate to="/" replace />

  return (
    <>
      <PageHeader title="Laporan Keuangan" description="Semua laporan bisa diekspor ke Excel atau dicetak / disimpan sebagai PDF" />

      <Tabs defaultValue={tabs[0].value}>
        <TabsList className="flex-wrap print:hidden">
          {tabs.map((t) => (
            <TabsTrigger key={t.value} value={t.value}>
              {t.label}
            </TabsTrigger>
          ))}
        </TabsList>
        {tabs.map((t) => (
          <TabsContent key={t.value} value={t.value} className="grid gap-4">
            {t.content()}
          </TabsContent>
        ))}
      </Tabs>
    </>
  )
}
