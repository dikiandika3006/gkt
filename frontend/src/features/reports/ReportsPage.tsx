import { Navigate } from 'react-router'
import { PageHeader } from '@/components/PageHeader'
import { useAuth } from '@/features/auth/AuthContext'
import { REPORTS, reportPath, type ReportEntry } from './registry'

/** /reports → laporan pertama yang boleh diakses. */
export default function ReportsIndex() {
  const { can } = useAuth()
  const first = REPORTS.find((r) => can(r.module))

  return <Navigate to={first ? reportPath(first) : '/'} replace />
}

/** Satu laporan = satu halaman (menu sendiri di grup Laporan). */
export function ReportView({ report }: { report: ReportEntry }) {
  const Page = report.page

  return (
    <>
      <div className="print:hidden">
        <PageHeader title={report.label} description={report.description} />
      </div>
      <div className="grid gap-4">
        <Page />
      </div>
    </>
  )
}
