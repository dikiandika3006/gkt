<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\CashAccount;
use App\Models\Transaction;
use App\Services\AppSettingService;
use App\Services\FinanceReportService;
use App\Services\RecurringExpenseService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(FinanceReportService $report, RecurringExpenseService $recurring, AppSettingService $settings): JsonResponse
    {
        // Kartu saldo mengikuti Setting "Rekening di Dashboard" (null = semua kas); angka lain tetap gabungan.
        $accountId = $settings->dashboardCashAccountId();
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $recent = Transaction::with(['category', 'cashAccount'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return response()->json([
            'period' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            'month' => $report->totals($start, $end),
            'total_balance' => $report->balance($accountId),
            'balance_opening' => $report->balance($accountId, $start),
            'balance_scope' => [
                'cash_account_id' => $accountId,
                'name' => $accountId ? CashAccount::whereKey($accountId)->value('name') : null,
            ],
            'balances' => $report->balancesPerAccount($accountId),
            'trend' => $report->monthlyTrend(12),
            'resilience' => $report->cashResilience(),
            'recurring' => $recurring->status($start->format('Y-m'))['sufficiency'],
            'recent_transactions' => TransactionResource::collection($recent),
        ]);
    }
}
