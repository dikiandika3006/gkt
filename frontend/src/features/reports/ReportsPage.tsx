import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { PageHeader } from '@/components/PageHeader'
import { CashFlowTab } from './CashFlowTab'
import { CategoryRankingTab } from './CategoryRankingTab'
import { MonthlyTab } from './MonthlyTab'
import { SecretariatTab } from './SecretariatTab'
import { SummaryTab } from './SummaryTab'

export default function ReportsPage() {
  return (
    <>
      <PageHeader title="Laporan Keuangan" description="Semua laporan bisa diekspor ke Excel atau dicetak / disimpan sebagai PDF" />

      <Tabs defaultValue="monthly">
        <TabsList className="flex-wrap print:hidden">
          <TabsTrigger value="monthly">Rekap Bulanan</TabsTrigger>
          <TabsTrigger value="summary">Ringkasan Periode</TabsTrigger>
          <TabsTrigger value="cash-flow">Buku Kas</TabsTrigger>
          <TabsTrigger value="categories">Analisa Kategori</TabsTrigger>
          <TabsTrigger value="secretariat">Sekretariat</TabsTrigger>
        </TabsList>
        <TabsContent value="monthly" className="grid gap-4">
          <MonthlyTab />
        </TabsContent>
        <TabsContent value="summary" className="grid gap-4">
          <SummaryTab />
        </TabsContent>
        <TabsContent value="cash-flow" className="grid gap-4">
          <CashFlowTab />
        </TabsContent>
        <TabsContent value="categories" className="grid gap-4">
          <CategoryRankingTab />
        </TabsContent>
        <TabsContent value="secretariat" className="grid gap-4">
          <SecretariatTab />
        </TabsContent>
      </Tabs>
    </>
  )
}
