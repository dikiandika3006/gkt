<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\FinanceReportService;
use App\Services\RecurringExpenseService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(FinanceReportService $report, RecurringExpenseService $recurring): JsonResponse
    {
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
            'total_balance' => $report->balance(),
            'balances' => $report->balancesPerAccount(),
            'trend' => $report->monthlyTrend(12),
            'resilience' => $report->cashResilience(),
            'recurring' => $recurring->status($start->format('Y-m'))['sufficiency'],
            'recent_transactions' => TransactionResource::collection($recent),
        ]);
    }
}
