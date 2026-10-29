export type Role = 'admin' | 'bendahara' | 'sekretariat' | 'viewer'
export type TransactionType = 'income' | 'expense'
export type CategoryType = TransactionType | 'both'
export type CategoryNature = 'operational' | 'titipan' | 'pinjaman' | 'transfer'
export type CashAccountType = 'cash' | 'bank'

export interface User {
  id: number
  name: string
  username: string
  email: string | null
  role: Role
  role_label: string
  is_active: boolean
  /** Hak akses per modul (menu Hak Akses). */
  permissions: Record<Module, PermissionLevel>
  created_at: string
}

export type Module =
  | 'dashboard'
  | 'transactions'
  | 'offerings'
  | 'recurring'
  | 'budgets'
  | 'fund_requests'
  | 'reports'
  | 'report_secretariat'
  | 'categories'
  | 'cash_accounts'
export type PermissionLevel = 'none' | 'view' | 'manage'

export interface PermissionMatrix {
  roles: { value: Exclude<Role, 'admin'>; label: string }[]
  modules: { key: Module; label: string; description: string; levels: PermissionLevel[]; defaults: Record<string, PermissionLevel> }[]
  permissions: Record<string, Record<Module, PermissionLevel>>
}

export interface FundAccountSummary {
  id: number
  code: string
  name: string
  balance: number
  active_requests: number
  pending_reports: number
  latest: { id: number; number: string; period: string; status: string; status_label: string } | null
}

export interface Category {
  id: number
  code: string
  name: string
  group: string
  type: CategoryType
  type_label: string
  nature: CategoryNature
  nature_label: string
  description: string | null
  is_active: boolean
  transactions_count?: number
}

export interface CashAccount {
  id: number
  code: string
  name: string
  type: CashAccountType
  type_label: string
  bank_name: string | null
  account_number: string | null
  opening_balance: number
  is_active: boolean
}

export interface Attachment {
  id: number
  original_name: string
  mime_type: string
  size: number
  created_at: string
}

export interface Transaction {
  id: number
  number: string
  date: string
  type: TransactionType
  type_label: string
  amount: number
  description: string
  counterparty: string | null
  reference: string | null
  notes: string | null
  bank_ref: string | null
  bank_description: string | null
  category_id: number
  cash_account_id: number
  transfer_group: string | null
  fund_request_id: number | null
  fund_report_item_id: number | null
  recurring_expense_id: number | null
  recurring_period: string | null
  is_managed: boolean
  category?: Category
  cash_account?: CashAccount
  creator?: { id: number; name: string } | null
  attachments?: Attachment[]
  attachments_count?: number
  created_at: string
}

export interface BankImportLine {
  category_id: number | null
  amount: number
  counterparty: string | null
  description: string
  recurring_expense_id: number | null
  recurring_period: string | null
}

export type BankImportStatus = 'new' | 'possible_duplicate' | 'imported'

export interface BankImportRow {
  key: string
  date: string
  type: TransactionType
  amount: number
  balance: number | null
  description: string
  remark: string
  name: string
  status: BankImportStatus
  status_reason: string | null
  lines: BankImportLine[]
}

export interface BankImportPreview {
  account_number: string | null
  account_name: string | null
  period: string | null
  cash_account_id: number | null
  last_manual_date: string | null
  summary: {
    bank_opening: number
    bank_closing: number | null
    app_opening: number | null
    opening_difference: number | null
    app_until_last_date: number | null
  }
  rows: BankImportRow[]
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null }
}

export interface Period {
  start_date: string
  end_date: string
}

export interface PeriodTotals {
  income: number
  expense: number
  net: number
  non_operational_in: number
  non_operational_out: number
  transfer_in: number
  transfer_out: number
  change: number
}

export interface DashboardData {
  period: Period
  month: PeriodTotals
  total_balance: number
  balances: { id: number; code: string; name: string; type: CashAccountType; balance: number }[]
  trend: { month: string; income: number; expense: number }[]
  resilience: { balance: number; average_expense: number; months: number | null }
  recurring: { bank_balance: number; remaining: number; shortfall: number }
  recent_transactions: Transaction[]
}

export interface CategoryTotal {
  category_id: number
  code: string
  name: string
  group: string
  nature: CategoryNature
  type: TransactionType
  total: number
  count: number
}

export interface ReportSummary extends PeriodTotals {
  period: Period
  opening_balance: number
  closing_balance: number
  categories: { income: CategoryTotal[]; expense: CategoryTotal[] }
}

export interface CashFlowReport {
  period: Period
  opening_balance: number
  closing_balance: number
  rows: {
    id: number
    number: string
    date: string
    description: string
    counterparty: string | null
    category: string | null
    cash_account: string | null
    income: number
    expense: number
    balance: number
  }[]
}

export type MonthValues = Record<string, number>

export interface MatrixGroup {
  group: string
  nature: CategoryNature
  months: MonthValues
  total: number
  categories: { category_id: number; code: string; name: string; months: MonthValues; total: number }[]
}

export interface MonthlyReport {
  period: Period
  opening_balance: number
  closing_balance: number
  months: (PeriodTotals & { month: string; opening_balance: number; closing_balance: number })[]
  income: MatrixGroup[]
  expense: MatrixGroup[]
}

export interface CategoryRankingReport {
  period: Period
  rows: (CategoryTotal & { rank: number; percentage: number; monthly_average: number })[]
}

export interface RecurringExpense {
  id: number
  name: string
  category_id: number
  category?: Category
  counterparty: string | null
  bank_info: string | null
  amount: number
  description: string | null
  is_active: boolean
  sort_order: number
}

export type RecurringStatusValue = 'belum' | 'sebagian' | 'lunas'

export interface RecurringStatus {
  period: string
  items: {
    id: number
    name: string
    category: { id: number; code: string; name: string } | null
    category_id: number
    counterparty: string | null
    bank_info: string | null
    budget: number
    paid: number
    remaining: number
    status: RecurringStatusValue
    payments: { id: number; number: string; date: string; amount: number; cash_account: string | null }[]
  }[]
  totals: { budget: number; paid: number; remaining: number }
  sufficiency: { bank_balance: number; remaining: number; shortfall: number }
}

export type FundRequestStatus = 'diajukan' | 'ditransfer' | 'lpj_sebagian' | 'selesai'

export interface FundRequestSummary {
  requested: number
  carry_over: number
  suggested_transfer: number
  transferred: number
  remaining_transfer: number
  reported: number
  difference: number
  closing_balance: number
  status: FundRequestStatus
  status_label: string
}

export interface FundReportItem {
  id: number
  date: string
  category_id: number
  category?: { id: number; code: string; name: string } | null
  description: string
  qty: number
  unit: string | null
  unit_price: number
  amount: number
  transaction_number?: string | null
  attachments?: Attachment[]
}

export interface FundRequest {
  id: number
  number: string
  period: string
  request_date: string
  notes: string | null
  cash_account_id: number
  cash_account?: { id: number; code: string; name: string }
  creator?: { id: number; name: string } | null
  items?: { id: number; description: string; amount: number }[]
  transfers?: { transfer_group: string; number: string; date: string; amount: number; description: string }[]
  report?: { id: number; notes: string | null; submitted_at: string | null; items: FundReportItem[] } | null
  summary: FundRequestSummary
}

export interface SecretariatReport {
  periods: string[]
  requests: (FundRequestSummary & { id: number; number: string; period: string })[]
  by_category: { category_id: number; category: string | null; periods: Record<string, number>; total: number }[]
  items: {
    id: number
    period: string
    request_number: string
    date: string
    category: string | null
    description: string
    qty: number
    unit: string | null
    unit_price: number
    amount: number
  }[]
  total_reported: number
}

export interface ActivityLog {
  id: number
  user: { id: number; name: string } | null
  action: 'created' | 'updated' | 'deleted'
  subject_type: string
  subject_id: number
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  created_at: string
}

export type BudgetStatus = 'aman' | 'waspada' | 'lewat' | 'tercapai' | 'tertinggal'

export interface BudgetRow {
  id: number
  year: number
  category_id: number
  category: { id: number; code: string; name: string; group: string } | null
  type: TransactionType
  type_label: string
  amount: number
  notes: string | null
  actual: number
  expected_to_date: number
  percentage: number
  remaining: number
  status: BudgetStatus
}

export interface BudgetReport {
  year: number
  elapsed: number
  rows: BudgetRow[]
  totals: { expense_budget: number; expense_actual: number; income_budget: number; income_actual: number }
}

export type InsightSeverity = 'danger' | 'warning' | 'info' | 'success'

export interface Insight {
  key: string
  severity: InsightSeverity
  title: string
  message: string
  recommendation: string | null
  link: string | null
  items: { label: string; value: number; note?: string }[]
}

export interface AssistantAnswer {
  text: string
  items: { label: string; value: number; note?: string }[]
  suggestions: string[]
  link: string | null
  intent: string | null
}

export type OfferingSection = 'ibadah' | 'syukur' | 'perpuluhan' | 'lainnya' | 'pengeluaran'
export type OfferingCheckStatus = 'belum' | 'cocok' | 'kurang' | 'lebih'

export interface OfferingSummary {
  sections: Record<OfferingSection, number>
  total_offering: number
  total_expense: number
  expected_deposit: number
  other_destination_total: number
  matched: number
  difference: number
  status: OfferingCheckStatus
}

export interface OfferingLine {
  id?: number
  section: OfferingSection
  offering_fund_id?: number | null
  fund?: { id: number; name: string } | null
  label: string | null
  name: string | null
  amount: number
  destination_cash_account_id: number | null
  destination?: { id: number; code: string; name: string; bank_name?: string | null; account_number?: string | null } | null
  destination_note: string | null
}

export interface OfferingReport {
  id: number
  service_date: string
  period_start: string
  period_end: string
  notes: string | null
  creator?: { id: number; name: string } | null
  lines?: OfferingLine[]
  transactions?: { id: number; number: string; date: string; description: string; counterparty: string | null; category: string | null; amount: number }[]
  summary: OfferingSummary
}

export interface OfferingHistory {
  period: { year: number; month: number }
  month_summary: { weeks: number; sections: Record<OfferingSection, number>; total_offering: number; total_expense: number }
  data: { id: number; service_date: string; period_start: string; period_end: string; summary: OfferingSummary }[]
}

export interface OfferingCandidate {
  id: number
  number: string
  date: string
  description: string
  bank_description: string | null
  counterparty: string | null
  category: string | null
  amount: number
  linked: boolean
}

export interface OfferingFundOption {
  id: number
  name: string
  /** Teks rekening, mis. "BRI 123 a.n. Komisi Diakonia". */
  account: string
}

export interface OfferingTemplate {
  service_date: string
  week_of_month: number
  fund: OfferingFundOption | null
  ibadah_lines: { label: string; offering_fund_id: number | null; account: string }[]
  funds: OfferingFundOption[]
  default_cash_account_id: number | null
  main_account: string
}

export interface OfferingFundSetting {
  id?: number
  /** Kunci sementara untuk pos baru (belum punya id) agar bisa dirujuk jadwal. */
  key?: string
  name: string
  cash_account_id: number | null
  bank_name: string | null
  account_number: string | null
  account_holder: string | null
  is_active: boolean
  account?: string
  used?: boolean
}

export interface OfferingSettings {
  funds: OfferingFundSetting[]
  schedule: Record<string, number | null>
  services: string[]
}
