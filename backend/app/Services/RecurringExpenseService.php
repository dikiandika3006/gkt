<?php

namespace App\Services;

use App\Enums\CashAccountType;
use App\Models\RecurringExpense;
use App\Models\Transaction;

/** Realisasi anggaran biaya rutin per bulan dan kecukupan dana rekening. */
class RecurringExpenseService
{
    public function __construct(private FinanceReportService $report) {}

    /** @param string $period YYYY-MM */
    public function status(string $period): array
    {
        $paid = Transaction::query()
            ->where('recurring_period', $period)
            ->whereNotNull('recurring_expense_id')
            ->with('cashAccount:id,name')
            ->orderBy('date')
            ->get(['id', 'number', 'date', 'amount', 'cash_account_id', 'recurring_expense_id'])
            ->groupBy('recurring_expense_id');

        // Item nonaktif tetap tampil jika sudah ada pembayaran di periode ini.
        $items = RecurringExpense::query()
            ->with('category:id,code,name')
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $paid->keys()))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (RecurringExpense $item) use ($paid) {
                $payments = $paid->get($item->id, collect());
                $budget = (float) $item->amount;
                $paidAmount = round((float) $payments->sum('amount'), 2);
                $remaining = round(max(0, $budget - $paidAmount), 2);

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'category' => $item->category?->only('id', 'code', 'name'),
                    'category_id' => $item->category_id,
                    'counterparty' => $item->counterparty,
                    'bank_info' => $item->bank_info,
                    'budget' => $budget,
                    'paid' => $paidAmount,
                    'remaining' => $remaining,
                    'status' => match (true) {
                        $paidAmount <= 0 => 'belum',
                        $remaining > 0 => 'sebagian',
                        default => 'lunas',
                    },
                    'payments' => $payments->map(fn (Transaction $t) => [
                        'id' => $t->id,
                        'number' => $t->number,
                        'date' => $t->date->toDateString(),
                        'amount' => (float) $t->amount,
                        'cash_account' => $t->cashAccount?->name,
                    ])->values(),
                ];
            });

        $bankBalance = round((float) $this->report->balancesPerAccount()
            ->where('type', CashAccountType::Bank->value)
            ->sum('balance'), 2);
        $remaining = round((float) $items->sum('remaining'), 2);

        return [
            'period' => $period,
            'items' => $items->values(),
            'totals' => [
                'budget' => round((float) $items->sum('budget'), 2),
                'paid' => round((float) $items->sum('paid'), 2),
                'remaining' => $remaining,
            ],
            'sufficiency' => [
                'bank_balance' => $bankBalance,
                'remaining' => $remaining,
                'shortfall' => round(max(0, $remaining - $bankBalance), 2),
            ],
        ];
    }
}
