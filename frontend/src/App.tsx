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
const FundRequestsPage = lazy(() => import('@/features/fund-requests/FundRequestsPage'))
const FundRequestDetailPage = lazy(() => import('@/features/fund-requests/FundRequestDetailPage'))
const CategoriesPage = lazy(() => import('@/features/categories/CategoriesPage'))
const CashAccountsPage = lazy(() => import('@/features/cash-accounts/CashAccountsPage'))
const ReportsPage = lazy(() => import('@/features/reports/ReportsPage'))
const UsersPage = lazy(() => import('@/features/users/UsersPage'))
const ActivityLogsPage = lazy(() => import('@/features/activity-logs/ActivityLogsPage'))

export default function App() {
  return (
    <BrowserRouter basename={import.meta.env.BASE_URL}>
      <Suspense fallback={null}>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route element={<ProtectedRoute />}>
            <Route element={<AppLayout />}>
              <Route index element={<DashboardPage />} />
              <Route path="transactions" element={<TransactionsPage />} />
              <Route element={<ProtectedRoute roles={['admin', 'bendahara']} />}>
                <Route path="transactions/import" element={<BankImportPage />} />
              </Route>
              <Route path="recurring" element={<RecurringExpensesPage />} />
              <Route path="budgets" element={<BudgetsPage />} />
              <Route path="fund-requests" element={<FundRequestsPage />} />
              <Route path="fund-requests/:id" element={<FundRequestDetailPage />} />
              <Route path="reports" element={<ReportsPage />} />
              <Route path="categories" element={<CategoriesPage />} />
              <Route path="cash-accounts" element={<CashAccountsPage />} />
              <Route element={<ProtectedRoute roles={['admin']} />}>
                <Route path="users" element={<UsersPage />} />
                <Route path="activity-logs" element={<ActivityLogsPage />} />
              </Route>
            </Route>
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </Suspense>
    </BrowserRouter>
  )
}
