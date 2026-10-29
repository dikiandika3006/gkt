import { lazy, Suspense } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router'
import { AppLayout } from '@/components/layout/AppLayout'
import { ProtectedRoute } from '@/features/auth/ProtectedRoute'
import LoginPage from '@/features/auth/LoginPage'

const DashboardPage = lazy(() => import('@/features/dashboard/DashboardPage'))
const TransactionsPage = lazy(() => import('@/features/transactions/TransactionsPage'))
const BankImportPage = lazy(() => import('@/features/transactions/BankImportPage'))
const RecurringExpensesPage = lazy(() => import('@/features/recurring/RecurringExpensesPage'))
const BudgetsPage = lazy(() => import('@/features/budgets/BudgetsPage'))
const OfferingsPage = lazy(() => import('@/features/offerings/OfferingsPage'))
const OfferingFormPage = lazy(() => import('@/features/offerings/OfferingFormPage'))
const OfferingDetailPage = lazy(() => import('@/features/offerings/OfferingDetailPage'))
const OfferingSettingsPage = lazy(() => import('@/features/offerings/OfferingSettingsPage'))
const FundRequestsPage = lazy(() => import('@/features/fund-requests/FundRequestsPage'))
const FundRequestDetailPage = lazy(() => import('@/features/fund-requests/FundRequestDetailPage'))
const CategoriesPage = lazy(() => import('@/features/categories/CategoriesPage'))
const CashAccountsPage = lazy(() => import('@/features/cash-accounts/CashAccountsPage'))
const ReportsPage = lazy(() => import('@/features/reports/ReportsPage'))
const UsersPage = lazy(() => import('@/features/users/UsersPage'))
const ActivityLogsPage = lazy(() => import('@/features/activity-logs/ActivityLogsPage'))
const PermissionsPage = lazy(() => import('@/features/permissions/PermissionsPage'))

export default function App() {
  return (
    <BrowserRouter basename={import.meta.env.BASE_URL}>
      <Suspense fallback={null}>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route element={<ProtectedRoute />}>
            <Route element={<AppLayout />}>
              {/* Dashboard menyesuaikan hak akses (lengkap / Kas Sekretariat). */}
              <Route index element={<DashboardPage />} />
              <Route element={<ProtectedRoute module="transactions" />}>
                <Route path="transactions" element={<TransactionsPage />} />
              </Route>
              <Route element={<ProtectedRoute module="transactions" level="manage" />}>
                <Route path="transactions/import" element={<BankImportPage />} />
              </Route>
              <Route element={<ProtectedRoute module="recurring" />}>
                <Route path="recurring" element={<RecurringExpensesPage />} />
              </Route>
              <Route element={<ProtectedRoute module="budgets" />}>
                <Route path="budgets" element={<BudgetsPage />} />
              </Route>
              <Route element={<ProtectedRoute module="offerings" />}>
                <Route path="offerings" element={<OfferingsPage />} />
                {/* Lihat saja untuk hak Lihat; tombol simpan hanya untuk hak Kelola. */}
                <Route path="offerings/settings" element={<OfferingSettingsPage />} />
                <Route path="offerings/:id" element={<OfferingDetailPage />} />
              </Route>
              <Route element={<ProtectedRoute module="offerings" level="manage" />}>
                <Route path="offerings/new" element={<OfferingFormPage />} />
                <Route path="offerings/:id/edit" element={<OfferingFormPage />} />
              </Route>
              <Route element={<ProtectedRoute module="fund_requests" />}>
                <Route path="fund-requests" element={<FundRequestsPage />} />
                <Route path="fund-requests/:id" element={<FundRequestDetailPage />} />
              </Route>
              {/* Tab laporan disaring per hak di dalam halaman. */}
              <Route path="reports" element={<ReportsPage />} />
              <Route element={<ProtectedRoute module="categories" />}>
                <Route path="categories" element={<CategoriesPage />} />
              </Route>
              <Route element={<ProtectedRoute module="cash_accounts" />}>
                <Route path="cash-accounts" element={<CashAccountsPage />} />
              </Route>
              <Route element={<ProtectedRoute roles={['admin']} />}>
                <Route path="users" element={<UsersPage />} />
                <Route path="activity-logs" element={<ActivityLogsPage />} />
                <Route path="permissions" element={<PermissionsPage />} />
              </Route>
            </Route>
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </Suspense>
    </BrowserRouter>
  )
}
